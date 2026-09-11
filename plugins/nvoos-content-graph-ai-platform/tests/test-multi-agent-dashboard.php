<?php
/**
 * Multi-Agent dashboard ported-class tests (Wave E-UI-1, sub-cluster 1).
 *
 * Verifies the extraction port of the base plugin's
 * `WP_MCP_AI_Admin_Multi_Agent_Dashboard` preserves the public
 * behaviour: the byte-identical page slug / nonce / AJAX action names,
 * the standalone-only menu registration under the NV Platform menu,
 * the per-mode collaborator seams (default-assistant seeder, meta-key
 * map, JetEngine probe, reinstall), the workflow-pattern
 * classification, the statistics shape, the render output (including
 * the monolith-only test-chat modal), the AJAX nonce/capability gates,
 * and the per-mode asset enqueues. Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Admin\Dashboards\MultiAgentDashboard;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The test-only exposer fixture shares this file with its test case.

/**
 * Test-only exposer: the dashboard's protected statics and render
 * internals are published as public wrappers.
 */
class MultiAgentDashboardExposer extends MultiAgentDashboard {

	public static function exposed_installed(): bool {
		return self::default_assistants_installed();
	}

	public static function exposed_reinstall() {
		return self::reinstall_default_assistants();
	}

	public static function exposed_meta_keys(): array {
		return self::assistant_meta_keys();
	}

	public static function exposed_jetengine(): bool {
		return self::jetengine_available();
	}

	public static function exposed_stats(): array {
		$dashboard = new self();
		return $dashboard->get_agent_statistics();
	}

