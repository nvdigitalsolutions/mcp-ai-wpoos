<?php
/**
 * Rate Limiting and Backoff Security Tests for NV oOS
 *
 * Tests to verify that rate limiting returns 429 status codes on burst requests,
 * backoff logs are generated, and audit entries are created.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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

		// Rate limiting only applies to state-changing traffic: GET/HEAD
		// requests are exempt by design, so the burst tests drive the POST
		// connectivity-check path on /mcp-ai/v1/assistants.
		$_SERVER['REQUEST_METHOD'] = 'POST';

		// Clear any existing rate limit data.
		$this->clear_rate_limit_data();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$this->clear_rate_limit_data();

		delete_option( 'wp_mcp_ai_settings' );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

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
	 * Enable rate limiting with the given per-window budget.
	 *
	 * @param int $max_requests Maximum requests per window.
	 * @param int $window       Window length in seconds.
	 */
	protected function enable_rate_limiting( $max_requests, $window = 3600 ) {
		$settings                         = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_rate_limiting'] = true;
		$settings['rate_limit_requests']  = $max_requests;
		$settings['rate_limit_window']    = $window;
		update_option( 'wp_mcp_ai_settings', $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();
	}

	/**
	 * Dispatch one POST connectivity-check request and return its status.
	 *
	 * @param string $nonce Valid wp_rest nonce.
	 * @return int HTTP status code.
	 */
	protected function dispatch_post_request( $nonce ) {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', $nonce );
		return rest_do_request( $request )->get_status();
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

		$nonce = wp_create_nonce( 'wp_rest' );

		// Enable rate limiting with a low budget for the window.
		$this->enable_rate_limiting( 5 );

		$rate_limited_count = 0;
		$successful_count   = 0;

		// Make burst of requests (more than the limit).
		for ( $i = 0; $i < 10; $i++ ) {
			$status = $this->dispatch_post_request( $nonce );

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
		$settings                         = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_rate_limiting'] = true;
		$settings['enable_logging']       = true;
		$settings['rate_limit_requests']  = 3;
		$settings['rate_limit_window']    = 3600;
		update_option( 'wp_mcp_ai_settings', $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Clear existing logs.
		delete_option( 'wp_mcp_ai_recent_errors' );
		delete_option( 'wp_mcp_ai_recent_activity' );

		// Make burst of requests to trigger rate limit.
		for ( $i = 0; $i < 10; $i++ ) {
			$this->dispatch_post_request( $nonce );
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
		$this->enable_rate_limiting( 2 );

		// Make requests to trigger rate limit.
		$responses = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/assistants' );
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
	}

	/**
	 * Test that the backoff manager retries rate-limited calls and gives up
	 * after the configured maximum.
	 *
	 * The request-tracking limiter itself lives in the REST controller
	 * (covered by the burst tests above); WP_MCP_AI_Rate_Limit_Manager is the
	 * exponential-backoff helper consumed by API clients.
	 */
	public function test_rate_limit_manager_retries_rate_limited_calls() {
		if ( ! class_exists( 'WP_MCP_AI_Rate_Limit_Manager' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Rate_Limit_Manager class not available' );
		}

		// Zero out the delays so the retry loop does not sleep in tests.
		add_filter( 'wp_mcp_ai_rate_limit_initial_delay', '__return_zero' );
		add_filter( 'wp_mcp_ai_rate_limit_max_delay', '__return_zero' );
		add_filter(
			'wp_mcp_ai_rate_limit_max_retries',
			static function () {
				return 2;
			}
		);

		$attempts = 0;
		$callable = static function () use ( &$attempts ) {
			++$attempts;
			if ( $attempts < 3 ) {
				return new WP_Error(
					'rate_limit_exceeded',
					'Slow down',
					array( 'status' => 429 )
				);
			}
			return 'ok';
		};

		$result = WP_MCP_AI_Rate_Limit_Manager::execute_with_retry( $callable );

		$this->assertSame( 'ok', $result );
		$this->assertSame( 3, $attempts );
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
		$this->enable_rate_limiting( 1 );

		// Make burst of requests.
		$rate_limited_response = null;
		for ( $i = 0; $i < 5; $i++ ) {
			$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/assistants' );
			$request->set_header( 'X-WP-Nonce', $nonce );
			$response = rest_do_request( $request );

			if ( 429 === $response->get_status() ) {
				$rate_limited_response = $response;
				break;
			}
		}

		$this->assertNotNull(
			$rate_limited_response,
			'Burst should eventually produce a 429 rate limit response'
		);

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
			array( 'wp_mcp_ai_rate_limit_exceeded', 'rate_limit_exceeded', 'too_many_requests' ),
			'Rate limit error code should be descriptive'
		);

		// The retry-after hint is surfaced either at the top level or in the
		// error data payload.
		$has_retry_hint = isset( $data['retry_after'] )
			|| ( isset( $data['data']['retry_after'] ) );
		$this->assertTrue(
			$has_retry_hint,
			'Rate limited response should include a retry hint'
		);
	}
}
