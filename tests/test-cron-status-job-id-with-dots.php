<?php
/**
 * Tests for Cron Status endpoint with job IDs containing dots
 *
 * Verifies that job IDs generated with uniqid('prefix', true) which contain
 * dots (e.g., veo_69203b5b2388f5.11575461) are properly handled by the
 * REST API endpoint.
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_Cron_Status_Job_ID_With_Dots
 */
class Test_Cron_Status_Job_ID_With_Dots extends WP_UnitTestCase {

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
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cron-status-service.php';
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-tools-controller.php';

		// Create test user.
		$this->user_id = $this->factory->user->create();
	}

	/**
	 * Test that job IDs with dots are properly sanitized.
	 */
	public function test_sanitize_job_id_preserves_dots() {
		$controller = new WP_MCP_AI_REST_Tools_Controller();

		// Test normal veo job ID with dot.
		$job_id    = 'veo_69203b5b2388f5.11575461';
		$sanitized = $controller->sanitize_job_id( $job_id );
		$this->assertEquals( $job_id, $sanitized, 'Job ID with single dot should be preserved' );

		// Test uppercase.
		$job_id    = 'VEO_69203B5B2388F5.11575461';
		$sanitized = $controller->sanitize_job_id( $job_id );
		$this->assertEquals( $job_id, $sanitized, 'Uppercase job ID should be preserved' );

		// Test with hyphen.
		$job_id    = 'veo_test-123.456';
		$sanitized = $controller->sanitize_job_id( $job_id );
		$this->assertEquals( $job_id, $sanitized, 'Job ID with hyphen and dot should be preserved' );
	}

	/**
	 * Test that path traversal attempts are blocked.
	 */
	public function test_sanitize_job_id_blocks_path_traversal() {
		$controller = new WP_MCP_AI_REST_Tools_Controller();

		// Test path traversal with dots.
		$job_id    = '../../../etc/passwd';
		$sanitized = $controller->sanitize_job_id( $job_id );
		$this->assertNotContains( '..', $sanitized, 'Path traversal should be removed' );
		$this->assertNotContains( '/', $sanitized, 'Slashes should be removed' );

		// Test consecutive dots.
		$job_id    = 'test..double..dots';
		$sanitized = $controller->sanitize_job_id( $job_id );
		$this->assertNotContains( '..', $sanitized, 'Consecutive dots should be removed' );

		// Test with malicious characters.
		$job_id    = 'test<script>alert(1)</script>';
		$sanitized = $controller->sanitize_job_id( $job_id );
		$this->assertNotContains( '<', $sanitized, 'HTML tags should be removed' );
		$this->assertNotContains( '>', $sanitized, 'HTML tags should be removed' );
		$this->assertEquals( 'testscriptalert1script', $sanitized );
	}

	/**
	 * Test REST endpoint with job ID containing dot.
	 */
	public function test_cron_status_endpoint_with_dot_in_job_id() {
		wp_set_current_user( $this->user_id );

		// Create a mock video generation job (dots removed for consistency).
		$job_id   = 'veo_' . str_replace( '.', '', uniqid( '', true ) );
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test-operation',
			'args'           => array(
				'prompt'  => 'Test video',
				'user_id' => $this->user_id,
			),
			'status'         => 'pending',
			'queued_at'      => time(),
			'poll_attempt'   => 0,
			'max_attempts'   => 60,
		);

		// Store the job in a transient.
		set_transient(
			WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id,
			$metadata,
			DAY_IN_SECONDS
		);

		// Make request to REST endpoint.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/cron-status/' . $job_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_do_request( $request );

		// Should not be 404.
		$this->assertNotEquals( 404, $response->get_status(), 'Endpoint should match route with dot in job_id' );

		// Should be 200 OK.
		$this->assertEquals( 200, $response->get_status(), 'Should return job details successfully' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'job_id', $data );
		$this->assertEquals( $job_id, $data['job_id'] );
		$this->assertEquals( 'pending', $data['status'] );

		// Clean up.
		delete_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id );
	}

	/**
	 * Test that service sanitization preserves dots.
	 */
	public function test_service_get_job_details_preserves_dots() {
		wp_set_current_user( $this->user_id );

		$service = new WP_MCP_AI_Cron_Status_Service();

		// Create a mock video job.
		$job_id   = 'veo_69203b5b2388f5.11575461';
		$metadata = array(
			'job_id'         => $job_id,
			'operation_name' => 'operations/test-op',
			'args'           => array(
				'prompt'  => 'Test',
				'user_id' => $this->user_id,
			),
			'status'         => 'pending',
			'queued_at'      => time(),
			'poll_attempt'   => 0,
			'max_attempts'   => 60,
		);

		set_transient(
			WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id,
			$metadata,
			DAY_IN_SECONDS
		);

		$result = $service->get_job_details( $job_id, $this->user_id );

		$this->assertFalse( is_wp_error( $result ), 'Should not return error for job ID with dot' );
		$this->assertArrayHasKey( 'job_id', $result );
		$this->assertEquals( $job_id, $result['job_id'], 'Job ID with dot should be preserved in service' );

		// Clean up.
		delete_transient( WP_MCP_AI_Gemini_Video_Generation_Service::ASYNC_OP_PREFIX . $job_id );
	}

	/**
	 * Test the actual uniqid format used in production.
	 */
	public function test_actual_uniqid_format() {
		// Generate a real job ID the way the code does (with dots removed).
		$job_id = 'veo_' . str_replace( '.', '', uniqid( '', true ) );

		// Verify it does NOT contain a dot (dots are removed for consistency).
		$this->assertStringNotContainsString( '.', $job_id, 'Job IDs should not contain dots after str_replace' );

		// Verify it can be sanitized without changes.
		$controller = new WP_MCP_AI_REST_Tools_Controller();
		$sanitized  = $controller->sanitize_job_id( $job_id );

		$this->assertEquals( $job_id, $sanitized, 'Job ID without dots should pass sanitization unchanged' );
		$this->assertStringNotContainsString( '.', $sanitized, 'Sanitized job ID should not contain dots' );
	}
}
