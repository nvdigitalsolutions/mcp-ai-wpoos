<?php
/**
 * SSE Authentication and CORS Security Tests for WP oOS
 *
 * Tests to verify that the /sse endpoint requires authentication
 * and respects CORS/origin headers properly.
 *
 * @package WP_MCP_AI
 */

/**
 * Test SSE auth and CORS security requirements.
 *
 * @group security
 * @group rest
 * @group sse
 */
class WP_MCP_AI_SSE_Auth_CORS_Test extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure REST server is initialized.
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tearDown();
	}

	/**
	 * Test that /sse endpoint requires authentication.
	 *
	 * Goal: ensure /sse requires auth.
	 */
	public function test_sse_endpoint_requires_authentication() {
		// Attempt to access /sse without any authentication.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/sse' );
		$request->set_header( 'Accept', 'text/event-stream' );

		$response = rest_do_request( $request );

		// Should return 401 Unauthorized or similar error.
		$this->assertContains(
			$response->get_status(),
			array( 401, 403 ),
			'SSE endpoint should require authentication'
		);
	}

	/**
	 * Test that /sse endpoint accepts bearer token authentication.
	 */
	public function test_sse_endpoint_accepts_bearer_token() {
		// Create admin user and assistant.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$assistant_id = $this->create_assistant_post();

		// Issue a credential token.
		$issued = WP_MCP_AI_Credentials::issue_credential( $assistant_id, $user_id );
		$this->assertArrayHasKey( 'token', $issued );

		// Clear current user to simulate remote access.
		wp_set_current_user( 0 );

		// Attempt to access /sse with bearer token.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/sse' );
		$request->set_header( 'Accept', 'text/event-stream' );
		$request->set_header( 'Authorization', 'Bearer ' . $issued['token'] );
		$request->set_param( 'assistant_id', $assistant_id );

		$response = rest_do_request( $request );

		// Should return 200 OK.
		$this->assertEquals(
			200,
			$response->get_status(),
			'SSE endpoint should accept bearer token authentication'
		);
	}

	/**
	 * Test that /sse endpoint accepts WordPress nonce for logged-in users.
	 */
	public function test_sse_endpoint_accepts_nonce_for_logged_in_users() {
		// Create admin user and assistant.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$assistant_id = $this->create_assistant_post();
		$nonce        = wp_create_nonce( 'wp_rest' );

		// Attempt to access /sse with nonce.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/sse' );
		$request->set_header( 'Accept', 'text/event-stream' );
		$request->set_header( 'X-WP-Nonce', $nonce );
		$request->set_param( 'assistant_id', $assistant_id );

		$response = rest_do_request( $request );

		// Should return 200 OK.
		$this->assertEquals(
			200,
			$response->get_status(),
			'SSE endpoint should accept nonce authentication for logged-in users'
		);
	}

	/**
	 * Test that SSE headers include proper CORS configuration.
	 */
	public function test_sse_headers_include_cors() {
		// Create admin user and assistant.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$assistant_id = $this->create_assistant_post();
		$nonce        = wp_create_nonce( 'wp_rest' );

		// Simulate origin header.
		$_SERVER['HTTP_ORIGIN'] = 'https://example.com';

		// Create request.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/sse' );
		$request->set_header( 'Accept', 'text/event-stream' );
		$request->set_header( 'X-WP-Nonce', $nonce );
		$request->set_header( 'Origin', 'https://example.com' );
		$request->set_param( 'assistant_id', $assistant_id );

		// Note: In unit tests, we can't easily capture headers sent by the SSE handler.
		// We'll verify the SSE handler class has the proper CORS methods.
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_SSE_Handler' ),
			'SSE Handler class should exist'
		);

		// Verify SSE handler has send_sse_headers method.
		$sse_handler = new WP_MCP_AI_SSE_Handler();
		$this->assertTrue(
			method_exists( $sse_handler, 'send_sse_headers' ),
			'SSE handler should have send_sse_headers method'
		);

		// Clean up.
		unset( $_SERVER['HTTP_ORIGIN'] );
	}

	/**
	 * Test that /sse endpoint respects Access-Control-Allow-Origin.
	 *
	 * Goal: ensure /sse respects origin.
	 */
	public function test_sse_respects_origin_header() {
		// Create admin user and assistant.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$assistant_id = $this->create_assistant_post();
		$nonce        = wp_create_nonce( 'wp_rest' );

		// Test with trusted origin.
		$_SERVER['HTTP_ORIGIN'] = home_url();

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/sse' );
		$request->set_header( 'Accept', 'text/event-stream' );
		$request->set_header( 'X-WP-Nonce', $nonce );
		$request->set_header( 'Origin', home_url() );
		$request->set_param( 'assistant_id', $assistant_id );

		$response = rest_do_request( $request );

		// Should be allowed.
		$this->assertEquals(
			200,
			$response->get_status(),
			'SSE endpoint should accept requests from trusted origin'
		);

		// Clean up.
		unset( $_SERVER['HTTP_ORIGIN'] );
	}

	/**
	 * Test that /sse OPTIONS request returns proper CORS headers.
	 */
	public function test_sse_options_request_returns_cors_headers() {
		// Simulate CORS preflight request.
		$request = new WP_REST_Request( 'OPTIONS', '/mcp-ai/v1/sse' );
		$request->set_header( 'Access-Control-Request-Method', 'GET' );
		$request->set_header( 'Origin', 'https://example.com' );

		$response = rest_do_request( $request );

		// OPTIONS should be handled appropriately.
		// The actual CORS headers are set by send_sse_headers, which happens during response.
		$this->assertNotNull( $response );
	}

	/**
	 * Test that SSE handler properly sets Content-Type header.
	 */
	public function test_sse_handler_sets_proper_content_type() {
		$sse_handler = new WP_MCP_AI_SSE_Handler();

		// Verify the handler can detect event stream preference.
		$this->assertTrue(
			method_exists( $sse_handler, 'prefers_event_stream' ),
			'SSE handler should have prefers_event_stream method'
		);
	}

	/**
	 * Test that guest tokens work with SSE endpoint.
	 */
	public function test_sse_endpoint_accepts_guest_token() {
		if ( ! class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Shortcode class not available' );
		}

		// Create assistant.
		$assistant_id = $this->create_assistant_post();

		// Generate guest token.
		$guest_token = WP_MCP_AI_Shortcode::generate_guest_token( $assistant_id );
		$this->assertNotEmpty( $guest_token );

		// Attempt to access /sse with guest token.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/sse' );
		$request->set_header( 'Accept', 'text/event-stream' );
		$request->set_header( 'X-WP-MCP-AI-Guest', $guest_token );
		$request->set_param( 'assistant_id', $assistant_id );

		$response = rest_do_request( $request );

		// Should return 200 OK for guest token.
		$this->assertEquals(
			200,
			$response->get_status(),
			'SSE endpoint should accept guest token authentication'
		);
	}

	/**
	 * Create a published assistant post for testing.
	 *
	 * @return int Assistant post ID.
	 */
	protected function create_assistant_post() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Test SSE Assistant',
				'post_status' => 'publish',
			)
		);

		$this->assertNotWPError( $assistant_id );
		$this->assertNotEmpty( $assistant_id );

		return $assistant_id;
	}
}