	public static function exposed_pattern( $slug, $roles ): string {
		$dashboard = new self();
		return $dashboard->detect_workflow_pattern( $slug, $roles );
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
 * Dashboard-port characterisation suite (Wave E-UI-1, sub-cluster 1).
 */
#[\PHPUnit\Framework\Attributes\Group( 'dashboards' )]
class Test_Multi_Agent_Dashboard extends \WP_UnitTestCase {

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
	}

	public function tearDown(): void {
		\wp_set_current_user( 0 );
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
	 * Register the dashboard and invoke its menu registration directly
	 * (firing admin_menu globally would run every base admin callback).
	 *
	 * @return void
	 */
	private function register_and_fire_menu(): void {
		$dashboard = new MultiAgentDashboard();
		$dashboard->register();
		$dashboard->add_menu_page();
	}

	// ─── Public surface constants ────────────────────────────────

	public function test_page_slug_and_nonce_are_byte_identical(): void {
		$this->assertSame( 'mcp-ai-multi-agent', MultiAgentDashboard::PAGE_SLUG );
		$this->assertSame( 'wp_mcp_ai_multi_agent', MultiAgentDashboard::NONCE_ACTION );
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
			( new \WP_MCP_AI_Admin_Multi_Agent_Dashboard() )->add_menu_page();

			$slugs = isset( $submenu['wp-mcp-ai-dashboard'] ) ? \wp_list_pluck( $submenu['wp-mcp-ai-dashboard'], 2 ) : array();
			$this->assertContains( 'mcp-ai-multi-agent', $slugs );
			$this->assertArrayNotHasKey( \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG, $submenu );
		} else {
			// Standalone: the dashboard registers under the NV Platform menu.
			$this->register_and_fire_menu();

			$this->assertArrayHasKey( \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG, $submenu );
			$slugs = \wp_list_pluck( $submenu[ \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG ], 2 );
			$this->assertContains( 'mcp-ai-multi-agent', $slugs );
		}
	}

	public function test_register_is_idempotent(): void {
		$dashboard = new MultiAgentDashboard();
		$dashboard->register();
		$dashboard->register();

		$this->assertSame( 1, $this->count_callbacks( 'wp_ajax_wp_mcp_ai_get_multi_agent_stats' ) );
		$this->assertSame( 1, $this->count_callbacks( 'wp_ajax_wp_mcp_ai_reinstall_agents' ) );
	}

	// ─── Per-mode collaborator seams ─────────────────────────────

	public function test_installed_seam_resolves_per_install_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame(
				\WP_MCP_AI_Default_Assistants::is_installed(),
				MultiAgentDashboardExposer::exposed_installed()
			);
		} else {
			$this->assertFalse( MultiAgentDashboardExposer::exposed_installed() );
		}
	}

	public function test_meta_keys_are_byte_identical_strings_in_both_modes(): void {
		$keys = MultiAgentDashboardExposer::exposed_meta_keys();

		$this->assertSame(
			array(
				'provider'      => '_wp_mcp_ai_provider',
				'model'         => '_wp_mcp_ai_model',
				'temperature'   => '_wp_mcp_ai_temperature',
				'tools'         => '_wp_mcp_ai_tools',
				'primary_roles' => '_wp_mcp_ai_primary_roles',
			),
			$keys
		);
	}

	public function test_jetengine_seam(): void {
		$expected = \function_exists( 'wp_mcp_ai_is_jetengine_available' )
			? \wp_mcp_ai_is_jetengine_available()
			: false;

		$this->assertSame( $expected, MultiAgentDashboardExposer::exposed_jetengine() );
	}

	public function test_reinstall_seam_standalone_degrades(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the base seeder owns the reinstall (mutates state —
			// not exercised here; the seam defers to the base class).
			$this->addToAssertionCount( 1 );
			return;
		}

		$result = MultiAgentDashboardExposer::exposed_reinstall();
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_default_assistants_unavailable', $result->get_error_code() );
	}

	// ─── Statistics + classification ────────────────────────────

	public function test_statistics_shape(): void {
		$stats = MultiAgentDashboardExposer::exposed_stats();

		$this->assertArrayHasKey( 'installed', $stats );
		$this->assertArrayHasKey( 'installation', $stats );
		$this->assertArrayHasKey( 'agents', $stats );
		$this->assertArrayHasKey( 'total_agents', $stats );
		$this->assertArrayHasKey( 'active_agents', $stats );
		$this->assertArrayHasKey( 'total_tools', $stats );
		$this->assertArrayHasKey( 'is_pro_active', $stats );
		$this->assertArrayHasKey( 'patterns', $stats );

		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			// Standalone: absent-seeder degradation — no agents.
			$this->assertFalse( $stats['installed'] );
			$this->assertSame( 0, $stats['total_agents'] );
			$this->assertSame( array(), $stats['agents'] );
		}
	}

	public function test_workflow_pattern_classification(): void {
		$this->assertSame( 'loop', MultiAgentDashboardExposer::exposed_pattern( 'architect-agent', array() ) );
		$this->assertSame( 'loop', MultiAgentDashboardExposer::exposed_pattern( 'some-agent', array( 'architect' ) ) );

		$sequential = array(
			'orchestrator-supervisor',
			'research-operative',
			'unstructured-parser',
			'content-drafter',
			'seo-compliance-auditor',
			'publisher-terminal',
		);
		foreach ( $sequential as $slug ) {
			$this->assertSame( 'sequential', MultiAgentDashboardExposer::exposed_pattern( $slug, array() ) );
		}

		// Unknown agents default to sequential.
		$this->assertSame( 'sequential', MultiAgentDashboardExposer::exposed_pattern( 'mystery-agent', array() ) );
	}

	// ─── Render ─────────────────────────────────────────────────

	public function test_render_dashboard_output_per_install_mode(): void {
		$this->admin_user();
		$output = ( new MultiAgentDashboardExposer() )->exposed_render();

		$this->assertStringContainsString( 'Multi-Agent Orchestration System', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-multi-agent-dashboard', $output );
		$this->assertStringContainsString( 'reinstall-agents-btn', $output );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the embedded test-chat modal renders.
			$this->assertStringContainsString( 'wp-mcp-ai-test-modal', $output );
		} else {
			// Standalone: no modal (chat bundle is monolith-only) and the
			// absent-seeder "Not Installed" banner renders.
			$this->assertStringNotContainsString( 'wp-mcp-ai-test-modal', $output );
			$this->assertStringContainsString( 'Not Installed', $output );
		}
	}

	public function test_render_dashboard_blocks_non_managers(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );

		$this->expectException( \WPDieException::class );

		( new MultiAgentDashboardExposer() )->exposed_render();
	}

	// ─── AJAX gates ─────────────────────────────────────────────

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

	public function test_ajax_get_stats_requires_nonce(): void {
		$this->admin_user();

		$output = $this->capture_ajax_die_message(
			static function (): void {
				( new MultiAgentDashboard() )->ajax_get_stats();
			}
		);

		$this->assertSame( '-1', $output );
	}

	public function test_ajax_get_stats_requires_capability(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );
		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: craft a valid nonce to isolate the capability gate.
		$_POST['nonce']     = \wp_create_nonce( MultiAgentDashboard::NONCE_ACTION );
		$_REQUEST['nonce'] = $_POST['nonce'];
		// phpcs:enable WordPress.Security.NonceVerification

		$output = $this->capture_ajax_call(
			static function (): void {
				( new MultiAgentDashboard() )->ajax_get_stats();
			}
		);

		$this->assertStringContainsString( 'Insufficient permissions.', $output );

		unset( $_POST['nonce'], $_REQUEST['nonce'] );
	}

	public function test_ajax_get_stats_success_payload(): void {
		$this->admin_user();
		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: craft a valid nonce for the success path.
		$_POST['nonce']     = \wp_create_nonce( MultiAgentDashboard::NONCE_ACTION );
		$_REQUEST['nonce'] = $_POST['nonce'];
		// phpcs:enable WordPress.Security.NonceVerification

		$output = $this->capture_ajax_call(
			static function (): void {
				( new MultiAgentDashboard() )->ajax_get_stats();
			}
		);

		$payload = \json_decode( $output, true );
		$this->assertIsArray( $payload );
		$this->assertTrue( $payload['success'] );
		$this->assertArrayHasKey( 'installed', $payload['data'] );
		$this->assertArrayHasKey( 'agents', $payload['data'] );

		unset( $_POST['nonce'], $_REQUEST['nonce'] );
	}

	// ─── Assets ─────────────────────────────────────────────────

	public function test_enqueue_assets_resolves_per_install_mode(): void {
		$this->admin_user();
		$dashboard = new MultiAgentDashboard();
		$dashboard->enqueue_assets( 'toplevel_page_' . MultiAgentDashboard::PAGE_SLUG );

		$this->assertTrue( \wp_style_is( 'wp-mcp-ai-multi-agent-dashboard', 'registered' ) );
		$this->assertTrue( \wp_style_is( 'wp-mcp-ai-admin-monitor-shared', 'registered' ) );
		$this->assertTrue( \wp_script_is( 'wp-mcp-ai-multi-agent-dashboard', 'registered' ) );

		// Localized envelope.
		global $wp_scripts;
		$data = $wp_scripts->registered['wp-mcp-ai-multi-agent-dashboard']->extra['data'] ?? '';
		$this->assertStringContainsString( 'wpMcpAiMultiAgent', $data );
		$this->assertStringContainsString( 'confirmReinstall', $data );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertTrue( \wp_script_is( 'wp-mcp-ai-chat', 'registered' ) );
			$this->assertTrue( \wp_script_is( 'wp-mcp-ai-admin-test-assistant', 'registered' ) );
		} else {
			$this->assertFalse( \wp_script_is( 'wp-mcp-ai-chat', 'registered' ) );
			$this->assertFalse( \wp_script_is( 'wp-mcp-ai-admin-test-assistant', 'registered' ) );
		}
	}

	public function test_enqueue_assets_skips_other_pages(): void {
		// Deregister any styles left behind by earlier tests in this process.
		\wp_deregister_style( 'wp-mcp-ai-multi-agent-dashboard' );
		\wp_deregister_style( 'wp-mcp-ai-admin-monitor-shared' );

		$dashboard = new MultiAgentDashboard();
		$dashboard->enqueue_assets( 'toplevel_page_other-page' );

		$this->assertFalse( \wp_style_is( 'wp-mcp-ai-multi-agent-dashboard', 'registered' ) );
	}

	/**
	 * Count callbacks on a hook tag.
	 *
	 * @param string $tag Hook tag.
	 * @return int
	 */
	private function count_callbacks( string $tag ): int {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $tag ] ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $wp_filter[ $tag ]->callbacks as $priority_callbacks ) {
			$count += \count( $priority_callbacks );
		}
		return $count;
	}
}
