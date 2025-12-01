<?php
/**
 * Tests for chat request rate limiting enforcement.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that rate limiting is properly enforced on chat requests.
 *
 * @group security
 * @group rate-limit
 * @group rest
 */
class WP_MCP_AI_Chat_Rate_Limiting_Test extends WP_UnitTestCase {

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
	 * Clear rate limit transients.
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
	 * Create a test assistant post.
	 *
	 * @return int Assistant post ID.
	 */
	protected function create_assistant_post() {
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant',
			)
		);

		// Set minimal assistant configuration.
		update_post_meta( $assistant_id, '_wp_mcp_ai_provider', 'openai' );
		update_post_meta( $assistant_id, '_wp_mcp_ai_model', 'gpt-4' );
		update_post_meta( $assistant_id, '_wp_mcp_ai_temperature', 0.7 );

		return $assistant_id;
	}

	/**
	 * Test that rate limiting prevents excessive chat requests.
	 */
	public function test_rate_limiting_prevents_excessive_chat_requests() {
		// Create admin user and assistant.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$assistant_id = $this->create_assistant_post();
		$nonce        = wp_create_nonce( 'wp_rest' );

		// Enable rate limiting with low limits for testing.
		$settings                         = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_rate_limiting'] = true;
		$settings['rate_limit_requests']  = 3; // Allow only 3 requests.
		$settings['rate_limit_window']    = 60; // Per 60 seconds.
		update_option( 'wp_mcp_ai_settings', $settings );

		$successful_count   = 0;
		$rate_limited_count = 0;

		// Make more requests than the limit.
		for ( $i = 0; $i < 6; $i++ ) {
			$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
			$request->set_header( 'X-WP-Nonce', $nonce );
			$request->set_param( 'assistant_id', $assistant_id );
			$request->set_param(
				'messages',
				array(
					array(
						'role'    => 'user',
						'content' => 'Hello',
					),
				)
			);

			$response = rest_do_request( $request );
			$status   = $response->get_status();

			if ( 429 === $status ) {
				++$rate_limited_count;
			} elseif ( 200 === $status || 500 === $status ) {
				// 500 can occur if provider is not configured, but permission check passed.
				++$successful_count;
			}
		}

		// At least 3 requests should be rate limited (requests 4, 5, 6).
		$this->assertGreaterThanOrEqual(
			3,
			$rate_limited_count,
			'Requests exceeding the limit should be rate limited with 429 status'
		);

		// Exactly 3 requests should succeed (up to the limit).
		$this->assertEquals(
			3,
			$successful_count,
			'Requests within the limit should succeed (or fail with non-rate-limit errors)'
		);

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that rate limiting returns proper error response.
	 */
	public function test_rate_limiting_error_response_format() {
		// Create admin user and assistant.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$assistant_id = $this->create_assistant_post();
		$nonce        = wp_create_nonce( 'wp_rest' );

		// Enable strict rate limiting.
		$settings                         = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_rate_limiting'] = true;
		$settings['rate_limit_requests']  = 1; // Allow only 1 request.
		$settings['rate_limit_window']    = 60;
		update_option( 'wp_mcp_ai_settings', $settings );

		// First request should succeed.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'X-WP-Nonce', $nonce );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			)
		);
		$response = rest_do_request( $request );

		// Second request should be rate limited.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'X-WP-Nonce', $nonce );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello again',
				),
			)
		);
		$response = rest_do_request( $request );

		$this->assertEquals( 429, $response->get_status(), 'Rate limited response should return 429 status' );

		$data = $response->get_data();

		$this->assertArrayHasKey( 'code', $data, 'Error response should include code' );
		$this->assertEquals( 'wp_mcp_ai_rate_limit_exceeded', $data['code'], 'Error code should indicate rate limit exceeded' );

		$this->assertArrayHasKey( 'message', $data, 'Error response should include message' );
		$this->assertStringContainsString( 'Rate limit exceeded', $data['message'], 'Message should describe rate limit' );

		$this->assertArrayHasKey( 'data', $data, 'Error response should include data' );
		$this->assertArrayHasKey( 'status', $data['data'], 'Error data should include status' );
		$this->assertEquals( 429, $data['data']['status'], 'Error data status should be 429' );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that rate limiting is disabled when setting is off.
	 */
	public function test_rate_limiting_disabled_when_setting_off() {
		// Create admin user and assistant.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$assistant_id = $this->create_assistant_post();
		$nonce        = wp_create_nonce( 'wp_rest' );

		// Disable rate limiting.
		$settings                         = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_rate_limiting'] = false;
		$settings['rate_limit_requests']  = 1; // Set low limit, but disabled.
		$settings['rate_limit_window']    = 60;
		update_option( 'wp_mcp_ai_settings', $settings );

		$non_rate_limited_count = 0;

		// Make many requests, none should be rate limited.
		for ( $i = 0; $i < 10; $i++ ) {
			$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
			$request->set_header( 'X-WP-Nonce', $nonce );
			$request->set_param( 'assistant_id', $assistant_id );
			$request->set_param(
				'messages',
				array(
					array(
						'role'    => 'user',
						'content' => "Hello $i",
					),
				)
			);

			$response = rest_do_request( $request );
			$status   = $response->get_status();

			if ( 429 !== $status ) {
				++$non_rate_limited_count;
			}
		}

		// All requests should NOT be rate limited.
		$this->assertEquals(
			10,
			$non_rate_limited_count,
			'When rate limiting is disabled, all requests should proceed (no 429 status)'
		);

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that rate limiting uses correct time window.
	 */
	public function test_rate_limiting_respects_time_window() {
		// Create admin user and assistant.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$assistant_id = $this->create_assistant_post();
		$nonce        = wp_create_nonce( 'wp_rest' );

		// Enable rate limiting with specific window.
		$settings                         = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_rate_limiting'] = true;
		$settings['rate_limit_requests']  = 2;
		$settings['rate_limit_window']    = 60; // 60 seconds window.
		update_option( 'wp_mcp_ai_settings', $settings );

		// Make 2 requests (should succeed).
		for ( $i = 0; $i < 2; $i++ ) {
			$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
			$request->set_header( 'X-WP-Nonce', $nonce );
			$request->set_param( 'assistant_id', $assistant_id );
			$request->set_param(
				'messages',
				array(
					array(
						'role'    => 'user',
						'content' => "Request $i",
					),
				)
			);
			$response = rest_do_request( $request );
		}

		// Third request should be rate limited.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'X-WP-Nonce', $nonce );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Request 3',
				),
			)
		);
		$response = rest_do_request( $request );

		$this->assertEquals( 429, $response->get_status(), 'Third request should be rate limited' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'retry_after', $data['data'] );
		$this->assertEquals( 60, $data['data']['retry_after'], 'Retry-after should match time window' );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}
}
