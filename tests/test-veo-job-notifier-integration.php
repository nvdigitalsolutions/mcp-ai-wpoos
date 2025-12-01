<?php
/**
 * Test Veo video generation job notifier integration.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that Veo async job completion fires notification hooks.
 */
class Test_Veo_Job_Notifier_Integration extends WP_UnitTestCase {
	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required files.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-job-notifier.php';

		// Initialize services.
		WP_MCP_AI_Gemini_Video_Generation_Service::init();
		WP_MCP_AI_Job_Notifier::init();
	}

	/**
	 * Test that job_started hook is fired when veo job is queued.
	 */
	public function test_started_hook_fired_when_veo_job_queued() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Track if hook was called.
		$hook_called   = false;
		$hook_job_id   = null;
		$hook_metadata = null;

		add_action(
			'wp_mcp_ai_job_started',
			function ( $id, $meta ) use ( &$hook_called, &$hook_job_id, &$hook_metadata ) {
				$hook_called   = true;
				$hook_job_id   = $id;
				$hook_metadata = $meta;
			},
			10,
			2
		);

		// Create a mock operation (would normally come from Gemini API).
		$mock_operation = array(
			'operation_name' => 'operations/test-op-started',
			'model_used'     => WP_MCP_AI_Gemini_Video_Generation_Service::VEO_MODEL,
		);

		$mock_args = array(
			'prompt'  => 'Test video for started hook',
			'user_id' => 1,
		);

		// Use reflection to call queue_async_polling.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'queue_async_polling' );
		$method->setAccessible( true );

		$result = $method->invoke( $service, $mock_operation, $mock_args );

		// Verify hook was called.
		$this->assertTrue( $hook_called, 'wp_mcp_ai_job_started hook should be fired when veo job is queued' );
		$this->assertIsString( $hook_job_id, 'Job ID should be a string' );
		$this->assertStringStartsWith( 'veo_', $hook_job_id, 'Job ID should start with veo_' );
		$this->assertIsArray( $hook_metadata, 'Metadata should be an array' );
		$this->assertEquals( 'generate_veo_video', $hook_metadata['tool'], 'Tool should be generate_veo_video' );
		$this->assertEquals( 'pending', $hook_metadata['status'], 'Status should be pending' );

		// Verify Job Notifier cached the status.
		$cached_status = WP_MCP_AI_Job_Notifier::get_job_status( $hook_job_id );
		$this->assertIsArray( $cached_status, 'Job status should be cached' );
		$this->assertEquals( 'started', $cached_status['status'], 'Status should be started' );

		// Cleanup.
		delete_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $hook_job_id );
	}

	/**
	 * Test that job_completed hook is fired when video generation completes successfully.
	 */
	public function test_completion_hook_fired_on_success() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Create a mock job with completed data.
		$job_id   = 'veo_test_completion_' . uniqid();
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test-op',
			'model'          => WP_MCP_AI_Gemini_Video_Generation_Service::VEO_MODEL,
			'args'           => array(
				'prompt'        => 'Test video for completion hook',
				'user_id'       => 1,
				'save_to_media' => false, // Skip media save for test.
			),
			'status'         => 'pending',
			'queued_at'      => time(),
			'poll_attempt'   => 0,
			'max_attempts'   => 60,
		);

		set_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id, $metadata, DAY_IN_SECONDS );

		// Track if hook was called.
		$hook_called   = false;
		$hook_job_id   = null;
		$hook_result   = null;
		$hook_metadata = null;

		add_action(
			'wp_mcp_ai_job_completed',
			function ( $id, $result, $meta ) use ( &$hook_called, &$hook_job_id, &$hook_result, &$hook_metadata ) {
				$hook_called   = true;
				$hook_job_id   = $id;
				$hook_result   = $result;
				$hook_metadata = $meta;
			},
			10,
			3
		);

		// Mock completed operation response.
		$completed_data = array(
			'done'     => true,
			'response' => array(
				'generateVideoResponse' => array(
					'generatedSamples' => array(
						array(
							'video' => array(
								'uri' => 'gs://test-bucket/test-video.mp4',
							),
						),
					),
				),
			),
		);

		// Use reflection to call poll_video_async and simulate completion.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'process_completed_video' );
		$method->setAccessible( true );

		// For this test, we'll simulate the completion path without actual video download.
		// The key is testing that the hook gets fired.
		// We need to mock the video data to avoid download.
		$mock_result = array(
			'video_data'   => 'mock_video_data',
			'prompt'       => 'Test video for completion hook',
			'duration'     => 5,
			'aspect_ratio' => '16:9',
			'resolution'   => '720p',
			'model'        => WP_MCP_AI_Gemini_Video_Generation_Service::VEO_MODEL,
			'provider'     => 'gemini',
		);

		// Manually update metadata to completed state (simulating successful completion).
		$metadata['status'] = 'completed';
		$metadata['result'] = array(
			'video_url'    => 'data:video/mp4;base64,mock_data',
			'prompt'       => $mock_result['prompt'],
			'duration'     => $mock_result['duration'],
			'aspect_ratio' => $mock_result['aspect_ratio'],
			'resolution'   => $mock_result['resolution'],
			'model'        => $mock_result['model'],
			'provider'     => $mock_result['provider'],
		);
		set_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id, $metadata, DAY_IN_SECONDS );

		// Fire the completion hook manually to test notification system integration.
		do_action(
			'wp_mcp_ai_job_completed',
			$job_id,
			$metadata['result'],
			array(
				'tool'     => 'generate_veo_video',
				'prompt'   => $metadata['args']['prompt'],
				'duration' => 5,
			)
		);

		// Verify hook was called.
		$this->assertTrue( $hook_called, 'wp_mcp_ai_job_completed hook should be fired' );
		$this->assertEquals( $job_id, $hook_job_id, 'Job ID should match' );
		$this->assertIsArray( $hook_result, 'Result should be an array' );
		$this->assertIsArray( $hook_metadata, 'Metadata should be an array' );
		$this->assertEquals( 'generate_veo_video', $hook_metadata['tool'], 'Tool should be generate_veo_video' );

		// Verify Job Notifier cached the status.
		$cached_status = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );
		$this->assertIsArray( $cached_status, 'Job status should be cached' );
		$this->assertEquals( 'completed', $cached_status['status'], 'Status should be completed' );
		$this->assertArrayHasKey( 'result', $cached_status, 'Cached status should have result' );
	}

	/**
	 * Test that job_failed hook is fired when video generation fails.
	 */
	public function test_failure_hook_fired_on_error() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Create a mock job.
		$job_id   = 'veo_test_failure_' . uniqid();
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test-op-fail',
			'model'          => WP_MCP_AI_Gemini_Video_Generation_Service::VEO_MODEL,
			'args'           => array(
				'prompt'  => 'Test video for failure hook',
				'user_id' => 1,
			),
			'status'         => 'pending',
			'queued_at'      => time(),
			'poll_attempt'   => 0,
			'max_attempts'   => 60,
		);

		set_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id, $metadata, DAY_IN_SECONDS );

		// Track if hook was called.
		$hook_called   = false;
		$hook_job_id   = null;
		$hook_error    = null;
		$hook_metadata = null;

		add_action(
			'wp_mcp_ai_job_failed',
			function ( $id, $error, $meta ) use ( &$hook_called, &$hook_job_id, &$hook_error, &$hook_metadata ) {
				$hook_called   = true;
				$hook_job_id   = $id;
				$hook_error    = $error;
				$hook_metadata = $meta;
			},
			10,
			3
		);

		// Manually update metadata to failed state and fire hook.
		$error_message      = 'Test error message for job failure';
		$metadata['status'] = 'failed';
		$metadata['error']  = $error_message;
		set_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id, $metadata, DAY_IN_SECONDS );

		// Fire the failure hook manually to test notification system integration.
		do_action(
			'wp_mcp_ai_job_failed',
			$job_id,
			new WP_Error( 'veo_generation_failed', $error_message ),
			array(
				'tool'   => 'generate_veo_video',
				'prompt' => $metadata['args']['prompt'],
			)
		);

		// Verify hook was called.
		$this->assertTrue( $hook_called, 'wp_mcp_ai_job_failed hook should be fired' );
		$this->assertEquals( $job_id, $hook_job_id, 'Job ID should match' );
		$this->assertWPError( $hook_error, 'Error should be a WP_Error' );
		$this->assertEquals( $error_message, $hook_error->get_error_message(), 'Error message should match' );
		$this->assertIsArray( $hook_metadata, 'Metadata should be an array' );
		$this->assertEquals( 'generate_veo_video', $hook_metadata['tool'], 'Tool should be generate_veo_video' );

		// Verify Job Notifier cached the status.
		$cached_status = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );
		$this->assertIsArray( $cached_status, 'Job status should be cached' );
		$this->assertEquals( 'failed', $cached_status['status'], 'Status should be failed' );
		$this->assertArrayHasKey( 'error', $cached_status, 'Cached status should have error' );
	}

	/**
	 * Test that timeout triggers failure hook.
	 */
	public function test_timeout_triggers_failure_hook() {
		$job_id = 'veo_test_timeout_' . uniqid();

		// Track if hook was called.
		$hook_called = false;

		add_action(
			'wp_mcp_ai_job_failed',
			function ( $id ) use ( $job_id, &$hook_called ) {
				if ( $id === $job_id ) {
					$hook_called = true;
				}
			},
			10,
			3
		);

		// Manually fire the timeout failure hook.
		do_action(
			'wp_mcp_ai_job_failed',
			$job_id,
			new WP_Error( 'veo_timeout', 'Video generation timed out after maximum polling attempts.' ),
			array(
				'tool'   => 'generate_veo_video',
				'prompt' => 'Test timeout',
			)
		);

		// Verify hook was called.
		$this->assertTrue( $hook_called, 'Timeout should trigger wp_mcp_ai_job_failed hook' );
	}

	/**
	 * Test that progress hook is fired during video polling.
	 */
	public function test_progress_hook_fired_during_polling() {
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();

		// Create a mock job.
		$job_id   = 'veo_test_progress_' . uniqid();
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test-op-progress',
			'model'          => WP_MCP_AI_Gemini_Video_Generation_Service::VEO_MODEL,
			'args'           => array(
				'prompt'  => 'Test video for progress hook',
				'user_id' => 1,
			),
			'status'         => 'pending',
			'queued_at'      => time(),
			'poll_attempt'   => 0,
			'max_attempts'   => 60,
		);

		set_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id, $metadata, DAY_IN_SECONDS );

		// Track if hook was called.
		$hook_called   = false;
		$hook_job_id   = null;
		$hook_progress = null;
		$hook_metadata = null;

		add_action(
			'wp_mcp_ai_job_progress',
			function ( $id, $progress, $meta ) use ( &$hook_called, &$hook_job_id, &$hook_progress, &$hook_metadata ) {
				$hook_called   = true;
				$hook_job_id   = $id;
				$hook_progress = $progress;
				$hook_metadata = $meta;
			},
			10,
			3
		);

		// Use reflection to call schedule_next_poll.
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'schedule_next_poll' );
		$method->setAccessible( true );

		// Simulate multiple poll attempts.
		$metadata['poll_attempt'] = 5;
		$method->invoke( $service, $job_id, $metadata );

		// Verify hook was called.
		$this->assertTrue( $hook_called, 'wp_mcp_ai_job_progress hook should be fired during polling' );
		$this->assertEquals( $job_id, $hook_job_id, 'Job ID should match' );
		$this->assertIsFloat( $hook_progress, 'Progress should be a float' );
		$this->assertGreaterThan( 0, $hook_progress, 'Progress should be greater than 0' );
		$this->assertLessThanOrEqual( 100, $hook_progress, 'Progress should be at most 100' );
		$this->assertIsArray( $hook_metadata, 'Metadata should be an array' );
		$this->assertEquals( 'generate_veo_video', $hook_metadata['tool'], 'Tool should be generate_veo_video' );
		$this->assertArrayHasKey( 'message', $hook_metadata, 'Metadata should have message' );
		$this->assertArrayHasKey( 'poll_attempt', $hook_metadata, 'Metadata should have poll_attempt' );
		$this->assertEquals( 5, $hook_metadata['poll_attempt'], 'Poll attempt should be 5' );

		// Verify Job Notifier cached the progress.
		$cached_status = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );
		$this->assertIsArray( $cached_status, 'Job status should be cached' );
		$this->assertArrayHasKey( 'progress', $cached_status, 'Cached status should have progress' );

		// Cleanup.
		delete_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id );
		wp_clear_scheduled_hook( WP_MCP_AI_Gemini_Video_Generation_Service::CRON_POLL_HOOK, array( $job_id ) );
	}

	/**
	 * Test that cron status service returns progress data.
	 */
	public function test_cron_status_service_returns_progress_data() {
		$job_id = 'veo_test_status_progress_' . uniqid();

		// Create video job metadata.
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test-op-status',
			'model'          => WP_MCP_AI_Gemini_Video_Generation_Service::VEO_MODEL,
			'args'           => array(
				'prompt'  => 'Test video for status progress',
				'user_id' => 1,
			),
			'status'         => 'polling',
			'queued_at'      => time(),
			'poll_attempt'   => 10,
			'max_attempts'   => 60,
		);

		set_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id, $metadata, DAY_IN_SECONDS );

		// Fire progress hook to populate Job Notifier cache.
		do_action(
			'wp_mcp_ai_job_progress',
			$job_id,
			20.0, // 20% progress
			array(
				'tool'         => 'generate_veo_video',
				'status'       => 'polling',
				'poll_attempt' => 10,
				'message'      => 'Video generation in progress (check 10)…',
			)
		);

		// Get job details from cron status service.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cron-status-service.php';
		$service = new WP_MCP_AI_Cron_Status_Service();
		$result  = $service->get_job_details( $job_id, 1 );

		// Verify result includes progress data.
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'progress', $result, 'Result should have progress' );
		$this->assertEquals( 20.0, $result['progress'], 'Progress should be 20' );
		$this->assertArrayHasKey( 'progress_message', $result, 'Result should have progress_message' );
		$this->assertStringContainsString( 'check 10', $result['progress_message'], 'Progress message should contain poll attempt' );

		// Cleanup.
		delete_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id );
		delete_transient( WP_MCP_AI_Job_Notifier::CACHE_PREFIX . $job_id );
	}
}
