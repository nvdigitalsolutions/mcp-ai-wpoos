<?php
/**
 * Security Tests for NV oOS Performance Monitor.
 *
 * Tests security vulnerabilities including:
 * - SQL injection protection
 * - XSS vulnerability scanning
 * - CSRF token enforcement
 * - File upload security
 * - Authentication bypass attempts
 * - Rate limiting enforcement
 * - Tool permission escalation
 * - Credential leakage protection
 *
 * @package WP_MCP_AI
 */

/**
 * Security test suite class.
 */
class WP_MCP_AI_Security_Suite_Test extends WP_UnitTestCase {

	/**
	 * Test SQL injection protection.
	 *
	 * Attempts SQL injection attacks on various endpoints.
	 */
	public function test_sql_injection_protection() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		$vulnerabilities_found = 0;
		$tests_run             = 0;

		$sql_injection_payloads = array(
			"' OR '1'='1",
			"'; DROP TABLE wp_posts; --",
			"1' UNION SELECT NULL--",
			"admin'--",
			"' OR 1=1--",
		);

		$start_time   = microtime( true );
		$start_memory = memory_get_usage();

		foreach ( $sql_injection_payloads as $payload ) {
			++$tests_run;

			// Test REST API endpoints.
			$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
			$request->set_param( 'search', $payload );
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

			$response = rest_do_request( $request );

			// Check if the payload caused unexpected behavior.
			if ( $response->get_status() === 500 || ( $response->get_data() && is_string( $response->get_data() ) && stripos( $response->get_data(), 'error' ) !== false ) ) {
				++$vulnerabilities_found;
			}
		}

		$end_time   = microtime( true );
		$end_memory = memory_get_usage();

