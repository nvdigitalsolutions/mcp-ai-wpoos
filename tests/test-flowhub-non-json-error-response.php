<?php
/**
 * Test Flowhub Client handles non-JSON error responses correctly.
 *
 * This test verifies the fix for the issue where Flowhub returns HTML
 * error pages (e.g., 403 Forbidden from nginx) instead of JSON responses.
 * The client should report the HTTP error code rather than "malformed JSON".
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Flowhub client non-JSON error response handling.
 */
class Test_Flowhub_Non_JSON_Error_Response extends WP_UnitTestCase {

	/**
	 * Test that 403 HTML response returns proper HTTP error, not "malformed JSON".
	 */
	public function test_403_html_response_returns_http_error() {
		if ( ! class_exists( 'WP_MCP_AI_Flowhub_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-flowhub-client.php';
		}

		// Mock the wp_remote_request function to return a 403 HTML response.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				// Only mock Flowhub API requests.
				if ( false === strpos( $url, 'api.flowhub.co' ) ) {
					return $preempt;
				}

				// Return a 403 Forbidden HTML response (like nginx error page).
				return array(
					'response' => array(
						'code'    => 403,
						'message' => 'Forbidden',
					),
					'body'     => '<html>
<head><title>403 Forbidden</title></head>
<body>
<center><h1>403 Forbidden</h1></center>
<hr><center>nginx</center>
</body>
</html>
',
					'headers'  => array(
						'content-type' => 'text/html',
					),
				);
			},
			10,
			3
		);

