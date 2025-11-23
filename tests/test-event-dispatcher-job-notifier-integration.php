<?php
/**
 * Tests for Event Dispatcher and Job Notifier integration.
 *
 * Verifies that video and async tool job events are properly bridged
 * to the generic job notification system for display in chat client job bars.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Event Dispatcher integration with Job Notifier.
 */
class Test_Event_Dispatcher_Job_Notifier_Integration extends WP_UnitTestCase {
	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required classes.
		if ( ! class_exists( 'WP_MCP_AI_Job_Notifier' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-job-notifier.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Event_Dispatcher_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-event-dispatcher-service.php';
		}

		// Initialize job notifier.
		WP_MCP_AI_Job_Notifier::init();

		// Initialize event dispatcher.
		WP_MCP_AI_Event_Dispatcher_Service::get_instance();
	}

	/**
	 * Test video job queued event bridges to job notifier.
	 */
	public function test_video_job_queued_creates_job_notifier_entry() {
		$job_id = 'veo_' . uniqid( '', true );
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test-operation',
			'args'           => array(
				'prompt'  => 'Test video',
				'user_id' => 1,
			),
		);

		// Simulate video job queued event.
		do_action( 'wp_mcp_ai_video_job_queued', $job_id, $metadata, $metadata['args'] );

