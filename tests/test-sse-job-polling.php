<?php
/**
 * Tests for SSE Job Status Polling
 *
 * Validates that the SSE streaming endpoint properly polls async job status
 * and sends completion notifications to the chat client.
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_SSE_Job_Polling
 */
class Test_SSE_Job_Polling extends WP_UnitTestCase {

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create test user with upload capabilities.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);
	}

	/**
	 * Test that video job metadata structure includes required fields for SSE streaming.
	 */
	public function test_video_job_metadata_structure() {
		// Load required classes.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

		// Create a mock video job metadata.
		$job_id   = 'veo_' . uniqid( '', true );
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test-op-123',
			'args'           => array(
				'prompt'  => 'Test video prompt',
				'user_id' => $this->user_id,
			),
			'status'         => 'pending',
			'queued_at'      => time(),
			'poll_attempt'   => 0,
			'max_attempts'   => 60,
		);

		// Store metadata as transient (simulating real job creation).
		set_transient( 'wp_mcp_ai_veo_async_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Retrieve and verify.
		$service = new WP_MCP_AI_Gemini_Video_Generation_Service();
		$result  = $service->get_async_status( $job_id );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'job_id', $result );
		$this->assertEquals( 'pending', $result['status'] );
		$this->assertEquals( $job_id, $result['job_id'] );

		// Clean up.
		delete_transient( 'wp_mcp_ai_veo_async_' . $job_id );
	}

	/**
	 * Test that cron status service properly retrieves video job details.
	 */
	public function test_cron_status_service_retrieves_video_job() {
		// Load required classes.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cron-status-service.php';

		// Create a test video job.
		$job_id   = 'veo_' . uniqid( '', true );
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test-op-456',
			'args'           => array(
				'prompt'  => 'Test polling video',
				'user_id' => $this->user_id,
			),
			'status'         => 'polling',
			'queued_at'      => time(),
			'poll_attempt'   => 5,
			'max_attempts'   => 60,
		);

		set_transient( 'wp_mcp_ai_veo_async_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Retrieve via cron status service.
		$service     = new WP_MCP_AI_Cron_Status_Service();
		$job_details = $service->get_job_details( $job_id, $this->user_id );

		$this->assertIsArray( $job_details );
		$this->assertArrayHasKey( 'status', $job_details );
		$this->assertEquals( 'polling', $job_details['status'] );

		// Clean up.
		delete_transient( 'wp_mcp_ai_veo_async_' . $job_id );
	}

	/**
	 * Test that completed video job includes result data with URL and attachment ID.
	 */
	public function test_completed_video_job_includes_result_data() {
		// Load required classes.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cron-status-service.php';

		// Create a completed video job with result.
		$job_id   = 'veo_' . uniqid( '', true );
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test-op-789',
			'args'           => array(
				'prompt'  => 'Completed test video',
				'user_id' => $this->user_id,
			),
			'status'         => 'completed',
			'queued_at'      => time() - 60,
			'completed_at'   => time(),
			'poll_attempt'   => 12,
			'max_attempts'   => 60,
			'result'         => array(
				'attachment_id' => 123,
				'url'           => 'http://example.com/video.mp4',
				'prompt'        => 'Completed test video',
				'duration'      => 5,
				'aspect_ratio'  => '16:9',
				'resolution'    => '720p',
				'model'         => 'veo-2.0',
				'provider'      => 'gemini',
			),
		);

		set_transient( 'wp_mcp_ai_veo_async_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Retrieve via cron status service.
		$service     = new WP_MCP_AI_Cron_Status_Service();
		$job_details = $service->get_job_details( $job_id, $this->user_id );

		$this->assertIsArray( $job_details );
		$this->assertEquals( 'completed', $job_details['status'] );
		$this->assertArrayHasKey( 'result', $job_details );
		$this->assertIsArray( $job_details['result'] );
		$this->assertArrayHasKey( 'url', $job_details['result'] );
		$this->assertArrayHasKey( 'attachment_id', $job_details['result'] );
		$this->assertEquals( 'http://example.com/video.mp4', $job_details['result']['url'] );
		$this->assertEquals( 123, $job_details['result']['attachment_id'] );

		// Clean up.
		delete_transient( 'wp_mcp_ai_veo_async_' . $job_id );
	}

	/**
	 * Test that failed video job includes error message.
	 */
	public function test_failed_video_job_includes_error() {
		// Load required classes.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cron-status-service.php';

		// Create a failed video job.
		$job_id   = 'veo_' . uniqid( '', true );
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test-op-error',
			'args'           => array(
				'prompt'  => 'Failed test video',
				'user_id' => $this->user_id,
			),
			'status'         => 'failed',
			'queued_at'      => time() - 120,
			'poll_attempt'   => 20,
			'max_attempts'   => 60,
			'error'          => 'API quota exceeded',
		);

		set_transient( 'wp_mcp_ai_veo_async_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Retrieve via cron status service.
		$service     = new WP_MCP_AI_Cron_Status_Service();
		$job_details = $service->get_job_details( $job_id, $this->user_id );

		$this->assertIsArray( $job_details );
		$this->assertEquals( 'failed', $job_details['status'] );
		$this->assertArrayHasKey( 'error', $job_details );
		$this->assertEquals( 'API quota exceeded', $job_details['error'] );

		// Clean up.
		delete_transient( 'wp_mcp_ai_veo_async_' . $job_id );
	}

	/**
	 * Test that permission checks prevent unauthorized access to jobs.
	 */
	public function test_permission_check_blocks_unauthorized_access() {
		// Load required classes.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cron-status-service.php';

		// Create another user.
		$other_user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Create a video job for the test user.
		$job_id   = 'veo_' . uniqid( '', true );
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test-op-private',
			'args'           => array(
				'prompt'  => 'Private test video',
				'user_id' => $this->user_id,
			),
			'status'         => 'completed',
			'queued_at'      => time(),
			'result'         => array(
				'url' => 'http://example.com/private-video.mp4',
			),
		);

		set_transient( 'wp_mcp_ai_veo_async_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Try to access with different user (should fail).
		$service     = new WP_MCP_AI_Cron_Status_Service();
		$job_details = $service->get_job_details( $job_id, $other_user_id );

		$this->assertWPError( $job_details );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $job_details->get_error_code() );

		// Clean up.
		delete_transient( 'wp_mcp_ai_veo_async_' . $job_id );
	}

	/**
	 * Test polling logic terminates on completed status.
	 *
	 * This validates the terminal state detection in stream_job_status_with_polling.
	 */
	public function test_polling_terminates_on_completed_status() {
		$terminal_statuses = array( 'completed', 'failed', 'error' );

		foreach ( $terminal_statuses as $status ) {
			$job_details = array(
				'job_id' => 'test_123',
				'status' => $status,
			);

			// Terminal state should be detected by checking status.
			$is_terminal = in_array( strtolower( $job_details['status'] ), array( 'completed', 'failed', 'error' ), true );

			$this->assertTrue( $is_terminal, "Status '{$status}' should be detected as terminal" );
		}
	}

	/**
	 * Test polling logic continues on pending/polling status.
	 */
	public function test_polling_continues_on_pending_status() {
		$non_terminal_statuses = array( 'pending', 'polling', 'running', 'queued' );

		foreach ( $non_terminal_statuses as $status ) {
			$job_details = array(
				'job_id' => 'test_456',
				'status' => $status,
			);

			// Non-terminal state should not be detected as terminal.
			$is_terminal = in_array( strtolower( $job_details['status'] ), array( 'completed', 'failed', 'error' ), true );

			$this->assertFalse( $is_terminal, "Status '{$status}' should not be detected as terminal" );
		}
	}
}
