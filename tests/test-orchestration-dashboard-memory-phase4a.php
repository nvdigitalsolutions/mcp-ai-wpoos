<?php
/**
 * Tests for orchestration dashboard memory widget — Phase 4a additions.
 *
 * Covers the new fields added to `get_agent_memory_stats()` (wings/rooms,
 * mined memories, bridge status, retrieval-path telemetry) plus the
 * `record_retrieval_telemetry()` helper on the wake-up tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test memory widget Phase 4a stats.
 */
class Test_Orchestration_Dashboard_Memory_Phase4a extends WP_UnitTestCase {

	/**
	 * Dashboard instance under test.
	 *
	 * @var WP_MCP_AI_Admin_Orchestration_Dashboard
	 */
	private $dashboard;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Make sure no stale cache leaks between tests.
		delete_transient( 'wp_mcp_ai_agent_memory_stats' );
		delete_option( 'wp_mcp_ai_wake_up_telemetry' );

		// Admin-only files are gated behind `is_admin()` in the loader, which
		// is false in CLI PHPUnit. Load the class directly so the test can
		// instantiate it.
		if ( ! class_exists( 'WP_MCP_AI_Admin_Orchestration_Dashboard' ) ) {
			$path = dirname( __DIR__ ) . '/includes/admin/class-wp-mcp-ai-admin-orchestration-dashboard.php';
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Admin_Orchestration_Dashboard' ) ) {
			$this->markTestSkipped( 'Base orchestration dashboard class not loaded.' );
		}