		// Verify job notifier received the event via wp_mcp_ai_job_started bridge.
		$cached = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );

		$this->assertIsArray( $cached, 'Job status should be cached in job notifier' );
		$this->assertEquals( $job_id, $cached['job_id'] );
		$this->assertEquals( 'started', $cached['status'] );
	}

	/**
	 * Test video job completed event bridges to job notifier.
	 */
	public function test_video_job_completed_creates_job_notifier_entry() {
		$job_id = 'veo_' . uniqid( '', true );
		$metadata = array(
			'job_id' => $job_id,
			'status' => 'completed',
			'result' => array(
				'attachment_id' => 123,
				'url'           => 'https://example.com/video.mp4',
				'prompt'        => 'Test video',
			),
			'args'   => array(
				'user_id' => 1,
			),
		);

		// Simulate video job completed event.
		do_action( 'wp_mcp_ai_video_job_completed', $job_id, $metadata, 'completed' );

		// Verify job notifier received the event via wp_mcp_ai_job_completed bridge.
		$cached = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );

		$this->assertIsArray( $cached, 'Job status should be cached in job notifier' );
		$this->assertEquals( $job_id, $cached['job_id'] );
		$this->assertEquals( 'completed', $cached['status'] );
		$this->assertArrayHasKey( 'result', $cached );
		$this->assertEquals( 123, $cached['result']['attachment_id'] );
	}

	/**
	 * Test video job failed event bridges to job notifier.
	 */
	public function test_video_job_failed_creates_job_notifier_entry() {
		$job_id = 'veo_' . uniqid( '', true );
		$metadata = array(
			'job_id' => $job_id,
			'status' => 'failed',
			'error'  => 'Video generation failed: API error',
			'args'   => array(
				'user_id' => 1,
			),
		);

		// Simulate video job failed event.
		do_action( 'wp_mcp_ai_video_job_completed', $job_id, $metadata, 'failed' );

		// Verify job notifier received the event via wp_mcp_ai_job_failed bridge.
		$cached = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );

		$this->assertIsArray( $cached, 'Job status should be cached in job notifier' );
		$this->assertEquals( $job_id, $cached['job_id'] );
		$this->assertEquals( 'failed', $cached['status'] );
		$this->assertArrayHasKey( 'error', $cached );
		$this->assertStringContainsString( 'Video generation failed', $cached['error']['message'] );
	}

	/**
	 * Test async job queued event bridges to job notifier.
	 */
	public function test_async_job_queued_creates_job_notifier_entry() {
		$job_id = 'async_tool_' . uniqid( '', true );
		$metadata = array(
			'job_id'    => $job_id,
			'tool_slug' => 'test_tool',
			'context'   => array(
				'user_id' => 1,
			),
		);

		// Simulate async job queued event.
		do_action( 'wp_mcp_ai_async_job_queued', $job_id, $metadata, 'test_tool' );

		// Verify job notifier received the event via wp_mcp_ai_job_started bridge.
		$cached = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );

		$this->assertIsArray( $cached, 'Job status should be cached in job notifier' );
		$this->assertEquals( $job_id, $cached['job_id'] );
		$this->assertEquals( 'started', $cached['status'] );
	}

	/**
	 * Test async job completed event bridges to job notifier.
	 */
	public function test_async_job_completed_creates_job_notifier_entry() {
		$job_id = 'async_tool_' . uniqid( '', true );
		$result = array( 'success' => true, 'data' => 'test result' );
		$metadata = array(
			'job_id'    => $job_id,
			'tool_slug' => 'test_tool',
			'duration'  => 2.5,
			'context'   => array(
				'user_id' => 1,
			),
		);

		// Simulate async job completed event.
		do_action( 'wp_mcp_ai_async_job_completed', $job_id, $metadata, $result, 'test_tool' );

		// Verify job notifier received the event via wp_mcp_ai_job_completed bridge.
		$cached = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );

		$this->assertIsArray( $cached, 'Job status should be cached in job notifier' );
		$this->assertEquals( $job_id, $cached['job_id'] );
		$this->assertEquals( 'completed', $cached['status'] );
		$this->assertArrayHasKey( 'result', $cached );
		$this->assertTrue( $cached['result']['success'] );
	}

	/**
	 * Test async job failed event bridges to job notifier.
	 */
	public function test_async_job_failed_creates_job_notifier_entry() {
		$job_id = 'async_tool_' . uniqid( '', true );
		$error_message = 'Tool execution failed';
		$metadata = array(
			'job_id'    => $job_id,
			'tool_slug' => 'test_tool',
			'context'   => array(
				'user_id' => 1,
			),
		);

		// Simulate async job failed event.
		do_action( 'wp_mcp_ai_async_job_failed', $job_id, $metadata, $error_message, null );

		// Verify job notifier received the event via wp_mcp_ai_job_failed bridge.
		$cached = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );

		$this->assertIsArray( $cached, 'Job status should be cached in job notifier' );
		$this->assertEquals( $job_id, $cached['job_id'] );
		$this->assertEquals( 'failed', $cached['status'] );
		$this->assertArrayHasKey( 'error', $cached );
		$this->assertStringContainsString( 'Tool execution failed', $cached['error']['message'] );
	}

	/**
	 * Test that job IDs with dots (from uniqid) work correctly.
	 */
	public function test_job_id_with_dots_works() {
		// Video job IDs use uniqid with more_entropy which creates dots.
		$job_id = 'veo_69203b5b2388f5.11575461';
		$metadata = array(
			'job_id' => $job_id,
			'args'   => array(
				'user_id' => 1,
			),
		);

		// Simulate video job queued event.
		do_action( 'wp_mcp_ai_video_job_queued', $job_id, $metadata, $metadata['args'] );

		// Verify job notifier handles dots in job ID correctly.
		$cached = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );

		$this->assertIsArray( $cached, 'Job status should be cached with dots in job_id' );
		$this->assertEquals( $job_id, $cached['job_id'] );
	}

	/**
	 * Test complete video generation lifecycle.
	 */
	public function test_complete_video_generation_lifecycle() {
		$job_id = 'veo_' . uniqid( '', true );

		// Step 1: Job queued.
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test-op',
			'args'           => array( 'prompt' => 'Test', 'user_id' => 1 ),
		);
		do_action( 'wp_mcp_ai_video_job_queued', $job_id, $metadata, $metadata['args'] );

		$cached = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );
		$this->assertEquals( 'started', $cached['status'] );

		// Step 2: Job completes.
		$metadata['status'] = 'completed';
		$metadata['result'] = array(
			'attachment_id' => 456,
			'url'           => 'https://example.com/video.mp4',
		);
		do_action( 'wp_mcp_ai_video_job_completed', $job_id, $metadata, 'completed' );

		$cached = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );
		$this->assertEquals( 'completed', $cached['status'] );
		$this->assertEquals( 456, $cached['result']['attachment_id'] );
	}
}
