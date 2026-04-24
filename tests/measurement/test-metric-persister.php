<?php
/**
 * Tests for `WP_MCP_AI_Metric_Persister`.
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Metric_Persister.
 */
class Test_WP_MCP_AI_Metric_Persister extends WP_UnitTestCase {

	/**
	 * Shared store instance.
	 *
	 * @var WP_MCP_AI_Metric_Event_Store
	 */
	private $store;

	/**
	 * Attach a fresh persister before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Clean slate for both singletons.
		WP_MCP_AI_Metric_Event_Store::reset_instance();
		WP_MCP_AI_Metric_Persister::reset_instance();

		$this->store = WP_MCP_AI_Metric_Event_Store::get_instance();
		$this->store->drop();
		$this->store->install();

		// The bootstrap has already attached a persister on plugins_loaded;
		// detach it so each test starts with a known hook state.
		remove_all_actions( 'wp_mcp_ai_metric_recorded' );

		WP_MCP_AI_Metric_Persister::get_instance()->attach();
	}

	/**
	 * Detach persister + drop store after each test.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Metric_Persister::reset_instance();
		$this->store->drop();
		WP_MCP_AI_Metric_Event_Store::reset_instance();
		parent::tearDown();
	}

	/**
	 * Events fired on the collector's action end up on the buffer, not
	 * the database, until shutdown runs.
	 */
	public function test_events_buffered_not_persisted_on_record() {
		$event = $this->make_event( 'metric.buffered', 1.0, 'internal' );
		do_action( 'wp_mcp_ai_metric_recorded', $event, $this->make_definition( 'metric.buffered' ), null );

		$this->assertSame( 1, WP_MCP_AI_Metric_Persister::get_instance()->buffer_size() );
		$this->assertSame( 0, $this->store->total_count() );
	}

	/**
	 * Explicit flush writes the buffered batch exactly once.
	 */
	public function test_flush_writes_batch() {
		for ( $i = 0; $i < 5; $i++ ) {
			do_action(
				'wp_mcp_ai_metric_recorded',
				$this->make_event( 'metric.flush', (float) $i, 'internal' ),
				$this->make_definition( 'metric.flush' ),
				null
			);
		}

		$written = WP_MCP_AI_Metric_Persister::get_instance()->flush();
		$this->assertSame( 5, $written );
		$this->assertSame( 5, $this->store->total_count() );

		// Buffer is drained.
		$this->assertSame( 0, WP_MCP_AI_Metric_Persister::get_instance()->buffer_size() );

		// Second flush is a no-op.
		$this->assertSame( 0, WP_MCP_AI_Metric_Persister::get_instance()->flush() );
	}

	/**
	 * Restricted events are dropped before buffering.
	 */
	public function test_restricted_events_dropped_before_buffer() {
		do_action(
			'wp_mcp_ai_metric_recorded',
			$this->make_event( 'metric.restricted', 1.0, 'restricted' ),
			$this->make_definition( 'metric.restricted' ),
			null
		);

		$this->assertSame( 0, WP_MCP_AI_Metric_Persister::get_instance()->buffer_size() );
		WP_MCP_AI_Metric_Persister::get_instance()->flush();
		$this->assertSame( 0, $this->store->total_count() );
	}

	/**
	 * `wp_mcp_ai_persister_should_persist` filter vetoes individual events.
	 */
	public function test_should_persist_filter_vetoes_events() {
		$veto = static function ( $should, $event ) {
			return 'metric.vetoed' === $event['id'] ? false : $should;
		};
		add_filter( 'wp_mcp_ai_persister_should_persist', $veto, 10, 2 );

		do_action(
			'wp_mcp_ai_metric_recorded',
			$this->make_event( 'metric.vetoed', 1.0, 'internal' ),
			$this->make_definition( 'metric.vetoed' ),
			null
		);
		do_action(
			'wp_mcp_ai_metric_recorded',
			$this->make_event( 'metric.kept', 1.0, 'internal' ),
			$this->make_definition( 'metric.kept' ),
			null
		);

		$this->assertSame( 1, WP_MCP_AI_Metric_Persister::get_instance()->buffer_size() );
		WP_MCP_AI_Metric_Persister::get_instance()->flush();
		$this->assertSame( 1, $this->store->total_count() );

		remove_filter( 'wp_mcp_ai_persister_should_persist', $veto, 10 );
	}

