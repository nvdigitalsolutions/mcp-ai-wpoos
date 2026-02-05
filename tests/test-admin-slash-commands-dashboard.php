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

	/**
	 * Test get_available_workflows returns array
	 */
	public function test_get_available_workflows_returns_array() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( $this->dashboard );
		$method = $reflection->getMethod( 'get_available_workflows' );
		$method->setAccessible( true );

		$workflows = $method->invoke( $this->dashboard );

		$this->assertIsArray( $workflows );
	}

	/**
	 * Test get_available_workflows returns more than 3 workflows
	 */
	public function test_get_available_workflows_returns_all_workflows() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( $this->dashboard );
		$method = $reflection->getMethod( 'get_available_workflows' );
		$method->setAccessible( true );

		$workflows = $method->invoke( $this->dashboard );

		// Should have more than the 3 hardcoded workflows.
		// The orchestrator has 27 workflows defined.
		$this->assertGreaterThan( 3, count( $workflows ), 'Should have more than 3 workflows from orchestrator' );
	}

	/**
	 * Test get_available_workflows returns formatted workflow data
	 */
	public function test_get_available_workflows_returns_formatted_data() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( $this->dashboard );
		$method = $reflection->getMethod( 'get_available_workflows' );
		$method->setAccessible( true );

		$workflows = $method->invoke( $this->dashboard );

		// Should have workflows from orchestrator.
		$this->assertNotEmpty( $workflows );

		// Check structure of first workflow.
		if ( ! empty( $workflows ) ) {
			$workflow = $workflows[0];
			$this->assertArrayHasKey( 'name', $workflow );
			$this->assertArrayHasKey( 'description', $workflow );
			$this->assertArrayHasKey( 'step_count', $workflow );
			$this->assertArrayHasKey( 'type', $workflow );
			$this->assertArrayHasKey( 'slug', $workflow );
		}
	}

	/**
	 * Test get_available_workflows includes orchestrator workflows
	 */
	public function test_get_available_workflows_includes_orchestrator_workflows() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( $this->dashboard );
		$method = $reflection->getMethod( 'get_available_workflows' );
		$method->setAccessible( true );

		$workflows = $method->invoke( $this->dashboard );

		// Find a known workflow from the orchestrator.
		$content_pipeline_found = false;
		foreach ( $workflows as $workflow ) {
			if ( $workflow['slug'] === 'content_pipeline' ) {
				$content_pipeline_found = true;
				$this->assertEquals( 'built-in', $workflow['type'] );
				$this->assertNotEmpty( $workflow['name'] );
				$this->assertNotEmpty( $workflow['description'] );
				$this->assertGreaterThan( 0, $workflow['step_count'] );
				break;
			}
		}

		$this->assertTrue( $content_pipeline_found, 'Content pipeline workflow from orchestrator should be included' );
	}
}
