<?php
/**
 * Tests for the Proposal 050 WP-CLI parity & hardening work (Phase A).
 *
 * Covers:
 *  - WP_MCP_AI_Assistant_Meta_Map (CLI flag => runtime meta key fidelity)
 *  - Structural guarantees on the extracted dispatcher command files
 *    (base-class inheritance + capability gating) because the command
 *    classes themselves are only defined under WP_CLI.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-assistant-meta-map.php';

/**
 * Meta-map unit tests + CLI structural assertions.
 *
 * @since 1.2.0
 */
class Test_WP_CLI_Parity_Hardening extends WP_UnitTestCase {

	/**
	 * Assistant post IDs created during a test.
	 *
	 * @var int[]
	 */
	protected $created_posts = array();

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		foreach ( $this->created_posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->created_posts = array();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// WP_MCP_AI_Assistant_Meta_Map
	// -----------------------------------------------------------------------

	/**
	 * Flag names resolve to the canonical runtime meta keys.
	 */
	public function test_meta_map_resolves_runtime_keys() {
		$this->assertSame( '_wp_mcp_ai_provider', WP_MCP_AI_Assistant_Meta_Map::key( 'provider' ) );
		$this->assertSame( '_wp_mcp_ai_model', WP_MCP_AI_Assistant_Meta_Map::key( 'model' ) );
		$this->assertSame( '_wp_mcp_ai_system_prompt', WP_MCP_AI_Assistant_Meta_Map::key( 'system-prompt' ) );
		$this->assertSame( '_wp_mcp_ai_tools', WP_MCP_AI_Assistant_Meta_Map::key( 'tools' ) );
		$this->assertSame( '', WP_MCP_AI_Assistant_Meta_Map::key( 'nonsense' ) );
	}

	/**
	 * The tools flag sanitizes CSV input into a deduplicated slug array.
	 */
	public function test_meta_map_tools_sanitizes_to_array() {
		$tools = WP_MCP_AI_Assistant_Meta_Map::sanitize( 'tools', ' web_search ,get_post,web_search, ' );
		$this->assertSame( array( 'web_search', 'get_post' ), $tools );
	}

	/**
	 * Scalar flags sanitize through the matching WordPress helpers.
	 */
	public function test_meta_map_scalar_sanitization() {
		$this->assertSame( 'openai', WP_MCP_AI_Assistant_Meta_Map::sanitize( 'provider', ' OpenAI ' ) );
		$this->assertSame( 'gpt-4o', WP_MCP_AI_Assistant_Meta_Map::sanitize( 'model', ' gpt-4o ' ) );
		$this->assertSame( 'Be nice.', WP_MCP_AI_Assistant_Meta_Map::sanitize( 'system-prompt', 'Be nice.' ) );
	}

	/**
	 * get_tools reads the canonical array and ignores legacy string shapes.
	 */
	public function test_meta_map_get_tools_reads_array_only() {
		$id                    = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Tools Map Test',
				'post_status' => 'draft',
			)
		);
		$this->created_posts[] = $id;

		// Legacy string shape (the pre-050 bug) must NOT be returned as tools.
		update_post_meta( $id, '_wp_mcp_ai_tools', 'web_search' );
		$this->assertSame( array(), WP_MCP_AI_Assistant_Meta_Map::get_tools( $id ) );

