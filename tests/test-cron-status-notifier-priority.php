<?php
/**
 * Test that cron status service properly prioritizes Job Notifier cache
 * for completion/failure status detection.
 *
 * This addresses the issue where veo job completion notifications weren't
 * being detected because the service was only checking the transient status,
 * not the Job Notifier cache which is updated when completion hooks fire.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for cron status service Job Notifier priority.
 */
class Test_Cron_Status_Notifier_Priority extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required files.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cron-status-service.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-job-notifier.php';

		// Initialize services.
		WP_MCP_AI_Gemini_Video_Generation_Service::init();
		WP_MCP_AI_Job_Notifier::init();
	}

	/**
	 * Test that veo job status uses Job Notifier completion status over transient.
	 *
	 * This simulates the race condition where the video file is created and
	 * completion hook fires, but the transient still shows 'polling' status.
	 */
	public function test_veo_job_uses_notifier_completed_status() {
		$job_id  = 'veo_test_notifier_' . uniqid();
		$user_id = 1;

		// Set up veo job transient with 'polling' status (simulating in-progress state).
		$veo_metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test-op',
			'model'          => WP_MCP_AI_Gemini_Video_Generation_Service::VEO_MODEL,
			'args'           => array(
				'prompt'  => 'Test video',
				'user_id' => $user_id,
			),
			'status'         => 'polling', // Still shows as polling.
			'queued_at'      => time() - 60,
			'poll_attempt'   => 10,
			'max_attempts'   => 60,
		);
		set_transient(
			WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id,
			$veo_metadata,
			DAY_IN_SECONDS
		);

		// Simulate completion by firing the job completed hook.
		// This should update the Job Notifier cache to 'completed'.
		$completed_result = array(
			'url'           => 'http://example.com/video.mp4',
			'attachment_id' => 123,
			'prompt'        => 'Test video',
		);

		do_action(
			'wp_mcp_ai_job_completed',
			$job_id,
			$completed_result,
			array(
				'tool'    => 'generate_veo_video',
				'user_id' => $user_id,
			)
		);

		// Now check job details via cron status service.
		$service = new WP_MCP_AI_Cron_Status_Service();
		$result  = $service->get_job_details( $job_id, $user_id );

		// Verify the service returns 'completed' status (from Job Notifier).
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertEquals( 'completed', $result['status'], 'Status should be completed (from Job Notifier), not polling' );
		$this->assertArrayHasKey( 'result', $result, 'Result should contain result data' );

		// Cleanup.
		delete_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id );
		delete_transient( WP_MCP_AI_Job_Notifier::CACHE_PREFIX . $job_id );
	}

	/**
	 * Test that veo job status uses Job Notifier failure status over transient.
	 */
	public function test_veo_job_uses_notifier_failed_status() {
		$job_id  = 'veo_test_fail_' . uniqid();
		$user_id = 1;

		// Set up veo job transient with 'polling' status.
		$veo_metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test-op',
			'model'          => WP_MCP_AI_Gemini_Video_Generation_Service::VEO_MODEL,
			'args'           => array(
				'prompt'  => 'Test video',
				'user_id' => $user_id,
			),
			'status'         => 'polling',
			'queued_at'      => time() - 60,
			'poll_attempt'   => 10,
			'max_attempts'   => 60,
		);
		set_transient(
			WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id,
			$veo_metadata,
			DAY_IN_SECONDS
		);

		// Simulate failure by firing the job failed hook.
		$error = new WP_Error( 'veo_generation_failed', 'Video generation failed' );

		do_action(
			'wp_mcp_ai_job_failed',
			$job_id,
			$error,
			array(
				'tool'    => 'generate_veo_video',
				'user_id' => $user_id,
			)
		);

		// Check job details via cron status service.
		$service = new WP_MCP_AI_Cron_Status_Service();
		$result  = $service->get_job_details( $job_id, $user_id );

		// Verify the service returns 'failed' status (from Job Notifier).
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertEquals( 'failed', $result['status'], 'Status should be failed (from Job Notifier)' );
		$this->assertArrayHasKey( 'error', $result, 'Result should contain error data' );

		// Cleanup.
		delete_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id );
		delete_transient( WP_MCP_AI_Job_Notifier::CACHE_PREFIX . $job_id );
	}

	/**
	 * Test that async job status uses Job Notifier completion status.
	 */
	public function test_async_job_uses_notifier_completed_status() {
		$job_id  = 'async_test_notifier_' . uniqid();
		$user_id = 1;

		// Set up async job transient with 'running' status.
		$async_metadata = array(
			'job_id'     => $job_id,
			'tool_slug'  => 'generate_veo_video',
			'context'    => array(
				'user_id' => $user_id,
			),
			'status'     => 'running', // Still shows as running.
			'queued_at'  => time() - 60,
			'started_at' => time() - 30,
		);
		set_transient(
			'wp_mcp_ai_async_meta_' . $job_id,
			$async_metadata,
			DAY_IN_SECONDS
		);

		// Simulate completion by firing the job completed hook.
		$completed_result = array(
			'url'           => 'http://example.com/video.mp4',
			'attachment_id' => 456,
		);

		do_action(
			'wp_mcp_ai_job_completed',
			$job_id,
			$completed_result,
			array(
				'tool'    => 'generate_veo_video',
				'user_id' => $user_id,
			)
		);

		// Check job details via cron status service.
		$service = new WP_MCP_AI_Cron_Status_Service();
		$result  = $service->get_job_details( $job_id, $user_id );

		// Verify the service returns 'completed' status (from Job Notifier).
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertEquals( 'completed', $result['status'], 'Status should be completed (from Job Notifier)' );
		$this->assertArrayHasKey( 'result', $result, 'Result should contain result data' );

		// Cleanup.
		delete_transient( 'wp_mcp_ai_async_meta_' . $job_id );
		delete_transient( WP_MCP_AI_Job_Notifier::CACHE_PREFIX . $job_id );
	}

	/**
	 * Test that progress data is still included when status is updated from notifier.
	 */
	public function test_progress_data_preserved_with_notifier_status() {
		$job_id  = 'veo_test_progress_' . uniqid();
		$user_id = 1;

		// Set up veo job transient.
		$veo_metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test-op',
			'model'          => WP_MCP_AI_Gemini_Video_Generation_Service::VEO_MODEL,
			'args'           => array(
				'prompt'  => 'Test video',
				'user_id' => $user_id,
			),
			'status'         => 'pending',
			'queued_at'      => time() - 60,
			'poll_attempt'   => 5,
			'max_attempts'   => 60,
		);
		set_transient(
			WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id,
			$veo_metadata,
			DAY_IN_SECONDS
		);

		// Fire progress hook first (to simulate polling in progress).
		do_action(
			'wp_mcp_ai_job_progress',
			$job_id,
			50.0, // 50% progress.
			array(
				'tool'         => 'generate_veo_video',
				'poll_attempt' => 5,
				'message'      => 'Video generation in progress (check 5)…',
			)
		);

		// Then fire completion hook.
		$completed_result = array(
			'url'           => 'http://example.com/video.mp4',
			'attachment_id' => 789,
		);

		do_action(
			'wp_mcp_ai_job_completed',
			$job_id,
			$completed_result,
			array(
				'tool'    => 'generate_veo_video',
				'user_id' => $user_id,
			)
		);

		// Check job details.
		$service = new WP_MCP_AI_Cron_Status_Service();
		$result  = $service->get_job_details( $job_id, $user_id );

		// Verify completed status is returned.
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertEquals( 'completed', $result['status'], 'Status should be completed' );
		$this->assertArrayHasKey( 'result', $result, 'Result should contain result data' );

		// Cleanup.
		delete_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id );
		delete_transient( WP_MCP_AI_Job_Notifier::CACHE_PREFIX . $job_id );
	}
}