	/**
	 * `wp_mcp_ai_persister_enabled = false` keeps the persister from attaching.
	 */
	public function test_disabled_filter_prevents_attach() {
		WP_MCP_AI_Metric_Persister::reset_instance();
		remove_all_actions( 'wp_mcp_ai_metric_recorded' );
		add_filter( 'wp_mcp_ai_persister_enabled', '__return_false' );

		$this->assertFalse( WP_MCP_AI_Metric_Persister::get_instance()->attach() );

		do_action(
			'wp_mcp_ai_metric_recorded',
			$this->make_event( 'metric.off', 1.0, 'internal' ),
			$this->make_definition( 'metric.off' ),
			null
		);
		$this->assertSame( 0, WP_MCP_AI_Metric_Persister::get_instance()->buffer_size() );

		remove_filter( 'wp_mcp_ai_persister_enabled', '__return_false' );
	}

	/**
	 * Buffer cap is enforced — overflow events are dropped, not persisted.
	 */
	public function test_buffer_cap_drops_overflow() {
		$cap = static function () {
			return 3;
		};
		add_filter( 'wp_mcp_ai_persister_buffer_max', $cap );
		WP_MCP_AI_Metric_Persister::reset_instance();
		remove_all_actions( 'wp_mcp_ai_metric_recorded' );
		WP_MCP_AI_Metric_Persister::get_instance()->attach();

		for ( $i = 0; $i < 10; $i++ ) {
			do_action(
				'wp_mcp_ai_metric_recorded',
				$this->make_event( 'metric.cap', (float) $i, 'internal' ),
				$this->make_definition( 'metric.cap' ),
				null
			);
		}

		$this->assertSame( 3, WP_MCP_AI_Metric_Persister::get_instance()->buffer_size() );
		WP_MCP_AI_Metric_Persister::get_instance()->flush();
		$this->assertSame( 3, $this->store->total_count() );

		remove_filter( 'wp_mcp_ai_persister_buffer_max', $cap );
	}

	/**
	 * Detach clears the buffer and stops recording.
	 */
	public function test_detach_clears_and_stops() {
		do_action(
			'wp_mcp_ai_metric_recorded',
			$this->make_event( 'metric.detached', 1.0, 'internal' ),
			$this->make_definition( 'metric.detached' ),
			null
		);
		WP_MCP_AI_Metric_Persister::get_instance()->detach();
		$this->assertSame( 0, WP_MCP_AI_Metric_Persister::get_instance()->buffer_size() );

		do_action(
			'wp_mcp_ai_metric_recorded',
			$this->make_event( 'metric.after-detach', 1.0, 'internal' ),
			$this->make_definition( 'metric.after-detach' ),
			null
		);
		$this->assertSame( 0, WP_MCP_AI_Metric_Persister::get_instance()->buffer_size() );
	}

	/**
	 * Build a minimal collector-shaped event.
	 *
	 * @param string $id      Metric id.
	 * @param float  $value   Metric value.
	 * @param string $privacy Privacy tier.
	 * @return array<string,mixed>
	 */
	private function make_event( $id, $value, $privacy ) {
		return array(
			'id'        => $id,
			'value'     => (float) $value,
			'type'      => 'counter',
			'unit'      => 'events',
			'privacy'   => $privacy,
			'timestamp' => time(),
			'context'   => array(),
		);
	}

	/**
	 * Build a minimal definition payload passed alongside events.
	 *
	 * @param string $id Metric id.
	 * @return array<string,mixed>
	 */
	private function make_definition( $id ) {
		return array(
			'id'           => $id,
			'type'         => 'counter',
			'unit'         => 'events',
			'privacy_tier' => 'internal',
		);
	}
}