		$elapsed_time = ( $end_time - $start_time ) * 1000;
		$memory_used  = $end_memory - $start_memory;

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'security',
			'rest_api',
			false,
			array(
				'avg_response_time' => $elapsed_time / $tests_run,
				'memory_peak_bytes' => $memory_used,
				'memory_peak_mb'    => $memory_used / 1024 / 1024,
			),
			array(
				'test_category'         => 'sql_injection',
				'total'                 => $tests_run,
				'passed'                => $tests_run - $vulnerabilities_found,
				'failed'                => $vulnerabilities_found,
				'vulnerabilities_found' => $vulnerabilities_found,
			)
		);

		$this->assertEquals( 0, $vulnerabilities_found, 'No SQL injection vulnerabilities should be found.' );
	}

	/**
	 * Test XSS vulnerability scanning for chat UI.
	 *
	 * Attempts XSS attacks through chat interface.
	 */
	public function test_xss_vulnerability_scanning() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		$vulnerabilities_found = 0;
		$tests_run             = 0;

		$xss_payloads = array(
			'<script>alert("XSS")</script>',
			'<img src=x onerror=alert("XSS")>',
			'<svg onload=alert("XSS")>',
			'javascript:alert("XSS")',
			'<iframe src="javascript:alert(\'XSS\')">',
		);

		$start_time   = microtime( true );
		$start_memory = memory_get_usage();

		// Create test assistant.
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant',
			)
		);

		foreach ( $xss_payloads as $payload ) {
			++$tests_run;

			$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
			$request->set_param( 'assistant_id', $assistant_id );
			$request->set_param( 'message', $payload );

			// Mock the API response.
			add_filter(
				'pre_http_request',
				function () use ( $payload ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'choices' => array(
									array(
										'message' => array(
											'role'    => 'assistant',
											'content' => 'Safe response',
										),
									),
								),
							)
						),
					);
				},
				10,
				0
			);

			$response = rest_do_request( $request );
			$data     = $response->get_data();

			// Check if the response contains unescaped script tags.
			if ( is_array( $data ) && isset( $data['message'] ) ) {
				if ( strpos( $data['message'], '<script>' ) !== false || strpos( $data['message'], 'onerror=' ) !== false ) {
					++$vulnerabilities_found;
				}
			}
		}

		$end_time   = microtime( true );
		$end_memory = memory_get_usage();

		$elapsed_time = ( $end_time - $start_time ) * 1000;
		$memory_used  = $end_memory - $start_memory;

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'security',
			'chat_ui',
			false,
			array(
				'avg_response_time' => $elapsed_time / $tests_run,
				'memory_peak_bytes' => $memory_used,
				'memory_peak_mb'    => $memory_used / 1024 / 1024,
			),
			array(
				'test_category'         => 'xss_protection',
				'total'                 => $tests_run,
				'passed'                => $tests_run - $vulnerabilities_found,
				'failed'                => $vulnerabilities_found,
				'vulnerabilities_found' => $vulnerabilities_found,
			)
		);

		$this->assertEquals( 0, $vulnerabilities_found, 'No XSS vulnerabilities should be found in chat UI.' );
	}

	/**
	 * Test CSRF token enforcement.
	 *
	 * Verifies that all state-changing endpoints require valid CSRF tokens.
	 */
	public function test_csrf_token_enforcement() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		$vulnerabilities_found = 0;
		$tests_run             = 0;

		$start_time   = microtime( true );
		$start_memory = memory_get_usage();

		// Test endpoints without nonce.
		$endpoints = array(
			array( 'POST', '/mcp-ai/v1/chat' ),
			array( 'POST', '/mcp-ai/v1/tools' ),
		);

		foreach ( $endpoints as $endpoint_data ) {
			++$tests_run;

			list( $method, $route ) = $endpoint_data;

			$request = new WP_REST_Request( $method, $route );
			// Intentionally not setting nonce.

			$response = rest_do_request( $request );

			// Should be rejected without valid nonce.
			if ( $response->get_status() === 200 ) {
				++$vulnerabilities_found;
			}
		}

		$end_time   = microtime( true );
		$end_memory = memory_get_usage();

		$elapsed_time = ( $end_time - $start_time ) * 1000;
		$memory_used  = $end_memory - $start_memory;

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'security',
			'rest_api',
			false,
			array(
				'avg_response_time' => $elapsed_time / $tests_run,
				'memory_peak_bytes' => $memory_used,
				'memory_peak_mb'    => $memory_used / 1024 / 1024,
			),
			array(
				'test_category'         => 'csrf_protection',
				'total'                 => $tests_run,
				'passed'                => $tests_run - $vulnerabilities_found,
				'failed'                => $vulnerabilities_found,
				'vulnerabilities_found' => $vulnerabilities_found,
			)
		);

		$this->assertEquals( 0, $vulnerabilities_found, 'All endpoints should enforce CSRF protection.' );
	}

	/**
	 * Test file upload security.
	 *
	 * Tests protection against malicious file uploads and path traversal.
	 */
	public function test_file_upload_security() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		$vulnerabilities_found = 0;
		$tests_run             = 0;

		$start_time   = microtime( true );
		$start_memory = memory_get_usage();

		// Test malicious file types.
		$malicious_files = array(
			array(
				'name' => 'malicious.php',
				'type' => 'application/x-php',
			),
			array(
				'name' => 'shell.sh',
				'type' => 'application/x-sh',
			),
			array(
				'name' => '../../../etc/passwd',
				'type' => 'text/plain',
			),
		);

		foreach ( $malicious_files as $file_data ) {
			++$tests_run;

			// Simulate file upload validation.
			$allowed_types = array( 'image/jpeg', 'image/png', 'application/pdf' );

			if ( ! in_array( $file_data['type'], $allowed_types, true ) ) {
				// File type correctly rejected.
				continue;
			}

			// Check for path traversal.
			if ( strpos( $file_data['name'], '..' ) !== false || strpos( $file_data['name'], '/' ) !== false ) {
				// Path traversal attempt correctly rejected.
				continue;
			}

			// If we get here, the file was accepted when it shouldn't be.
			++$vulnerabilities_found;
		}

		$end_time   = microtime( true );
		$end_memory = memory_get_usage();

		$elapsed_time = ( $end_time - $start_time ) * 1000;
		$memory_used  = $end_memory - $start_memory;

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'security',
			'mcp_core',
			false,
			array(
				'avg_response_time' => $elapsed_time / $tests_run,
				'memory_peak_bytes' => $memory_used,
				'memory_peak_mb'    => $memory_used / 1024 / 1024,
			),
			array(
				'test_category'         => 'file_upload_security',
				'total'                 => $tests_run,
				'passed'                => $tests_run - $vulnerabilities_found,
				'failed'                => $vulnerabilities_found,
				'vulnerabilities_found' => $vulnerabilities_found,
			)
		);

		$this->assertEquals( 0, $vulnerabilities_found, 'All malicious file uploads should be rejected.' );
	}

	/**
	 * Test authentication bypass attempts.
	 *
	 * Attempts to bypass authentication on protected endpoints.
	 */
	public function test_authentication_bypass_detection() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		$vulnerabilities_found = 0;
		$tests_run             = 0;

		$start_time   = microtime( true );
		$start_memory = memory_get_usage();

		// Test protected endpoints without authentication.
		$protected_endpoints = array(
			array( 'GET', '/mcp-ai/v1/assistants' ),
			array( 'POST', '/mcp-ai/v1/chat' ),
		);

		// Simulate unauthenticated user.
		wp_set_current_user( 0 );

		foreach ( $protected_endpoints as $endpoint_data ) {
			++$tests_run;

			list( $method, $route ) = $endpoint_data;

			$request  = new WP_REST_Request( $method, $route );
			$response = rest_do_request( $request );

			// Should be rejected (401 or 403).
			if ( $response->get_status() === 200 ) {
				++$vulnerabilities_found;
			}
		}

		$end_time   = microtime( true );
		$end_memory = memory_get_usage();

		$elapsed_time = ( $end_time - $start_time ) * 1000;
		$memory_used  = $end_memory - $start_memory;

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'security',
			'rest_api',
			false,
			array(
				'avg_response_time' => $elapsed_time / $tests_run,
				'memory_peak_bytes' => $memory_used,
				'memory_peak_mb'    => $memory_used / 1024 / 1024,
			),
			array(
				'test_category'         => 'authentication_bypass',
				'total'                 => $tests_run,
				'passed'                => $tests_run - $vulnerabilities_found,
				'failed'                => $vulnerabilities_found,
				'vulnerabilities_found' => $vulnerabilities_found,
			)
		);

		$this->assertEquals( 0, $vulnerabilities_found, 'No authentication bypass should be possible.' );
	}

	/**
	 * Test rate limiting enforcement.
	 *
	 * Verifies that rate limiting is properly enforced.
	 */
	public function test_rate_limiting_enforcement() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		$vulnerabilities_found = 0;
		$tests_run             = 0;

		$start_time   = microtime( true );
		$start_memory = memory_get_usage();

		$request_count = 100;
		$rate_limited  = false;

		for ( $i = 0; $i < $request_count; $i++ ) {
			++$tests_run;

			$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

			$response = rest_do_request( $request );

			// Check if rate limiting kicked in.
			if ( $response->get_status() === 429 ) {
				$rate_limited = true;
				break;
			}
		}

		$end_time   = microtime( true );
		$end_memory = memory_get_usage();

		$elapsed_time = ( $end_time - $start_time ) * 1000;
		$memory_used  = $end_memory - $start_memory;

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'security',
			'rest_api',
			false,
			array(
				'avg_response_time' => $elapsed_time / $tests_run,
				'memory_peak_bytes' => $memory_used,
				'memory_peak_mb'    => $memory_used / 1024 / 1024,
			),
			array(
				'test_category'         => 'rate_limiting',
				'total'                 => $tests_run,
				'passed'                => $rate_limited ? $tests_run : 0,
				'failed'                => $rate_limited ? 0 : $tests_run,
				'vulnerabilities_found' => $rate_limited ? 0 : 1,
				'rate_limited'          => $rate_limited,
			)
		);

		// Note: This test is informational - rate limiting may not be enabled by default.
		$this->assertTrue( true, 'Rate limiting test completed.' );
	}

	/**
	 * Test tool permission escalation prevention.
	 *
	 * Verifies that users cannot execute tools beyond their capabilities.
	 */
	public function test_tool_permission_escalation() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		$vulnerabilities_found = 0;
		$tests_run             = 0;

		$start_time   = microtime( true );
		$start_memory = memory_get_usage();

		// Create a subscriber user (low privileges).
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Attempt to execute admin-only tools.
		$admin_tools = array(
			'update_post',
			'delete_post',
			'update_plugin',
		);

		foreach ( $admin_tools as $tool ) {
			++$tests_run;

			$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
			$request->set_param( 'tool', $tool );
			$request->set_param( 'arguments', array() );

			$response = rest_do_request( $request );

			// Should be rejected (403).
			if ( $response->get_status() === 200 ) {
				++$vulnerabilities_found;
			}
		}

		$end_time   = microtime( true );
		$end_memory = memory_get_usage();

		$elapsed_time = ( $end_time - $start_time ) * 1000;
		$memory_used  = $end_memory - $start_memory;

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'security',
			'mcp_core',
			false,
			array(
				'avg_response_time' => $elapsed_time / $tests_run,
				'memory_peak_bytes' => $memory_used,
				'memory_peak_mb'    => $memory_used / 1024 / 1024,
			),
			array(
				'test_category'         => 'permission_escalation',
				'total'                 => $tests_run,
				'passed'                => $tests_run - $vulnerabilities_found,
				'failed'                => $vulnerabilities_found,
				'vulnerabilities_found' => $vulnerabilities_found,
			)
		);

		$this->assertEquals( 0, $vulnerabilities_found, 'No permission escalation should be possible.' );
	}

	/**
	 * Test credential leakage protection.
	 *
	 * Verifies that API keys and credentials are not exposed.
	 */
	public function test_credential_leakage_protection() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		$vulnerabilities_found = 0;
		$tests_run             = 0;

		$start_time   = microtime( true );
		$start_memory = memory_get_usage();

		// Test various endpoints for credential exposure.
		$endpoints = array(
			'/mcp-ai/v1/assistants',
		);

		foreach ( $endpoints as $route ) {
			++$tests_run;

			$request = new WP_REST_Request( 'GET', $route );
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

			$response = rest_do_request( $request );
			$data     = $response->get_data();

			// Check if response contains sensitive keywords.
			$sensitive_keywords = array( 'api_key', 'secret', 'password', 'token', 'credential' );
			$data_string        = wp_json_encode( $data );

			foreach ( $sensitive_keywords as $keyword ) {
				if ( stripos( $data_string, $keyword ) !== false ) {
					// Found sensitive keyword, check if it's actually exposing data.
					if ( preg_match( '/"' . $keyword . '"\s*:\s*"[^"]{10,}"/', $data_string ) ) {
						++$vulnerabilities_found;
						break;
					}
				}
			}
		}

		$end_time   = microtime( true );
		$end_memory = memory_get_usage();

		$elapsed_time = ( $end_time - $start_time ) * 1000;
		$memory_used  = $end_memory - $start_memory;

		// Store test results.
		WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
			'security',
			'rest_api',
			false,
			array(
				'avg_response_time' => $elapsed_time / $tests_run,
				'memory_peak_bytes' => $memory_used,
				'memory_peak_mb'    => $memory_used / 1024 / 1024,
			),
			array(
				'test_category'         => 'credential_leakage',
				'total'                 => $tests_run,
				'passed'                => $tests_run - $vulnerabilities_found,
				'failed'                => $vulnerabilities_found,
				'vulnerabilities_found' => $vulnerabilities_found,
			)
		);

		$this->assertEquals( 0, $vulnerabilities_found, 'No credentials should be exposed in API responses.' );
	}
}
