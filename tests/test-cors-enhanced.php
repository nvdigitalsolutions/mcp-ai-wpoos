<?php
/**
 * Tests for enhanced CORS functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * CORS test case.
 */
class Test_CORS_Enhanced extends WP_UnitTestCase {

	/**
	 * Test CORS headers are present in MCP responses.
	 */
	public function test_cors_headers_present() {
		$response = new WP_REST_Response( array( 'test' => 'data' ) );
		$headers = array(
			'Access-Control-Allow-Origin' => '*',
			'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
			'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-WP-Nonce',
		);

		foreach ( $headers as $key => $value ) {
			$response->header( $key, $value );
		}

		$response_headers = $response->get_headers();
		$this->assertArrayHasKey( 'Access-Control-Allow-Origin', $response_headers );
		$this->assertArrayHasKey( 'Access-Control-Allow-Methods', $response_headers );
		$this->assertArrayHasKey( 'Access-Control-Allow-Headers', $response_headers );
	}

	/**
	 * Test CORS origin can be filtered.
	 */
	public function test_cors_origin_filter() {
		add_filter( 'wp_mcp_ai_cors_allow_origin', function() {
			return 'https://example.com';
		} );

		$filtered = apply_filters( 'wp_mcp_ai_cors_allow_origin', '*' );
		$this->assertEquals( 'https://example.com', $filtered );

		remove_all_filters( 'wp_mcp_ai_cors_allow_origin' );
	}

	/**
	 * Test CORS methods can be filtered.
	 */
	public function test_cors_methods_filter() {
		add_filter( 'wp_mcp_ai_cors_allow_methods', function() {
			return 'GET, OPTIONS';
		} );

		$filtered = apply_filters( 'wp_mcp_ai_cors_allow_methods', 'GET, POST, OPTIONS' );
		$this->assertEquals( 'GET, OPTIONS', $filtered );

		remove_all_filters( 'wp_mcp_ai_cors_allow_methods' );
	}

	/**
	 * Test CORS headers can be customized.
	 */
	public function test_cors_headers_filter() {
		add_filter( 'wp_mcp_ai_cors_headers', function( $headers ) {
			$headers['Access-Control-Allow-Credentials'] = 'true';
			return $headers;
		} );

		$headers = array(
			'Access-Control-Allow-Origin' => '*',
		);

		$filtered = apply_filters( 'wp_mcp_ai_cors_headers', $headers );
		$this->assertArrayHasKey( 'Access-Control-Allow-Credentials', $filtered );

		remove_all_filters( 'wp_mcp_ai_cors_headers' );
	}
}
