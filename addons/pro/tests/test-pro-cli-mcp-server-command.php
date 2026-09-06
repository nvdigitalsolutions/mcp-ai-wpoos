<?php
// phpcs:ignore Universal.Files.OneObjectStructurePerFile.MultipleFound
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
// phpcs:disable Universal.Namespaces.DisallowCurlyBraceSyntax,Universal.Namespaces.OneDeclarationPerFile,Universal.Namespaces.DisallowDeclarationWithoutName
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
namespace { // phpcs:ignore Universal.Namespaces.DisallowCurlyBraceSyntax,Universal.Namespaces.OneDeclarationPerFile,Universal.Namespaces.DisallowDeclarationWithoutName

	// Bootstrap MCP servers.
	require_once dirname( __DIR__ ) . '/includes/mcp-servers/mcp-servers-init.php';

	// Define the WP_CLI constant before loading the command file.
	if ( ! defined( 'WP_CLI' ) ) {
		define( 'WP_CLI', true );
	}

	// Stub the WP_CLI class (if not already present from a previous test run).
	if ( ! class_exists( 'WP_CLI' ) ) {
		/**
		 * Minimal WP_CLI stub for unit tests.
		 *
		 * Captures all output in static properties instead of printing to STDOUT.
		 * `::error()` throws a RuntimeException so tests can assert fatal error paths
		 * without terminating the process.
		 */
		class WP_CLI { // phpcs:ignore
			/**
			 * Last error message.
			 *
			 * @var string
			 */
			public static $last_error = '';
			/**
			 * Last success message.
			 *
			 * @var string
			 */
			public static $last_success = '';
			/**
			 * Last log message.
			 *
			 * @var string
			 */
			public static $last_log = '';
			/**
			 * Last line message.
			 *
			 * @var string
			 */
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

	// WP_CLI_Command is the base class used by WP_MCP_AI_CLI_Base_Command.
	// It is not available in the PHPUnit environment, so stub it here.
	if ( ! class_exists( 'WP_CLI_Command' ) ) {
		class WP_CLI_Command {} // phpcs:ignore
	}

	// Load the command class.
	require_once dirname( __DIR__ ) . '/includes/cli/class-wp-mcp-ai-pro-cli-mcp-server-command.php';

	/** Summary.
	 *
	 * @group toolkit-mcp-servers
	 * @group cli
	 */
	class Test_Pro_CLI_Mcp_Server_Command extends WP_UnitTestCase { // phpcs:ignore

		/** Summary.
		 *
		 * @var WP_MCP_AI_Pro_CLI_Mcp_Server_Command
		 */
		private $cmd;

		/** Set up test.
		 */
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

		/** Tear down test.
		 */
		public function tearDown(): void {
			delete_option( WP_MCP_AI_Toolkit_Server_Base::OPTION_PREFIX . 'crm' );
			parent::tearDown();
		}

		/** Test command class exists.
		 */
		public function test_command_class_exists(): void {
			$this->assertTrue( class_exists( 'WP_MCP_AI_Pro_CLI_Mcp_Server_Command' ) );
		}

		/** Test command extends base.
		 */
		public function test_command_extends_base(): void {
			$this->assertInstanceOf( 'WP_MCP_AI_Pro_CLI_Base_Command', $this->cmd );
		}

		/** Test required methods exist.
		 */
		public function test_required_methods_exist(): void {
			// The command declares `list()` (legal as a method name since PHP 7.0)
			// and pins the CLI-facing name with an `@subcommand list` annotation.
			foreach ( array( 'list', 'get', 'enable', 'disable', 'tools' ) as $method ) {
				$this->assertTrue(
					method_exists( $this->cmd, $method ),
					"Method {$method}() must exist."
				);
			}
		}

		/** Test list ids contains crm.
		 */
		public function test_list_ids_contains_crm(): void {
			$this->cmd->list(
				array(),
				array(
					'status' => 'all',
					'format' => 'ids',
				)
			);
			$this->assertStringContainsString( 'crm', WP_CLI::$last_line );
		}

		/** Test list enabled filter excludes disabled server.
		 */
		public function test_list_enabled_filter_excludes_disabled_server(): void {
			$server         = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'crm' );
			$cfg            = $server->get_configuration();
			$cfg['enabled'] = false;
			$server->update_configuration( $cfg );

			$this->cmd->list(
				array(),
				array(
					'status' => 'enabled',
					'format' => 'ids',
				)
			);
			$this->assertStringNotContainsString( 'crm', WP_CLI::$last_line );
		}

		/** Test list disabled filter includes disabled server.
		 */
		public function test_list_disabled_filter_includes_disabled_server(): void {
			$server         = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'crm' );
			$cfg            = $server->get_configuration();
			$cfg['enabled'] = false;
			$server->update_configuration( $cfg );

			$this->cmd->list(
				array(),
				array(
					'status' => 'disabled',
					'format' => 'ids',
				)
			);
			$this->assertStringContainsString( 'crm', WP_CLI::$last_line );
		}

		/** Test get json output contains slug.
		 */
		public function test_get_json_output_contains_slug(): void {
			$this->cmd->get( array( 'crm' ), array( 'format' => 'json' ) );
			$decoded = json_decode( WP_CLI::$last_line, true );
			$this->assertIsArray( $decoded );
			$this->assertSame( 'crm', $decoded['slug'] );
		}

		/** Test get unknown slug throws.
		 */
		public function test_get_unknown_slug_throws(): void {
			$this->expectException( RuntimeException::class );
			$this->cmd->get( array( 'unknown-xyz-abc' ), array() );
		}

		/** Test get missing slug throws.
		 */
		public function test_get_missing_slug_throws(): void {
			$this->expectException( RuntimeException::class );
			$this->cmd->get( array(), array() );
		}

		/** Test enable disable round trip.
		 */
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

		/** Test tools unknown slug throws.
		 */
		public function test_tools_unknown_slug_throws(): void {
			$this->expectException( RuntimeException::class );
			$this->cmd->tools( array( 'totally-unknown-server-xyz' ), array() );
		}

		/** Test tools ids for known server does not fatal.
		 */
		public function test_tools_ids_for_known_server_does_not_fatal(): void {
			// Tool classes may not be loaded in unit-test env, but no fatal should occur.
			$this->cmd->tools( array( 'crm' ), array( 'format' => 'ids' ) );
			$this->assertTrue( true );
		}
	}

}

// phpcs:enable Universal.Namespaces.OneDeclarationPerFile,Universal.Namespaces.DisallowDeclarationWithoutName,Universal.Namespaces.EnforceCurlyBraceSyntax
// namespace.
