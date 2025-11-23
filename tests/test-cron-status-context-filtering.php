<?php
/**
 * Tests for Cron Status Service Context Filtering
 *
 * Tests that internal async tool jobs are hidden from chat clients
 * but user-created jobs remain visible.
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_Cron_Status_Context_Filtering
 */
class Test_Cron_Status_Context_Filtering extends WP_UnitTestCase {

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

		// Create test user.
		$this->user_id = $this->factory->user->create();

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
	 * Test that internal async tool jobs are hidden in chat context.
	 */
	public function test_internal_async_jobs_hidden_in_chat_context() {
		// Create an internal async tool execution job.
		$internal_hook = 'wp_mcp_ai_async_tool_execution';
		$timestamp     = time() + HOUR_IN_SECONDS;
		$args          = array( 'async_12345' );

		wp_schedule_single_event( $timestamp, $internal_hook, $args );
		WP_MCP_AI_Cron_Manager::record_job( $internal_hook, $args, 'single', $timestamp, $this->user_id );

		// Get status summary with chat context.
		$chat_summary = $this->service->get_status_summary( $this->user_id, 10, null, 'chat' );

		// Internal job should be hidden in chat context.
		$this->assertEmpty( $chat_summary, 'Internal async job should be hidden in chat context' );

		// Get status summary with admin context.
		$admin_summary = $this->service->get_status_summary( $this->user_id, 10, null, 'admin' );

		// Internal job should be visible in admin context.
		$this->assertNotEmpty( $admin_summary, 'Internal async job should be visible in admin context' );
		$this->assertCount( 1, $admin_summary );
		$this->assertEquals( $internal_hook, $admin_summary[0]['hook'] );
	}

	/**
	 * Test that user-created cron jobs are visible in both contexts.
	 */
	public function test_user_created_jobs_visible_in_both_contexts() {
		// Create a user-created cron job (via create_cron_job tool).
		$user_hook = 'my_custom_hook';
		$timestamp = time() + HOUR_IN_SECONDS;
		$args      = array( 'custom' => 'data' );

		wp_schedule_single_event( $timestamp, $user_hook, $args );
		WP_MCP_AI_Cron_Manager::record_job( $user_hook, $args, 'single', $timestamp, $this->user_id );

		// Get status summary with chat context.
		$chat_summary = $this->service->get_status_summary( $this->user_id, 10, null, 'chat' );

		// User job should be visible in chat context.
		$this->assertNotEmpty( $chat_summary, 'User-created job should be visible in chat context' );
		$this->assertCount( 1, $chat_summary );
		$this->assertEquals( $user_hook, $chat_summary[0]['hook'] );

		// Get status summary with admin context.
		$admin_summary = $this->service->get_status_summary( $this->user_id, 10, null, 'admin' );

		// User job should be visible in admin context.
		$this->assertNotEmpty( $admin_summary, 'User-created job should be visible in admin context' );
		$this->assertCount( 1, $admin_summary );
		$this->assertEquals( $user_hook, $admin_summary[0]['hook'] );
	}

	/**
	 * Test that video polling jobs are visible in chat context.
	 */
	public function test_video_polling_jobs_visible_in_chat_context() {
		// Create a video polling job (user-initiated video generation).
		$video_hook = 'wp_mcp_ai_poll_veo_video';
		$timestamp  = time() + HOUR_IN_SECONDS;
		$args       = array( 'veo_12345' );

		wp_schedule_single_event( $timestamp, $video_hook, $args );
		WP_MCP_AI_Cron_Manager::record_job( $video_hook, $args, 'single', $timestamp, $this->user_id );

		// Get status summary with chat context.
		$chat_summary = $this->service->get_status_summary( $this->user_id, 10, null, 'chat' );

		// Video polling job should be visible in chat context (user-initiated).
		$this->assertNotEmpty( $chat_summary, 'Video polling job should be visible in chat context' );
		$this->assertCount( 1, $chat_summary );
		$this->assertEquals( $video_hook, $chat_summary[0]['hook'] );

		// Get status summary with admin context.
		$admin_summary = $this->service->get_status_summary( $this->user_id, 10, null, 'admin' );

		// Video polling job should be visible in admin context.
		$this->assertNotEmpty( $admin_summary, 'Video polling job should be visible in admin context' );
		$this->assertCount( 1, $admin_summary );
		$this->assertEquals( $video_hook, $admin_summary[0]['hook'] );
	}

