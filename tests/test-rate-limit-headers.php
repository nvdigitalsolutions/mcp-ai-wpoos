<?php
/**
 * Tests for WP_MCP_AI_Rate_Limit_Headers.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test IETF rate-limit response headers.
 */
class Test_RateLimit_Headers extends WP_UnitTestCase {

	/**
	 * Clean up after test.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_chat_rate_limit' );
		remove_all_filters( 'wp_mcp_ai_chat_rate_limit_window' );

		parent::tearDown();
	}

	/**
	 * Build a REST request against a plugin route.
	 *
	 * @return WP_REST_Request
	 */
	private function plugin_request() {
		return new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
	}

	/**
	 * Test normalized rate-limit errors receive the standard headers.
	 */
	public function test_normalized_error_gets_headers() {
		$response = new WP_REST_Response(
			array(
				'data' => array(
					'code'    => 'rate_limited',
					'message' => 'Too many requests.',
					'data'    => array( 'retry_after' => 45 ),
				),
			)
		);

		$result  = WP_MCP_AI_Rate_Limit_Headers::add_headers( $response, null, $this->plugin_request() );
		$headers = $result->get_headers();

		$this->assertSame( 'quota;q=60;w=60', $headers['RateLimit-Policy'] );
		$this->assertSame( 'quota;r=0;t=45', $headers['RateLimit'] );
		$this->assertSame( '45', $headers['Retry-After'] );
	}

	/**
	 * Test that the headers honor the rate-limit filters.
	 */
	public function test_headers_honor_filters() {
		add_filter( 'wp_mcp_ai_chat_rate_limit', array( __CLASS__, 'filter_max_requests' ) );
		add_filter( 'wp_mcp_ai_chat_rate_limit_window', array( __CLASS__, 'filter_window' ) );

		$response = new WP_REST_Response(
			array(
				'data' => array(
					'code' => 'rate_limited',
					'data' => array( 'retry_after' => 30 ),
				),
			)
		);

		$result  = WP_MCP_AI_Rate_Limit_Headers::add_headers( $response, null, $this->plugin_request() );
		$headers = $result->get_headers();

		$this->assertSame( 'quota;q=120;w=30', $headers['RateLimit-Policy'] );
		$this->assertSame( 'quota;r=0;t=30', $headers['RateLimit'] );
	}

	/**
	 * Filter callback: max requests.
	 *
	 * @return int
	 */
	public static function filter_max_requests() {
		return 120;
	}

	/**
	 * Filter callback: window seconds.
	 *
	 * @return int
	 */
	public static function filter_window() {
		return 30;
	}

	/**
	 * Test raw WP_Error envelopes receive the headers.
	 */
	public function test_wp_error_envelope_gets_headers() {
		$response = new WP_REST_Response(
			array(
				'data' => array(
					'errors'     => array(
						'rate_limited' => array( 'Too many requests.' ),
					),
					'error_data' => array(
						'rate_limited' => array(
							'status'      => 429,
							'retry_after' => 60,
						),
					),
				),
			)
		);

		$result  = WP_MCP_AI_Rate_Limit_Headers::add_headers( $response, null, $this->plugin_request() );
		$headers = $result->get_headers();

		$this->assertSame( 'quota;r=0;t=60', $headers['RateLimit'] );
		$this->assertSame( '60', $headers['Retry-After'] );
	}

	/**
	 * Test HTTP 429 responses get headers even without an error body.
	 */
	public function test_http_429_gets_headers() {
		$response = new WP_REST_Response( array( 'message' => 'No.' ), 429 );

		$result  = WP_MCP_AI_Rate_Limit_Headers::add_headers( $response, null, $this->plugin_request() );
		$headers = $result->get_headers();

		$this->assertSame( 'quota;q=60;w=60', $headers['RateLimit-Policy'] );
		$this->assertSame( 'quota;r=0;t=60', $headers['RateLimit'] );
		$this->assertSame( '60', $headers['Retry-After'] );
	}

	/**
	 * Test non-plugin routes are left untouched.
	 */
	public function test_non_plugin_routes_untouched() {
		$request  = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$response = new WP_REST_Response( array( 'data' => array( 'code' => 'rate_limited' ) ) );

		$result  = WP_MCP_AI_Rate_Limit_Headers::add_headers( $response, null, $request );
		$headers = $result->get_headers();

		$this->assertArrayNotHasKey( 'RateLimit-Policy', $headers );
		$this->assertArrayNotHasKey( 'RateLimit', $headers );
		$this->assertArrayNotHasKey( 'Retry-After', $headers );
	}

	/**
	 * Test successful responses are left untouched.
	 */
	public function test_success_responses_untouched() {
		$response = new WP_REST_Response( array( 'data' => array( 'content' => 'Done.' ) ) );

		$result  = WP_MCP_AI_Rate_Limit_Headers::add_headers( $response, null, $this->plugin_request() );
		$headers = $result->get_headers();

		$this->assertArrayNotHasKey( 'RateLimit-Policy', $headers );
		$this->assertArrayNotHasKey( 'RateLimit', $headers );
		$this->assertArrayNotHasKey( 'Retry-After', $headers );
	}
}
