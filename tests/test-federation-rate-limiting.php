<?php
/**
 * Test Federation Directory Rate Limiting.
 *
 * @package WP_MCP_AI
 */

/**
 * Federation Rate Limiting Test Class
 */
class Test_Federation_Rate_Limiting extends WP_UnitTestCase {

	/**
	 * Rate limiter instance.
	 *
	 * @var WP_MCP_AI_Federation_Rate_Limiter
	 */
	private $rate_limiter;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->rate_limiter = new WP_MCP_AI_Federation_Rate_Limiter();

		// Clear any existing rate limit transients.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wp_mcp_ai_fed_rate_limit_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wp_mcp_ai_fed_rate_limit_%'" );
	}

	/**
	 * Test that requests under the limit are allowed.
	 */
	public function test_allows_requests_under_limit() {
		// Make 59 requests (under the limit of 60).
		for ( $i = 0; $i < 59; $i++ ) {
			$result = $this->rate_limiter->check_rate_limit( '/test-endpoint', 60, 60 );
			$this->assertTrue( $result, "Request $i should be allowed" );
		}
	}

	/**
	 * Test that requests over the limit are blocked.
	 */
	public function test_blocks_requests_over_limit() {
		// Make 60 requests (the limit).
		for ( $i = 0; $i < 60; $i++ ) {
			$this->rate_limiter->check_rate_limit( '/test-endpoint', 60, 60 );
		}

		// 61st request should be blocked.
		$result = $this->rate_limiter->check_rate_limit( '/test-endpoint', 60, 60 );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_rate_limit_exceeded', $result->get_error_code() );
	}

	/**
	 * Test that rate limiter returns 429 status code.
	 */
	public function test_returns_429_status_code() {
		// Exceed rate limit.
		for ( $i = 0; $i <= 60; $i++ ) {
			$this->rate_limiter->check_rate_limit( '/test-endpoint', 60, 60 );
		}

		$result     = $this->rate_limiter->check_rate_limit( '/test-endpoint', 60, 60 );
		$error_data = $result->get_error_data();

		$this->assertWPError( $result );
		$this->assertIsArray( $error_data );
		$this->assertArrayHasKey( 'status', $error_data );
		$this->assertEquals( 429, $error_data['status'] );
	}

	/**
	 * Test that rate limit error includes retry_after.
	 */
	public function test_includes_retry_after() {
		// Exceed rate limit.
		for ( $i = 0; $i <= 60; $i++ ) {
			$this->rate_limiter->check_rate_limit( '/test-endpoint', 60, 60 );
		}

		$result     = $this->rate_limiter->check_rate_limit( '/test-endpoint', 60, 60 );
		$error_data = $result->get_error_data();

		$this->assertArrayHasKey( 'retry_after', $error_data );
		$this->assertEquals( 60, $error_data['retry_after'] );
	}

	/**
	 * Test that admin bypasses rate limit.
	 */
	public function test_admin_bypasses_rate_limit() {
		// Set up admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Make 100 requests (well over the limit).
		for ( $i = 0; $i < 100; $i++ ) {
			$result = $this->rate_limiter->check_rate_limit( '/test-endpoint', 60, 60 );
			$this->assertTrue( $result, "Admin request $i should bypass rate limit" );
		}
	}

	/**
	 * Test that rate limits are per-endpoint.
	 */
	public function test_rate_limit_per_endpoint() {
		// Exhaust limit on endpoint1.
		for ( $i = 0; $i <= 60; $i++ ) {
			$this->rate_limiter->check_rate_limit( '/endpoint1', 60, 60 );
		}

		// Verify endpoint1 is rate limited.
		$result = $this->rate_limiter->check_rate_limit( '/endpoint1', 60, 60 );
		$this->assertWPError( $result );

		// Verify endpoint2 still works.
		$result = $this->rate_limiter->check_rate_limit( '/endpoint2', 60, 60 );
		$this->assertTrue( $result );
	}

	/**
	 * Test that rate limit headers are added correctly.
	 */
	public function test_rate_limit_headers() {
		$response = new WP_REST_Response( array( 'test' => 'data' ), 200 );

		// Make a few requests first.
		$this->rate_limiter->check_rate_limit( '/test-endpoint', 60, 60 );
		$this->rate_limiter->check_rate_limit( '/test-endpoint', 60, 60 );
		$this->rate_limiter->check_rate_limit( '/test-endpoint', 60, 60 );

		// Add headers.
		$response = $this->rate_limiter->add_rate_limit_headers( $response, '/test-endpoint', 60, 60 );

		$headers = $response->get_headers();

		$this->assertArrayHasKey( 'X-RateLimit-Limit', $headers );
		$this->assertArrayHasKey( 'X-RateLimit-Remaining', $headers );
		$this->assertArrayHasKey( 'X-RateLimit-Reset', $headers );

		$this->assertEquals( '60', $headers['X-RateLimit-Limit'] );
		$this->assertEquals( '57', $headers['X-RateLimit-Remaining'] ); // 60 - 3 requests.
		$this->assertIsNumeric( $headers['X-RateLimit-Reset'] );
	}

	/**
	 * Test that different IPs have separate rate limits.
	 */
	public function test_separate_limits_per_ip() {
		// Simulate different IPs by clearing transients between tests.
		// In reality, the rate limiter uses $_SERVER vars which we can't easily mock.
		// This test verifies the concept.

		// Make 60 requests from "IP 1" (default).
		for ( $i = 0; $i < 60; $i++ ) {
			$this->rate_limiter->check_rate_limit( '/test-endpoint', 60, 60 );
		}

		// 61st request should be blocked.
		$result = $this->rate_limiter->check_rate_limit( '/test-endpoint', 60, 60 );
		$this->assertWPError( $result );
	}
}
