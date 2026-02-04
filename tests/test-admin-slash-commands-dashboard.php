<?php
/**
 * Test Admin Slash Commands Dashboard
 *
 * PHPUnit tests for the slash commands admin dashboard page.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test admin slash commands dashboard functionality
 */
class Test_Admin_Slash_Commands_Dashboard extends WP_UnitTestCase {

	/**
	 * Admin dashboard instance
	 *
	 * @var WP_MCP_AI_Admin_Slash_Commands_Dashboard
	 */
	private $dashboard;

	/**
	 * Setup test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Initialize slash commands system.
		if ( ! function_exists( 'wp_mcp_ai_init_slash_commands' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/slash-commands/slash-commands-init.php';
		}
		wp_mcp_ai_init_slash_commands();

		// Load admin dashboard class.
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-slash-commands-dashboard.php';
		$this->dashboard = new WP_MCP_AI_Admin_Slash_Commands_Dashboard();
	}

	/**
	 * Test get_available_commands returns array
	 */
	public function test_get_available_commands_returns_array() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( $this->dashboard );
		$method = $reflection->getMethod( 'get_available_commands' );
		$method->setAccessible( true );

		$commands = $method->invoke( $this->dashboard );

		$this->assertIsArray( $commands );
	}

	/**
	 * Test get_available_commands returns formatted commands
	 */
	public function test_get_available_commands_returns_formatted_data() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( $this->dashboard );
		$method = $reflection->getMethod( 'get_available_commands' );
		$method->setAccessible( true );

		$commands = $method->invoke( $this->dashboard );

		// Should have at least the help command.
		$this->assertNotEmpty( $commands );

		// Check structure of first command.
		if ( ! empty( $commands ) ) {
			$command = $commands[0];
			$this->assertArrayHasKey( 'name', $command );
			$this->assertArrayHasKey( 'description', $command );
			$this->assertArrayHasKey( 'aliases', $command );
			$this->assertArrayHasKey( 'capability', $command );
		}
	}

	/**
	 * Test get_available_commands includes registered commands
	 */
	public function test_get_available_commands_includes_registered_commands() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( $this->dashboard );
		$method = $reflection->getMethod( 'get_available_commands' );
		$method->setAccessible( true );

		$commands = $method->invoke( $this->dashboard );

		// Find help command.
		$help_found = false;
		foreach ( $commands as $command ) {
			if ( $command['name'] === 'help' ) {
				$help_found = true;
				$this->assertIsArray( $command['aliases'] );
				$this->assertNotEmpty( $command['description'] );
				break;
			}
		}

		$this->assertTrue( $help_found, 'Help command should be registered' );
	}

	/**
	 * Test dashboard handles missing handler gracefully
	 */
	public function test_dashboard_handles_missing_handler() {
		// Save the current handler.
		global $wp_mcp_ai_slash_command_handler;
		$saved_handler = $wp_mcp_ai_slash_command_handler;

		// Temporarily unset the handler.
		$wp_mcp_ai_slash_command_handler = null;

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $this->dashboard );
		$method = $reflection->getMethod( 'get_available_commands' );
		$method->setAccessible( true );

		$commands = $method->invoke( $this->dashboard );

		// Should return empty array, not error.
		$this->assertIsArray( $commands );
		$this->assertEmpty( $commands );

		// Restore the handler.
		$wp_mcp_ai_slash_command_handler = $saved_handler;
	}
}
