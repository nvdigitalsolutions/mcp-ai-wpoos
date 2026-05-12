<?php
/**
 * Tests for WP_MCP_AI_Pro_CLI_Mcp_Server_Command.
 *
 * WP-CLI commands cannot be run in a real CLI context inside PHPUnit, but we
 * can stub the WP_CLI class and the \WP_CLI\Utils functions and then exercise
 * the command methods directly to verify:
 *   - Contract: class and methods exist.
 *   - list / get / enable / disable / tools logic.
 *   - Error paths (unknown slug, missing slug).
 *   - Enable/disable round-trip via the underlying option.
 *
 * Uses bracketed-namespace syntax so stubs for the \WP_CLI\Utils functions
 * can coexist with global-namespace code in a single file.
 *
 * @package WP_MCP_AI_Pro
 */

// ── \WP_CLI\Utils stub functions ─────────────────────────────────────────────
namespace WP_CLI\Utils {
	if ( ! function_exists( 'WP_CLI\Utils\get_flag_value' ) ) {
		/**
		 * Stub for WP_CLI\Utils\get_flag_value.
		 *
		 * @param array  $assoc_args  Associative args array.
		 * @param string $flag        Key to look up.
		 * @param mixed  $default     Fallback value.
		 * @return mixed
		 */
		function get_flag_value( $assoc_args, $flag, $default = null ) {
			return isset( $assoc_args[ $flag ] ) ? $assoc_args[ $flag ] : $default;
		}
	}
	if ( ! function_exists( 'WP_CLI\Utils\format_items' ) ) {
		/**
		 * Stub for WP_CLI\Utils\format_items — no-op in test context.
		 *
		 * @param string   $format Output format.
		 * @param iterable $items  Row data.
		 * @param array    $fields Column names.
		 */
		function format_items( $format, $items, $fields ) {}
	}
}

// ── Global namespace: stubs + test class ─────────────────────────────────────
namespace {

	// Bootstrap MCP servers.
	require_once dirname( __DIR__ ) . '/includes/mcp-servers/mcp-servers-init.php';

	// Define the WP_CLI constant before loading the command file.
	if ( ! defined( 'WP_CLI' ) ) {
		define( 'WP_CLI', true );
	}

	// Stub the WP_CLI class (if not already present from a previous test run).
	if ( ! class_exists( 'WP_CLI' ) ) {
		/**
		 * Minimal WP_CLI stub that captures output rather than printing to STDOUT
		 * and throws RuntimeException on ::error() so tests can assert fatal paths.
		 */
		class WP_CLI { // phpcs:ignore
			/** @var string */
			public static $last_error = '';
			/** @var string */
			public static $last_success = '';
			/** @var string */
			public static $last_log = '';
			/** @var string */
			public static $last_line = '';

			public static function add_command( $name, $class ) {} // phpcs:ignore
			public static function error( $msg, $exit = true ) { // phpcs:ignore
				self::$last_error = $msg;
				if ( $exit ) {
					throw new RuntimeException( 'WP_CLI::error: ' . $msg ); // phpcs:ignore
				}
			}
			public static function success( $msg ) { self::$last_success = $msg; } // phpcs:ignore
			public static function log( $msg )     { self::$last_log     = $msg; } // phpcs:ignore
			public static function line( $msg )    { self::$last_line    = $msg; } // phpcs:ignore
			public static function confirm( $msg ) {} // phpcs:ignore
		}
	}

	// Load the command class.
	require_once dirname( __DIR__ ) . '/includes/cli/class-wp-mcp-ai-pro-cli-mcp-server-command.php';

	/**
	 * @group toolkit-mcp-servers
	 * @group cli
	 */
	class Test_Pro_CLI_Mcp_Server_Command extends WP_UnitTestCase { // phpcs:ignore

		/**
		 * @var WP_MCP_AI_Pro_CLI_Mcp_Server_Command
		 */
		private $cmd;

