<?php
/**
 * Orchestration dashboard ported-class tests (Wave E-UI-1, sub-cluster 2).
 *
 * Verifies the extraction port of the base plugin's
 * `WP_MCP_AI_Admin_Orchestration_Dashboard` preserves the public
 * behaviour: the byte-identical page slug / nonce / AJAX action names,
 * the standalone-only menu registration under the NV Platform menu,
 * the per-mode collaborator seams (profession meta keys, seeder,
 * tool inventory, context manager, SSE probe, settings cross-links),
 * the orchestration statistics shape (with real profession posts),
 * the status banner thresholds, the system status shape, the
 * recent-workflows transient list, the agent memory stats aggregation
 * (type/wing/importance/rooms/mined/persistent/retrieval-path), the
 * render output (including the per-mode settings links), the AJAX
 * nonce/capability gates, the workflow restart flow, and the
 * per-mode asset enqueues. Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Admin\Dashboards\OrchestrationDashboard;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The test-only exposer fixture shares this file with its test case.

/**
 * Test-only exposer: the dashboard's protected statics and render
 * internals are published as public wrappers.
 */
class OrchestrationDashboardExposer extends OrchestrationDashboard {

	public static function exposed_profession_meta_keys(): array {
		return array(
			'agent_role'    => self::profession_agent_role_meta_key(),
			'task_patterns' => self::profession_task_patterns_meta_key(),
		);
	}

	public static function exposed_seeder() {
		return self::seeder_instance();
	}

	public static function exposed_tool_entries(): array {
		return self::tool_registry_entries();
	}

	public static function exposed_context_manager() {
		return self::context_manager();
	}

	public static function exposed_sse_available(): bool {
		return self::sse_available();
	}

	public static function exposed_settings_page_url( $tab ): string {
		return self::settings_page_url( $tab );
	}

	public static function exposed_stats(): array {
		$dashboard = new self();
		return $dashboard->get_orchestration_statistics();
	}

	public static function exposed_system_status(): array {
		$dashboard = new self();
		return $dashboard->get_system_status();
	}

	public static function exposed_recent_workflows(): array {
		$dashboard = new self();
		return $dashboard->get_recent_workflows();
	}

	public static function exposed_memory_stats(): array {
		$dashboard = new self();
		return $dashboard->get_agent_memory_stats();
	}

	public static function exposed_tool_counts(): array {
		$dashboard = new self();
		return array(
			'orchestration' => $dashboard->count_orchestration_tools(),
			'agent_names'   => $dashboard->get_agent_tool_names(),
		);
	}

	public static function exposed_banner( $stats ): string {
		$dashboard = new self();
		\ob_start();
		try {
			$dashboard->render_status_banner( $stats );
		} finally {
			return (string) \ob_get_clean();
		}
	}

	public static function exposed_role_chart( $stats ): string {
		$dashboard = new self();
		\ob_start();
		try {
			$dashboard->render_role_distribution_chart( $stats );
		} finally {
			return (string) \ob_get_clean();
		}
	}

	public static function exposed_breakdown_table( $group_key, $column_label, array $counts, $total, $active ): string {
		$dashboard = new self();
		\ob_start();
		try {
			$dashboard->render_breakdown_table( $group_key, $column_label, $counts, $total, $active );
		} finally {
			return (string) \ob_get_clean();
		}
	}

	public static function exposed_retrieval_chart( array $telemetry ): string {
		$dashboard = new self();
		\ob_start();
		try {
			$dashboard->render_retrieval_path_chart( $telemetry );
		} finally {
			return (string) \ob_get_clean();
		}
	}

	public function exposed_render(): string {
		\ob_start();
		try {
			$this->render_dashboard();
		} finally {
			$output = (string) \ob_get_clean();
		}
		return $output;
	}
}

/**
 * Orchestration dashboard characterisation suite (Wave E-UI-1, sub-cluster 2).
 */
