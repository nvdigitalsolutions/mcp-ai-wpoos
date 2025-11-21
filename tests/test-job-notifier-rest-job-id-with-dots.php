<?php
/**
 * Tests for Job Notifier REST endpoints with job IDs containing dots
 *
 * Verifies that job IDs generated with uniqid('prefix', true) which contain
 * dots (e.g., veo_69203b5b2388f5.11575461) are properly handled by the
 * job notifier REST API endpoints.
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_Job_Notifier_REST_Job_ID_With_Dots
 */
class Test_Job_Notifier_REST_Job_ID_With_Dots extends WP_UnitTestCase {

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

		// Load required classes.
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-job-notifier.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-job-notifier-rest.php';

		// Initialize job notifier.
		WP_MCP_AI_Job_Notifier::init();
		WP_MCP_AI_Job_Notifier_REST::init();

		// Create test user.
		$this->user_id = $this->factory->user->create();
	}

	/**
	 * Test job status endpoint with job ID containing dot.
	 */
	public function test_job_status_endpoint_with_dot_in_job_id() {
		wp_set_current_user( $this->user_id );

		// Create a mock job with dot in ID.
		$job_id = 'veo_' . uniqid( '', true );
		$status = array(
			'job_id'     => $job_id,
			'status'     => 'running',
			'progress'   => 50,
			'started_at' => current_time( 'mysql', true ),
		);

		// Cache the job status.
		set_transient( WP_MCP_AI_Job_Notifier::CACHE_PREFIX . $job_id, $status, WP_MCP_AI_Job_Notifier::CACHE_DURATION );

		// Make request to REST endpoint.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/jobs/' . $job_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_do_request( $request );

		// Should not be 404.
		$this->assertNotEquals( 404, $response->get_status(), 'Endpoint should match route with dot in job_id' );

		// Should be 200 OK.
		$this->assertEquals( 200, $response->get_status(), 'Should return job status successfully' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'job_id', $data );
		$this->assertEquals( $job_id, $data['job_id'] );
		$this->assertEquals( 'running', $data['status'] );

		// Clean up.
		delete_transient( WP_MCP_AI_Job_Notifier::CACHE_PREFIX . $job_id );
	}

	/**
	 * Test job stream endpoint with job ID containing dot.
	 */
	public function test_job_stream_endpoint_with_dot_in_job_id() {
		wp_set_current_user( $this->user_id );

		// Create a mock job with dot in ID.
		$job_id = 'test_' . uniqid( '', true );
		$status = array(
			'job_id'     => $job_id,
			'status'     => 'started',
			'started_at' => current_time( 'mysql', true ),
		);

		// Cache the job status.
		set_transient( WP_MCP_AI_Job_Notifier::CACHE_PREFIX . $job_id, $status, WP_MCP_AI_Job_Notifier::CACHE_DURATION );

		// Make request to REST endpoint.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/jobs/' . $job_id . '/stream' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_do_request( $request );

		// Should not be 404.
		$this->assertNotEquals( 404, $response->get_status(), 'Stream endpoint should match route with dot in job_id' );

		// Clean up.
		delete_transient( WP_MCP_AI_Job_Notifier::CACHE_PREFIX . $job_id );
	}

	/**
	 * Test webhook endpoint with job ID containing dot.
	 */
	public function test_webhook_endpoint_with_dot_in_job_id() {
		wp_set_current_user( $this->user_id );

		// Create a job ID with dot.
		$job_id = 'err_' . uniqid( '', true );

		// Make request to REST endpoint.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/jobs/' . $job_id . '/webhooks' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'webhook_url', 'https://example.com/webhook' );

		$response = rest_do_request( $request );

		// Should not be 404.
		$this->assertNotEquals( 404, $response->get_status(), 'Webhook endpoint should match route with dot in job_id' );

		// May be 403 or other error due to permissions, but should not be 404.
		$this->assertNotEquals( 404, $response->get_status() );
	}

	/**
	 * Test that real uniqid format works with all endpoints.
	 */
	public function test_real_uniqid_format_all_endpoints() {
		wp_set_current_user( $this->user_id );

		// Generate a real job ID the way the code does.
		$job_id = 'veo_' . uniqid( '', true );

		// Verify it contains a dot.
		$this->assertStringContainsString( '.', $job_id, 'uniqid with more_entropy should contain a dot' );

		// Create status for the job.
		$status = array(
			'job_id' => $job_id,
			'status' => 'completed',
		);
		set_transient( WP_MCP_AI_Job_Notifier::CACHE_PREFIX . $job_id, $status, WP_MCP_AI_Job_Notifier::CACHE_DURATION );

		// Test status endpoint.
		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/jobs/' . $job_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$response = rest_do_request( $request );
		$this->assertNotEquals( 404, $response->get_status(), 'Status endpoint should work with real uniqid format' );

		// Test stream endpoint.
		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/jobs/' . $job_id . '/stream' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$response = rest_do_request( $request );
		$this->assertNotEquals( 404, $response->get_status(), 'Stream endpoint should work with real uniqid format' );

		// Clean up.
		delete_transient( WP_MCP_AI_Job_Notifier::CACHE_PREFIX . $job_id );
	}

	/**
	 * Test that wildcard patterns work for webhooks.
	 */
	public function test_webhook_wildcard_patterns() {
		wp_set_current_user( $this->user_id );

		// Test wildcard pattern.
		$job_pattern = 'veo_*';

		// Make request to REST endpoint.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/jobs/' . $job_pattern . '/webhooks' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'webhook_url', 'https://example.com/webhook' );

		$response = rest_do_request( $request );

		// Should not be 404 (route should match).
		$this->assertNotEquals( 404, $response->get_status(), 'Webhook endpoint should match route with wildcard pattern' );
	}
}