		// Create settings with dummy credentials to bypass validation.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'flowhub_client_id' => 'test_client_id',
				'flowhub_api_key'   => 'test_api_key',
			)
		);

		$client = new WP_MCP_AI_Flowhub_Client();
		$result = $client->get_inventory();

		// Assert that we get a WP_Error.
		$this->assertInstanceOf( WP_Error::class, $result, 'Should return WP_Error for 403 response' );

		// Assert that the error code is the API error, not invalid response.
		$this->assertEquals( 'wp_mcp_ai_api_error', $result->get_error_code(), 'Error code should be api_error, not invalid_response' );

		// Assert that the error message mentions HTTP 403, not "malformed JSON".
		$error_message = $result->get_error_message();
		$this->assertStringContainsString( '403', $error_message, 'Error message should mention HTTP 403' );
		$this->assertStringNotContainsString( 'malformed JSON', $error_message, 'Error message should not mention malformed JSON' );

		// Assert that the error data includes the status code and HTML body.
		$error_data = $result->get_error_data();
		$this->assertIsArray( $error_data, 'Error data should be an array' );
		$this->assertEquals( 403, $error_data['status'], 'Status should be 403' );
		$this->assertIsString( $error_data['body'], 'Body should be a string' );
		$this->assertStringContainsString( '403 Forbidden', $error_data['body'], 'Body should contain the HTML error' );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test that 500 HTML response returns proper HTTP error, not "malformed JSON".
	 */
	public function test_500_html_response_returns_http_error() {
		if ( ! class_exists( 'WP_MCP_AI_Flowhub_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-flowhub-client.php';
		}

		// Mock the wp_remote_request function to return a 500 HTML response.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				// Only mock Flowhub API requests.
				if ( false === strpos( $url, 'api.flowhub.co' ) ) {
					return $preempt;
				}

				// Return a 500 Internal Server Error HTML response.
				return array(
					'response' => array(
						'code'    => 500,
						'message' => 'Internal Server Error',
					),
					'body'     => '<html><head><title>500 Internal Server Error</title></head><body><h1>Internal Server Error</h1></body></html>',
					'headers'  => array(
						'content-type' => 'text/html',
					),
				);
			},
			10,
			3
		);

		// Create settings with dummy credentials to bypass validation.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'flowhub_client_id' => 'test_client_id',
				'flowhub_api_key'   => 'test_api_key',
			)
		);

		$client = new WP_MCP_AI_Flowhub_Client();
		$result = $client->get_orders();

		// Assert that we get a WP_Error.
		$this->assertInstanceOf( WP_Error::class, $result, 'Should return WP_Error for 500 response' );

		// Assert that the error message mentions HTTP 500, not "malformed JSON".
		$error_message = $result->get_error_message();
		$this->assertStringContainsString( '500', $error_message, 'Error message should mention HTTP 500' );
		$this->assertStringNotContainsString( 'malformed JSON', $error_message, 'Error message should not mention malformed JSON' );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test that JSON error responses still work correctly.
	 */
	public function test_json_error_response_still_works() {
		if ( ! class_exists( 'WP_MCP_AI_Flowhub_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-flowhub-client.php';
		}

		// Mock the wp_remote_request function to return a JSON error response.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				// Only mock Flowhub API requests.
				if ( false === strpos( $url, 'api.flowhub.co' ) ) {
					return $preempt;
				}

				// Return a 401 Unauthorized JSON response.
				return array(
					'response' => array(
						'code'    => 401,
						'message' => 'Unauthorized',
					),
					'body'     => wp_json_encode(
						array(
							'error'   => 'invalid_credentials',
							'message' => 'Invalid API key or client ID',
						)
					),
					'headers'  => array(
						'content-type' => 'application/json',
					),
				);
			},
			10,
			3
		);

		// Create settings with dummy credentials to bypass validation.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'flowhub_client_id' => 'test_client_id',
				'flowhub_api_key'   => 'test_api_key',
			)
		);

		$client = new WP_MCP_AI_Flowhub_Client();
		$result = $client->get_customers();

		// Assert that we get a WP_Error.
		$this->assertInstanceOf( WP_Error::class, $result, 'Should return WP_Error for 401 response' );

		// Assert that the error message includes the JSON error message.
		$error_message = $result->get_error_message();
		$this->assertStringContainsString( 'Invalid API key or client ID', $error_message, 'Error message should include JSON error message' );

		// Assert that the error data includes decoded JSON.
		$error_data = $result->get_error_data();
		$this->assertIsArray( $error_data, 'Error data should be an array' );
		$this->assertEquals( 401, $error_data['status'], 'Status should be 401' );
		$this->assertIsArray( $error_data['body'], 'Body should be decoded JSON array' );
		$this->assertEquals( 'invalid_credentials', $error_data['body']['error'], 'Body should contain error field' );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test that successful responses with valid JSON still work correctly.
	 */
	public function test_success_response_with_json_works() {
		if ( ! class_exists( 'WP_MCP_AI_Flowhub_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-flowhub-client.php';
		}

		// Mock the wp_remote_request function to return a successful JSON response.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				// Only mock Flowhub API requests.
				if ( false === strpos( $url, 'api.flowhub.co' ) ) {
					return $preempt;
				}

				// Return a successful JSON response.
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'status' => 200,
							'data'   => array(
								array(
									'id'   => 1,
									'name' => 'Test Product',
								),
							),
						)
					),
					'headers'  => array(
						'content-type' => 'application/json',
					),
				);
			},
			10,
			3
		);

		// Create settings with dummy credentials to bypass validation.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'flowhub_client_id' => 'test_client_id',
				'flowhub_api_key'   => 'test_api_key',
			)
		);

		$client = new WP_MCP_AI_Flowhub_Client();
		$result = $client->get_products();

		// Assert that we get a successful array response (not WP_Error).
		$this->assertIsArray( $result, 'Should return array for successful response' );
		$this->assertNotInstanceOf( WP_Error::class, $result, 'Should not return WP_Error for successful response' );

		// Assert that the data is unwrapped correctly.
		$this->assertCount( 1, $result, 'Should have one product' );
		$this->assertEquals( 1, $result[0]['id'], 'Product ID should be 1' );
		$this->assertEquals( 'Test Product', $result[0]['name'], 'Product name should be correct' );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test that 200 response with malformed JSON returns proper error.
	 */
	public function test_success_response_with_malformed_json() {
		if ( ! class_exists( 'WP_MCP_AI_Flowhub_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-flowhub-client.php';
		}

		// Mock the wp_remote_request function to return malformed JSON in a 200 response.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				// Only mock Flowhub API requests.
				if ( false === strpos( $url, 'api.flowhub.co' ) ) {
					return $preempt;
				}

				// Return a 200 response with malformed JSON.
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => '{"invalid": "json", "missing": }',
					'headers'  => array(
						'content-type' => 'application/json',
					),
				);
			},
			10,
			3
		);

		// Create settings with dummy credentials to bypass validation.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'flowhub_client_id' => 'test_client_id',
				'flowhub_api_key'   => 'test_api_key',
			)
		);

		$client = new WP_MCP_AI_Flowhub_Client();
		$result = $client->get_products();

		// Assert that we get a WP_Error.
		$this->assertInstanceOf( WP_Error::class, $result, 'Should return WP_Error for malformed JSON in success response' );

		// Assert that the error code is invalid_response for successful HTTP codes with malformed JSON.
		$this->assertEquals( 'wp_mcp_ai_invalid_response', $result->get_error_code(), 'Error code should be invalid_response for malformed JSON in 200 response' );

		// Assert that the error message mentions malformed JSON.
		$error_message = $result->get_error_message();
		$this->assertStringContainsString( 'malformed JSON', $error_message, 'Error message should mention malformed JSON for 200 response' );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
		remove_all_filters( 'pre_http_request' );
	}
}
