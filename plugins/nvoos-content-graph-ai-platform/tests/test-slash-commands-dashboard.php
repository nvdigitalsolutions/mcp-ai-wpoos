<?php
/**
 * Slash Commands dashboard ported-class tests (Wave E-UI-1, sub-cluster 3).
 *
 * Verifies the extraction port of the base plugin's
 * `WP_MCP_AI_Admin_Slash_Commands_Dashboard` preserves the public
 * behaviour: the byte-identical constants, page slug, nonce and AJAX
 * action names, the standalone-only menu registration (with the
 * `edit_posts` capability) under the NV Platform menu, the four-tab
 * routing with the `$_GET['tab']` whitelist, the handler-driven
 * command list (formatted shape), the toolkit grouping seam, the
 * orchestrator-driven workflow list (built-in + uploads YAML), the
 * execution history option (sort/limit/entry shape/truncation/cap),
 * the render output, the AJAX nonce/capability gates, the
 * execute-command success + error envelopes, the history
 * get/entry/clear flows, and the per-mode asset enqueues. Runs in
 * both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Admin\Dashboards\SlashCommandsDashboard;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The test-only exposer fixture shares this file with its test case.

/**
 * Test-only exposer: the dashboard's protected statics, privates and
 * render internals are published as public wrappers.
 */
class SlashCommandsDashboardExposer extends SlashCommandsDashboard {

	public static function exposed_toolkit_manager() {
		return self::toolkit_manager();
	}

	public static function exposed_toolkit_name( $slug ): string {
		return self::toolkit_name( $slug );
	}

	public static function exposed_commands(): array {
		$dashboard = new self();
		return $dashboard->get_available_commands();
	}

	public static function exposed_workflows(): array {
		$dashboard = new self();
		return $dashboard->get_available_workflows();
	}

	public static function exposed_history( $limit = 10 ): array {
		$dashboard = new self();
		return $dashboard->get_execution_history( $limit );
	}

	public static function exposed_log_execution( $type, $command, $result ): void {
		$dashboard = new self();
		$dashboard->log_execution( $type, $command, $result );
	}

	public static function exposed_grouped( array $commands ): array {
		$dashboard = new self();
		return $dashboard->group_commands_by_toolkit( $commands );
	}

	public static function exposed_global( array $commands ): array {
		$dashboard = new self();
		return $dashboard->get_global_commands( $commands );
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
 * Slash Commands dashboard characterisation suite (Wave E-UI-1, sub-cluster 3).
 */
#[\PHPUnit\Framework\Attributes\Group( 'dashboards' )]
class Test_Slash_Commands_Dashboard extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		\wp_set_current_user( 0 );
		unset( $_GET['tab'] );

		// Standalone: the global-function shims ship via the plugins_loaded
		// boot (SlashCommandService::register), which has already fired by
		// the time the test bootstrap requires the ecosystem plugin files.
		// Load them up front so every seam resolves the global surface.
		if ( ! \function_exists( 'wp_mcp_ai_init_slash_commands' ) && ! defined( 'WP_MCP_AI_PATH' ) ) {
			require_once NVOOS_CONTENT_GRAPH_AI_PLATFORM_PATH . 'src/SlashCommands/shim-functions.php';
		}

		// Script/style queue leaks + WP 6.9 all_queued_deps memoization:
		// reset through the public API so the memo invalidates.
		global $wp_scripts;
		foreach ( (array) $wp_scripts->queue as $handle ) {
			\wp_dequeue_script( $handle );
		}
		foreach ( (array) \wp_styles()->queue as $handle ) {
			\wp_dequeue_style( $handle );
		}

		\delete_option( 'wp_mcp_ai_slash_command_history' );
	}

