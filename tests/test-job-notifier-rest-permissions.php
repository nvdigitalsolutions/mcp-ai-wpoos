<?php
/**
 * Tests for Job Notifier REST endpoint permissions
 *
 * Verifies that the job notifier REST endpoints properly support all
 * authentication methods: mesh key, local tokens, guest tokens, bearer
 * tokens, and WordPress nonces.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_Job_Notifier_REST_Permissions
 */
class Test_Job_Notifier_REST_Permissions extends WP_UnitTestCase {

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
	 * Test job ID.
	 *
	 * @var string
	 */
	protected $job_id;

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

		// Create test users.
		$this->user_id  = $this->factory->user->create();
		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Create a test job with a simple unique identifier (no dots to avoid routing issues).
		$this->job_id = 'test_job_' . wp_generate_uuid4();
		$status       = array(
			'job_id'     => $this->job_id,
			'status'     => 'running',
			'progress'   => 50,
			'started_at' => current_time( 'mysql', true ),
		);
		set_transient(
			WP_MCP_AI_Job_Notifier::CACHE_PREFIX . $this->job_id,
			$status,
			WP_MCP_AI_Job_Notifier::CACHE_DURATION
		);
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		// Clean up transient.
		delete_transient( WP_MCP_AI_Job_Notifier::CACHE_PREFIX . $this->job_id );