		$this->dashboard = new WP_MCP_AI_Admin_Orchestration_Dashboard();
	}

	/**
	 * Tear down after each test — wipe per-agent indexes we created.
	 */
	public function tearDown(): void {
		delete_transient( 'wp_mcp_ai_agent_memory_stats' );
		delete_option( 'wp_mcp_ai_wake_up_telemetry' );

		// Clear any test agent index transients.
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup of transients we seeded.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_mcp_ai_ctx_index_' ) . '%'
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_timeout_mcp_ai_ctx_index_' ) . '%'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		parent::tearDown();
	}

	/**
	 * Helper: invoke a protected method via reflection.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Args.
	 * @return mixed
	 */
	private function invoke( $method, array $args = array() ) {
		$ref    = new ReflectionClass( $this->dashboard );
		$reflex = $ref->getMethod( $method );
		$reflex->setAccessible( true );
		return $reflex->invokeArgs( $this->dashboard, $args );
	}

	/**
	 * Helper: write a per-agent context index transient that mimics the shape
	 * produced by `store_agent_context`.
	 *
	 * @param string $agent_id Agent identifier.
	 * @param array  $entries  Array of context_id => meta entries.
	 * @return void
	 */
	private function seed_agent_index( $agent_id, array $entries ) {
		$key = 'mcp_ai_ctx_index_' . md5( (string) $agent_id );
		set_transient( $key, $entries, HOUR_IN_SECONDS );
	}

	/**
	 * Empty store should still return all keys with sensible defaults.
	 */
	public function test_stats_have_phase_4a_keys_when_empty() {
		$stats = $this->invoke( 'get_agent_memory_stats' );

		$this->assertSame( 0, $stats['total_contexts'] );
		$this->assertSame( 0, $stats['total_agents'] );
		$this->assertArrayHasKey( 'wings_count', $stats );
		$this->assertArrayHasKey( 'rooms_count', $stats );
		$this->assertArrayHasKey( 'mined_count', $stats );
		$this->assertArrayHasKey( 'bridge_active', $stats );
		$this->assertArrayHasKey( 'retrieval_path', $stats );
		$this->assertArrayHasKey( 'contexts_by_wing', $stats );
		$this->assertArrayHasKey( 'contexts_by_importance', $stats );

		$this->assertSame( 0, $stats['wings_count'] );
		$this->assertSame( 0, $stats['rooms_count'] );
		$this->assertSame( 0, $stats['mined_count'] );
		$this->assertSame( 0, $stats['retrieval_path']['graph'] );
		$this->assertSame( 0, $stats['retrieval_path']['transient'] );
		$this->assertSame( 0, $stats['retrieval_path']['total'] );
	}

	/**
	 * Wings, rooms and mined counts should be derived from the index.
	 */
	public function test_stats_count_wings_rooms_and_mined() {
		// Force cache miss for this run.
		delete_transient( 'wp_mcp_ai_agent_memory_stats' );

		$this->seed_agent_index(
			'agent-1',
			array(
				'ctx_a' => array(
					'type'       => 'fact',
					'wing'       => 'docs',
					'room'       => 'api',
					'verbatim'   => true,
					'importance' => 'high',
				),
				'ctx_b' => array(
					'type'       => 'fact',
					'wing'       => 'docs',
					'room'       => 'guides',
					'verbatim'   => false,
					'importance' => 'medium',
				),
				'ctx_c' => array(
					'type'       => 'note',
					'wing'       => 'projects',
					'room'       => 'phase4a',
					'verbatim'   => true,
					'importance' => 'high',
				),
				'ctx_d' => array(
					'type'       => 'note',
					'wing'       => '',     // unscoped should not increment wings_count.
					'room'       => '',
					'verbatim'   => false,
					'importance' => 'low',
				),
			)
		);

		$stats = $this->invoke( 'get_agent_memory_stats' );

		$this->assertSame( 4, $stats['total_contexts'] );
		$this->assertSame( 1, $stats['total_agents'] );
		// Two distinct real wings (docs, projects) — `(unscoped)` is not counted.
		$this->assertSame( 2, $stats['wings_count'] );
		// Three distinct (wing, room) pairs: docs/api, docs/guides, projects/phase4a.
		$this->assertSame( 3, $stats['rooms_count'] );
		// Two verbatim records.
		$this->assertSame( 2, $stats['mined_count'] );

		// Breakdowns include the new buckets.
		$this->assertSame( 2, $stats['contexts_by_wing']['docs'] );
		$this->assertSame( 1, $stats['contexts_by_wing']['projects'] );
		$this->assertSame( 1, $stats['contexts_by_wing']['(unscoped)'] );
		$this->assertSame( 2, $stats['contexts_by_importance']['high'] );
		$this->assertSame( 1, $stats['contexts_by_importance']['medium'] );
		$this->assertSame( 1, $stats['contexts_by_importance']['low'] );
	}

	/**
	 * Bridge flag mirrors `class_exists()` of the Graphify bridge.
	 */
	public function test_bridge_active_flag_reflects_class_existence() {
		$stats = $this->invoke( 'get_agent_memory_stats' );
		$this->assertSame( class_exists( 'NV_oOS_Graphify_Memory_Bridge' ), $stats['bridge_active'] );
	}

	/**
	 * Bridge flag must be recomputed on cache hits so that activating the
	 * Graphify add-on after the dashboard was first viewed does not leave a
	 * stale `false` baked into the 5-minute transient cache.
	 *
	 * Regression: dashboard reported "Graphify Memory Bridge: not installed"
	 * for up to 5 minutes after Graphify was activated.
	 */
	public function test_bridge_active_recomputed_on_cache_hit() {
		// Seed the cache with a stale false (simulates dashboard view before
		// the Graphify add-on was activated).
		$stale = array(
			'total_contexts'         => 0,
			'total_agents'           => 0,
			'contexts_by_type'       => array(),
			'contexts_by_wing'       => array(),
			'contexts_by_importance' => array(),
			'wings_count'            => 0,
			'rooms_count'            => 0,
			'mined_count'            => 0,
			'bridge_active'          => false,
			'retrieval_path'         => array(
				'graph'     => 0,
				'transient' => 0,
				'total'     => 0,
			),
			'persistent_storage'     => array(
				'cct_count'      => 0,
				'available'      => false,
				'tier_breakdown' => array(),
			),
		);
		set_transient( 'wp_mcp_ai_agent_memory_stats', $stale, 5 * MINUTE_IN_SECONDS );

		$stats = $this->invoke( 'get_agent_memory_stats' );

		// Cached payload was returned (total_contexts is the cached zero, not
		// a recomputed value), but `bridge_active` is the live class-existence
		// state.
		$this->assertSame( 0, $stats['total_contexts'] );
		$this->assertSame( class_exists( 'NV_oOS_Graphify_Memory_Bridge' ), $stats['bridge_active'] );
	}

	/**
	 * `persistent_storage.available` must be re-checked on cache hits so
	 * that activating JetEngine (or its agent-memory CCT table) after the
	 * dashboard was first viewed does not leave a stale `false` baked into
	 * the 5-minute transient cache.
	 *
	 * Regression: dashboard reported "Install JetEngine to enable durable
	 * agent-memory storage…" for up to 5 minutes after JetEngine became
	 * available, even though the orchestration page knows the plugin is
	 * active.
	 */
	public function test_persistent_storage_available_recomputed_on_cache_hit() {
		// Seed the cache with a stale `available => false` (simulates the
		// dashboard being viewed before JetEngine / its CCT table existed).
		$stale = array(
			'total_contexts'         => 0,
			'total_agents'           => 0,
			'contexts_by_type'       => array(),
			'contexts_by_wing'       => array(),
			'contexts_by_importance' => array(),
			'wings_count'            => 0,
			'rooms_count'            => 0,
			'mined_count'            => 0,
			'bridge_active'          => false,
			'retrieval_path'         => array(
				'graph'     => 0,
				'transient' => 0,
				'total'     => 0,
			),
			'persistent_storage'     => array(
				'cct_count'      => 0,
				'available'      => false,
				'tier_breakdown' => array(),
			),
		);
		set_transient( 'wp_mcp_ai_agent_memory_stats', $stale, 5 * MINUTE_IN_SECONDS );

		$stats = $this->invoke( 'get_agent_memory_stats' );

		$live_available = $this->invoke( 'is_persistent_memory_available' );
		$this->assertSame(
			$live_available,
			! empty( $stats['persistent_storage']['available'] ),
			'persistent_storage.available should track live CCT-table existence, not the cached value.'
		);
	}

	/**
	 * `record_retrieval_telemetry()` accumulates 7-day rolling totals.
	 */
	public function test_record_retrieval_telemetry_aggregates_into_stats() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Wake_Up_Context' ) ) {
			$this->markTestSkipped( 'Wake_Up_Context tool not loaded.' );
		}

		WP_MCP_AI_Tool_Wake_Up_Context::record_retrieval_telemetry( 'graph' );
		WP_MCP_AI_Tool_Wake_Up_Context::record_retrieval_telemetry( 'graph' );
		WP_MCP_AI_Tool_Wake_Up_Context::record_retrieval_telemetry( 'transient' );
		// Rejected — silently dropped.
		WP_MCP_AI_Tool_Wake_Up_Context::record_retrieval_telemetry( 'bogus' );
		WP_MCP_AI_Tool_Wake_Up_Context::record_retrieval_telemetry( '' );

		// Force the dashboard to recompute.
		delete_transient( 'wp_mcp_ai_agent_memory_stats' );
		$stats = $this->invoke( 'get_agent_memory_stats' );

		$this->assertSame( 2, $stats['retrieval_path']['graph'] );
		$this->assertSame( 1, $stats['retrieval_path']['transient'] );
		$this->assertSame( 3, $stats['retrieval_path']['total'] );
	}

	/**
	 * Telemetry buckets older than 7 days are pruned automatically.
	 */
	public function test_record_retrieval_telemetry_prunes_old_buckets() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Wake_Up_Context' ) ) {
			$this->markTestSkipped( 'Wake_Up_Context tool not loaded.' );
		}

		// Plant a stale bucket 30 days back.
		$stale_date = gmdate( 'Y-m-d', time() - ( 30 * DAY_IN_SECONDS ) );
		update_option(
			'wp_mcp_ai_wake_up_telemetry',
			array(
				$stale_date => array(
					'graph'     => 99,
					'transient' => 99,
				),
			),
			false
		);

		// Recording a fresh hit triggers the prune.
		WP_MCP_AI_Tool_Wake_Up_Context::record_retrieval_telemetry( 'graph' );

		$telemetry = get_option( 'wp_mcp_ai_wake_up_telemetry', array() );
		$this->assertArrayNotHasKey( $stale_date, $telemetry, 'Stale bucket should be pruned.' );

		// Today's bucket should hold the single increment.
		$today = gmdate( 'Y-m-d' );
		$this->assertArrayHasKey( $today, $telemetry );
		$this->assertSame( 1, (int) $telemetry[ $today ]['graph'] );
	}
}