	public function tearDown(): void {
		\wp_set_current_user( 0 );
		unset( $_GET['tab'] );
		\delete_option( 'wp_mcp_ai_slash_command_history' );
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
	 * Ensure the slash-command handler global is initialized in-process.
	 *
	 * Monolith declares wp_mcp_ai_init_slash_commands() (base init);
	 * standalone the shim file ships the same function but only loads via
	 * the plugins_loaded boot — which has already fired by the time the
	 * test bootstrap requires the ecosystem plugin files, so require it
	 * directly (mirrors SlashCommandService::register()'s standalone branch).
	 *
	 * @return object Handler instance.
	 */
	private function init_handler() {
		if ( ! \function_exists( 'wp_mcp_ai_init_slash_commands' ) && ! defined( 'WP_MCP_AI_PATH' ) ) {
			require_once NVOOS_CONTENT_GRAPH_AI_PLATFORM_PATH . 'src/SlashCommands/shim-functions.php';
		}

		\wp_mcp_ai_init_slash_commands();
		$handler = \wp_mcp_ai_get_slash_command_handler();
		$this->assertNotNull( $handler );
		return $handler;
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
		$_POST['nonce']    = \wp_create_nonce( SlashCommandsDashboard::NONCE_ACTION );
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

	public function test_constants_are_byte_identical(): void {
		$this->assertSame( 100, SlashCommandsDashboard::MAX_HISTORY_ENTRIES );
		$this->assertSame( 500, SlashCommandsDashboard::HISTORY_OUTPUT_PREVIEW_LENGTH );
		$this->assertSame( 'mcp-ai-slash-commands', SlashCommandsDashboard::PAGE_SLUG );
		$this->assertSame( 'wp_mcp_ai_slash_commands', SlashCommandsDashboard::NONCE_ACTION );
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
			( new \WP_MCP_AI_Admin_Slash_Commands_Dashboard() )->add_menu_page();

			$slugs = isset( $submenu['wp-mcp-ai-dashboard'] ) ? \wp_list_pluck( $submenu['wp-mcp-ai-dashboard'], 2 ) : array();
			$this->assertContains( 'mcp-ai-slash-commands', $slugs );
			$this->assertArrayNotHasKey( \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG, $submenu );
		} else {
			// Standalone: the dashboard registers under the NV Platform menu.
			$dashboard = new SlashCommandsDashboard();
			$dashboard->register();
			$dashboard->add_menu_page();

			$this->assertArrayHasKey( \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG, $submenu );
			$slugs = \wp_list_pluck( $submenu[ \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG ], 2 );
			$this->assertContains( 'mcp-ai-slash-commands', $slugs );
		}
	}

	public function test_register_is_idempotent(): void {
		$dashboard = new SlashCommandsDashboard();
		$dashboard->register();
		$dashboard->register();

		$this->assertSame( 1, $this->count_callbacks( 'wp_ajax_wp_mcp_ai_execute_command' ) );
		$this->assertSame( 1, $this->count_callbacks( 'wp_ajax_wp_mcp_ai_get_command_history' ) );
		$this->assertSame( 1, $this->count_callbacks( 'wp_ajax_wp_mcp_ai_get_history_entry' ) );
		$this->assertSame( 1, $this->count_callbacks( 'wp_ajax_wp_mcp_ai_clear_command_history' ) );
		$this->assertSame( 1, $this->count_callbacks( 'wp_ajax_wp_mcp_ai_execute_slash_workflow' ) );
	}

	// ─── Toolkit grouping seams ──────────────────────────────────

	public function test_toolkit_manager_seam_resolves_per_install_mode(): void {
		$manager = SlashCommandsDashboardExposer::exposed_toolkit_manager();

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertInstanceOf( \WP_MCP_AI_Slash_Command_Toolkit_Manager::class, $manager );
		} else {
			$this->assertInstanceOf( \NvoosContentGraphAiPlatform\SlashCommands\SlashCommandToolkitManager::class, $manager );
		}
	}

	public function test_toolkit_grouping_and_global_split(): void {
		$commands = array(
			array(
				'name'        => 'groupcmd',
				'description' => 'Grouped',
				'aliases'     => array(),
				'capability'  => 'edit_posts',
				'toolkit'     => 'content',
			),
			array(
				'name'        => 'globalcmd',
				'description' => 'Global',
				'aliases'     => array(),
				'capability'  => 'edit_posts',
				'toolkit'     => '',
			),
		);

		$expected_name = SlashCommandsDashboardExposer::exposed_toolkit_name( 'content' );

		$grouped = SlashCommandsDashboardExposer::exposed_grouped( $commands );
		$global  = SlashCommandsDashboardExposer::exposed_global( $commands );

		$this->assertArrayHasKey( $expected_name, $grouped );
		$this->assertSame( 'groupcmd', $grouped[ $expected_name ][0]['name'] );
		$this->assertCount( 1, $global );
		$this->assertSame( 'globalcmd', $global[0]['name'] );
	}

	// ─── Command list ────────────────────────────────────────────

	public function test_available_commands_shape(): void {
		$this->init_handler();

		\wp_mcp_ai_register_slash_command(
			'orctestcmd',
			array(
				'handler'     => static function () {
					return 'ok';
				},
				'description' => 'Orchestration test command',
				'capability'  => 'edit_posts',
				'aliases'     => array( 'otc' ),
				'toolkit'     => 'orctest',
			)
		);

		$commands = SlashCommandsDashboardExposer::exposed_commands();

		$found = null;
		foreach ( $commands as $entry ) {
			if ( 'orctestcmd' === $entry['name'] ) {
				$found = $entry;
				break;
			}
		}

		$this->assertNotNull( $found, 'Registered command missing from the formatted list.' );
		$this->assertSame( 'Orchestration test command', $found['description'] );
		$this->assertSame( 'edit_posts', $found['capability'] );
		$this->assertSame( 'orctest', $found['toolkit'] );
		$this->assertContains( 'otc', $found['aliases'] );
	}

	// ─── Workflow list ───────────────────────────────────────────

	public function test_available_workflows_shape(): void {
		$this->init_handler();

		$workflows = SlashCommandsDashboardExposer::exposed_workflows();

		$this->assertIsArray( $workflows );
		foreach ( $workflows as $workflow ) {
			$this->assertArrayHasKey( 'name', $workflow );
			$this->assertArrayHasKey( 'description', $workflow );
			$this->assertArrayHasKey( 'step_count', $workflow );
			$this->assertArrayHasKey( 'type', $workflow );
			$this->assertArrayHasKey( 'slug', $workflow );
			$this->assertContains( $workflow['type'], array( 'built-in', 'custom' ), true );
		}
	}

	// ─── Execution history ───────────────────────────────────────

	public function test_execution_history_sorts_and_limits(): void {
		\update_option(
			'wp_mcp_ai_slash_command_history',
			array(
				array(
					'id'            => 'a',
					'timestamp_raw' => 100,
					'timestamp'     => 't1',
					'type'          => 'command',
					'command'       => '/a',
					'user'          => 'U',
					'status'        => 'success',
					'output'        => '',
				),
				array(
					'id'            => 'b',
					'timestamp_raw' => 300,
					'timestamp'     => 't3',
					'type'          => 'command',
					'command'       => '/b',
					'user'          => 'U',
					'status'        => 'success',
					'output'        => '',
				),
				array(
					'id'            => 'c',
					'timestamp_raw' => 200,
					'timestamp'     => 't2',
					'type'          => 'command',
					'command'       => '/c',
					'user'          => 'U',
					'status'        => 'success',
					'output'        => '',
				),
			)
		);

		$history = SlashCommandsDashboardExposer::exposed_history( 2 );

		$this->assertCount( 2, $history );
		$this->assertSame( 'b', $history[0]['id'] );
		$this->assertSame( 'c', $history[1]['id'] );
	}

	public function test_log_execution_entry_shape_truncation_and_cap(): void {
		$this->admin_user();

		// Long output → truncated to the 500-char preview.
		SlashCommandsDashboardExposer::exposed_log_execution( 'command', '/test', \str_repeat( 'x', 1200 ) );

		$history = \get_option( 'wp_mcp_ai_slash_command_history', array() );
		$this->assertCount( 1, $history );
		$entry = $history[0];
		$this->assertStringStartsWith( 'exec_', $entry['id'] );
		$this->assertSame( 'command', $entry['type'] );
		$this->assertSame( '/test', $entry['command'] );
		$this->assertSame( 'success', $entry['status'] );
		$this->assertSame( 500, \strlen( $entry['output'] ) );
		$this->assertArrayHasKey( 'timestamp', $entry );
		$this->assertArrayHasKey( 'timestamp_raw', $entry );
		$this->assertArrayHasKey( 'user_id', $entry );

		// Error results store the error message as output.
		SlashCommandsDashboardExposer::exposed_log_execution( 'command', '/boom', new \WP_Error( 'boom_code', 'Boom message' ) );
		$history = \get_option( 'wp_mcp_ai_slash_command_history', array() );
		$this->assertSame( 'error', $history[0]['status'] );
		$this->assertSame( 'Boom message', $history[0]['output'] );

		// Cap: entries beyond MAX_HISTORY_ENTRIES are dropped.
		$overage = array();
		for ( $i = 0; $i < SlashCommandsDashboard::MAX_HISTORY_ENTRIES + 5; $i++ ) {
			$overage[] = array(
				'id'            => 'bulk_' . $i,
				'timestamp'     => 't',
				'timestamp_raw' => \time() + $i,
				'type'          => 'command',
				'command'       => '/bulk',
				'user'          => 'U',
				'user_id'       => 1,
				'status'        => 'success',
				'output'        => '',
			);
		}
		\update_option( 'wp_mcp_ai_slash_command_history', $overage );
		SlashCommandsDashboardExposer::exposed_log_execution( 'command', '/one-more', 'ok' );

		$history = \get_option( 'wp_mcp_ai_slash_command_history', array() );
		$this->assertCount( SlashCommandsDashboard::MAX_HISTORY_ENTRIES, $history );
		$this->assertSame( '/one-more', $history[0]['command'] );
	}

	// ─── Render output + tab routing ─────────────────────────────

	public function test_render_tab_routing(): void {
		$this->admin_user();
		$this->init_handler();

		// Default tab → commands.
		$output = ( new SlashCommandsDashboardExposer() )->exposed_render();
		$this->assertStringContainsString( 'Available Commands', $output );
		$this->assertStringContainsString( 'wp-mcp-ai-slash-commands-dashboard', $output );

		$_GET['tab'] = 'workflows';
		$output      = ( new SlashCommandsDashboardExposer() )->exposed_render();
		$this->assertStringContainsString( 'Available Workflows', $output );

		$_GET['tab'] = 'history';
		$output      = ( new SlashCommandsDashboardExposer() )->exposed_render();
		$this->assertStringContainsString( 'Recent command and workflow executions:', $output );

		$_GET['tab'] = 'test';
		$output      = ( new SlashCommandsDashboardExposer() )->exposed_render();
		$this->assertStringContainsString( 'Enter Command:', $output );

		// Invalid tab falls back to commands (the nav-tab labels render on
		// every tab, so assert on tab-body-only markers).
		$_GET['tab'] = 'nope';
		$output      = ( new SlashCommandsDashboardExposer() )->exposed_render();
		$this->assertStringContainsString( 'Available Commands', $output );
		$this->assertStringNotContainsString( 'Enter Command:', $output );
	}

	public function test_render_dashboard_blocks_non_contributors(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );

		$this->expectException( \WPDieException::class );

		( new SlashCommandsDashboardExposer() )->exposed_render();
	}