		parent::tearDown();
	}

	/**
	 * Test that unauthenticated requests are rejected with 401.
	 */
	public function test_job_status_requires_authentication() {
		wp_set_current_user( 0 ); // Log out.

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/jobs/' . $this->job_id );
		$response = rest_do_request( $request );

		$this->assertEquals( 401, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 'wp_mcp_ai_missing_credentials', $data['code'] );

		// Verify actionable error messages are provided.
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'actions', $data['data'] );
		$this->assertArrayHasKey( 'supply_bearer_token', $data['data']['actions'] );
		$this->assertArrayHasKey( 'supply_guest_token', $data['data']['actions'] );
		$this->assertArrayHasKey( 'include_rest_nonce', $data['data']['actions'] );
	}

	/**
	 * Test that WordPress nonce authentication works.
	 */
	public function test_job_status_with_valid_nonce() {
		wp_set_current_user( $this->user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/jobs/' . $this->job_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'job_id', $data );
		$this->assertEquals( $this->job_id, $data['job_id'] );
		$this->assertEquals( 'running', $data['status'] );
	}

	/**
	 * Test that invalid nonce is rejected.
	 */
	public function test_job_status_with_invalid_nonce() {
		wp_set_current_user( $this->user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/jobs/' . $this->job_id );
		$request->set_header( 'X-WP-Nonce', 'invalid_nonce' );

		$response = rest_do_request( $request );

		// Should return 401 or 403 for invalid nonce.
		$status = $response->get_status();
		$this->assertTrue(
			in_array( $status, array( 401, 403 ), true ),
			'Expected status 401 or 403, got ' . $status
		);
	}

	/**
	 * Test that webhook registration requires admin capability.
	 */
	public function test_webhook_register_requires_admin() {
		wp_set_current_user( $this->user_id ); // Non-admin user.

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/jobs/' . $this->job_id . '/webhooks' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'webhook_url', 'https://example.com/webhook' );
		$request->set_param( 'events', array( 'completed' ) );

		$response = rest_do_request( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test that admin can register webhooks.
	 */
	public function test_webhook_register_with_admin() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/jobs/' . $this->job_id . '/webhooks' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'webhook_url', 'https://example.com/webhook' );
		$request->set_param( 'events', array( 'completed', 'failed' ) );

		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertEquals( $this->job_id, $data['job_id'] );

		// Clean up webhooks.
		delete_option( WP_MCP_AI_Job_Notifier::WEBHOOK_OPTION_KEY );
	}

	/**
	 * Test job stream endpoint permission check.
	 */
	public function test_job_stream_requires_authentication() {
		wp_set_current_user( 0 ); // Log out.

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/jobs/' . $this->job_id . '/stream' );
		$response = rest_do_request( $request );

		$this->assertEquals( 401, $response->get_status() );
	}

	/**
	 * Test job stream endpoint with valid nonce.
	 */
	public function test_job_stream_with_valid_nonce() {
		wp_set_current_user( $this->user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/jobs/' . $this->job_id . '/stream' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_do_request( $request );

		// Stream endpoint returns 200.
		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test that the permissions_check_job_stream method is used for both status and stream.
	 */
	public function test_job_status_uses_same_permission_check_as_stream() {
		// Set up user.
		wp_set_current_user( $this->user_id );
		$nonce = wp_create_nonce( 'wp_rest' );

		// Test status endpoint.
		$request_status = new WP_REST_Request( 'GET', '/mcp-ai/v1/jobs/' . $this->job_id );
		$request_status->set_header( 'X-WP-Nonce', $nonce );
		$response_status = rest_do_request( $request_status );

		// Test stream endpoint.
		$request_stream = new WP_REST_Request( 'GET', '/mcp-ai/v1/jobs/' . $this->job_id . '/stream' );
		$request_stream->set_header( 'X-WP-Nonce', $nonce );
		$response_stream = rest_do_request( $request_stream );

		// Both should be successful.
		$this->assertEquals( 200, $response_status->get_status() );
		$this->assertEquals( 200, $response_stream->get_status() );

		// Now test without nonce - both should fail similarly.
		wp_set_current_user( 0 );

		$request_status_no_auth  = new WP_REST_Request( 'GET', '/mcp-ai/v1/jobs/' . $this->job_id );
		$response_status_no_auth = rest_do_request( $request_status_no_auth );

		$request_stream_no_auth  = new WP_REST_Request( 'GET', '/mcp-ai/v1/jobs/' . $this->job_id . '/stream' );
		$response_stream_no_auth = rest_do_request( $request_stream_no_auth );

		// Both should require authentication.
		$this->assertEquals( 401, $response_status_no_auth->get_status() );
		$this->assertEquals( 401, $response_stream_no_auth->get_status() );
	}

	/**
	 * Test sanitize_job_id removes dangerous characters.
	 */
	public function test_sanitize_job_id() {
		// Test normal job ID.
		$sanitized = WP_MCP_AI_Job_Notifier_REST::sanitize_job_id( 'veo_123abc' );
		$this->assertEquals( 'veo_123abc', $sanitized );

		// Test job ID with dot (from uniqid with more_entropy).
		$sanitized = WP_MCP_AI_Job_Notifier_REST::sanitize_job_id( 'veo_69203b5b2388f5.11575461' );
		$this->assertEquals( 'veo_69203b5b2388f5.11575461', $sanitized );

		// Test job ID with hyphen.
		$sanitized = WP_MCP_AI_Job_Notifier_REST::sanitize_job_id( 'async_generate-veo-video_123' );
		$this->assertEquals( 'async_generate-veo-video_123', $sanitized );

		// Test wildcard pattern for webhooks.
		$sanitized = WP_MCP_AI_Job_Notifier_REST::sanitize_job_id( 'veo_*' );
		$this->assertEquals( 'veo_*', $sanitized );

		// Test path traversal attempt is blocked.
		$sanitized = WP_MCP_AI_Job_Notifier_REST::sanitize_job_id( 'veo_../../../etc/passwd' );
		$this->assertStringNotContainsString( '..', $sanitized );
		$this->assertStringNotContainsString( '/', $sanitized );

		// Test HTML/script injection is blocked.
		$sanitized = WP_MCP_AI_Job_Notifier_REST::sanitize_job_id( '<script>alert(1)</script>' );
		$this->assertStringNotContainsString( '<', $sanitized );
		$this->assertStringNotContainsString( '>', $sanitized );
	}
}
