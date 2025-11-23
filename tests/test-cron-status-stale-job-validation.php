<?php
/**
 * Tests for Cron Status Service - Stale Job Validation
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_Cron_Status_Stale_Job_Validation
 */
class Test_Cron_Status_Stale_Job_Validation extends WP_UnitTestCase {

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
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cron-status-service.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';

		$this->service = new WP_MCP_AI_Cron_Status_Service();
		$this->user_id = $this->factory->user->create();

		// Clear any existing cron jobs and transients.
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );
		$this->clear_async_transients();
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		// Clean up cron jobs and transients.
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );
		$this->clear_async_transients();

		parent::tearDown();
	}

	/**
	 * Clear async job transients.
	 */
	protected function clear_async_transients() {
		global $wpdb;
		
		// Clear async tool transients.
		$wpdb->query(
			"DELETE FROM {$wpdb->options} 
			WHERE option_name LIKE '_transient_wp_mcp_ai_async_meta_%' 
			OR option_name LIKE '_transient_timeout_wp_mcp_ai_async_meta_%'"
		);
		
		// Clear video generation transients.
		$wpdb->query(
			"DELETE FROM {$wpdb->options} 
			WHERE option_name LIKE '_transient_wp_mcp_ai_veo_async_%' 
			OR option_name LIKE '_transient_timeout_wp_mcp_ai_veo_async_%'"
		);
	}

	/**
	 * Test that async job with active cron event shows as running.
	 */
	public function test_async_job_with_cron_event_shows_running() {
		$job_id = 'async_test_' . wp_generate_uuid4();
		
		// Create async job metadata.
		$metadata = array(
			'job_id'     => $job_id,
			'tool_slug'  => 'test_tool',
			'status'     => 'running',
			'started_at' => time(),
			'context'    => array(
				'user_id' => $this->user_id,
			),
		);
		
		set_transient( 'wp_mcp_ai_async_meta_' . $job_id, $metadata, HOUR_IN_SECONDS );
		
		// Schedule corresponding cron event.
		wp_schedule_single_event( time(), 'wp_mcp_ai_async_tool_execution', array( $job_id ) );
		
		// Get status counts.
		$counts = $this->service->get_status_counts( $this->user_id, null, 'chat' );
		
		// Job should show as running because cron event exists.
		$this->assertEquals( 1, $counts['running'] );
		$this->assertEquals( 0, $counts['failed'] );
		$this->assertEquals( 1, $counts['total'] );
	}

	/**
	 * Test that stale async job without cron event shows as failed after 10 minutes.
	 */
	public function test_stale_async_job_without_cron_event_shows_failed() {
		$job_id = 'async_test_' . wp_generate_uuid4();
		
		// Create async job metadata from 15 minutes ago (> 10 minute threshold).
		$metadata = array(
			'job_id'     => $job_id,
			'tool_slug'  => 'test_tool',
			'status'     => 'running',
			'started_at' => time() - ( 15 * MINUTE_IN_SECONDS ),
			'context'    => array(
				'user_id' => $this->user_id,
			),
		);
		
		set_transient( 'wp_mcp_ai_async_meta_' . $job_id, $metadata, HOUR_IN_SECONDS );
		
		// No cron event scheduled (simulating stale job).
		
		// Get status counts.
		$counts = $this->service->get_status_counts( $this->user_id, null, 'chat' );
		
		// Job should show as failed because cron event doesn't exist and it's stale.
		$this->assertEquals( 0, $counts['running'] );
		$this->assertEquals( 1, $counts['failed'] );
		$this->assertEquals( 1, $counts['total'] );
	}

	/**
	 * Test that recent async job without cron event still shows as running.
	 */
	public function test_recent_async_job_without_cron_event_shows_running() {
		$job_id = 'async_test_' . wp_generate_uuid4();
		
		// Create async job metadata from 5 minutes ago (< 10 minute threshold).
		$metadata = array(
			'job_id'     => $job_id,
			'tool_slug'  => 'test_tool',
			'status'     => 'running',
			'started_at' => time() - ( 5 * MINUTE_IN_SECONDS ),
			'context'    => array(
				'user_id' => $this->user_id,
			),
		);
		
		set_transient( 'wp_mcp_ai_async_meta_' . $job_id, $metadata, HOUR_IN_SECONDS );
		
		// No cron event scheduled (but job is recent, not stale yet).
		
		// Get status counts.
		$counts = $this->service->get_status_counts( $this->user_id, null, 'chat' );
		
		// Job should still show as running because it's recent (< 10 minutes).
		$this->assertEquals( 1, $counts['running'] );
		$this->assertEquals( 0, $counts['failed'] );
		$this->assertEquals( 1, $counts['total'] );
	}

	/**
	 * Test that completed async job shows as completed.
	 */
	public function test_completed_async_job_shows_completed() {
		$job_id = 'async_test_' . wp_generate_uuid4();
		
		// Create completed async job metadata.
		$metadata = array(
			'job_id'       => $job_id,
			'tool_slug'    => 'test_tool',
			'status'       => 'completed',
			'started_at'   => time() - ( 5 * MINUTE_IN_SECONDS ),
			'completed_at' => time(),
			'result'       => array( 'success' => true ),
			'context'      => array(
				'user_id' => $this->user_id,
			),
		);
		
		set_transient( 'wp_mcp_ai_async_meta_' . $job_id, $metadata, HOUR_IN_SECONDS );
		
		// No cron event (single events are removed after execution).
		
		// Get status counts.
		$counts = $this->service->get_status_counts( $this->user_id, null, 'chat' );
		
		// Job should show as completed, not failed (even though cron event doesn't exist).
		$this->assertEquals( 0, $counts['running'] );
		$this->assertEquals( 0, $counts['failed'] );
		$this->assertEquals( 1, $counts['completed'] );
		$this->assertEquals( 1, $counts['total'] );
	}

	/**
	 * Test that video generation job with polling status and cron event shows as running.
	 */
	public function test_video_job_with_cron_event_shows_running() {
		$job_id = 'veo_test_' . wp_generate_uuid4();
		
		// Create video job metadata.
		$metadata = array(
			'job_id'    => $job_id,
			'tool_slug' => 'generate_veo_video',
			'type'      => 'video_generation',
			'status'    => 'polling',
			'queued_at' => time(),
			'args'      => array(
				'user_id' => $this->user_id,
			),
		);
		
		set_transient( 'wp_mcp_ai_veo_async_' . $job_id, $metadata, HOUR_IN_SECONDS );
		
		// Schedule corresponding video polling cron event.
		wp_schedule_single_event( time() + 30, 'wp_mcp_ai_poll_veo_video', array( $job_id ) );
		
		// Get status counts.
		$counts = $this->service->get_status_counts( $this->user_id, null, 'chat' );
		
		// Job should show as running because cron event exists.
		$this->assertEquals( 1, $counts['running'] );
		$this->assertEquals( 0, $counts['failed'] );
		$this->assertEquals( 1, $counts['total'] );
	}

	/**
	 * Test that stale video job without cron event shows as failed.
	 */
	public function test_stale_video_job_without_cron_event_shows_failed() {
		$job_id = 'veo_test_' . wp_generate_uuid4();
		
		// Create video job metadata from 15 minutes ago.
		$metadata = array(
			'job_id'    => $job_id,
			'tool_slug' => 'generate_veo_video',
			'type'      => 'video_generation',
			'status'    => 'polling',
			'queued_at' => time() - ( 15 * MINUTE_IN_SECONDS ),
			'args'      => array(
				'user_id' => $this->user_id,
			),
		);
		
		set_transient( 'wp_mcp_ai_veo_async_' . $job_id, $metadata, HOUR_IN_SECONDS );
		
		// No cron event (simulating stale video job).
		
		// Get status counts.
		$counts = $this->service->get_status_counts( $this->user_id, null, 'chat' );
		
		// Job should show as failed.
		$this->assertEquals( 0, $counts['running'] );
		$this->assertEquals( 1, $counts['failed'] );
		$this->assertEquals( 1, $counts['total'] );
	}

	/**
	 * Test that completed video job shows as completed.
	 */
	public function test_completed_video_job_shows_completed() {
		$job_id = 'veo_test_' . wp_generate_uuid4();
		
		// Create completed video job metadata.
		$metadata = array(
			'job_id'    => $job_id,
			'tool_slug' => 'generate_veo_video',
			'type'      => 'video_generation',
			'status'    => 'completed',
			'queued_at' => time() - ( 5 * MINUTE_IN_SECONDS ),
			'result'    => array(
				'attachment_id' => 123,
				'url'           => 'https://example.com/video.mp4',
			),
			'args'      => array(
				'user_id' => $this->user_id,
			),
		);
		
		set_transient( 'wp_mcp_ai_veo_async_' . $job_id, $metadata, HOUR_IN_SECONDS );
		
		// No cron event (removed after completion).
		
		// Get status counts.
		$counts = $this->service->get_status_counts( $this->user_id, null, 'chat' );
		
		// Job should show as completed.
		$this->assertEquals( 0, $counts['running'] );
		$this->assertEquals( 0, $counts['failed'] );
		$this->assertEquals( 1, $counts['completed'] );
		$this->assertEquals( 1, $counts['total'] );
	}
}