	// ─── AJAX gates ──────────────────────────────────────────────

	public function test_ajax_execute_command_requires_nonce(): void {
		$this->admin_user();

		$output = $this->capture_ajax_die_message(
			static function (): void {
				( new SlashCommandsDashboard() )->ajax_execute_command();
			}
		);

		$this->assertSame( '-1', $output );
	}

	public function test_ajax_execute_command_requires_capability(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );
		$this->set_nonce();

		$output = $this->capture_ajax_call(
			static function (): void {
				( new SlashCommandsDashboard() )->ajax_execute_command();
			}
		);

		$this->assertStringContainsString( 'Insufficient permissions.', $output );
		$this->clear_nonce();
	}

	public function test_ajax_execute_command_empty_command(): void {
		$this->admin_user();
		$this->set_nonce();

		$output = $this->capture_ajax_call(
			static function (): void {
				( new SlashCommandsDashboard() )->ajax_execute_command();
			}
		);

		$this->assertStringContainsString( 'No command provided.', $output );
		$this->clear_nonce();
	}

	public function test_ajax_execute_command_success_and_error_envelopes(): void {
		$this->admin_user();
		$this->init_handler();

		\wp_mcp_ai_register_slash_command(
			'orchok',
			array(
				'handler'     => static function () {
					return 'command output ok';
				},
				'description' => 'ok command',
				'capability'  => 'edit_posts',
			)
		);
		\wp_mcp_ai_register_slash_command(
			'orchfail',
			array(
				'handler'     => static function () {
					return new \WP_Error( 'orch_fail_code', 'Command failed hard.' );
				},
				'description' => 'fail command',
				'capability'  => 'edit_posts',
			)
		);

		// Success envelope.
		$this->set_nonce();
		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: the nonce was crafted above; the command is test input.
		$_POST['command']    = '/orchok';
		$_REQUEST['command'] = '/orchok';
		// phpcs:enable WordPress.Security.NonceVerification

		$output = $this->capture_ajax_call(
			static function (): void {
				( new SlashCommandsDashboard() )->ajax_execute_command();
			}
		);

		$payload = \json_decode( $output, true );
		$this->assertIsArray( $payload );
		$this->assertTrue( $payload['success'] );
		$this->assertSame( 'command output ok', $payload['data']['output'] );

		// Error envelope.
		$_POST['command']    = '/orchfail';
		$_REQUEST['command'] = '/orchfail';

		$output = $this->capture_ajax_call(
			static function (): void {
				( new SlashCommandsDashboard() )->ajax_execute_command();
			}
		);

		$payload = \json_decode( $output, true );
		$this->assertIsArray( $payload );
		$this->assertFalse( $payload['success'] );
		$this->assertSame( 'Command failed hard.', $payload['data']['message'] );
		$this->assertSame( '', $payload['data']['output'] );

		// Both executions were logged to the history option.
		$history = \get_option( 'wp_mcp_ai_slash_command_history', array() );
		$this->assertCount( 2, $history );
		$this->assertSame( 'success', $history[1]['status'] );
		$this->assertSame( 'error', $history[0]['status'] );

		unset( $_POST['command'], $_REQUEST['command'] );
		$this->clear_nonce();
	}

	public function test_ajax_get_history_and_entry(): void {
		$this->admin_user();

		\update_option(
			'wp_mcp_ai_slash_command_history',
			array(
				array(
					'id'            => 'exec_known',
					'timestamp'     => '2026-09-07 10:00:00',
					'timestamp_raw' => 100,
					'type'          => 'command',
					'command'       => '/known',
					'user'          => 'Admin',
					'user_id'       => 1,
					'status'        => 'success',
					'output'        => 'out',
				),
			)
		);

		// History list payload.
		$this->set_nonce();
		$output  = $this->capture_ajax_call(
			static function (): void {
				( new SlashCommandsDashboard() )->ajax_get_history();
			}
		);
		$payload = \json_decode( $output, true );
		$this->assertTrue( $payload['success'] );
		$this->assertSame( 'exec_known', $payload['data']['history'][0]['id'] );

		// Single entry payload.
		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: the nonce was crafted above; the entry id is test input.
		$_POST['entry_id']    = 'exec_known';
		$_REQUEST['entry_id'] = 'exec_known';
		// phpcs:enable WordPress.Security.NonceVerification
		$output  = $this->capture_ajax_call(
			static function (): void {
				( new SlashCommandsDashboard() )->ajax_get_history_entry();
			}
		);
		$payload = \json_decode( $output, true );
		$this->assertTrue( $payload['success'] );
		$this->assertSame( '/known', $payload['data']['entry']['command'] );

		// Unknown entry → not-found envelope.
		$_POST['entry_id']    = 'exec_unknown';
		$_REQUEST['entry_id'] = 'exec_unknown';
		$output               = $this->capture_ajax_call(
			static function (): void {
				( new SlashCommandsDashboard() )->ajax_get_history_entry();
			}
		);
		$payload              = \json_decode( $output, true );
		$this->assertFalse( $payload['success'] );
		$this->assertSame( 'History entry not found.', $payload['data']['message'] );

		unset( $_POST['entry_id'], $_REQUEST['entry_id'] );
		$this->clear_nonce();
	}

	public function test_ajax_clear_history_gates_and_clears(): void {
		\update_option(
			'wp_mcp_ai_slash_command_history',
			array(
				array(
					'id'            => 'exec_1',
					'timestamp'     => 't',
					'timestamp_raw' => 1,
					'type'          => 'command',
					'command'       => '/x',
					'user'          => 'U',
					'user_id'       => 1,
					'status'        => 'success',
					'output'        => '',
				),
			)
		);

		// Subscriber lacks manage_options → denied, history intact.
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );
		$this->set_nonce();
		$output = $this->capture_ajax_call(
			static function (): void {
				( new SlashCommandsDashboard() )->ajax_clear_history();
			}
		);
		$this->assertStringContainsString( 'Insufficient permissions.', $output );
		$this->assertNotFalse( \get_option( 'wp_mcp_ai_slash_command_history' ) );
		$this->clear_nonce();

		// Admin clears — craft a fresh nonce for the admin user (nonces are
		// user-bound, so the subscriber's nonce cannot cross over).
		$this->admin_user();
		$this->set_nonce();
		$output  = $this->capture_ajax_call(
			static function (): void {
				( new SlashCommandsDashboard() )->ajax_clear_history();
			}
		);
		$payload = \json_decode( $output, true );
		$this->assertTrue( $payload['success'] );
		$this->assertFalse( \get_option( 'wp_mcp_ai_slash_command_history' ) );

		$this->clear_nonce();
	}

	public function test_ajax_execute_workflow_gates(): void {
		$this->admin_user();

		// Nonce gate.
		$output = $this->capture_ajax_die_message(
			static function (): void {
				( new SlashCommandsDashboard() )->ajax_execute_workflow();
			}
		);
		$this->assertSame( '-1', $output );

		// Empty workflow gate.
		$this->set_nonce();
		$output = $this->capture_ajax_call(
			static function (): void {
				( new SlashCommandsDashboard() )->ajax_execute_workflow();
			}
		);
		$this->assertStringContainsString( 'No workflow provided.', $output );

		// With a workflow slug, the handler-driven '/workflow X' execution
		// resolves (success or error envelope — the gate chain is the
		// contract) and an execution history entry is always written.
		$this->init_handler();
		// phpcs:disable WordPress.Security.NonceVerification -- Test fixture: the nonce was crafted above; the workflow slug is test input.
		$_POST['workflow']    = 'orchtest-flow';
		$_REQUEST['workflow'] = 'orchtest-flow';
		// phpcs:enable WordPress.Security.NonceVerification

		$output = $this->capture_ajax_call(
			static function (): void {
				( new SlashCommandsDashboard() )->ajax_execute_workflow();
			}
		);

		$payload = \json_decode( $output, true );
		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'success', $payload );

		$history = \get_option( 'wp_mcp_ai_slash_command_history', array() );
		$this->assertNotEmpty( $history );
		$this->assertSame( 'workflow', $history[0]['type'] );
		$this->assertSame( 'orchtest-flow', $history[0]['command'] );

		unset( $_POST['workflow'], $_REQUEST['workflow'] );
		$this->clear_nonce();
	}

	// ─── Assets ─────────────────────────────────────────────────

	public function test_enqueue_assets_resolves_per_install_mode(): void {
		$this->admin_user();
		$dashboard = new SlashCommandsDashboard();
		$dashboard->enqueue_assets( 'toplevel_page_' . SlashCommandsDashboard::PAGE_SLUG );

		$this->assertTrue( \wp_style_is( 'wp-mcp-ai-slash-commands-dashboard', 'registered' ) );
		$this->assertTrue( \wp_script_is( 'wp-mcp-ai-slash-commands-dashboard', 'registered' ) );

		// The localized envelope carries the byte-identical key.
		global $wp_scripts;
		$data = $wp_scripts->registered['wp-mcp-ai-slash-commands-dashboard']->extra['data'] ?? '';
		$this->assertStringContainsString( 'wpMcpAiSlashCommands', $data );
		$this->assertStringContainsString( 'ajaxUrl', $data );
		$this->assertStringContainsString( '"nonce"', $data );
	}

	public function test_enqueue_assets_skips_other_pages(): void {
		$this->admin_user();
		\wp_deregister_style( 'wp-mcp-ai-slash-commands-dashboard' );

		$dashboard = new SlashCommandsDashboard();
		$dashboard->enqueue_assets( 'toplevel_page_something-else' );

		$this->assertFalse( \wp_style_is( 'wp-mcp-ai-slash-commands-dashboard', 'registered' ) );
	}
}
