<?php
/**
 * Tests for `WP_MCP_AI_Metric_Event_Store`.
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Metric_Event_Store.
 */
class Test_WP_MCP_AI_Metric_Event_Store extends WP_UnitTestCase {

	/**
	 * Shared store instance.
	 *
	 * @var WP_MCP_AI_Metric_Event_Store
	 */
	private $store;

	/**
	 * Install schema before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Metric_Event_Store::reset_instance();
		$this->store = WP_MCP_AI_Metric_Event_Store::get_instance();
		$this->store->drop();
		$this->store->install();
	}

	/**
	 * Drop schema after each test.
	 */
	public function tearDown(): void {
		$this->store->drop();
		WP_MCP_AI_Metric_Event_Store::reset_instance();
		parent::tearDown();
	}

	/**
	 * Schema install is idempotent and short-circuits on repeat.
	 */
	public function test_install_is_idempotent() {
		$this->assertTrue( $this->store->table_exists() );
		// Second call must not throw and must still report the table.
		$this->assertTrue( $this->store->install() );
		$this->assertTrue( $this->store->table_exists() );

		$this->assertSame( (int) WP_MCP_AI_Metric_Event_Store::SCHEMA_VERSION, (int) get_option( WP_MCP_AI_Metric_Event_Store::SCHEMA_OPTION ) );
	}

	/**
	 * Schema option is populated with the current SCHEMA_VERSION.
	 */
	public function test_install_records_schema_version() {
		$this->assertSame(
			WP_MCP_AI_Metric_Event_Store::SCHEMA_VERSION,
			(int) get_option( WP_MCP_AI_Metric_Event_Store::SCHEMA_OPTION )
		);
	}

	/**
	 * Basic round-trip: insert then query returns the same rows.
	 */
	public function test_insert_batch_and_query_roundtrip() {
		$now    = time();
		$events = array(
			$this->make_event( 'tool.execution.count', 1, 'internal', $now - 10 ),
			$this->make_event( 'tool.execution.count', 1, 'internal', $now - 5 ),
			$this->make_event( 'tool.execution.duration_ms', 42.5, 'internal', $now ),
		);

		$written = $this->store->insert_batch( $events );
		$this->assertSame( 3, $written );

		$rows = $this->store->query_by_metric( 'tool.execution.count', $now - 60, $now + 60, 100 );
		$this->assertCount( 2, $rows );
		foreach ( $rows as $row ) {
			$this->assertSame( 'tool.execution.count', $row['metric_id'] );
			$this->assertSame( 1.0, $row['metric_value'] );
			$this->assertSame( 'internal', $row['privacy'] );
			$this->assertIsArray( $row['context'] );
		}
	}

	/**
	 * Restricted events are hard-dropped at the store barrier.
	 */
	public function test_restricted_tier_is_hard_dropped() {
		$now = time();
		$this->store->insert_batch(
			array(
				$this->make_event( 'metric.restricted', 1, 'restricted', $now ),
				$this->make_event( 'metric.internal', 1, 'internal', $now ),
			)
		);

		$counts = $this->store->count_by_privacy();
		$this->assertArrayNotHasKey( 'restricted', $counts );
		$this->assertSame( 1, $counts['internal'] );
	}

	/**
	 * Unknown privacy tier is coerced to internal rather than dropped.
	 */
	public function test_unknown_privacy_coerces_to_internal() {
		$now = time();
		$this->store->insert_batch(
			array( $this->make_event( 'metric.weird', 1, 'made-up-tier', $now ) )
		);

		$counts = $this->store->count_by_privacy();
		$this->assertSame( 1, $counts['internal'] );
	}

	/**
	 * Invalid events are skipped without failing the whole batch.
	 */
	public function test_invalid_events_skipped() {
		$now     = time();
		$written = $this->store->insert_batch(
			array(
				array(
					'id'        => '',
					'value'     => 1,
					'privacy'   => 'internal',
					'timestamp' => $now,
				),
				array(
					'id'        => 'missing.value',
					'privacy'   => 'internal',
					'timestamp' => $now,
				),
				$this->make_event( 'valid.one', 2, 'internal', $now ),
			)
		);
		$this->assertSame( 1, $written );
	}

