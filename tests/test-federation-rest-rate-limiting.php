<?php
/**
 * Test Federation REST Endpoints with Rate Limiting.
 *
 * @package WP_MCP_AI
 */

/**
 * Federation REST Rate Limiting Integration Test
 */
class Test_Federation_REST_Rate_Limiting extends WP_UnitTestCase {

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Clear any existing rate limit transients.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wp_mcp_ai_fed_rate_limit_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wp_mcp_ai_fed_rate_limit_%'" );

		// Register REST routes.
		rest_get_server()->register_routes();
	}

	/**
	 * Test that /peers endpoint is rate limited.
	 */
	public function test_peers_endpoint_rate_limited() {
		// Make 60 requests (the limit).
		for ( $i = 0; $i < 60; $i++ ) {
			$request  = new WP_REST_Request( 'GET', '/ai-dir/v1/peers' );
			$response = rest_do_request( $request );
			$this->assertEquals( 200, $response->get_status(), "Request $i should succeed" );
		}

		// 61st request should be rate limited.
		$request  = new WP_REST_Request( 'GET', '/ai-dir/v1/peers' );
		$response = rest_do_request( $request );
		$this->assertEquals( 429, $response->get_status(), 'Should return 429 Too Many Requests' );
	}

	/**
	 * Test that /peers/{id} endpoint is rate limited.
	 */
	public function test_peers_id_endpoint_rate_limited() {
		// Create a test peer.
		$peer_id = $this->factory->post->create(
			array(
				'post_type'  => 'wp_mcp_ai_ai_peer',
				'post_title' => 'Test Peer',
			)
		);

		// Make 60 requests.
		for ( $i = 0; $i < 60; $i++ ) {
			$request  = new WP_REST_Request( 'GET', '/ai-dir/v1/peers/' . $peer_id );
			$response = rest_do_request( $request );
			$this->assertContains( $response->get_status(), array( 200, 404 ), "Request $i should succeed or return 404" );
		}

		// 61st request should be rate limited.
		$request  = new WP_REST_Request( 'GET', '/ai-dir/v1/peers/' . $peer_id );
		$response = rest_do_request( $request );
		$this->assertEquals( 429, $response->get_status() );
	}

	/**
	 * Test that /search endpoint is rate limited.
	 */
	public function test_search_endpoint_rate_limited() {
		// Make 60 requests.
		for ( $i = 0; $i < 60; $i++ ) {
			$request  = new WP_REST_Request( 'GET', '/ai-dir/v1/search' );
			$response = rest_do_request( $request );
			$this->assertEquals( 200, $response->get_status(), "Request $i should succeed" );
		}

		// 61st request should be rate limited.
		$request  = new WP_REST_Request( 'GET', '/ai-dir/v1/search' );
		$response = rest_do_request( $request );
		$this->assertEquals( 429, $response->get_status() );
	}

	/**
	 * Test that rate limit headers are present in responses.
	 */
	public function test_rate_limit_headers_present() {
		$request  = new WP_REST_Request( 'GET', '/ai-dir/v1/peers' );
		$response = rest_do_request( $request );

		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'X-RateLimit-Limit', $headers );
		$this->assertArrayHasKey( 'X-RateLimit-Remaining', $headers );
		$this->assertArrayHasKey( 'X-RateLimit-Reset', $headers );

		$this->assertEquals( '60', $headers['X-RateLimit-Limit'] );
		$this->assertIsNumeric( $headers['X-RateLimit-Remaining'] );
		$this->assertIsNumeric( $headers['X-RateLimit-Reset'] );
	}

	/**
	 * Test that rate limits are per-endpoint.
	 */
	public function test_rate_limit_per_endpoint() {
		// Exhaust limit on /peers.
		for ( $i = 0; $i <= 60; $i++ ) {
			$request = new WP_REST_Request( 'GET', '/ai-dir/v1/peers' );
			rest_do_request( $request );
		}

		// Verify /peers is rate limited.
		$request  = new WP_REST_Request( 'GET', '/ai-dir/v1/peers' );
		$response = rest_do_request( $request );
		$this->assertEquals( 429, $response->get_status() );

		// Verify /search still works (different endpoint).
		$request  = new WP_REST_Request( 'GET', '/ai-dir/v1/search' );
		$response = rest_do_request( $request );
		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test that admin users bypass rate limiting.
	 */
	public function test_admin_bypasses_rate_limit() {
		// Set up admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Make 100 requests (well over the limit).
		for ( $i = 0; $i < 100; $i++ ) {
			$request  = new WP_REST_Request( 'GET', '/ai-dir/v1/peers' );
			$response = rest_do_request( $request );
			$this->assertEquals( 200, $response->get_status(), "Admin request $i should succeed" );
		}
	}

	/**
	 * Test that 429 response includes retry_after in error data.
	 */
	public function test_429_includes_retry_after() {
		// Exceed rate limit.
		for ( $i = 0; $i <= 60; $i++ ) {
			$request = new WP_REST_Request( 'GET', '/ai-dir/v1/peers' );
			rest_do_request( $request );
		}

		$request  = new WP_REST_Request( 'GET', '/ai-dir/v1/peers' );
		$response = rest_do_request( $request );

		$this->assertEquals( 429, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'status', $data['data'] );
		$this->assertEquals( 429, $data['data']['status'] );
	}
}