	/**
	 * Test mixed jobs - internal and user-created.
	 */
	public function test_mixed_jobs_filtering() {
		// Create internal async job.
		$internal_hook = 'wp_mcp_ai_async_tool_execution';
		$timestamp1    = time() + HOUR_IN_SECONDS;
		wp_schedule_single_event( $timestamp1, $internal_hook, array( 'async_1' ) );
		WP_MCP_AI_Cron_Manager::record_job( $internal_hook, array( 'async_1' ), 'single', $timestamp1, $this->user_id );

		// Create user job.
		$user_hook  = 'my_notification_hook';
		$timestamp2 = time() + ( 2 * HOUR_IN_SECONDS );
		wp_schedule_single_event( $timestamp2, $user_hook, array( 'message' => 'test' ) );
		WP_MCP_AI_Cron_Manager::record_job( $user_hook, array( 'message' => 'test' ), 'single', $timestamp2, $this->user_id );

		// Create cleanup job (internal).
		$cleanup_hook = 'wp_mcp_ai_cleanup_async_results';
		$timestamp3   = time() + ( 3 * HOUR_IN_SECONDS );
		wp_schedule_single_event( $timestamp3, $cleanup_hook, array() );
		WP_MCP_AI_Cron_Manager::record_job( $cleanup_hook, array(), 'single', $timestamp3, $this->user_id );

		// Create video job (user-initiated).
		$video_hook = 'wp_mcp_ai_poll_veo_video';
		$timestamp4 = time() + ( 4 * HOUR_IN_SECONDS );
		wp_schedule_single_event( $timestamp4, $video_hook, array( 'veo_123' ) );
		WP_MCP_AI_Cron_Manager::record_job( $video_hook, array( 'veo_123' ), 'single', $timestamp4, $this->user_id );

		// Get status summary with chat context.
		$chat_summary = $this->service->get_status_summary( $this->user_id, 10, null, 'chat' );

		// User job and video job should be visible in chat context.
		$this->assertCount( 2, $chat_summary, 'User-created job and video job should be visible in chat context' );
		$chat_hooks = array_column( $chat_summary, 'hook' );
		$this->assertContains( $user_hook, $chat_hooks );
		$this->assertContains( $video_hook, $chat_hooks );
		$this->assertNotContains( $internal_hook, $chat_hooks );
		$this->assertNotContains( $cleanup_hook, $chat_hooks );

		// Get status summary with admin context.
		$admin_summary = $this->service->get_status_summary( $this->user_id, 10, null, 'admin' );

		// All jobs should be visible in admin context.
		$this->assertCount( 4, $admin_summary, 'All jobs should be visible in admin context' );

		$admin_hooks = array_column( $admin_summary, 'hook' );
		$this->assertContains( $internal_hook, $admin_hooks );
		$this->assertContains( $user_hook, $admin_hooks );
		$this->assertContains( $cleanup_hook, $admin_hooks );
		$this->assertContains( $video_hook, $admin_hooks );
	}

	/**
	 * Test status counts are filtered correctly.
	 */
	public function test_status_counts_filtered_by_context() {
		// Create internal async job.
		$internal_hook = 'wp_mcp_ai_async_tool_execution';
		$timestamp1    = time() + HOUR_IN_SECONDS;
		wp_schedule_single_event( $timestamp1, $internal_hook, array( 'async_1' ) );
		WP_MCP_AI_Cron_Manager::record_job( $internal_hook, array( 'async_1' ), 'single', $timestamp1, $this->user_id );

		// Create user job.
		$user_hook  = 'my_custom_hook';
		$timestamp2 = time() + ( 2 * HOUR_IN_SECONDS );
		wp_schedule_single_event( $timestamp2, $user_hook, array() );
		WP_MCP_AI_Cron_Manager::record_job( $user_hook, array(), 'single', $timestamp2, $this->user_id );

		// Create video job (user-initiated).
		$video_hook = 'wp_mcp_ai_poll_veo_video';
		$timestamp3 = time() + ( 3 * HOUR_IN_SECONDS );
		wp_schedule_single_event( $timestamp3, $video_hook, array( 'veo_123' ) );
		WP_MCP_AI_Cron_Manager::record_job( $video_hook, array( 'veo_123' ), 'single', $timestamp3, $this->user_id );

		// Get counts with chat context.
		$chat_counts = $this->service->get_status_counts( $this->user_id, null, 'chat' );

		// User job and video job should be counted in chat context.
		$this->assertEquals( 2, $chat_counts['total'], 'User job and video job should be counted in chat context' );
		$this->assertEquals( 2, $chat_counts['pending'] );

		// Get counts with admin context.
		$admin_counts = $this->service->get_status_counts( $this->user_id, null, 'admin' );

		// All jobs should be counted in admin context.
		$this->assertEquals( 3, $admin_counts['total'], 'All jobs should be counted in admin context' );
		$this->assertEquals( 3, $admin_counts['pending'] );
	}

	/**
	 * Test default context is 'admin'.
	 */
	public function test_default_context_is_admin() {
		// Create internal async job.
		$internal_hook = 'wp_mcp_ai_async_tool_execution';
		$timestamp     = time() + HOUR_IN_SECONDS;
		wp_schedule_single_event( $timestamp, $internal_hook, array( 'async_1' ) );
		WP_MCP_AI_Cron_Manager::record_job( $internal_hook, array( 'async_1' ), 'single', $timestamp, $this->user_id );

		// Get status summary without context parameter (defaults to admin).
		$summary = $this->service->get_status_summary( $this->user_id, 10 );

		// Internal job should be visible with default context.
		$this->assertNotEmpty( $summary, 'Internal job should be visible with default admin context' );
		$this->assertCount( 1, $summary );
	}
}
