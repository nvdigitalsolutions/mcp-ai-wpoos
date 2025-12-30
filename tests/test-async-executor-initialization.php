<?php
/**
 * Tests for async executor initialization and cron hook registration.
 *
 * Verifies that the WP_MCP_AI_Tool_Async_Executor properly registers
 * its cron hook handler during initialization, allowing async tools to execute.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-registry.php';

/**
 * Test async executor initialization.
 */
class WP_MCP_AI_Async_Executor_Initialization_Test extends WP_UnitTestCase {

	/**
	 * Test that executor init registers the cron hook handler.
	 */
	public function test_executor_init_registers_cron_hook() {
		global $wp_filter;

		// Create and initialize executor.
		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$executor->init();

		// Verify the hook is registered.
		$hook_name = WP_MCP_AI_Tool_Async_Executor::CRON_HOOK;
		$this->assertTrue(
			isset( $wp_filter[ $hook_name ] ),
			'Cron hook wp_mcp_ai_async_tool_execution should be registered'
		);

		// Verify executor method is in the hook.
		$has_callback = false;
		if ( isset( $wp_filter[ $hook_name ] ) ) {
			foreach ( $wp_filter[ $hook_name ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) &&
						$callback['function'][0] instanceof WP_MCP_AI_Tool_Async_Executor &&
						'execute_async_tool' === $callback['function'][1] ) {
						$has_callback = true;
						break 2;
					}
				}
			}
		}

		$this->assertTrue(
			$has_callback,
			'Executor execute_async_tool method should be registered as callback'
		);
	}

	/**
	 * Test that cron job actually triggers tool execution.
	 *
	 * This is the core fix verification - ensures tools execute when cron runs.
	 */
	public function test_cron_job_executes_tool() {
		// Clear any existing cron jobs and results.
		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );

		// Create a mock tool for testing.
		$mock_tool = $this->create_mock_tool();

		// Register the mock tool.
		$registry = new WP_MCP_AI_Tool_Registry();
		add_filter(
			'wp_mcp_ai_register_tools',
			function ( $tools ) use ( $mock_tool ) {
				$tools[] = $mock_tool;
				return $tools;
			}
		);
		$registry->init();

		// Create and initialize executor.
		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$executor->init();

		// Queue the mock tool.
		$job_id = $executor->queue_tool(
			'test_mock_tool',
			array( 'test_param' => 'test_value' ),
			array( 'user_id' => 1 )
		);

		$this->assertIsString( $job_id );

		// Verify job is pending.
		$result = $executor->get_result( $job_id );
		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $result['status'] );

		// Manually trigger the cron job (simulates WP-Cron running).
		do_action( WP_MCP_AI_Tool_Async_Executor::CRON_HOOK, $job_id );

		// Verify tool executed.
		$result = $executor->get_result( $job_id );
		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['status'], 'Tool should execute and complete' );
		$this->assertArrayHasKey( 'result', $result );

		// Verify the tool actually ran (check the mock tool's output).
		if ( isset( $result['result']['data'] ) ) {
			$tool_result = $result['result']['data'];
			$this->assertSame( 'test_mock_tool executed successfully', $tool_result['message'] );
			$this->assertSame( 'test_value', $tool_result['received_param'] );
		}
	}

	/**
	 * Test that executor is initialized during plugin bootstrap.
	 */
	public function test_executor_initialized_during_bootstrap() {
		global $wp_filter;

		// Simulate the bootstrap process.
		do_action( 'wp_mcp_ai_bootstrapped' );

		// The executor should be initialized by the hook.
		$hook_name = WP_MCP_AI_Tool_Async_Executor::CRON_HOOK;

		$this->assertTrue(
			isset( $wp_filter[ $hook_name ] ),
			'Async executor should be initialized during plugin bootstrap'
		);
	}

	/**
	 * Create a mock tool for testing.
	 *
	 * @return object Mock tool instance.
	 */
	private function create_mock_tool() {
		return new class() implements WP_MCP_AI_Tool_Interface {
			/**
			 * Get the tool slug.
			 *
			 * @return string Tool slug.
			 */
			public function get_slug() {
				return 'test_mock_tool';
			}

			/**
			 * Get the tool name.
			 *
			 * @return string Tool name.
			 */
			public function get_name() {
				return 'Test Mock Tool';
			}

			/**
			 * Get the tool description.
			 *
			 * @return string Tool description.
			 */
			public function get_description() {
				return 'A mock tool for testing async execution';
			}

			/**
			 * Get the parameters schema.
			 *
			 * @return array Parameters schema.
			 */
			public function get_parameters_schema() {
				return array(
					'type'       => 'object',
					'properties' => array(
						'test_param' => array(
							'type'        => 'string',
							'description' => 'Test parameter',
						),
					),
				);
			}

			/**
			 * Execute the tool.
			 *
			 * @param array $arguments Tool arguments.
			 * @param array $context Execution context.
			 * @return array|WP_Error Tool result.
			 */
			public function execute( array $arguments = array(), array $context = array() ) {
				return array(
					'success'        => true,
					'message'        => 'test_mock_tool executed successfully',
					'received_param' => isset( $arguments['test_param'] ) ? $arguments['test_param'] : null,
				);
			}
		};
	}
}
