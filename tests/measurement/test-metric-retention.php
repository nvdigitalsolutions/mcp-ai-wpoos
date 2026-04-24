<?php
/**
 * Tests for `WP_MCP_AI_Metric_Retention`.
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Metric_Retention — exercises TTL defaults,
 * filter overrides, tier-scoped purge, and cron scheduling.
 */
class Test_WP_MCP_AI_Metric_Retention extends WP_UnitTestCase {

	/**
	 * Event store instance used by each test.
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
		WP_MCP_AI_Metric_Retention::unschedule();
	}

	/**
	 * Drop schema and unschedule cron after each test.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Metric_Retention::unschedule();
		$this->store->drop();
		WP_MCP_AI_Metric_Event_Store::reset_instance();
		parent::tearDown();
	}

	/**
	 * Default TTLs match the documented values.
	 */
	public function test_default_ttls_match_docs() {
		$ttls = WP_MCP_AI_Metric_Retention::default_ttls_days();
		$this->assertSame( 365, $ttls['public'] );
		$this->assertSame( 90, $ttls['internal'] );
		$this->assertSame( 30, $ttls['sensitive'] );
		$this->assertArrayNotHasKey( 'restricted', $ttls );
	}

	/**
	 * `wp_mcp_ai_measurement_retention` filter is honoured, with clamping.
	 */
	public function test_retention_filter_is_honoured_and_clamped() {
		$filter = static function () {
			return array(
				'public'    => 10,
				'internal'  => 0,    // Below floor → clamped to 1.
				'sensitive' => 99999, // Above ceiling → clamped to 3650.
			);
		};
		add_filter( 'wp_mcp_ai_measurement_retention', $filter );

		$ttls = WP_MCP_AI_Metric_Retention::resolve_ttls_days();
		$this->assertSame( 10, $ttls['public'] );
		$this->assertSame( 1, $ttls['internal'] );
		$this->assertSame( 3650, $ttls['sensitive'] );

		remove_filter( 'wp_mcp_ai_measurement_retention', $filter );
	}

	/**
	 * Malformed filter return is ignored (fallback to defaults).
	 */
	public function test_non_array_filter_return_falls_back_to_defaults() {
		$filter = static function () {
			return 'nonsense';
		};
		add_filter( 'wp_mcp_ai_measurement_retention', $filter );

		$ttls = WP_MCP_AI_Metric_Retention::resolve_ttls_days();
		$this->assertSame( WP_MCP_AI_Metric_Retention::default_ttls_days(), $ttls );

		remove_filter( 'wp_mcp_ai_measurement_retention', $filter );
	}

	/**
	 * End-to-end purge: events older than the per-tier cutoff are removed,
	 * newer events survive.
	 */
	public function test_run_deletes_only_expired_rows_per_tier() {
		$now = time();
		$this->store->insert_batch(
			array(
				// Internal: 90d TTL. Expired / fresh.
				$this->event( 'metric.int', 'internal', $now - ( 100 * DAY_IN_SECONDS ) ),
				$this->event( 'metric.int', 'internal', $now - ( 10 * DAY_IN_SECONDS ) ),
				// Sensitive: 30d TTL.
				$this->event( 'metric.sen', 'sensitive', $now - ( 60 * DAY_IN_SECONDS ) ),
				$this->event( 'metric.sen', 'sensitive', $now - ( 5 * DAY_IN_SECONDS ) ),
				// Public: 365d TTL.
				$this->event( 'metric.pub', 'public', $now - ( 400 * DAY_IN_SECONDS ) ),
				$this->event( 'metric.pub', 'public', $now - ( 100 * DAY_IN_SECONDS ) ),
			)
		);

		$deleted = WP_MCP_AI_Metric_Retention::run();
		$this->assertSame( 1, $deleted['internal'] );
		$this->assertSame( 1, $deleted['sensitive'] );
		$this->assertSame( 1, $deleted['public'] );

		$counts = $this->store->count_by_privacy();
		$this->assertSame( 1, $counts['internal'] );
		$this->assertSame( 1, $counts['sensitive'] );
		$this->assertSame( 1, $counts['public'] );
	}

	/**
	 * The completion action fires with the deleted counts.
	 */
	public function test_completion_action_fires_with_counts() {
		$now = time();
		$this->store->insert_batch(
			array( $this->event( 'metric.int', 'internal', $now - ( 100 * DAY_IN_SECONDS ) ) )
		);

		$received = null;
		$listener = static function ( $deleted ) use ( &$received ) {
			$received = $deleted;
		};
		add_action( 'wp_mcp_ai_measurement_retention_completed', $listener );
		WP_MCP_AI_Metric_Retention::run();
		remove_action( 'wp_mcp_ai_measurement_retention_completed', $listener );

		$this->assertIsArray( $received );
		$this->assertSame( 1, $received['internal'] );
	}

	/**
	 * Schedule / unschedule lifecycle.
	 */
	public function test_schedule_and_unschedule() {
		WP_MCP_AI_Metric_Retention::schedule();
		$this->assertNotFalse( wp_next_scheduled( WP_MCP_AI_Metric_Retention::CRON_HOOK ) );

		WP_MCP_AI_Metric_Retention::unschedule();
		$this->assertFalse( wp_next_scheduled( WP_MCP_AI_Metric_Retention::CRON_HOOK ) );
	}

	/**
	 * Schedule is idempotent — calling twice does not queue two events.
	 */
	public function test_schedule_is_idempotent() {
		WP_MCP_AI_Metric_Retention::schedule();
		$first = wp_next_scheduled( WP_MCP_AI_Metric_Retention::CRON_HOOK );

		WP_MCP_AI_Metric_Retention::schedule();
		$second = wp_next_scheduled( WP_MCP_AI_Metric_Retention::CRON_HOOK );

		$this->assertSame( $first, $second );
	}

	/**
	 * Restricted tier has no TTL key, so the cron does not attempt to
	 * purge it — the "never persisted" invariant is not accidentally
	 * reinterpreted as "persisted with 0-day TTL".
	 */
	public function test_restricted_tier_is_not_purged() {
		// Simulate a stray restricted row (e.g. from a bug) — the cron
		// must NOT delete it; that's the defensive signal something is wrong.
		global $wpdb;
		$table = $this->store->table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->insert(
			$table,
			array(
				'metric_id'    => 'metric.bug',
				'metric_value' => 1.0,
				'metric_type'  => 'counter',
				'metric_unit'  => 'events',
				'privacy'      => 'restricted',
				'recorded_at'  => gmdate( 'Y-m-d H:i:s', time() - ( 1000 * DAY_IN_SECONDS ) ),
				'context'      => '{}',
			)
		);

		$deleted = WP_MCP_AI_Metric_Retention::run();
		$this->assertArrayNotHasKey( 'restricted', $deleted );

		$counts = $this->store->count_by_privacy();
		$this->assertSame( 1, $counts['restricted'] );
	}

	/**
	 * Build an event at a specific timestamp for retention tests.
	 *
	 * @param string $id        Metric id.
	 * @param string $privacy   Privacy tier.
	 * @param int    $timestamp UTC timestamp.
	 * @return array<string,mixed>
	 */
	private function event( $id, $privacy, $timestamp ) {
		return array(
			'id'        => $id,
			'value'     => 1.0,
			'type'      => 'counter',
			'unit'      => 'events',
			'privacy'   => $privacy,
			'timestamp' => $timestamp,
			'context'   => array(),
		);
	}
}
