<?php
/**
 * Tests for Cron Status Service
 *
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

	/**
	 * Test get_status_summary filters by assistant_id for multi-widget isolation.
	 *
	 * When assistant_id is provided, only jobs for that specific assistant should be returned.
	 * Regular cron jobs (which don't have assistant_id) should be excluded.
	 */
	public function test_get_status_summary_filters_by_assistant_id() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';

		$assistant_1 = 100;
		$assistant_2 = 200;

		// Create a regular cron job (no assistant_id - should be excluded when filtering).
		$hook      = 'wp_mcp_ai_test_cron';
		$timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_single_event( $timestamp, $hook, array() );
		WP_MCP_AI_Cron_Manager::record_job( $hook, array(), 'single', $timestamp, $this->user_id );

		// Create an async tool job for assistant 1.
		$async_job_1 = 'async_test_assistant_1';
		set_transient(
			WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $async_job_1,
			array(
				'job_id'    => $async_job_1,
				'tool_slug' => 'test_tool',
				'status'    => 'pending',
				'context'   => array(
					'user_id'      => $this->user_id,
					'assistant_id' => $assistant_1,
				),
			),
			DAY_IN_SECONDS
		);

		// Create a video job for assistant 2.
		$video_job_2 = 'veo_assistant_2';
		set_transient(
			'wp_mcp_ai_veo_async_' . $video_job_2,
			array(
				'job_id'         => $video_job_2,
				'operation_name' => 'operations/test',
				'status'         => 'pending',
				'args'           => array(
					'user_id'      => $this->user_id,
					'assistant_id' => $assistant_2,
				),
			),
			DAY_IN_SECONDS
		);

		// Without assistant filter - should return all jobs (cron + async + video).
		$summary_all = $this->service->get_status_summary( $this->user_id, 10, null );
		$this->assertCount( 3, $summary_all, 'Without filter, all jobs should be returned' );

		// Filter by assistant_1 - should only return async_job_1, not the regular cron or video_job_2.
		$summary_1 = $this->service->get_status_summary( $this->user_id, 10, $assistant_1 );
		$this->assertCount( 1, $summary_1, 'Only assistant 1 job should be returned' );
		$this->assertEquals( $async_job_1, $summary_1[0]['job_id'] );

		// Filter by assistant_2 - should only return video_job_2.
		$summary_2 = $this->service->get_status_summary( $this->user_id, 10, $assistant_2 );
		$this->assertCount( 1, $summary_2, 'Only assistant 2 job should be returned' );
		$this->assertEquals( $video_job_2, $summary_2[0]['job_id'] );

		// Filter by non-existent assistant - should return no jobs.
		$summary_none = $this->service->get_status_summary( $this->user_id, 10, 999 );
		$this->assertCount( 0, $summary_none, 'Non-existent assistant should return no jobs' );

		// Clean up transients.
		delete_transient( WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $async_job_1 );
		delete_transient( 'wp_mcp_ai_veo_async_' . $video_job_2 );
	}

	/**
	 * Test get_status_counts filters by assistant_id for multi-widget isolation.
	 *
	 * When assistant_id is provided, only jobs for that specific assistant should be counted.
	 * Regular cron jobs (which don't have assistant_id) should be excluded.
	 */
	public function test_get_status_counts_filters_by_assistant_id() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';

		$assistant_1 = 100;
		$assistant_2 = 200;

		// Create a regular cron job (no assistant_id - should be excluded when filtering).
		$hook      = 'wp_mcp_ai_test_cron_counts';
		$timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_single_event( $timestamp, $hook, array() );
		WP_MCP_AI_Cron_Manager::record_job( $hook, array(), 'single', $timestamp, $this->user_id );

		// Create an async tool job for assistant 1.
		$async_job_1 = 'async_test_counts_1';
		set_transient(
			WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $async_job_1,
			array(
				'job_id'    => $async_job_1,
				'tool_slug' => 'test_tool',
				'status'    => 'pending',
				'context'   => array(
					'user_id'      => $this->user_id,
					'assistant_id' => $assistant_1,
				),
			),
			DAY_IN_SECONDS
		);

		// Create a completed video job for assistant 1.
		$video_job_1 = 'veo_counts_1';
		set_transient(
			'wp_mcp_ai_veo_async_' . $video_job_1,
			array(
				'job_id'         => $video_job_1,
				'operation_name' => 'operations/test',
				'status'         => 'completed',
				'args'           => array(
					'user_id'      => $this->user_id,
					'assistant_id' => $assistant_1,
				),
			),
			DAY_IN_SECONDS
		);

		// Create an async tool job for assistant 2.
		$async_job_2 = 'async_test_counts_2';
		set_transient(
			WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $async_job_2,
			array(
				'job_id'    => $async_job_2,
				'tool_slug' => 'test_tool',
				'status'    => 'pending',
				'context'   => array(
					'user_id'      => $this->user_id,
					'assistant_id' => $assistant_2,
				),
			),
			DAY_IN_SECONDS
		);

		// Without assistant filter - should count all jobs.
		$counts_all = $this->service->get_status_counts( $this->user_id, null );
		$this->assertEquals( 4, $counts_all['total'], 'Without filter, all jobs should be counted' );
		$this->assertEquals( 3, $counts_all['pending'], 'Without filter, pending jobs should include cron + async jobs' );
		$this->assertEquals( 1, $counts_all['completed'], 'Without filter, completed job should be counted' );

		// Filter by assistant_1 - should only count jobs for assistant 1.
		$counts_1 = $this->service->get_status_counts( $this->user_id, $assistant_1 );
		$this->assertEquals( 2, $counts_1['total'], 'Assistant 1 should have 2 jobs' );
		$this->assertEquals( 1, $counts_1['pending'], 'Assistant 1 should have 1 pending job' );
		$this->assertEquals( 1, $counts_1['completed'], 'Assistant 1 should have 1 completed job' );

		// Filter by assistant_2 - should only count jobs for assistant 2.
		$counts_2 = $this->service->get_status_counts( $this->user_id, $assistant_2 );
		$this->assertEquals( 1, $counts_2['total'], 'Assistant 2 should have 1 job' );
		$this->assertEquals( 1, $counts_2['pending'], 'Assistant 2 should have 1 pending job' );
		$this->assertEquals( 0, $counts_2['completed'], 'Assistant 2 should have 0 completed jobs' );

		// Filter by non-existent assistant - should return zero counts.
		$counts_none = $this->service->get_status_counts( $this->user_id, 999 );
		$this->assertEquals( 0, $counts_none['total'], 'Non-existent assistant should have 0 jobs' );

		// Clean up transients.
		delete_transient( WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $async_job_1 );
		delete_transient( WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $async_job_2 );
		delete_transient( 'wp_mcp_ai_veo_async_' . $video_job_1 );
	}

	/**
	 * Test that polling status is counted as running, not completed.
	 */
	public function test_polling_status_counted_as_running() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

		// Create a video generation job with 'polling' status.
		$job_id   = 'veo_polling_test';
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test',
			'args'           => array(
				'prompt'  => 'Test video prompt',
				'user_id' => $this->user_id,
			),
			'status'         => 'polling',
			'queued_at'      => time() - 30,
			'poll_attempt'   => 3,
			'max_attempts'   => 60,
		);

		set_transient( 'wp_mcp_ai_veo_async_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Get status counts.
		$counts = $this->service->get_status_counts( $this->user_id );

		// The polling job should be counted as running, not completed.
		$this->assertEquals( 1, $counts['running'] );
		$this->assertEquals( 0, $counts['completed'] );
		$this->assertEquals( 1, $counts['total'] );

		// Clean up transient.
		delete_transient( 'wp_mcp_ai_veo_async_' . $job_id );
	}

	/**
	 * Test that async tool job results are included in status summary for agentic workflow.
	 */
	public function test_async_tool_job_includes_result_in_status_summary() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';

		// Create a completed async tool job with result data.
		$job_id = 'async_test_result';
		$result = array(
			'text'   => 'Tool execution completed successfully',
			'data'   => array(
				'file_id' => 'file-abc123',
				'url'     => 'https://example.com/file.pdf',
			),
			'status' => 'success',
		);

		$metadata = array(
			'job_id'       => $job_id,
			'tool_slug'    => 'test_tool',
			'arguments'    => array( 'param' => 'value' ),
			'context'      => array( 'user_id' => $this->user_id ),
			'status'       => 'completed',
			'queued_at'    => time() - 120,
			'started_at'   => time() - 60,
			'completed_at' => time() - 10,
			'result'       => $result,
			'error'        => null,
			'duration'     => 50.5,
		);

		set_transient( WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $job_id, $metadata, DAY_IN_SECONDS );

		// Get status summary.
		$summary = $this->service->get_status_summary( $this->user_id, 10 );

		$this->assertNotEmpty( $summary );
		$this->assertCount( 1, $summary );

		$job = $summary[0];
		$this->assertEquals( $job_id, $job['job_id'] );
		$this->assertEquals( 'test_tool', $job['tool_slug'] );
		$this->assertEquals( 'completed', $job['status'] );
		$this->assertEquals( 'async_tool', $job['type'] );

		// Verify timing information.
		$this->assertArrayHasKey( 'completed_at', $job );
		$this->assertArrayHasKey( 'timestamp', $job['completed_at'] );
		$this->assertArrayHasKey( 'relative', $job['completed_at'] );

		// Verify result data is included for agentic workflow.
		$this->assertArrayHasKey( 'has_result', $job );
		$this->assertTrue( $job['has_result'] );
		$this->assertArrayHasKey( 'result', $job );
		$this->assertIsArray( $job['result'] );
		$this->assertEquals( 'Tool execution completed successfully', $job['result']['text'] );
		$this->assertEquals( 'file-abc123', $job['result']['data']['file_id'] );
		$this->assertEquals( 'https://example.com/file.pdf', $job['result']['data']['url'] );

		// Verify duration.
		$this->assertArrayHasKey( 'duration', $job );
		$this->assertEquals( 50.5, $job['duration'] );

		// Clean up transient.
		delete_transient( WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $job_id );
	}

	/**
	 * Test that async tool job without result doesn't include result field.
	 */
	public function test_async_tool_job_without_result() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';

		// Create a pending async tool job without result.
		$job_id   = 'async_test_pending';
		$metadata = array(
			'job_id'       => $job_id,
			'tool_slug'    => 'test_tool',
			'arguments'    => array( 'param' => 'value' ),
			'context'      => array( 'user_id' => $this->user_id ),
			'status'       => 'pending',
			'queued_at'    => time() - 10,
			'started_at'   => null,
			'completed_at' => null,
			'result'       => null,
			'error'        => null,
		);

		set_transient( WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $job_id, $metadata, DAY_IN_SECONDS );

		// Get status summary.
		$summary = $this->service->get_status_summary( $this->user_id, 10 );

		$this->assertNotEmpty( $summary );
		$this->assertCount( 1, $summary );

		$job = $summary[0];
		$this->assertEquals( $job_id, $job['job_id'] );
		$this->assertEquals( 'pending', $job['status'] );

		// Verify no result data for pending job.
		$this->assertArrayNotHasKey( 'has_result', $job );
		$this->assertArrayNotHasKey( 'result', $job );

		// Clean up transient.
		delete_transient( WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $job_id );
	}

	/**
	 * Test that completed video generation jobs include result data in status summary.
	 */
	public function test_video_generation_job_includes_result_in_status_summary() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';

		// Create a completed video generation job with result data.
		$job_id = 'veo_completed_test';
		$result = array(
			'attachment_id' => 456,
			'url'           => 'https://example.com/generated-video.mp4',
			'prompt'        => 'A beautiful sunset over the ocean',
			'duration'      => 5,
			'aspect_ratio'  => '16:9',
			'resolution'    => '720p',
			'model'         => 'veo-3.1-generate-preview',
			'provider'      => 'gemini',
		);

		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/completed',
			'args'           => array(
				'prompt'  => 'A beautiful sunset over the ocean',
				'user_id' => $this->user_id,
			),
			'status'         => 'completed',
			'queued_at'      => time() - 120,
			'poll_attempt'   => 10,
			'max_attempts'   => 60,
			'result'         => $result,
		);

		set_transient( 'wp_mcp_ai_veo_async_' . $job_id, $metadata, DAY_IN_SECONDS );

		// Get status summary.
		$summary = $this->service->get_status_summary( $this->user_id, 10 );

		$this->assertNotEmpty( $summary );
		$this->assertCount( 1, $summary );

		$job = $summary[0];
		$this->assertEquals( $job_id, $job['job_id'] );
		$this->assertEquals( WP_MCP_AI_Cron_Status_Service::VIDEO_GENERATION_TOOL_SLUG, $job['tool_slug'] );
		$this->assertEquals( 'completed', $job['status'] );
		$this->assertEquals( WP_MCP_AI_Cron_Status_Service::VIDEO_GENERATION_JOB_TYPE, $job['type'] );

		// Verify result data is included for agentic workflow (key requirement for veo).
		$this->assertArrayHasKey( 'has_result', $job );
		$this->assertTrue( $job['has_result'] );
		$this->assertArrayHasKey( 'result', $job );
		$this->assertIsArray( $job['result'] );

		// Verify complete video result structure.
		$this->assertEquals( 456, $job['result']['attachment_id'] );
		$this->assertEquals( 'https://example.com/generated-video.mp4', $job['result']['url'] );
		$this->assertEquals( 'A beautiful sunset over the ocean', $job['result']['prompt'] );
		$this->assertEquals( 5, $job['result']['duration'] );
		$this->assertEquals( '16:9', $job['result']['aspect_ratio'] );
		$this->assertEquals( '720p', $job['result']['resolution'] );
		$this->assertEquals( 'veo-3.1-generate-preview', $job['result']['model'] );
		$this->assertEquals( 'gemini', $job['result']['provider'] );

		// Clean up transient.
		delete_transient( 'wp_mcp_ai_veo_async_' . $job_id );
	}
}
