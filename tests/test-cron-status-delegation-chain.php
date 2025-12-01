<?php
/**
 * Test that cron status service properly handles the delegation chain.
 *
 * Tests scenarios where:
 * - An async job delegates to a veo job and the veo job completes
 * - An async job delegates to a veo job and the veo job fails
 * - Progress data is passed through from delegated jobs
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for cron status service delegation chain handling.
 */
class Test_Cron_Status_Delegation_Chain extends WP_UnitTestCase {

	/**
	 * Service instance.
	 *
	 * @var WP_MCP_AI_Cron_Status_Service
	 */
	protected $service;

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required files.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cron-status-service.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-job-notifier.php';

		$this->service = new WP_MCP_AI_Cron_Status_Service();
		$this->user_id = $this->factory->user->create();

		// Initialize services.
		WP_MCP_AI_Job_Notifier::init();
	}

	/**
	 * Test that delegated job failure is properly propagated to parent job.
	 *
	 * This is the main fix: when a delegated veo job fails, the parent async job
	 * should also show as failed with the error message from the veo job.
	 */
	public function test_delegated_job_failure_propagates_to_parent() {
		$parent_job_id = 'async_test_parent_' . uniqid();
		$veo_job_id    = 'veo_test_child_' . uniqid();

		// Set up parent async job with 'delegated' status.
		// The async executor stores metadata directly (not in compressed format).
		$parent_metadata = array(
			'job_id'       => $parent_job_id,
			'tool_slug'    => 'generate_veo_video',
			'status'       => 'delegated',
			'delegated_to' => $veo_job_id,
			'context'      => array(
				'user_id' => $this->user_id,
			),
			'queued_at'    => time() - 60,
		);
		set_transient(
			WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $parent_job_id,
			$parent_metadata,
			DAY_IN_SECONDS
		);

		// Set up veo job with 'failed' status.
		$veo_metadata = array(
			'job_id'         => $veo_job_id,
			'operation_name' => 'operations/test-op',
			'model'          => 'veo-3.1-generate-preview',
			'args'           => array(
				'prompt'  => 'Test video',
				'user_id' => $this->user_id,
			),
			'status'         => 'failed',
			'error'          => 'Video generation failed due to content policy violation.',
			'queued_at'      => time() - 60,
			'poll_attempt'   => 10,
			'max_attempts'   => 60,
		);
		set_transient(
			WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $veo_job_id,
			$veo_metadata,
			DAY_IN_SECONDS
		);

		// Get parent job details.
		$result = $this->service->get_job_details( $parent_job_id, $this->user_id );

		// Verify parent job now shows as failed.
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'failed', $result['status'], 'Parent job should show failed status from delegated job' );
		$this->assertArrayHasKey( 'error', $result, 'Parent job should have error from delegated job' );
		$this->assertStringContainsString( 'content policy', $result['error'], 'Error message should be from delegated job' );

		// Cleanup.
		delete_transient( WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $parent_job_id );
		delete_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $veo_job_id );
	}

	/**
	 * Test that delegated job completion is properly propagated to parent job.
	 */
	public function test_delegated_job_completion_propagates_to_parent() {
		$parent_job_id = 'async_test_complete_' . uniqid();
		$veo_job_id    = 'veo_test_complete_' . uniqid();

		// Set up parent async job with 'delegated' status.
		$parent_metadata = array(
			'job_id'       => $parent_job_id,
			'tool_slug'    => 'generate_veo_video',
			'status'       => 'delegated',
			'delegated_to' => $veo_job_id,
			'context'      => array(
				'user_id'      => $this->user_id,
				'tool_call_id' => 'call_test_123',
			),
			'queued_at'    => time() - 60,
		);
		set_transient(
			WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $parent_job_id,
			$parent_metadata,
			DAY_IN_SECONDS
		);

		// Set up veo job with 'completed' status.
		$veo_result = array(
			'success'       => true,
			'attachment_id' => 123,
			'url'           => 'https://example.com/video.mp4',
			'prompt'        => 'Test video',
			'duration'      => 5,
			'aspect_ratio'  => '16:9',
			'resolution'    => '720p',
			'model'         => 'veo-3.1-generate-preview',
			'provider'      => 'gemini',
		);

		$veo_metadata = array(
			'job_id'         => $veo_job_id,
			'operation_name' => 'operations/test-op',
			'model'          => 'veo-3.1-generate-preview',
			'args'           => array(
				'prompt'  => 'Test video',
				'user_id' => $this->user_id,
			),
			'status'         => 'completed',
			'result'         => $veo_result,
			'queued_at'      => time() - 60,
			'poll_attempt'   => 10,
			'max_attempts'   => 60,
		);
		set_transient(
			WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $veo_job_id,
			$veo_metadata,
			DAY_IN_SECONDS
		);

		// Get parent job details.
		$result = $this->service->get_job_details( $parent_job_id, $this->user_id );

		// Verify parent job now shows as completed.
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'completed', $result['status'], 'Parent job should show completed status from delegated job' );
		$this->assertArrayHasKey( 'result', $result, 'Parent job should have result from delegated job' );
		$this->assertEquals( 123, $result['result']['attachment_id'], 'Result should have attachment ID' );
		$this->assertEquals( 'https://example.com/video.mp4', $result['result']['url'], 'Result should have video URL' );

		// Verify tool_results array was built for chat client compatibility.
		$this->assertArrayHasKey( 'tool_results', $result, 'Parent job should have tool_results array' );
		$this->assertCount( 1, $result['tool_results'], 'Should have one tool result' );
		$this->assertEquals( 'tool', $result['tool_results'][0]['role'], 'Tool result should have role=tool' );
		$this->assertEquals( 'call_test_123', $result['tool_results'][0]['tool_call_id'], 'Tool result should use original tool_call_id' );

		// Cleanup.
		delete_transient( WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $parent_job_id );
		delete_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $veo_job_id );
	}

	/**
	 * Test that progress data from delegated job is passed through.
	 */
	public function test_delegated_job_progress_passed_through() {
		$parent_job_id = 'async_test_progress_' . uniqid();
		$veo_job_id    = 'veo_test_progress_' . uniqid();

		// Set up parent async job with 'delegated' status.
		$parent_metadata = array(
			'job_id'       => $parent_job_id,
			'tool_slug'    => 'generate_veo_video',
			'status'       => 'delegated',
			'delegated_to' => $veo_job_id,
			'context'      => array(
				'user_id' => $this->user_id,
			),
			'queued_at'    => time() - 60,
		);
		set_transient(
			WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $parent_job_id,
			$parent_metadata,
			DAY_IN_SECONDS
		);

		// Set up veo job with 'polling' status (in progress).
		$veo_metadata = array(
			'job_id'         => $veo_job_id,
			'operation_name' => 'operations/test-op',
			'model'          => 'veo-3.1-generate-preview',
			'args'           => array(
				'prompt'  => 'Test video',
				'user_id' => $this->user_id,
			),
			'status'         => 'polling',
			'queued_at'      => time() - 60,
			'poll_attempt'   => 25,
			'max_attempts'   => 60,
		);
		set_transient(
			WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $veo_job_id,
			$veo_metadata,
			DAY_IN_SECONDS
		);

		// Fire progress hook to populate Job Notifier cache for the veo job.
		do_action(
			'wp_mcp_ai_job_progress',
			$veo_job_id,
			50.0,
			array(
				'tool'         => 'generate_veo_video',
				'status'       => 'polling',
				'poll_attempt' => 25,
				'message'      => 'Video generation in progress (check 25)…',
			)
		);

		// Get parent job details.
		$result = $this->service->get_job_details( $parent_job_id, $this->user_id );

		// Verify parent job still shows as delegated but has progress data.
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'delegated', $result['status'], 'Parent job should still show delegated status' );
		$this->assertArrayHasKey( 'poll_attempt', $result, 'Parent job should have poll_attempt from delegated job' );
		$this->assertEquals( 25, $result['poll_attempt'], 'Poll attempt should be from delegated job' );

		// Cleanup.
		delete_transient( WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $parent_job_id );
		delete_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $veo_job_id );
		delete_transient( WP_MCP_AI_Job_Notifier::CACHE_PREFIX . $veo_job_id );
	}

	/**
	 * Test that non-veo delegated jobs are not followed (prevents recursion).
	 */
	public function test_only_veo_delegation_followed() {
		$parent_job_id  = 'async_test_no_recurse_' . uniqid();
		$non_veo_job_id = 'async_nested_' . uniqid(); // Not a veo_ job.

		// Set up parent async job with 'delegated' status to a non-veo job.
		$parent_metadata = array(
			'job_id'       => $parent_job_id,
			'tool_slug'    => 'some_other_tool',
			'status'       => 'delegated',
			'delegated_to' => $non_veo_job_id, // Not veo_, should not be followed.
			'context'      => array(
				'user_id' => $this->user_id,
			),
			'queued_at'    => time() - 60,
		);
		set_transient(
			WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $parent_job_id,
			$parent_metadata,
			DAY_IN_SECONDS
		);

		// Get parent job details.
		$result = $this->service->get_job_details( $parent_job_id, $this->user_id );

		// Verify parent job still shows as delegated (not followed).
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'delegated', $result['status'], 'Parent job should still show delegated (non-veo delegation not followed)' );

		// Cleanup.
		delete_transient( WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $parent_job_id );
	}

	/**
	 * Test that error from get_job_details for delegated job doesn't break parent job.
	 */
	public function test_delegated_job_not_found_doesnt_break_parent() {
		$parent_job_id = 'async_test_missing_' . uniqid();
		$veo_job_id    = 'veo_missing_' . uniqid();

		// Set up parent async job with 'delegated' status.
		$parent_metadata = array(
			'job_id'       => $parent_job_id,
			'tool_slug'    => 'generate_veo_video',
			'status'       => 'delegated',
			'delegated_to' => $veo_job_id,
			'context'      => array(
				'user_id' => $this->user_id,
			),
			'queued_at'    => time() - 60,
		);
		set_transient(
			WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $parent_job_id,
			$parent_metadata,
			DAY_IN_SECONDS
		);

		// Do NOT set up the veo job - it should return error when fetched.

		// Get parent job details.
		$result = $this->service->get_job_details( $parent_job_id, $this->user_id );

		// Verify parent job still shows as delegated (graceful handling of missing veo job).
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'delegated', $result['status'], 'Parent job should still show delegated (missing veo job handled gracefully)' );

		// Cleanup.
		delete_transient( WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $parent_job_id );
	}
}
