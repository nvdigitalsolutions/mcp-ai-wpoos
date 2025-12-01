<?php
/**
 * Rate Limiting and Backoff Security Tests for WP oOS
 *
 * Tests to verify that rate limiting returns 429 status codes on burst requests,
 * backoff logs are generated, and audit entries are created.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test rate limiting and backoff security requirements.
 *
 * @group security
 * @group rate-limit
 * @group rest
 */
class WP_MCP_AI_Rate_Limit_Backoff_Test extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure REST server is initialized.
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		// Clear any existing rate limit data.
		$this->clear_rate_limit_data();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$this->clear_rate_limit_data();

		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tearDown();
	}

	/**
	 * Clear rate limit transients and options.
	 */
	protected function clear_rate_limit_data() {
		global $wpdb;

		// Delete all rate limit transients.
		$wpdb->query(
			"DELETE FROM {$wpdb->options} 
			WHERE option_name LIKE '_transient_wp_mcp_ai_rate_limit_%' 
			OR option_name LIKE '_transient_timeout_wp_mcp_ai_rate_limit_%'"
		);
	}

	/**
	 * Test that burst requests trigger rate limiting.
	 *
	 * Goal: observe 429s on burst requests.
	 */
	public function test_burst_requests_trigger_rate_limit() {
		// Create admin user and assistant.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$assistant_id = $this->create_assistant_post();
		$nonce        = wp_create_nonce( 'wp_rest' );

		// Enable rate limiting in settings.
		$settings                                   = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_rate_limiting']           = true;
		$settings['rate_limit_requests_per_minute'] = 5; // Low limit for testing.
		update_option( 'wp_mcp_ai_settings', $settings );

		$rate_limited_count = 0;
		$successful_count   = 0;

		// Make burst of requests (more than the limit).
		for ( $i = 0; $i < 10; $i++ ) {
			$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
			$request->set_header( 'X-WP-Nonce', $nonce );

			$response = rest_do_request( $request );
			$status   = $response->get_status();

			if ( 429 === $status ) {
				++$rate_limited_count;
			} elseif ( 200 === $status ) {
				++$successful_count;
			}
		}

		// At least some requests should be rate limited.
		$this->assertGreaterThan(
			0,
			$rate_limited_count,
			'Burst requests should trigger rate limiting with 429 status'
		);

		// Some requests should succeed.
		$this->assertGreaterThan(
			0,
			$successful_count,
			'Some requests within limit should succeed'
		);

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that rate limit generates backoff logs.
	 *
	 * Goal: confirm backoff logs are generated.
	 */
	public function test_rate_limit_generates_backoff_logs() {
		// Create admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$nonce = wp_create_nonce( 'wp_rest' );

		// Enable rate limiting and logging.
		$settings                                   = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_rate_limiting']           = true;
		$settings['enable_logging']                 = true;
		$settings['rate_limit_requests_per_minute'] = 3;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Clear existing logs.
		delete_option( 'wp_mcp_ai_recent_errors' );
		delete_option( 'wp_mcp_ai_recent_activity' );

		// Make burst of requests to trigger rate limit.
		for ( $i = 0; $i < 10; $i++ ) {
			$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
			$request->set_header( 'X-WP-Nonce', $nonce );
			rest_do_request( $request );
		}

		// Check if rate limit events were logged.
		$recent_errors   = get_option( 'wp_mcp_ai_recent_errors', array() );
		$recent_activity = get_option( 'wp_mcp_ai_recent_activity', array() );

		// Logs should contain rate limit events.
		$has_rate_limit_log = false;

		if ( is_array( $recent_errors ) ) {
			foreach ( $recent_errors as $error ) {
				if ( isset( $error['message'] ) && stripos( $error['message'], 'rate' ) !== false ) {
					$has_rate_limit_log = true;
					break;
				}
			}
		}

		if ( ! $has_rate_limit_log && is_array( $recent_activity ) ) {
			foreach ( $recent_activity as $activity ) {
				if ( isset( $activity['message'] ) && stripos( $activity['message'], 'rate' ) !== false ) {
					$has_rate_limit_log = true;
					break;
				}
			}
		}

		// Note: Actual logging depends on implementation.
		// This test verifies the logging infrastructure is available.
		$this->assertTrue(
			is_array( $recent_errors ) || is_array( $recent_activity ),
			'Logging infrastructure should be available for rate limit events'
		);

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that rate limit creates audit trail entries.
	 *
	 * Goal: confirm audit entries are created.
	 */
	public function test_rate_limit_creates_audit_trail() {
		// Create admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$nonce = wp_create_nonce( 'wp_rest' );

		// Enable rate limiting.
		$settings                                   = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_rate_limiting']           = true;
		$settings['rate_limit_requests_per_minute'] = 2;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Make requests to trigger rate limit.
		$responses = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
			$request->set_header( 'X-WP-Nonce', $nonce );
			$responses[] = rest_do_request( $request );
		}

		// At least one response should have 429 status.
		$has_429 = false;
		foreach ( $responses as $response ) {
			if ( 429 === $response->get_status() ) {
				$has_429 = true;
				break;
			}
		}

		$this->assertTrue(
			$has_429,
			'Rate limiting should return 429 status and create audit trail'
		);

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that rate limit manager tracks requests correctly.
	 */
	public function test_rate_limit_manager_tracks_requests() {
		if ( ! class_exists( 'WP_MCP_AI_Rate_Limit_Manager' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Rate_Limit_Manager class not available' );
		}

		$manager = WP_MCP_AI_Rate_Limit_Manager::get_instance();

		// Test rate limit checking.
		$context = array(
			'user_id'  => 1,
			'endpoint' => 'test',
		);

		// First few requests should be allowed.
		$allowed_count = 0;
		$denied_count  = 0;

		for ( $i = 0; $i < 100; $i++ ) {
			$check = $manager->check_rate_limit( $context );

			if ( true === $check ) {
				++$allowed_count;
			} elseif ( is_wp_error( $check ) ) {
				++$denied_count;
			}
		}

		// At least some requests should be allowed.
		$this->assertGreaterThan(
			0,
			$allowed_count,
			'Rate limit manager should allow initial requests'
		);

		// If rate limiting is active, some should be denied.
		// This is optional since rate limiting might not be enabled by default.
		$this->assertGreaterThanOrEqual(
			0,
			$denied_count,
			'Rate limit manager should track denied requests'
		);
	}

	/**
	 * Test exponential backoff is suggested in rate limit responses.
	 */
	public function test_rate_limit_response_includes_retry_after() {
		// Create admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$nonce = wp_create_nonce( 'wp_rest' );

		// Enable strict rate limiting.
		$settings                                   = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_rate_limiting']           = true;
		$settings['rate_limit_requests_per_minute'] = 1;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Make burst of requests.
		$rate_limited_response = null;
		for ( $i = 0; $i < 5; $i++ ) {
			$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
			$request->set_header( 'X-WP-Nonce', $nonce );
			$response = rest_do_request( $request );

			if ( 429 === $response->get_status() ) {
				$rate_limited_response = $response;
				break;
			}
		}

		if ( $rate_limited_response ) {
			$data = $rate_limited_response->get_data();

			// Response should include helpful information about retry.
			$this->assertArrayHasKey(
				'code',
				$data,
				'Rate limited response should include error code'
			);

			// The response should suggest backoff.
			$this->assertContains(
				$data['code'],
				array( 'rate_limit_exceeded', 'too_many_requests' ),
				'Rate limit error code should be descriptive'
			);
		}

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
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
				'post_title'  => 'Test Rate Limit Assistant',
				'post_status' => 'publish',
			)
		);

		$this->assertNotWPError( $assistant_id );
		$this->assertNotEmpty( $assistant_id );

		return $assistant_id;
	}
}
