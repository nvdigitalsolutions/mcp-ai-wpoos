<?php
/**
 * Tests for Cron Status REST endpoint
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_REST_Cron_Status
 */
class Test_REST_Cron_Status extends WP_UnitTestCase {

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

		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';

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
	 * Test endpoint returns 401 when not authenticated.
	 */
	public function test_cron_status_requires_authentication() {
		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/cron-status' );
		$response = rest_do_request( $request );

		// Should require authentication.
		$this->assertEquals( 401, $response->get_status() );
	}

	/**
	 * Test endpoint returns empty data when no jobs exist.
	 */
	public function test_cron_status_with_no_jobs() {
		wp_set_current_user( $this->user_id );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/cron-status' );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'jobs', $data );
		$this->assertArrayHasKey( 'counts', $data );
		$this->assertEmpty( $data['jobs'] );
		$this->assertEquals( 0, $data['counts']['total'] );
	}

	/**
	 * Test endpoint returns job data.
	 */
	public function test_cron_status_with_jobs() {
		wp_set_current_user( $this->user_id );

		// Create a pending job.
		$hook      = 'wp_mcp_ai_test_pending';
		$timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_single_event( $timestamp, $hook, array() );
		WP_MCP_AI_Cron_Manager::record_job( $hook, array(), 'single', $timestamp, $this->user_id );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/cron-status' );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertNotEmpty( $data['jobs'] );
		$this->assertCount( 1, $data['jobs'] );
		$this->assertEquals( 'pending', $data['jobs'][0]['status'] );
		$this->assertEquals( $hook, $data['jobs'][0]['hook'] );
		$this->assertEquals( 1, $data['counts']['pending'] );
		$this->assertEquals( 0, $data['counts']['completed'] );
	}

	/**
	 * Test limit parameter.
	 */
	public function test_cron_status_limit_parameter() {
		wp_set_current_user( $this->user_id );

		// Create 5 jobs.
		for ( $i = 1; $i <= 5; $i++ ) {
			$hook      = 'wp_mcp_ai_test_limit_' . $i;
			$timestamp = time() + ( $i * HOUR_IN_SECONDS );
			wp_schedule_single_event( $timestamp, $hook, array() );
			WP_MCP_AI_Cron_Manager::record_job( $hook, array(), 'single', $timestamp, $this->user_id );
		}

		// Request with limit of 3.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/cron-status' );
		$request->set_param( 'limit', 3 );
		$response = rest_do_request( $request );

		$data = $response->get_data();
		$this->assertCount( 3, $data['jobs'] );

		// Request with limit of 10 (should return all 5).
		$request->set_param( 'limit', 10 );
		$response = rest_do_request( $request );

		$data = $response->get_data();
		$this->assertCount( 5, $data['jobs'] );
	}

	/**
	 * Test user filtering (non-admin sees only own jobs).
	 */
	public function test_cron_status_user_filtering() {
		// Create job for user 1.
		$hook1     = 'wp_mcp_ai_test_user1';
		$timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_single_event( $timestamp, $hook1, array() );
		WP_MCP_AI_Cron_Manager::record_job( $hook1, array(), 'single', $timestamp, $this->user_id );

		// Create job for another user.
		$other_user = $this->factory->user->create();
		$hook2      = 'wp_mcp_ai_test_user2';
		wp_schedule_single_event( $timestamp, $hook2, array() );
		WP_MCP_AI_Cron_Manager::record_job( $hook2, array(), 'single', $timestamp, $other_user );

		// User 1 should only see their own job.
		wp_set_current_user( $this->user_id );
		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/cron-status' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertCount( 1, $data['jobs'] );
		$this->assertEquals( $hook1, $data['jobs'][0]['hook'] );

		// Admin should see all jobs.
		wp_set_current_user( $this->admin_id );
		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/cron-status' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertCount( 2, $data['jobs'] );
	}

	/**
	 * Test response format.
	 */
	public function test_cron_status_response_format() {
		wp_set_current_user( $this->user_id );

		// Create a job.
		$hook      = 'wp_mcp_ai_test_format';
		$timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_single_event( $timestamp, $hook, array() );
		WP_MCP_AI_Cron_Manager::record_job( $hook, array(), 'single', $timestamp, $this->user_id );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/cron-status' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		// Check jobs array.
		$this->assertArrayHasKey( 'jobs', $data );
		$this->assertIsArray( $data['jobs'] );
		$this->assertNotEmpty( $data['jobs'] );

		$job = $data['jobs'][0];
		$this->assertArrayHasKey( 'job_id', $job );
		$this->assertArrayHasKey( 'hook', $job );
		$this->assertArrayHasKey( 'status', $job );
		$this->assertArrayHasKey( 'created_by', $job );

		// Check counts array.
		$this->assertArrayHasKey( 'counts', $data );
		$this->assertIsArray( $data['counts'] );
		$this->assertArrayHasKey( 'pending', $data['counts'] );
		$this->assertArrayHasKey( 'completed', $data['counts'] );
		$this->assertArrayHasKey( 'total', $data['counts'] );
	}

	/**
	 * Test that any authenticated user (even without edit_posts capability) can access their cron status.
	 */
	public function test_cron_status_accessible_to_all_authenticated_users() {
		// Create a subscriber user (no edit_posts capability).
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		// Create a job for this subscriber.
		$hook      = 'wp_mcp_ai_test_subscriber';
		$timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_single_event( $timestamp, $hook, array() );
		WP_MCP_AI_Cron_Manager::record_job( $hook, array(), 'single', $timestamp, $subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/cron-status' );
		$response = rest_do_request( $request );

		// Subscriber should be able to access their own cron jobs.
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'jobs', $data );
		$this->assertCount( 1, $data['jobs'] );
		$this->assertEquals( $hook, $data['jobs'][0]['hook'] );
		$this->assertEquals( $subscriber_id, $data['jobs'][0]['created_by'] );
	}
}
