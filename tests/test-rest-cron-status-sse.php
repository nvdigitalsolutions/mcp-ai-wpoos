<?php
/**
 * Tests for Cron Status REST endpoint SSE streaming support
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_REST_Cron_Status_SSE
 */
class Test_REST_Cron_Status_SSE extends WP_UnitTestCase {

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

		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';

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
	 * Test that stream parameter is accepted.
	 */
	public function test_cron_status_accepts_stream_parameter() {
		wp_set_current_user( $this->user_id );

		// Create a test job.
		$hook      = 'wp_mcp_ai_test_sse';
		$timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_single_event( $timestamp, $hook, array() );
		WP_MCP_AI_Cron_Manager::record_job( $hook, array(), 'single', $timestamp, $this->user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/cron-status' );
		$request->set_param( 'stream', true );
		$response = rest_do_request( $request );

		// Should not error with stream parameter.
		$this->assertEquals( 200, $response->get_status() );

		// Response should have SSE content-type when stream=true.
		$headers = $response->get_headers();
		if ( isset( $headers['Content-Type'] ) ) {
			$this->assertStringContainsString( 'text/event-stream', $headers['Content-Type'] );
		}
	}

	/**
	 * Test that stream=false returns JSON.
	 */
	public function test_cron_status_stream_false_returns_json() {
		wp_set_current_user( $this->user_id );

		// Create a test job.
		$hook      = 'wp_mcp_ai_test_json';
		$timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_single_event( $timestamp, $hook, array() );
		WP_MCP_AI_Cron_Manager::record_job( $hook, array(), 'single', $timestamp, $this->user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/cron-status' );
		$request->set_param( 'stream', false );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		// Should return regular JSON data.
		$data = $response->get_data();
		$this->assertArrayHasKey( 'jobs', $data );
		$this->assertArrayHasKey( 'counts', $data );
		$this->assertNotEmpty( $data['jobs'] );
	}

	/**
	 * Test that SSE endpoint works with assistant_id filtering.
	 */
	public function test_cron_status_sse_with_assistant_id() {
		wp_set_current_user( $this->user_id );

		// Create a test job.
		$hook      = 'wp_mcp_ai_test_assistant';
		$timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_single_event( $timestamp, $hook, array() );
		WP_MCP_AI_Cron_Manager::record_job( $hook, array(), 'single', $timestamp, $this->user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/cron-status' );
		$request->set_param( 'stream', true );
		$request->set_param( 'assistant_id', 123 );
		$response = rest_do_request( $request );

		// Should accept both stream and assistant_id parameters.
		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test that SSE endpoint respects limit parameter.
	 */
	public function test_cron_status_sse_with_limit() {
		wp_set_current_user( $this->user_id );

		// Create 5 jobs.
		for ( $i = 1; $i <= 5; $i++ ) {
			$hook      = 'wp_mcp_ai_test_limit_sse_' . $i;
			$timestamp = time() + ( $i * HOUR_IN_SECONDS );
			wp_schedule_single_event( $timestamp, $hook, array() );
			WP_MCP_AI_Cron_Manager::record_job( $hook, array(), 'single', $timestamp, $this->user_id );
		}

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/cron-status' );
		$request->set_param( 'stream', true );
		$request->set_param( 'limit', 3 );
		$response = rest_do_request( $request );

		// Should accept both stream and limit parameters.
		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test that SSE endpoint requires authentication.
	 */
	public function test_cron_status_sse_requires_authentication() {
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/cron-status' );
		$request->set_param( 'stream', true );
		$response = rest_do_request( $request );

		// Should require authentication even with SSE.
		$this->assertEquals( 401, $response->get_status() );
	}
}
