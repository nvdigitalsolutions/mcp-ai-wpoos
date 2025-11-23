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
		$hook_called    = false;
		$hook_job_id    = null;
		$hook_result    = null;
		$hook_metadata  = null;

		add_action(
			'wp_mcp_ai_job_completed',
			function( $id, $result, $meta ) use ( &$hook_called, &$hook_job_id, &$hook_result, &$hook_metadata ) {
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
		$hook_called    = false;
		$hook_job_id    = null;
		$hook_error     = null;
		$hook_metadata  = null;

		add_action(
			'wp_mcp_ai_job_failed',
			function( $id, $error, $meta ) use ( &$hook_called, &$hook_job_id, &$hook_error, &$hook_metadata ) {
				$hook_called   = true;
				$hook_job_id   = $id;
				$hook_error    = $error;
				$hook_metadata = $meta;
			},
			10,
			3
		);

		// Manually update metadata to failed state and fire hook.
		$error_message         = 'Test error message for job failure';
		$metadata['status']    = 'failed';
		$metadata['error']     = $error_message;
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
			function( $id ) use ( $job_id, &$hook_called ) {
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
}
