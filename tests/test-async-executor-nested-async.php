<?php
/**
 * Test Async Executor Handling of Nested Async Responses
 *
 * Tests that the async executor correctly handles when a tool returns
 * a nested async response (e.g., veo video generation falling back to async).
 * The parent job should be marked as 'delegated' not 'completed'.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for async executor nested async handling
 */
class Test_Async_Executor_Nested_Async extends WP_UnitTestCase {

	/**
	 * Test that executor marks job as 'delegated' when tool returns nested async response.
	 */
	public function test_executor_delegates_on_nested_async_response() {
		// Load executor.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';

		// Create executor instance.
		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$executor->init();

		// Create a mock tool that returns a nested async response.
		$mock_tool = new class() implements WP_MCP_AI_Tool_Interface {
			public function get_slug() {
				return 'test_nested_async_tool';
			}

			public function get_name() {
				return 'Test Nested Async Tool';
			}

			public function get_description() {
				return 'Test tool that returns nested async response';
			}

			public function get_parameters_schema() {
				return array(
					'type'       => 'object',
					'properties' => array(),
				);
			}

			public function execute( array $arguments = array(), array $context = array() ) {
				// Simulate a tool that falls back to async (like veo video generation on timeout).
				return array(
					'async'   => true,
					'job_id'  => 'nested_veo_12345',
					'status'  => 'pending',
					'message' => 'Video generation started in background',
				);
			}
		};

		// Register the mock tool.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( $mock_tool );

		// Queue the tool for async execution.
		$parent_job_id = $executor->queue_tool( 'test_nested_async_tool', array(), array( 'user_id' => 1 ) );

		$this->assertNotInstanceOf( 'WP_Error', $parent_job_id, 'Job should be queued successfully' );
		$this->assertStringStartsWith( 'async_', $parent_job_id, 'Job ID should start with async_' );

		// Execute the async tool (simulates cron execution).
		$executor->execute_async_tool( $parent_job_id );

		// Retrieve job metadata.
		$reflection = new ReflectionClass( $executor );
		$method     = $reflection->getMethod( 'get_metadata' );
		$method->setAccessible( true );
		$metadata = $method->invoke( $executor, $parent_job_id );

		// Verify the job was marked as 'delegated', not 'completed'.
		$this->assertIsArray( $metadata, 'Metadata should exist' );
		$this->assertEquals( 'delegated', $metadata['status'], 'Job status should be delegated' );
		$this->assertArrayHasKey( 'delegated_to', $metadata, 'Metadata should have delegated_to field' );
		$this->assertEquals( 'nested_veo_12345', $metadata['delegated_to'], 'Should be delegated to nested job' );

		// Verify the result contains the nested async response.
		$this->assertArrayHasKey( 'result', $metadata, 'Metadata should have result' );
		
		// Decompress the result.
		$method = $reflection->getMethod( 'decompress_result' );
		$method->setAccessible( true );
		$result = $method->invoke( $executor, $metadata['result'] );

		$this->assertIsArray( $result, 'Result should be array' );
		$this->assertTrue( $result['async'], 'Result should indicate async' );
		$this->assertEquals( 'nested_veo_12345', $result['job_id'], 'Result should have nested job ID' );

		// Cleanup.
		$method = $reflection->getMethod( 'delete_metadata' );
		$method->setAccessible( true );
		$method->invoke( $executor, $parent_job_id );
	}

	/**
	 * Test that executor marks job as 'completed' for normal (non-async) results.
	 */
	public function test_executor_completes_on_normal_response() {
		// Load executor.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';

		// Create executor instance.
		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$executor->init();

		// Create a mock tool that returns a normal result.
		$mock_tool = new class() implements WP_MCP_AI_Tool_Interface {
			public function get_slug() {
				return 'test_normal_tool';
			}

			public function get_name() {
				return 'Test Normal Tool';
			}

			public function get_description() {
				return 'Test tool that returns normal result';
			}

			public function get_parameters_schema() {
				return array(
					'type'       => 'object',
					'properties' => array(),
				);
			}

			public function execute( array $arguments = array(), array $context = array() ) {
				// Return a normal result (not async).
				return array(
					'success' => true,
					'message' => 'Task completed successfully',
				);
			}
		};

		// Register the mock tool.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( $mock_tool );

		// Queue the tool for async execution.
		$job_id = $executor->queue_tool( 'test_normal_tool', array(), array( 'user_id' => 1 ) );

		$this->assertNotInstanceOf( 'WP_Error', $job_id, 'Job should be queued successfully' );

		// Execute the async tool.
		$executor->execute_async_tool( $job_id );

		// Retrieve job metadata.
		$reflection = new ReflectionClass( $executor );
		$method     = $reflection->getMethod( 'get_metadata' );
		$method->setAccessible( true );
		$metadata = $method->invoke( $executor, $job_id );

		// Verify the job was marked as 'completed', not 'delegated'.
		$this->assertIsArray( $metadata, 'Metadata should exist' );
		$this->assertEquals( 'completed', $metadata['status'], 'Job status should be completed' );
		$this->assertArrayNotHasKey( 'delegated_to', $metadata, 'Should not have delegated_to field' );

		// Cleanup.
		$method = $reflection->getMethod( 'delete_metadata' );
		$method->setAccessible( true );
		$method->invoke( $executor, $job_id );
	}

	/**
	 * Test that wp_mcp_ai_job_started hook is fired for delegated jobs.
	 */
	public function test_job_started_hook_fired_on_delegation() {
		// Load executor.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';

		$hook_fired = false;
		$hook_job_id = null;
		$hook_metadata = null;

		// Hook into job_started to verify it's fired.
		add_action(
			'wp_mcp_ai_job_started',
			function( $job_id, $metadata ) use ( &$hook_fired, &$hook_job_id, &$hook_metadata ) {
				$hook_fired = true;
				$hook_job_id = $job_id;
				$hook_metadata = $metadata;
			},
			10,
			2
		);

		// Create executor instance.
		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$executor->init();

		// Create a mock tool that returns nested async.
		$mock_tool = new class() implements WP_MCP_AI_Tool_Interface {
			public function get_slug() {
				return 'test_hook_tool';
			}

			public function get_name() {
				return 'Test Hook Tool';
			}

			public function get_description() {
				return 'Test tool for hook verification';
			}

			public function get_parameters_schema() {
				return array(
					'type'       => 'object',
					'properties' => array(),
				);
			}

			public function execute( array $arguments = array(), array $context = array() ) {
				return array(
					'async'   => true,
					'job_id'  => 'nested_job_789',
					'status'  => 'pending',
					'message' => 'Job started',
				);
			}
		};

		// Register the mock tool.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( $mock_tool );

		// Queue and execute the tool.
		$job_id = $executor->queue_tool( 'test_hook_tool', array(), array( 'user_id' => 1 ) );
		$executor->execute_async_tool( $job_id );

		// Verify hook was fired.
		$this->assertTrue( $hook_fired, 'wp_mcp_ai_job_started hook should be fired' );
		$this->assertEquals( $job_id, $hook_job_id, 'Hook should receive correct job ID' );
		$this->assertIsArray( $hook_metadata, 'Hook metadata should be array' );
		$this->assertEquals( 'test_hook_tool', $hook_metadata['tool'], 'Hook metadata should have tool name' );
		$this->assertEquals( 'nested_job_789', $hook_metadata['delegated_to'], 'Hook metadata should have nested job ID' );

		// Cleanup.
		$reflection = new ReflectionClass( $executor );
		$method     = $reflection->getMethod( 'delete_metadata' );
		$method->setAccessible( true );
		$method->invoke( $executor, $job_id );
	}
}
