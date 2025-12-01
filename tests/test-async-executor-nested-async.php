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

		$hook_fired    = false;
		$hook_job_id   = null;
		$hook_metadata = null;

		// Hook into job_started to verify it's fired.
		add_action(
			'wp_mcp_ai_job_started',
			function ( $job_id, $metadata ) use ( &$hook_fired, &$hook_job_id, &$hook_metadata ) {
				$hook_fired    = true;
				$hook_job_id   = $job_id;
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

	/**
	 * Test that executor preserves veo-merged metadata in unified job flow.
	 *
	 * When a tool returns async with the same job_id as the parent (unified flow),
	 * the executor should refresh metadata from transient before saving to avoid
	 * overwriting veo-specific fields like operation_name.
	 *
	 * This tests the fix for the bug where async executor was overwriting
	 * veo-merged metadata with its stale copy.
	 */
	public function test_executor_preserves_merged_metadata_in_unified_flow() {
		// Load executor.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';

		// Create executor instance.
		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$executor->init();

		// Create a mock tool that simulates veo's unified job flow:
		// 1. It will receive the parent_job_id in context.
		// 2. It will merge its own metadata (like operation_name) into the transient.
		// 3. It will return the same job_id as the parent (unified flow)
		$mock_tool = new class() implements WP_MCP_AI_Tool_Interface {
			public function get_slug() {
				return 'test_unified_flow_tool';
			}

			public function get_name() {
				return 'Test Unified Flow Tool';
			}

			public function get_description() {
				return 'Test tool that simulates veo unified job flow';
			}

			public function get_parameters_schema() {
				return array(
					'type'       => 'object',
					'properties' => array(),
				);
			}

			public function execute( array $arguments = array(), array $context = array() ) {
				// Get the parent job ID from context (passed by async executor).
				$parent_job_id = isset( $context['parent_job_id'] ) ? $context['parent_job_id'] : '';

				if ( empty( $parent_job_id ) ) {
					return new WP_Error( 'no_parent', 'Expected parent_job_id in context' );
				}

				// Simulate what veo does: merge additional metadata into the transient.
				$transient_key     = 'wp_mcp_ai_async_meta_' . $parent_job_id;
				$existing_metadata = get_transient( $transient_key );

				if ( $existing_metadata && is_array( $existing_metadata ) ) {
					// Merge veo-specific fields.
					$veo_fields = array(
						'operation_name'    => 'operations/test-op-123',
						'model'             => 'veo-3.1-generate-preview',
						'expected_filename' => 'veo-video-' . $parent_job_id . '.mp4',
						'use_parent_job'    => true,
					);
					$merged     = array_merge( $existing_metadata, $veo_fields );
					set_transient( $transient_key, $merged, DAY_IN_SECONDS );
				}

				// Return unified job response (same job_id as parent).
				return array(
					'async'   => true,
					'job_id'  => $parent_job_id, // SAME as parent = unified flow.
					'status'  => 'pending',
					'message' => 'Video generation started (unified flow)',
				);
			}
		};

		// Register the mock tool.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( $mock_tool );

		// Queue the tool for async execution.
		$job_id = $executor->queue_tool( 'test_unified_flow_tool', array(), array( 'user_id' => 1 ) );

		$this->assertNotInstanceOf( 'WP_Error', $job_id, 'Job should be queued successfully' );
		$this->assertStringStartsWith( 'async_', $job_id, 'Job ID should start with async_' );

		// Execute the async tool (simulates cron execution).
		$executor->execute_async_tool( $job_id );

		// Retrieve job metadata from transient.
		$reflection = new ReflectionClass( $executor );
		$method     = $reflection->getMethod( 'get_metadata' );
		$method->setAccessible( true );
		$metadata = $method->invoke( $executor, $job_id );

		// Verify the job was marked as 'polling' (unified flow status).
		$this->assertIsArray( $metadata, 'Metadata should exist' );
		$this->assertEquals( 'polling', $metadata['status'], 'Job status should be polling in unified flow' );

		// CRITICAL: Verify veo-specific fields are preserved (not overwritten).
		$this->assertArrayHasKey( 'operation_name', $metadata, 'operation_name should be preserved from veo merge' );
		$this->assertEquals( 'operations/test-op-123', $metadata['operation_name'], 'operation_name value should match' );

		$this->assertArrayHasKey( 'model', $metadata, 'model should be preserved from veo merge' );
		$this->assertEquals( 'veo-3.1-generate-preview', $metadata['model'], 'model value should match' );

		$this->assertArrayHasKey( 'expected_filename', $metadata, 'expected_filename should be preserved from veo merge' );
		$this->assertStringContainsString( $job_id, $metadata['expected_filename'], 'expected_filename should contain job_id' );

		$this->assertArrayHasKey( 'use_parent_job', $metadata, 'use_parent_job flag should be preserved' );
		$this->assertTrue( $metadata['use_parent_job'], 'use_parent_job should be true' );

		// Also verify async executor's original fields are preserved.
		$this->assertArrayHasKey( 'tool_slug', $metadata, 'tool_slug should be preserved' );
		$this->assertEquals( 'test_unified_flow_tool', $metadata['tool_slug'], 'tool_slug value should match' );

		// Cleanup.
		$method = $reflection->getMethod( 'delete_metadata' );
		$method->setAccessible( true );
		$method->invoke( $executor, $job_id );
	}
}