	/**
	 * Query honours `since`/`until` bounds.
	 */
	public function test_query_honours_time_bounds() {
		$now = time();
		$this->store->insert_batch(
			array(
				$this->make_event( 'metric.time', 1, 'internal', $now - 3600 ),
				$this->make_event( 'metric.time', 1, 'internal', $now - 60 ),
				$this->make_event( 'metric.time', 1, 'internal', $now - 5 ),
			)
		);

		$recent = $this->store->query_by_metric( 'metric.time', $now - 120, $now + 10, 100 );
		$this->assertCount( 2, $recent );
	}

	/**
	 * Query LIMIT is clamped to 5000 upper bound.
	 */
	public function test_query_limit_is_clamped() {
		$now    = time();
		$events = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$events[] = $this->make_event( 'metric.lim', 1, 'internal', $now - $i );
		}
		$this->store->insert_batch( $events );

		$rows = $this->store->query_by_metric( 'metric.lim', null, null, 0 );
		// 0 is clamped to 1.
		$this->assertCount( 1, $rows );

		$rows = $this->store->query_by_metric( 'metric.lim', null, null, 99999 );
		// Does not blow up on absurd requests.
		$this->assertLessThanOrEqual( 5, count( $rows ) );
	}

	/**
	 * Purge removes rows older than cutoff for the given tier only.
	 */
	public function test_purge_older_than_is_tier_scoped() {
		$now = time();
		$this->store->insert_batch(
			array(
				$this->make_event( 'metric.old', 1, 'internal', $now - ( 100 * DAY_IN_SECONDS ) ),
				$this->make_event( 'metric.new', 1, 'internal', $now - 10 ),
				$this->make_event( 'metric.sens', 1, 'sensitive', $now - ( 100 * DAY_IN_SECONDS ) ),
			)
		);

		$deleted = $this->store->purge_older_than( 'internal', $now - ( 90 * DAY_IN_SECONDS ) );
		$this->assertSame( 1, $deleted );

		// Sensitive row is untouched because tier scope is strict.
		$counts = $this->store->count_by_privacy();
		$this->assertSame( 1, $counts['sensitive'] );
	}

	/**
	 * Purge refuses unknown or restricted tiers.
	 */
	public function test_purge_refuses_unknown_or_restricted() {
		$this->assertSame( 0, $this->store->purge_older_than( 'restricted', time() ) );
		$this->assertSame( 0, $this->store->purge_older_than( 'made-up', time() ) );
	}

	/**
	 * Large batch is chunked but still commits every row.
	 */
	public function test_large_batch_chunks_correctly() {
		$now    = time();
		$events = array();
		// More than MAX_BATCH_ROWS to force chunking.
		$total = WP_MCP_AI_Metric_Event_Store::MAX_BATCH_ROWS + 50;
		for ( $i = 0; $i < $total; $i++ ) {
			$events[] = $this->make_event( 'metric.chunk', 1, 'internal', $now - $i );
		}

		$written = $this->store->insert_batch( $events );
		$this->assertSame( $total, $written );
		$this->assertSame( $total, $this->store->total_count() );
	}

	/**
	 * `install()` after a fresh instance reuses the existing schema
	 * without erroring. This complements the idempotency test and
	 * sits as a lightweight regression guard for schema plumbing.
	 */
	public function test_install_after_reset_returns_ready() {
		WP_MCP_AI_Metric_Event_Store::reset_instance();
		$fresh = WP_MCP_AI_Metric_Event_Store::get_instance();
		$this->assertTrue( $fresh->install() );
		$this->assertTrue( $fresh->table_exists() );
	}

	/**
	 * Build a minimal collector-shaped event for store tests.
	 *
	 * @param string $id        Metric id.
	 * @param float  $value     Metric value.
	 * @param string $privacy   Privacy tier.
	 * @param int    $timestamp UTC timestamp.
	 * @param array  $context   Optional context array.
	 * @return array
	 */
	private function make_event( $id, $value, $privacy, $timestamp, $context = array() ) {
		return array(
			'id'        => $id,
			'value'     => (float) $value,
			'type'      => 'counter',
			'unit'      => 'events',
			'privacy'   => $privacy,
			'timestamp' => $timestamp,
			'context'   => $context,
		);
	}
}
