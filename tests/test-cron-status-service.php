<?php
/**
 * Tests for Cron Status Service
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_Cron_Status_Service
 */
class Test_Cron_Status_Service extends WP_UnitTestCase {

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
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cron-status-service.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';

		$this->service = new WP_MCP_AI_Cron_Status_Service();

		// Create test users.
		$this->user_id  = $this->factory->user->create();
		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Clear any existing cron jobs.
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		// Clean up cron jobs.
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );

		parent::tearDown();
	}

	/**
	 * Test get_status_summary with no jobs.
	 */
	public function test_get_status_summary_with_no_jobs() {
		$summary = $this->service->get_status_summary( $this->user_id, 10 );

		$this->assertIsArray( $summary );
		$this->assertEmpty( $summary );
	}

	/**
	 * Test get_status_summary with pending job.
	 */
	public function test_get_status_summary_with_pending_job() {
		// Create a pending job.
		$hook      = 'wp_mcp_ai_test_pending';
		$args      = array( 'test' => 'pending' );
		$timestamp = time() + HOUR_IN_SECONDS;

		wp_schedule_single_event( $timestamp, $hook, $args );
		WP_MCP_AI_Cron_Manager::record_job( $hook, $args, 'single', $timestamp, $this->user_id );

		// Get status summary.
		$summary = $this->service->get_status_summary( $this->user_id, 10 );

		$this->assertNotEmpty( $summary );
		$this->assertCount( 1, $summary );
		$this->assertEquals( 'pending', $summary[0]['status'] );
		$this->assertEquals( $hook, $summary[0]['hook'] );
		$this->assertArrayHasKey( 'next_run', $summary[0] );
		$this->assertArrayHasKey( 'timestamp', $summary[0]['next_run'] );
		$this->assertArrayHasKey( 'relative', $summary[0]['next_run'] );
	}

	/**
	 * Test get_status_summary with completed job.
	 */
	public function test_get_status_summary_with_completed_job() {
		// Create a job that already ran.
		$hook      = 'wp_mcp_ai_test_completed';
		$args      = array( 'test' => 'completed' );
		$timestamp = time() - HOUR_IN_SECONDS;

		// Record the job (but don't schedule it in WP-Cron).
		WP_MCP_AI_Cron_Manager::record_job( $hook, $args, 'single', $timestamp, $this->user_id );

		// Get status summary.
		$summary = $this->service->get_status_summary( $this->user_id, 10 );

		$this->assertNotEmpty( $summary );
		$this->assertCount( 1, $summary );
		$this->assertEquals( 'completed', $summary[0]['status'] );
		$this->assertEquals( $hook, $summary[0]['hook'] );
		$this->assertArrayHasKey( 'completed_at', $summary[0] );
		$this->assertArrayHasKey( 'timestamp', $summary[0]['completed_at'] );
		$this->assertArrayHasKey( 'relative', $summary[0]['completed_at'] );
	}

	/**
	 * Test get_status_counts.
	 */
	public function test_get_status_counts() {
		// Create pending and completed jobs.
		$pending_hook      = 'wp_mcp_ai_test_pending';
		$pending_timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_single_event( $pending_timestamp, $pending_hook, array() );
		WP_MCP_AI_Cron_Manager::record_job( $pending_hook, array(), 'single', $pending_timestamp, $this->user_id );

		$completed_hook      = 'wp_mcp_ai_test_completed';
		$completed_timestamp = time() - HOUR_IN_SECONDS;
		WP_MCP_AI_Cron_Manager::record_job( $completed_hook, array(), 'single', $completed_timestamp, $this->user_id );

		// Get counts.
		$counts = $this->service->get_status_counts( $this->user_id );

		$this->assertIsArray( $counts );
		$this->assertEquals( 1, $counts['pending'] );
		$this->assertEquals( 1, $counts['completed'] );
		$this->assertEquals( 2, $counts['total'] );
	}

	/**
	 * Test user filtering.
	 */
	public function test_user_filtering() {
		// Create a job for user 1.
		$hook1     = 'wp_mcp_ai_test_user1';
		$timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_single_event( $timestamp, $hook1, array() );
		WP_MCP_AI_Cron_Manager::record_job( $hook1, array(), 'single', $timestamp, $this->user_id );

		// Create a job for another user.
		$other_user = $this->factory->user->create();
		$hook2      = 'wp_mcp_ai_test_user2';
		wp_schedule_single_event( $timestamp, $hook2, array() );
		WP_MCP_AI_Cron_Manager::record_job( $hook2, array(), 'single', $timestamp, $other_user );

		// User 1 should only see their own job.
		$summary = $this->service->get_status_summary( $this->user_id, 10 );
		$this->assertCount( 1, $summary );
		$this->assertEquals( $hook1, $summary[0]['hook'] );

		// Admin should see both jobs.
		$admin_summary = $this->service->get_status_summary( $this->admin_id, 10 );
		$this->assertCount( 2, $admin_summary );
	}

	/**
	 * Test limit parameter.
	 */
	public function test_limit_parameter() {
		// Create 5 jobs.
		for ( $i = 1; $i <= 5; $i++ ) {
			$hook      = 'wp_mcp_ai_test_limit_' . $i;
			$timestamp = time() + ( $i * HOUR_IN_SECONDS );
			wp_schedule_single_event( $timestamp, $hook, array() );
			WP_MCP_AI_Cron_Manager::record_job( $hook, array(), 'single', $timestamp, $this->user_id );
		}

		// Get with limit of 3.
		$summary = $this->service->get_status_summary( $this->user_id, 3 );
		$this->assertCount( 3, $summary );

		// Get with limit of 10 (should return all 5).
		$summary = $this->service->get_status_summary( $this->user_id, 10 );
		$this->assertCount( 5, $summary );
	}

	/**
	 * Test get_job_details with video generation job.
	 */
	public function test_get_job_details_with_video_generation_job() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

		// Create a mock video generation job in transient.
		$job_id   = 'veo_test123';
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test',
			'args'           => array(
				'prompt'  => 'Test video prompt',
				'user_id' => $this->user_id,
			),
			'status'         => 'completed',
			'queued_at'      => time() - 60,
			'poll_attempt'   => 5,
			'max_attempts'   => 60,
			'result'         => array(
				'attachment_id' => 123,
				'url'           => 'https://example.com/video.mp4',
				'prompt'        => 'Test video prompt',
				'duration'      => 5,
				'aspect_ratio'  => '16:9',
				'resolution'    => '720p',
				'model'         => 'veo-3.1-generate-preview',
				'provider'      => 'gemini',
			),
		);

		set_transient( 'wp_mcp_ai_veo_async_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Get job details as the owner.
		$details = $this->service->get_job_details( $job_id, $this->user_id );

		$this->assertIsArray( $details );
		$this->assertNotInstanceOf( 'WP_Error', $details );
		$this->assertEquals( $job_id, $details['job_id'] );
		$this->assertEquals( 'completed', $details['status'] );
		$this->assertArrayHasKey( 'result', $details );
		$this->assertEquals( 123, $details['result']['attachment_id'] );
		$this->assertEquals( 'https://example.com/video.mp4', $details['result']['url'] );
		$this->assertArrayHasKey( 'admin_url', $details );

		// Clean up transient.
		delete_transient( 'wp_mcp_ai_veo_async_' . $job_id );
	}

	/**
	 * Test get_job_details with video generation job - permission check.
	 */
	public function test_get_job_details_video_generation_permission() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

		$other_user = $this->factory->user->create();

		// Create a video generation job owned by another user.
		$job_id   = 'veo_test456';
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test',
			'args'           => array(
				'prompt'  => 'Test video prompt',
				'user_id' => $other_user,
			),
			'status'         => 'completed',
			'queued_at'      => time() - 60,
			'poll_attempt'   => 5,
			'max_attempts'   => 60,
			'result'         => array(
				'attachment_id' => 123,
				'url'           => 'https://example.com/video.mp4',
			),
		);

		set_transient( 'wp_mcp_ai_veo_async_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Try to get job details as a different user - should be forbidden.
		$details = $this->service->get_job_details( $job_id, $this->user_id );

		$this->assertInstanceOf( 'WP_Error', $details );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $details->get_error_code() );

		// Admin should be able to access.
		$admin_details = $this->service->get_job_details( $job_id, $this->admin_id );
		$this->assertIsArray( $admin_details );
		$this->assertNotInstanceOf( 'WP_Error', $admin_details );

		// Clean up transient.
		delete_transient( 'wp_mcp_ai_veo_async_' . $job_id );
	}

	/**
	 * Test get_status_summary includes video generation jobs.
	 */
	public function test_get_status_summary_includes_video_jobs() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

		// Create a regular cron job.
		$hook      = 'wp_mcp_ai_test_regular';
		$timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_single_event( $timestamp, $hook, array() );
		WP_MCP_AI_Cron_Manager::record_job( $hook, array(), 'single', $timestamp, $this->user_id );

		// Create a video generation job.
		$job_id   = 'veo_test789';
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test',
			'args'           => array(
				'prompt'  => 'Test video prompt',
				'user_id' => $this->user_id,
			),
			'status'         => 'pending',
			'queued_at'      => time(),
			'poll_attempt'   => 0,
			'max_attempts'   => 60,
		);

		set_transient( 'wp_mcp_ai_veo_async_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Get status summary.
		$summary = $this->service->get_status_summary( $this->user_id, 10 );

		// Should have 2 jobs: 1 regular + 1 video.
		$this->assertCount( 2, $summary );

		// Find the video job.
		$video_job = null;
		foreach ( $summary as $job ) {
			if ( isset( $job['tool_slug'] ) && WP_MCP_AI_Cron_Status_Service::VIDEO_GENERATION_TOOL_SLUG === $job['tool_slug'] ) {
				$video_job = $job;
				break;
			}
		}

		$this->assertNotNull( $video_job );
		$this->assertEquals( 'pending', $video_job['status'] );
		$this->assertEquals( WP_MCP_AI_Cron_Status_Service::VIDEO_GENERATION_JOB_TYPE, $video_job['type'] );

		// Clean up transient.
		delete_transient( 'wp_mcp_ai_veo_async_' . $job_id );
	}
}
