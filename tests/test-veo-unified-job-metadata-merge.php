<?php
/**
 * Test that veo service merges metadata with async executor when using unified job IDs.
 *
 * When use_parent_job is true, the veo service should preserve the async executor's
 * metadata fields (tool_slug, context, arguments) instead of overwriting them.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for unified job ID metadata merging.
 */
class Test_Veo_Unified_Job_Metadata_Merge extends WP_UnitTestCase {

	/**
	 * Service instance.
	 *
	 * @var WP_MCP_AI_Gemini_Video_Generation_Service
	 */
	protected $service;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required files.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

		$this->service = new WP_MCP_AI_Gemini_Video_Generation_Service();
	}

	/**
	 * Test that queue_async_polling merges with existing async executor metadata.
	 *
	 * This test simulates the scenario where:
	 * 1. Async executor creates a job with async_xxx ID (with tool_slug, context, etc.)
	 * 2. Veo service is called with use_parent_job=true
	 * 3. Veo should MERGE its metadata with the existing metadata, not overwrite
	 */
	public function test_queue_async_polling_merges_parent_metadata() {
		// Create a parent job ID that simulates what async executor would create.
		$parent_job_id = 'async_' . substr( md5( time() . wp_rand() ), 0, 16 );

		// Simulate what async executor stores in transient.
		$async_executor_metadata = array(
			'job_id'       => $parent_job_id,
			'tool_slug'    => 'generate_veo_video',
			'arguments'    => array( 'prompt' => 'Test video' ),
			'context'      => array(
				'user_id'      => 42,
				'assistant_id' => 123,
				'session_id'   => 'test-session',
				'tool_call_id' => 'call_abc123',
			),
			'status'       => 'running',
			'queued_at'    => time() - 10,
			'started_at'   => time() - 5,
			'completed_at' => null,
			'result'       => null,
			'error'        => null,
		);

		// Save to transient using async executor's prefix.
		$transient_prefix = 'wp_mcp_ai_async_meta_';
		set_transient( $transient_prefix . $parent_job_id, $async_executor_metadata, DAY_IN_SECONDS );

		// Use reflection to call the protected queue_async_polling method.
		$reflection = new ReflectionClass( $this->service );
		$method     = $reflection->getMethod( 'queue_async_polling' );
		$method->setAccessible( true );

		// Create a mock operation response.
		$operation = array(
			'operation_name' => 'operations/test-operation-12345',
			'model_used'     => 'veo-3.1-generate-preview',
		);

		// Args with parent_job_id and in_async_executor flag.
		$args = array(
			'prompt'            => 'Test video',
			'user_id'           => 42,
			'in_async_executor' => true,
			'parent_job_id'     => $parent_job_id,
		);

		// Execute queue_async_polling.
		$result = $method->invoke( $this->service, $operation, $args );

		// Verify the result returns the parent job ID (unified job flow).
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertTrue( $result['async'], 'Result should indicate async' );
		$this->assertEquals( $parent_job_id, $result['job_id'], 'Should return the parent job ID' );

		// Retrieve the merged metadata from transient.
		$merged_metadata = get_transient( $transient_prefix . $parent_job_id );

		$this->assertIsArray( $merged_metadata, 'Merged metadata should exist' );

		// Verify async executor fields are preserved.
		$this->assertArrayHasKey( 'tool_slug', $merged_metadata, 'tool_slug should be preserved' );
		$this->assertEquals( 'generate_veo_video', $merged_metadata['tool_slug'], 'tool_slug value should be preserved' );

		$this->assertArrayHasKey( 'context', $merged_metadata, 'context should be preserved' );
		$this->assertIsArray( $merged_metadata['context'], 'context should be an array' );
		$this->assertEquals( 42, $merged_metadata['context']['user_id'], 'context.user_id should be preserved' );
		$this->assertEquals( 123, $merged_metadata['context']['assistant_id'], 'context.assistant_id should be preserved' );
		$this->assertEquals( 'call_abc123', $merged_metadata['context']['tool_call_id'], 'context.tool_call_id should be preserved' );

		$this->assertArrayHasKey( 'arguments', $merged_metadata, 'arguments should be preserved' );

		// Verify veo-specific fields are added.
		$this->assertArrayHasKey( 'operation_name', $merged_metadata, 'operation_name should be added' );
		$this->assertEquals( 'operations/test-operation-12345', $merged_metadata['operation_name'], 'operation_name value should be correct' );

		$this->assertArrayHasKey( 'model', $merged_metadata, 'model should be added' );
		$this->assertArrayHasKey( 'expected_filename', $merged_metadata, 'expected_filename should be added' );
		$this->assertStringContainsString( $parent_job_id, $merged_metadata['expected_filename'], 'expected_filename should contain job ID' );

		$this->assertArrayHasKey( 'use_parent_job', $merged_metadata, 'use_parent_job flag should be set' );
		$this->assertTrue( $merged_metadata['use_parent_job'], 'use_parent_job should be true' );

		// Status should be updated to 'polling'.
		$this->assertEquals( 'polling', $merged_metadata['status'], 'status should be updated to polling' );

		// Cleanup.
		delete_transient( $transient_prefix . $parent_job_id );
	}

	/**
	 * Test that cron-status service can retrieve unified job correctly.
	 *
	 * This verifies that after merging, the cron-status service can:
	 * 1. Find the job using async_ prefix
	 * 2. Check permissions using context.user_id
	 * 3. Apply sanitization using tool_slug
	 */
	public function test_cron_status_retrieves_merged_job() {
		// Load required services.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cron-status-service.php';

		// Create job with merged metadata.
		$job_id           = 'async_' . substr( md5( time() . wp_rand() ), 0, 16 );
		$transient_prefix = 'wp_mcp_ai_async_meta_';

		// Simulated merged metadata (what should exist after our fix).
		$merged_metadata = array(
			// Async executor fields (preserved).
			'job_id'            => $job_id,
			'tool_slug'         => 'generate_veo_video',
			'arguments'         => array( 'prompt' => 'Test video' ),
			'context'           => array(
				'user_id'      => 1, // Admin user.
				'assistant_id' => 123,
				'session_id'   => 'test-session',
			),
			// Veo fields (added).
			'operation_name'    => 'operations/test-12345',
			'model'             => 'veo-3.1-generate-preview',
			'status'            => 'polling',
			'use_parent_job'    => true,
			'expected_filename' => 'veo-video-' . $job_id . '.mp4',
			'poll_attempt'      => 0,
		);

		set_transient( $transient_prefix . $job_id, $merged_metadata, DAY_IN_SECONDS );

		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Use cron-status service to get job details.
		$service = new WP_MCP_AI_Cron_Status_Service();
		$result  = $service->get_job_details( $job_id, 1 );

		// Should NOT be an error.
		$this->assertNotWPError( $result, 'get_job_details should succeed for merged job' );
		$this->assertIsArray( $result, 'Result should be an array' );

		// Should have status.
		$this->assertArrayHasKey( 'status', $result, 'Result should have status' );

		// Cleanup.
		delete_transient( $transient_prefix . $job_id );
	}

	/**
	 * Test that without parent job, veo creates its own transient.
	 *
	 * When use_parent_job is false, veo should use its own prefix (veo_xxx).
	 */
	public function test_queue_async_polling_without_parent_uses_veo_prefix() {
		// Use reflection to call queue_async_polling.
		$reflection = new ReflectionClass( $this->service );
		$method     = $reflection->getMethod( 'queue_async_polling' );
		$method->setAccessible( true );

		$operation = array(
			'operation_name' => 'operations/test-operation-67890',
			'model_used'     => 'veo-2.0-generate-001',
		);

		// Args WITHOUT in_async_executor flag (normal veo async flow).
		$args = array(
			'prompt'  => 'Test video without parent',
			'user_id' => 1,
		);

		$result = $method->invoke( $this->service, $operation, $args );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertTrue( $result['async'], 'Result should indicate async' );
		$this->assertStringStartsWith( 'veo_', $result['job_id'], 'Job ID should start with veo_ prefix' );

		// Verify transient was created with veo prefix.
		$veo_prefix = 'wp_mcp_ai_veo_async_op_';
		$metadata   = get_transient( $veo_prefix . $result['job_id'] );

		$this->assertIsArray( $metadata, 'Metadata should exist with veo prefix' );
		$this->assertArrayHasKey( 'operation_name', $metadata, 'Should have operation_name' );
		$this->assertFalse( isset( $metadata['use_parent_job'] ) && $metadata['use_parent_job'], 'use_parent_job should be false' );

		// Cleanup.
		delete_transient( $veo_prefix . $result['job_id'] );
	}
}