		public function setUp(): void {
			parent::setUp();
			WP_MCP_AI_Toolkit_Server_Registry::get_instance()->bootstrap();
			$this->cmd = new WP_MCP_AI_Pro_CLI_Mcp_Server_Command();

			// Reset captures.
			WP_CLI::$last_error   = '';
			WP_CLI::$last_success = '';
			WP_CLI::$last_log     = '';
			WP_CLI::$last_line    = '';
		}

		public function tearDown(): void {
			delete_option( WP_MCP_AI_Toolkit_Server_Base::OPTION_PREFIX . 'crm' );
			parent::tearDown();
		}

		public function test_command_class_exists(): void {
			$this->assertTrue( class_exists( 'WP_MCP_AI_Pro_CLI_Mcp_Server_Command' ) );
		}

		public function test_command_extends_base(): void {
			$this->assertInstanceOf( 'WP_MCP_AI_Pro_CLI_Base_Command', $this->cmd );
		}

		public function test_required_methods_exist(): void {
			foreach ( array( 'list_', 'get', 'enable', 'disable', 'tools' ) as $method ) {
				$this->assertTrue(
					method_exists( $this->cmd, $method ),
					"Method {$method}() must exist."
				);
			}
		}

		public function test_list_ids_contains_crm(): void {
			$this->cmd->list_( array(), array( 'status' => 'all', 'format' => 'ids' ) );
			$this->assertStringContainsString( 'crm', WP_CLI::$last_line );
		}

		public function test_list_enabled_filter_excludes_disabled_server(): void {
			$server           = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'crm' );
			$cfg              = $server->get_configuration();
			$cfg['enabled']   = false;
			$server->update_configuration( $cfg );

			$this->cmd->list_( array(), array( 'status' => 'enabled', 'format' => 'ids' ) );
			$this->assertStringNotContainsString( 'crm', WP_CLI::$last_line );
		}

		public function test_list_disabled_filter_includes_disabled_server(): void {
			$server           = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'crm' );
			$cfg              = $server->get_configuration();
			$cfg['enabled']   = false;
			$server->update_configuration( $cfg );

			$this->cmd->list_( array(), array( 'status' => 'disabled', 'format' => 'ids' ) );
			$this->assertStringContainsString( 'crm', WP_CLI::$last_line );
		}

		public function test_get_json_output_contains_slug(): void {
			$this->cmd->get( array( 'crm' ), array( 'format' => 'json' ) );
			$decoded = json_decode( WP_CLI::$last_line, true );
			$this->assertIsArray( $decoded );
			$this->assertSame( 'crm', $decoded['slug'] );
		}

		public function test_get_unknown_slug_throws(): void {
			$this->expectException( RuntimeException::class );
			$this->cmd->get( array( 'unknown-xyz-abc' ), array() );
		}

		public function test_get_missing_slug_throws(): void {
			$this->expectException( RuntimeException::class );
			$this->cmd->get( array(), array() );
		}

		public function test_enable_disable_round_trip(): void {
			$this->cmd->disable( array( 'crm' ), array( 'yes' => true ) );
			$this->assertStringContainsString( 'disabled', WP_CLI::$last_success );

			$server = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'crm' );
			$this->assertFalse( $server->is_enabled() );

			$this->cmd->enable( array( 'crm' ), array() );
			$this->assertStringContainsString( 'enabled', WP_CLI::$last_success );

			$server = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'crm' );
			$this->assertTrue( $server->is_enabled() );
		}

		public function test_tools_unknown_slug_throws(): void {
			$this->expectException( RuntimeException::class );
			$this->cmd->tools( array( 'totally-unknown-server-xyz' ), array() );
		}

		public function test_tools_ids_for_known_server_does_not_fatal(): void {
			// Tool classes may not be loaded in unit-test env, but no fatal should occur.
			$this->cmd->tools( array( 'crm' ), array( 'format' => 'ids' ) );
			$this->assertTrue( true );
		}
	}

} // namespace