		update_post_meta( $id, '_wp_mcp_ai_tools', array( 'web_search', 'get_post' ) );
		$this->assertSame( array( 'web_search', 'get_post' ), WP_MCP_AI_Assistant_Meta_Map::get_tools( $id ) );
	}

	// -----------------------------------------------------------------------
	// Structural guarantees on the extracted dispatcher commands (G1 / A1)
	// -----------------------------------------------------------------------

	/**
	 * Read a plugin PHP file for structural assertions.
	 *
	 * @param string $relative_path Path relative to the plugin root.
	 * @return string File contents.
	 */
	private function read_plugin_file( $relative_path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local plugin file for assertion.
		$contents = file_get_contents( WP_MCP_AI_PATH . $relative_path );
		$this->assertNotFalse( $contents, "File should be readable: {$relative_path}" );
		return (string) $contents;
	}

	/**
	 * The legacy dispatcher is a loader shim that requires the extracted files.
	 */
	public function test_dispatcher_requires_extracted_command_files() {
		$shim = $this->read_plugin_file( 'includes/class-wp-mcp-ai-cli-command.php' );

		foreach ( array( 'root', 'plugins', 'queue', 'token', 'rabbitmq', 'stdio', 'health', 'cache', 'version' ) as $noun ) {
			$this->assertStringContainsString( "cli/class-wp-mcp-ai-cli-{$noun}-command.php", $shim, "Dispatcher should require the {$noun} command file" );
		}

		// Regression pin: `mcp-ai content` shipped with a self-registering
		// command file that nothing required, so the command silently never
		// registered under WP-CLI (the tree test only asserts on source).
		$this->assertStringContainsString( 'cli/class-wp-mcp-ai-cli-content-command.php', $shim, 'Dispatcher should require the content command file' );
	}

	/**
	 * Extracted commands extend the base class (which provides capability gating).
	 */
	public function test_extracted_commands_extend_base_class() {
		foreach ( array( 'root', 'plugins', 'queue', 'token', 'rabbitmq', 'stdio', 'health', 'cache', 'version' ) as $noun ) {
			$contents = $this->read_plugin_file( "includes/cli/class-wp-mcp-ai-cli-{$noun}-command.php" );
			$this->assertStringContainsString( 'extends WP_MCP_AI_CLI_Base_Command', $contents, "{$noun} command should extend the base class" );
		}
	}

	/**
	 * Every mutating subcommand in the extracted files gates on manage_options.
	 */
	public function test_extracted_mutating_commands_are_capability_gated() {
		$mutating_files = array(
			'root'     => array( 'cleanup_cct' ),
			'plugins'  => array( 'activate', 'deactivate' ),
			'queue'    => array( 'process', 'clear', 'retry' ),
			'token'    => array( 'migrate_providers' ),
			'rabbitmq' => array( 'setup', 'send_test_message', 'worker' ),
			'stdio'    => array( '__invoke' ),
			'cache'    => array( 'clear' ),
		);

		foreach ( $mutating_files as $noun => $methods ) {
			$contents = $this->read_plugin_file( "includes/cli/class-wp-mcp-ai-cli-{$noun}-command.php" );

			foreach ( $methods as $method ) {
				$signature = 'public function ' . $method . '(';
				$pos       = strpos( $contents, $signature );
				$this->assertNotFalse( $pos, "{$noun}::{$method} should exist" );

				// The gate must appear inside the method body.
				$method_body = substr( $contents, $pos, 2500 );
				$this->assertStringContainsString( "require_capability( 'manage_options' )", $method_body, "{$noun}::{$method} should be capability gated" );
			}
		}
	}

	/**
	 * The fleet-operator credential CLI is capability gated.
	 */
	public function test_fleet_operator_cli_is_capability_gated() {
		$contents = $this->read_plugin_file( 'addons/fleet-operator/includes/class-wp-mcp-ai-operator-cli.php' );
		$this->assertStringContainsString( "current_user_can( 'manage_options' )", $contents );
	}

	/**
	 * The tool command exposes the one-shot `call` verb (G2 / A3).
	 */
	public function test_tool_command_exposes_call_verb() {
		$contents = $this->read_plugin_file( 'includes/cli/class-wp-mcp-ai-cli-tool-command.php' );
		$this->assertStringContainsString( 'public function call( $args, $assoc_args )', $contents );
		$this->assertStringContainsString( 'get_required_capability', $contents, 'tool call should honor per-tool capabilities' );
	}

	// -----------------------------------------------------------------------
	// Toolkit settings-map centralisation (CLI gap closure)
	// -----------------------------------------------------------------------

	/**
	 * The canonical map covers every toolkit key that previously drifted out
	 * of the hardcoded CLI lists (cloudways, dietpi, calendar-booking, …).
	 */
	public function test_toolkit_map_covers_drift_keys() {
		$map = $this->read_plugin_file( 'addons/pro/includes/cli/class-wp-mcp-ai-pro-toolkit-settings-map.php' );

		$drift_keys = array(
			'enable_cloudways_toolkit',
			'enable_dietpi_toolkit',
			'enable_calendar_booking_toolkit',
			'enable_outbound_booking_toolkit',
			'enable_ezuite_toolkit',
			'enable_law_firm_toolkit',
			'enable_cre_debt_toolkit',
			'enable_healthcare_imaging',
			'enable_medical_vitals',
			'enable_extended_cognition_toolkit',
			'enable_vision_analysis_toolkit',
		);

		foreach ( $drift_keys as $key ) {
			$this->assertStringContainsString( "'{$key}'", $map, "Toolkit map should contain {$key}" );
		}
	}

	/**
	 * Both CLI consumers read the shared map instead of hardcoding their own
	 * copies (the drift source for the F&B toggle bug class).
	 */
	public function test_toolkit_and_status_commands_use_shared_map() {
		foreach ( array( 'toolkit', 'status' ) as $noun ) {
			$contents = $this->read_plugin_file( "addons/pro/includes/cli/class-wp-mcp-ai-pro-cli-{$noun}-command.php" );
			$this->assertStringContainsString( 'WP_MCP_AI_Pro_Toolkit_Settings_Map', $contents, "{$noun} command should read the shared toolkit settings map" );
		}
	}

	/**
	 * The Pro loader requires the map and the new subsystem commands, so they
	 * register under WP-CLI (a load-order regression drops them silently).
	 */
	public function test_pro_loader_requires_map_and_new_commands() {
		$loader = $this->read_plugin_file( 'addons/pro/mcp-ai-wpoos-pro.php' );

		foreach (
			array(
				'class-wp-mcp-ai-pro-toolkit-settings-map.php',
				'class-wp-mcp-ai-pro-cli-foundry-command.php',
				'class-wp-mcp-ai-pro-cli-para-command.php',
				'class-wp-mcp-ai-pro-cli-qms-command.php',
				'class-wp-mcp-ai-pro-cli-mcp-app-command.php',
				'class-wp-mcp-ai-pro-cli-fnb-command.php',
				'includes/class-wp-mcp-ai-ezuite-cli.php',
				'includes/class-wp-mcp-ai-flowhub-cli.php',
				'includes/class-wp-mcp-ai-shopify-sync-cli.php',
			) as $file
		) {
			$this->assertStringContainsString( $file, $loader, "Pro CLI loader should require {$file}" );
		}
	}
}