#[\PHPUnit\Framework\Attributes\Group( 'dashboards' )]
class Test_Orchestration_Dashboard extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		\wp_set_current_user( 0 );

		// Script/style queue leaks + WP 6.9 all_queued_deps memoization:
		// reset through the public API so the memo invalidates.
		global $wp_scripts;
		foreach ( (array) $wp_scripts->queue as $handle ) {
			\wp_dequeue_script( $handle );
		}
		foreach ( (array) \wp_styles()->queue as $handle ) {
			\wp_dequeue_style( $handle );
		}

		// The dashboard caches through these transients — start clean.
		\delete_transient( 'wp_mcp_ai_agent_memory_stats' );
		\delete_transient( 'wp_mcp_ai_recent_workflows' );

		// The persistent test database accumulates ctx-index + workflow
		// transient rows across runs and suites — the aggregation tests
		// count by namespace, so isolate the namespaces up front.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-isolation cleanup on the options table; the transient API has no wildcard delete.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_mcp_ai_ctx_index_' ) . '%' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-isolation cleanup on the options table; the transient API has no wildcard delete.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_wp_mcp_ai_workflow_' ) . '%' ) );
	}

	public function tearDown(): void {
		\wp_set_current_user( 0 );
		\delete_transient( 'wp_mcp_ai_agent_memory_stats' );
		\delete_transient( 'wp_mcp_ai_recent_workflows' );
		parent::tearDown();
	}

	/**
	 * Create + switch to an administrator user.
	 *
	 * @return int
	 */
	private function admin_user(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Count the registered callbacks on a hook.
	 *
	 * @param string $hook Hook name.
	 * @return int
	 */
	private function count_callbacks( string $hook ): int {
		global $wp_filter;
		return isset( $wp_filter[ $hook ] ) ? \count( $wp_filter[ $hook ]->callbacks ) : 0;
	}

	/**
	 * Invoke an AJAX handler and capture its echoed wp_die payload.
	 *
	 * @param callable $callback AJAX handler invocation.
	 * @return string Echoed payload (the WPDieException is swallowed).
	 */
	private function capture_ajax_call( callable $callback ): string {
		\ob_start();
		try {
			$callback();
		} catch ( \WPDieException $e ) {
			unset( $e ); // Expected — the JSON payload was echoed before the die.
		}
		return (string) \ob_get_clean();
	}

	/**
	 * Invoke an AJAX handler and capture the WPDieException message instead
	 * of the echo buffer. check_ajax_referer() failures call wp_die( -1 )
	 * through the throwing test die-handler, which echoes nothing — the
	 * payload lives in the exception.
	 *
	 * @param callable $callback AJAX handler invocation.
	 * @return string The die message, or the echoed output if no die fired.
	 */
	private function capture_ajax_die_message( callable $callback ): string {
		\ob_start();
		try {
			$callback();
		} catch ( \WPDieException $e ) {
			\ob_end_clean();
			return $e->getMessage();
		}
		return (string) \ob_get_clean();
	}

	/**
	 * Craft a valid nonce into the superglobals for the success paths.
	 *
	 * @return void
	 */
	private function set_nonce(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: craft a valid nonce to isolate the downstream gates.
		$_POST['nonce']    = \wp_create_nonce( OrchestrationDashboard::NONCE_ACTION );
		$_REQUEST['nonce'] = $_POST['nonce'];
		// phpcs:enable WordPress.Security.NonceVerification
	}

	/**
	 * Remove the test nonce from the superglobals.
	 *
	 * @return void
	 */
	private function clear_nonce(): void {
		unset( $_POST['nonce'], $_REQUEST['nonce'] );
	}

	// ─── Public surface constants ────────────────────────────────

	public function test_page_slug_and_nonce_are_byte_identical(): void {
		$this->assertSame( 'mcp-ai-orchestration', OrchestrationDashboard::PAGE_SLUG );
		$this->assertSame( 'wp_mcp_ai_orchestration', OrchestrationDashboard::NONCE_ACTION );
	}

	// ─── Menu + hook registration ────────────────────────────────

	public function test_menu_registration_resolves_per_install_mode(): void {
		global $submenu;

		// Isolate from any prior contamination — the submenu global is not
		// reliably reset between tests in this process.
		$submenu = array();

		// add_submenu_page() early-returns when the current user lacks the
		// declared capability, so an administrator must be set first.
		$this->admin_user();

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the base admin owns the same page under the base
			// settings dashboard menu; the ported class stays unwired.
			( new \WP_MCP_AI_Admin_Orchestration_Dashboard() )->add_menu_page();

			$slugs = isset( $submenu['wp-mcp-ai-dashboard'] ) ? \wp_list_pluck( $submenu['wp-mcp-ai-dashboard'], 2 ) : array();
			$this->assertContains( 'mcp-ai-orchestration', $slugs );
			$this->assertArrayNotHasKey( \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG, $submenu );
		} else {
			// Standalone: the dashboard registers under the NV Platform menu.
			$dashboard = new OrchestrationDashboard();
			$dashboard->register();
			$dashboard->add_menu_page();

			$this->assertArrayHasKey( \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG, $submenu );
			$slugs = \wp_list_pluck( $submenu[ \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG ], 2 );
			$this->assertContains( 'mcp-ai-orchestration', $slugs );
		}
	}

	public function test_register_is_idempotent(): void {
		$dashboard = new OrchestrationDashboard();
		$dashboard->register();
		$dashboard->register();

		$this->assertSame( 1, $this->count_callbacks( 'wp_ajax_wp_mcp_ai_run_orchestration_seeder' ) );
		$this->assertSame( 1, $this->count_callbacks( 'wp_ajax_wp_mcp_ai_get_orchestration_stats' ) );
		$this->assertSame( 1, $this->count_callbacks( 'wp_ajax_wp_mcp_ai_get_recent_workflows' ) );
		$this->assertSame( 1, $this->count_callbacks( 'wp_ajax_wp_mcp_ai_execute_workflow' ) );
		$this->assertSame( 1, $this->count_callbacks( 'wp_ajax_wp_mcp_ai_restart_workflow' ) );
		$this->assertSame( 1, $this->count_callbacks( 'wp_ajax_wp_mcp_ai_refresh_memory_stats' ) );
	}

	// ─── Collaborator seams ──────────────────────────────────────

	public function test_profession_meta_keys_are_byte_identical_strings(): void {
		$keys = OrchestrationDashboardExposer::exposed_profession_meta_keys();

		$this->assertSame( '_wp_mcp_ai_profession_agent_role', $keys['agent_role'] );
		$this->assertSame( '_wp_mcp_ai_profession_task_patterns', $keys['task_patterns'] );
	}

	public function test_seeder_seam_resolves_per_install_mode(): void {
		$seeder = OrchestrationDashboardExposer::exposed_seeder();

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertInstanceOf( \WP_MCP_AI_Profession_Orchestration_Seeder::class, $seeder );
		} else {
			$this->assertInstanceOf( \NvoosContentGraphAiPlatform\Professions\ProfessionOrchestrationSeeder::class, $seeder );
		}
	}

	public function test_tool_entries_seam_resolves_per_install_mode(): void {
		$entries = OrchestrationDashboardExposer::exposed_tool_entries();

		$this->assertIsArray( $entries );
		foreach ( $entries as $entry ) {
			$this->assertIsArray( $entry );
			$this->assertArrayHasKey( 'slug', $entry );
			$this->assertArrayHasKey( 'description', $entry );
			$this->assertIsString( $entry['slug'] );
			$this->assertIsString( $entry['description'] );
		}

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// The base registry carries the full ~1.5k tool inventory.
			$this->assertNotEmpty( $entries );
		}
	}

	public function test_context_manager_seam_resolves_per_install_mode(): void {
		$manager = OrchestrationDashboardExposer::exposed_context_manager();

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertInstanceOf( \WP_MCP_AI_Agent_Context_Manager::class, $manager );
		} else {
			$this->assertNull( $manager );
		}
	}

	public function test_sse_probe_resolves_per_install_mode(): void {
		// Both modes ship an SSE stream for the mcp-ai/v1/jobs endpoint.
		$this->assertTrue( OrchestrationDashboardExposer::exposed_sse_available() );
	}

	public function test_settings_page_url_resolves_per_install_mode(): void {
		$url = OrchestrationDashboardExposer::exposed_settings_page_url( 'orchestration' );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertStringContainsString( 'wp-mcp-ai-dashboard', $url );
			$this->assertStringContainsString( 'tab=orchestration', $url );
		} else {
			$this->assertStringContainsString( \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG, $url );
			$this->assertStringNotContainsString( 'tab=orchestration', $url );
		}
	}

	// ─── Orchestration statistics ────────────────────────────────

	public function test_orchestration_statistics_shape_and_counts(): void {
		$meta_keys = OrchestrationDashboardExposer::exposed_profession_meta_keys();

		// Seed two professions: one planner with patterns, one executor.
		$p1 = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_status' => 'publish',
			)
		);
		$p2 = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_status' => 'publish',
			)
		);
		\update_post_meta( $p1, $meta_keys['agent_role'], 'planner' );
		\update_post_meta( $p1, $meta_keys['task_patterns'], '{"steps":["a","b"]}' );
		\update_post_meta( $p2, $meta_keys['agent_role'], 'executor' );

		$stats = OrchestrationDashboardExposer::exposed_stats();

		$this->assertSame( 2, $stats['total_professions'] );
		$this->assertSame( 2, $stats['seeded_professions'] );
		$this->assertSame( 1, $stats['roles']['planner'] );
		$this->assertSame( 1, $stats['roles']['executor'] );
		$this->assertSame( 0, $stats['roles']['critic'] );
		$this->assertSame( 1, $stats['with_task_patterns'] );
		$this->assertArrayHasKey( 'seeder_version', $stats );
	}

	// ─── Status banner + role chart ──────────────────────────────

	public function test_status_banner_thresholds(): void {
		$base = array(
			'total_professions'  => 100,
			'seeded_professions' => 0,
		);

		$warning = OrchestrationDashboardExposer::exposed_banner( $base );
		$this->assertStringContainsString( 'Seeding Incomplete', $warning );
		$this->assertStringContainsString( 'run-seeder-btn', $warning );

		$info = OrchestrationDashboardExposer::exposed_banner( array_merge( $base, array( 'seeded_professions' => 60 ) ) );
		$this->assertStringContainsString( 'Partially Seeded', $info );
		$this->assertStringContainsString( 'run-seeder-btn', $info );

		$ready = OrchestrationDashboardExposer::exposed_banner( array_merge( $base, array( 'seeded_professions' => 100 ) ) );
		$this->assertStringContainsString( 'System Ready', $ready );
		$this->assertStringContainsString( 'refresh-stats-btn', $ready );
	}

	public function test_role_chart_renders_bars_and_empty_state(): void {
		$with_roles = OrchestrationDashboardExposer::exposed_role_chart(
			array(
				'roles' => array(
					'planner'    => 3,
					'executor'   => 1,
					'critic'     => 0,
					'specialist' => 0,
					'generalist' => 0,
				),
			)
		);
		$this->assertStringContainsString( 'role-bar-row', $with_roles );
		$this->assertStringContainsString( 'Planner', $with_roles );
		$this->assertStringContainsString( '75%', $with_roles );

		$empty = OrchestrationDashboardExposer::exposed_role_chart(
			array(
				'roles' => array(
					'planner'    => 0,
					'executor'   => 0,
					'critic'     => 0,
					'specialist' => 0,
					'generalist' => 0,
				),
			)
		);
		$this->assertStringContainsString( 'No agent roles assigned yet', $empty );
	}

	// ─── System status shape ─────────────────────────────────────

	public function test_system_status_shape_and_sse_endpoint(): void {
		$status = OrchestrationDashboardExposer::exposed_system_status();

		$this->assertArrayHasKey( 'cron', $status );
		$this->assertArrayHasKey( 'async', $status );
		$this->assertArrayHasKey( 'health', $status );
		$this->assertArrayHasKey( 'sse', $status );
		$this->assertTrue( $status['sse']['available'] );
		$this->assertStringContainsString( 'mcp-ai/v1/jobs', $status['sse']['endpoint'] );
	}

	// ─── Recent workflows ────────────────────────────────────────

	public function test_recent_workflows_list_and_cache(): void {
		// Seed a workflow transient with a completed + a pending task.
		\set_transient(
			'wp_mcp_ai_workflow_wf-1',
			array(
				'workflow_id'  => 'wf-1',
				'state'        => 'running',
				'tasks'        => array(
					array(
						'type'   => 'analysis',
						'status' => 'completed',
					),
					array(
						'type'   => 'composition',
						'status' => 'pending',
					),
				),
				'created_at'   => '2026-09-07 10:00:00',
				'updated_at'   => '2026-09-07 10:05:00',
				'started_at'   => '2026-09-07 10:01:00',
				'completed_at' => null,
				'team_id'      => 'team-1',
				'task_type'    => 'generic',
			),
			HOUR_IN_SECONDS
		);

		$workflows = OrchestrationDashboardExposer::exposed_recent_workflows();

		$this->assertCount( 1, $workflows );
		$this->assertSame( 'wf-1', $workflows[0]['workflow_id'] );
		$this->assertSame( 'running', $workflows[0]['state'] );
		$this->assertSame( 2, $workflows[0]['tasks_total'] );
		$this->assertSame( 1, $workflows[0]['tasks_done'] );
		$this->assertSame( 'team-1', $workflows[0]['team_id'] );
		$this->assertSame( 'generic', $workflows[0]['task_type'] );

		// The 5-minute cache transient is written for the next call.
		$this->assertNotFalse( \get_transient( 'wp_mcp_ai_recent_workflows' ) );
	}

	// ─── Agent memory stats ──────────────────────────────────────

	public function test_agent_memory_stats_aggregation(): void {
		// Seed two agent context indexes with type/wing/room/importance/verbatim.
		\set_transient(
			'mcp_ai_ctx_index_agenta',
			array(
				'ctx-1' => array(
					'type'       => 'fact',
					'wing'       => 'ops',
					'room'       => 'r1',
					'importance' => 'high',
					'verbatim'   => true,
				),
				'ctx-2' => array(
					'type'       => 'fact',
					'wing'       => 'ops',
					'room'       => 'r2',
					'importance' => 'medium',
				),
				'ctx-3' => array(
					'type'       => 'preference',
					'wing'       => '',
					'importance' => 'medium',
				),
			),
			HOUR_IN_SECONDS
		);
		\set_transient(
			'mcp_ai_ctx_index_agentb',
			array(
				'ctx-4' => array(
					'type'       => 'preference',
					'wing'       => 'ops',
					'importance' => 'high',
					'verbatim'   => true,
				),
			),
			HOUR_IN_SECONDS
		);

		// Fresh retrieval-path telemetry inside the 7-day window.
		\update_option(
			'wp_mcp_ai_wake_up_telemetry',
			array(
				\gmdate( 'Y-m-d' ) => array(
					'graph'     => 2,
					'transient' => 3,
				),
				\gmdate( 'Y-m-d', \time() - ( 8 * DAY_IN_SECONDS ) ) => array(
					'graph'     => 100,
					'transient' => 50,
				),
			)
		);

		$stats = OrchestrationDashboardExposer::exposed_memory_stats();

		$this->assertSame( 4, $stats['total_contexts'] );
		$this->assertSame( 2, $stats['total_agents'] );
		$this->assertSame( 2, $stats['contexts_by_type']['fact'] );
		$this->assertSame( 2, $stats['contexts_by_type']['preference'] );
		$this->assertSame( 3, $stats['contexts_by_wing']['ops'] );
		$this->assertSame( 1, $stats['contexts_by_wing']['(unscoped)'] );
		$this->assertSame( 2, $stats['contexts_by_importance']['medium'] );
		$this->assertSame( 2, $stats['contexts_by_importance']['high'] );
		$this->assertSame( 1, $stats['wings_count'] );
		$this->assertSame( 2, $stats['rooms_count'] );
		$this->assertSame( 2, $stats['mined_count'] );

		// Retrieval path: only the current-day bucket is inside the window.
		$this->assertSame( 2, $stats['retrieval_path']['graph'] );
		$this->assertSame( 3, $stats['retrieval_path']['transient'] );
		$this->assertSame( 5, $stats['retrieval_path']['total'] );

		// Persistent storage: JetEngine CCT absent in both test matrices.
		$this->assertArrayHasKey( 'available', $stats['persistent_storage'] );
		$this->assertFalse( $stats['persistent_storage']['available'] );
		$this->assertSame( 0, $stats['persistent_storage']['cct_count'] );

		// Bridge active follows the Graphify add-on probe.
		$this->assertFalse( $stats['bridge_active'] );

		\delete_option( 'wp_mcp_ai_wake_up_telemetry' );
		\delete_transient( 'mcp_ai_ctx_index_agenta' );
		\delete_transient( 'mcp_ai_ctx_index_agentb' );
	}

	public function test_tool_count_seam_returns_sane_shapes(): void {
		$counts = OrchestrationDashboardExposer::exposed_tool_counts();

		$this->assertIsInt( $counts['orchestration'] );
		$this->assertGreaterThanOrEqual( 0, $counts['orchestration'] );
		$this->assertIsArray( $counts['agent_names'] );
		foreach ( $counts['agent_names'] as $slug ) {
			$this->assertIsString( $slug );
		}
	}

	public function test_breakdown_table_sorts_and_renders(): void {
		$html = OrchestrationDashboardExposer::exposed_breakdown_table(
			'type',
			'Context Type',
			array(
				'fact'       => 2,
				'preference' => 1,
			),
			3,
			true
		);

		$this->assertStringContainsString( 'memory-breakdown-pane', $html );
		$this->assertStringNotContainsString( 'aria-hidden', $html );
		$this->assertStringContainsString( '66.7%', $html );
		// Largest bucket on top (fact before preference).
		$fact_pos = \strpos( $html, '>Fact<' );
		$pref_pos = \strpos( $html, '>Preference<' );
		$this->assertNotFalse( $fact_pos, 'Fact row missing: ' . $html );
		$this->assertNotFalse( $pref_pos, 'Preference row missing: ' . $html );
		$this->assertLessThan( $pref_pos, $fact_pos );

		$hidden = OrchestrationDashboardExposer::exposed_breakdown_table( 'wing', 'Wing', array(), 0, false );
		$this->assertStringContainsString( 'hidden', $hidden );
		$this->assertStringContainsString( 'aria-hidden="true"', $hidden );
		$this->assertStringContainsString( 'No data available for this grouping.', $hidden );
	}

	public function test_retrieval_path_chart_states(): void {
		$empty = OrchestrationDashboardExposer::exposed_retrieval_chart(
			array(
				'graph'     => 0,
				'transient' => 0,
				'total'     => 0,
			)
		);
		$this->assertStringContainsString( 'No wake_up_context calls recorded', $empty );

		$filled = OrchestrationDashboardExposer::exposed_retrieval_chart(
			array(
				'graph'     => 3,
				'transient' => 1,
				'total'     => 4,
			)
		);
		$this->assertStringContainsString( 'memory-retrieval-path-bar-segment--graph', $filled );
		$this->assertStringContainsString( 'graph: 75.0% (3)', $filled );
		$this->assertStringContainsString( 'transient: 25.0% (1)', $filled );
	}

	// ─── Render output ───────────────────────────────────────────

	public function test_render_dashboard_output_per_install_mode(): void {
		$this->admin_user();
		$output = ( new OrchestrationDashboardExposer() )->exposed_render();

		$this->assertStringContainsString( 'DeepSeek V4 Multi-Agent Orchestration', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-orchestration-dashboard', $output );
		$this->assertStringContainsString( 'orchestration-status-banner', $output );
		$this->assertStringContainsString( 'Agent Memory Usage', $output );
		$this->assertStringContainsString( 'agent-memory-stats-widget', $output );
		$this->assertStringContainsString( 'action-run-seeder', $output );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertStringContainsString( 'wp-mcp-ai-dashboard', $output );
			$this->assertStringContainsString( 'tab=orchestration', $output );
		} else {
			$this->assertStringContainsString( \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG, $output );
			$this->assertStringNotContainsString( 'tab=orchestration', $output );
		}
	}

	public function test_render_dashboard_blocks_non_managers(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );

		$this->expectException( \WPDieException::class );

		( new OrchestrationDashboardExposer() )->exposed_render();
	}

	// ─── AJAX gates ──────────────────────────────────────────────

	public function test_ajax_get_stats_requires_nonce(): void {
		$this->admin_user();

		$output = $this->capture_ajax_die_message(
			static function (): void {
				( new OrchestrationDashboard() )->ajax_get_stats();
			}
		);

		$this->assertSame( '-1', $output );
	}

	public function test_ajax_get_stats_requires_capability(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );
		$this->set_nonce();

		$output = $this->capture_ajax_call(
			static function (): void {
				( new OrchestrationDashboard() )->ajax_get_stats();
			}
		);

		$this->assertStringContainsString( 'Insufficient permissions.', $output );
		$this->clear_nonce();
	}

	public function test_ajax_get_stats_success_payload(): void {
		$this->admin_user();
		$this->set_nonce();

		$output = $this->capture_ajax_call(
			static function (): void {
				( new OrchestrationDashboard() )->ajax_get_stats();
			}
		);

		$payload = \json_decode( $output, true );
		$this->assertIsArray( $payload );
		$this->assertTrue( $payload['success'] );
		$this->assertArrayHasKey( 'total_professions', $payload['data'] );
		$this->assertArrayHasKey( 'system_status', $payload['data'] );
		$this->assertArrayHasKey( 'sse', $payload['data']['system_status'] );

		$this->clear_nonce();
	}

	public function test_ajax_run_seeder_success_path(): void {
		$this->admin_user();
		$this->set_nonce();
		\delete_option( 'wp_mcp_ai_profession_orchestration_version' );

		$output = $this->capture_ajax_call(
			static function (): void {
				( new OrchestrationDashboard() )->ajax_run_seeder();
			}
		);

		$payload = \json_decode( $output, true );
		$this->assertIsArray( $payload );
		$this->assertTrue( $payload['success'] );

		$this->clear_nonce();
		\delete_option( 'wp_mcp_ai_profession_orchestration_version' );
	}

	public function test_ajax_execute_workflow_gates_and_standalone_degradation(): void {
		$this->admin_user();

		// Empty workflow ID is rejected before the coordinator probe.
		$this->set_nonce();
		$output = $this->capture_ajax_call(
			static function (): void {
				( new OrchestrationDashboard() )->ajax_execute_workflow();
			}
		);
		$this->assertStringContainsString( 'Workflow ID is required.', $output );
		$this->clear_nonce();

		// With a workflow ID, the per-mode coordinator gate resolves.
		$this->set_nonce();
		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: the nonce was crafted above; the workflow_id is test input.
		$_POST['workflow_id']    = 'wf-nope';
		$_REQUEST['workflow_id'] = 'wf-nope';
		// phpcs:enable WordPress.Security.NonceVerification

		$output = $this->capture_ajax_call(
			static function (): void {
				( new OrchestrationDashboard() )->ajax_execute_workflow();
			}
		);

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the coordinator exists; execution of a nonexistent
			// workflow resolves through the coordinator (success envelope or
			// error envelope — both are legal, the gate chain is the contract).
			$payload = \json_decode( $output, true );
			$this->assertIsArray( $payload );
		} else {
			// Standalone: the coordinator is not ported — documented degrade.
			$this->assertStringContainsString( 'Workflow coordinator not available.', $output );
		}

		unset( $_POST['workflow_id'], $_REQUEST['workflow_id'] );
		$this->clear_nonce();
	}

	public function test_ajax_restart_workflow_flow(): void {
		$this->admin_user();
		$this->set_nonce();

		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: the nonce was crafted above; the workflow_id is test input.
		$_POST['workflow_id']    = 'wf-r1';
		$_REQUEST['workflow_id'] = 'wf-r1';
		// phpcs:enable WordPress.Security.NonceVerification

		// Unknown workflow → not-found envelope.
		$output = $this->capture_ajax_call(
			static function (): void {
				( new OrchestrationDashboard() )->ajax_restart_workflow();
			}
		);
		$this->assertStringContainsString( 'Workflow not found.', $output );

		// Seed a workflow, then restart it.
		\set_transient(
			'wp_mcp_ai_workflow_wf-r1',
			array(
				'workflow_id'  => 'wf-r1',
				'state'        => 'completed',
				'started_at'   => '2026-09-07 09:00:00',
				'completed_at' => '2026-09-07 09:30:00',
				'updated_at'   => '2026-09-07 09:30:00',
				'tasks'        => array(
					array(
						'type'         => 'analysis',
						'status'       => 'completed',
						'completed_at' => 'x',
						'error'        => 'boom',
					),
					array(
						'type'   => 'composition',
						'status' => 'completed',
					),
				),
			),
			HOUR_IN_SECONDS
		);

		$output = $this->capture_ajax_call(
			static function (): void {
				( new OrchestrationDashboard() )->ajax_restart_workflow();
			}
		);

		$payload = \json_decode( $output, true );
		$this->assertIsArray( $payload );
		$this->assertTrue( $payload['success'] );
		$this->assertSame( 'wf-r1', $payload['data']['workflow_id'] );
		$this->assertSame( 1, $payload['data']['metrics']['tasks_reset'] );
		$this->assertSame( 'completed', $payload['data']['metrics']['original_state'] );
		$this->assertSame( 'initialized', $payload['data']['workflow']['state'] );

		// Non-composition tasks reset to pending without error/completed_at;
		// composition tasks stay untouched.
		$stored = \get_transient( 'wp_mcp_ai_workflow_wf-r1' );
		$this->assertSame( 'pending', $stored['tasks'][0]['status'] );
		$this->assertArrayNotHasKey( 'error', $stored['tasks'][0] );
		$this->assertArrayNotHasKey( 'completed_at', $stored['tasks'][0] );
		$this->assertSame( 'completed', $stored['tasks'][1]['status'] );

		unset( $_POST['workflow_id'], $_REQUEST['workflow_id'] );
		$this->clear_nonce();
		\delete_transient( 'wp_mcp_ai_workflow_wf-r1' );
	}

	public function test_ajax_refresh_memory_stats_payload(): void {
		$this->admin_user();
		$this->set_nonce();

		$output = $this->capture_ajax_call(
			static function (): void {
				( new OrchestrationDashboard() )->ajax_refresh_memory_stats();
			}
		);

		$payload = \json_decode( $output, true );
		$this->assertIsArray( $payload );
		$this->assertTrue( $payload['success'] );
		$this->assertArrayHasKey( 'total_contexts', $payload['data']['stats'] );
		$this->assertArrayHasKey( 'persistent_storage', $payload['data']['stats'] );

		$this->clear_nonce();
	}

	// ─── Assets ─────────────────────────────────────────────────

	public function test_enqueue_assets_resolves_per_install_mode(): void {
		$this->admin_user();
		$dashboard = new OrchestrationDashboard();
		$dashboard->enqueue_assets( 'toplevel_page_' . OrchestrationDashboard::PAGE_SLUG );

		$this->assertTrue( \wp_style_is( 'wp-mcp-ai-admin-monitor-shared', 'registered' ) );
		$this->assertTrue( \wp_style_is( 'wp-mcp-ai-orchestration-dashboard', 'registered' ) );
		$this->assertTrue( \wp_script_is( 'wp-mcp-ai-orchestration-dashboard', 'registered' ) );

		// The localized envelope carries the byte-identical key + nonce action.
		global $wp_scripts;
		$data = $wp_scripts->registered['wp-mcp-ai-orchestration-dashboard']->extra['data'] ?? '';
		$this->assertStringContainsString( 'wpMcpAiOrchestration', $data );
		$this->assertStringContainsString( 'ajaxUrl', $data );
		$this->assertStringContainsString( '"nonce"', $data );
	}

	public function test_enqueue_assets_skips_other_pages(): void {
		$this->admin_user();
		\wp_deregister_style( 'wp-mcp-ai-admin-monitor-shared' );
		\wp_deregister_style( 'wp-mcp-ai-orchestration-dashboard' );

		$dashboard = new OrchestrationDashboard();
		$dashboard->enqueue_assets( 'toplevel_page_something-else' );
		$dashboard->enqueue_assets( 'toplevel_page_mcp-ai-orchestration-pro' );

		$this->assertFalse( \wp_style_is( 'wp-mcp-ai-admin-monitor-shared', 'registered' ) );
		$this->assertFalse( \wp_style_is( 'wp-mcp-ai-orchestration-dashboard', 'registered' ) );
	}
}
