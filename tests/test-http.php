<?php
/**
 * Tests for WP_MCP_AI_HTTP class.
 *
 * @package WP_MCP_AI
 */

/**
 * @group http
 */
class WP_MCP_AI_HTTP_Tests extends WP_UnitTestCase {

	/**
	 * Test bootstrap registration.
	 */
	public function test_bootstrap_registers_hooks() {
		// Reset bootstrap state using reflection since property is protected.
		$reflection = new ReflectionClass( 'WP_MCP_AI_HTTP' );
		$property   = $reflection->getProperty( 'bootstrapped' );
		$property->setAccessible( true );
		$property->setValue( null, false );

		WP_MCP_AI_HTTP::bootstrap();

		$this->assertTrue( has_action( 'http_api_debug', array( 'WP_MCP_AI_HTTP', 'log_http_api_debug' ) ) !== false );
	}

	/**
	 * Test bootstrap is idempotent.
	 */
	public function test_bootstrap_is_idempotent() {
		WP_MCP_AI_HTTP::bootstrap();
		$priority1 = has_action( 'http_api_debug', array( 'WP_MCP_AI_HTTP', 'log_http_api_debug' ) );

		WP_MCP_AI_HTTP::bootstrap();
		$priority2 = has_action( 'http_api_debug', array( 'WP_MCP_AI_HTTP', 'log_http_api_debug' ) );

		$this->assertEquals( $priority1, $priority2 );
	}

	/**
	 * Test timeout error detection with http_request_timeout code.
	 */
	public function test_is_wordpress_timeout_error_with_timeout_code() {
		$error = new WP_Error( 'http_request_timeout', 'Request timed out' );
		$this->assertTrue( WP_MCP_AI_HTTP::is_wordpress_timeout_error( $error ) );
	}

	/**
	 * Test timeout error detection with timeout message.
	 */
	public function test_is_wordpress_timeout_error_with_timeout_message() {
		$error = new WP_Error( 'http_request_failed', 'The connection timed out after 30 seconds' );
		$this->assertTrue( WP_MCP_AI_HTTP::is_wordpress_timeout_error( $error ) );
	}

	/**
	 * Test timeout error detection with curl error 28.
	 */
	public function test_is_wordpress_timeout_error_with_curl_error() {
		$error = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$this->assertTrue( WP_MCP_AI_HTTP::is_wordpress_timeout_error( $error ) );
	}

	/**
	 * Test timeout error detection with 504 status in data.
	 */
	public function test_is_wordpress_timeout_error_with_504_status() {
		$error = new WP_Error( 'http_request_failed', 'Gateway timeout', array( 'status' => 504 ) );
		$this->assertTrue( WP_MCP_AI_HTTP::is_wordpress_timeout_error( $error ) );
	}

	/**
	 * Test timeout error detection with timeout flag in data.
	 */
	public function test_is_wordpress_timeout_error_with_timeout_flag() {
		$error = new WP_Error( 'http_request_failed', 'Request failed', array( 'timeout' => true ) );
		$this->assertTrue( WP_MCP_AI_HTTP::is_wordpress_timeout_error( $error ) );
	}

	/**
	 * Test non-timeout error is not detected as timeout.
	 */
	public function test_is_wordpress_timeout_error_with_non_timeout_error() {
		$error = new WP_Error( 'http_request_failed', 'Connection refused' );
		$this->assertFalse( WP_MCP_AI_HTTP::is_wordpress_timeout_error( $error ) );
	}

	/**
	 * Test timeout error detection with non-WP_Error.
	 */
	public function test_is_wordpress_timeout_error_with_non_error() {
		$this->assertFalse( WP_MCP_AI_HTTP::is_wordpress_timeout_error( 'not an error' ) );
		$this->assertFalse( WP_MCP_AI_HTTP::is_wordpress_timeout_error( null ) );
		$this->assertFalse( WP_MCP_AI_HTTP::is_wordpress_timeout_error( array() ) );
	}

	/**
	 * Test prepare_transport_error with timeout error.
	 */
	public function test_prepare_transport_error_with_timeout() {
		$timeout_error = new WP_Error( 'http_request_timeout', 'Request timed out' );
		$prepared      = WP_MCP_AI_HTTP::prepare_transport_error(
			$timeout_error,
			'default_code',
			'Default message',
			'OpenAI API'
		);

		$this->assertInstanceOf( 'WP_Error', $prepared );
		$this->assertEquals( 'wp_mcp_ai_wordpress_timeout', $prepared->get_error_code() );
		$this->assertStringContainsString( 'OpenAI API', $prepared->get_error_message() );

		$data = $prepared->get_error_data();
		$this->assertArrayHasKey( 'actions', $data );
		$this->assertArrayHasKey( 'error', $data );
		$this->assertEquals( 504, $data['status'] );
	}

	/**
	 * Test prepare_transport_error with non-timeout error.
	 */
	public function test_prepare_transport_error_with_non_timeout() {
		$error    = new WP_Error( 'http_request_failed', 'Connection refused' );
		$prepared = WP_MCP_AI_HTTP::prepare_transport_error(
			$error,
			'custom_code',
			'Custom message'
		);

		$this->assertInstanceOf( 'WP_Error', $prepared );
		$this->assertEquals( 'custom_code', $prepared->get_error_code() );
		$this->assertEquals( 'Custom message', $prepared->get_error_message() );

		$data = $prepared->get_error_data();
		$this->assertArrayHasKey( 'error', $data );
	}

	/**
	 * Test prepare_transport_error with non-WP_Error.
	 */
	public function test_prepare_transport_error_with_non_error_input() {
		$prepared = WP_MCP_AI_HTTP::prepare_transport_error(
			'not an error',
			'fallback_code',
			'Fallback message'
		);

		$this->assertInstanceOf( 'WP_Error', $prepared );
		$this->assertEquals( 'fallback_code', $prepared->get_error_code() );
		$this->assertEquals( 'Fallback message', $prepared->get_error_message() );
	}

	/**
	 * Test prepare_transport_error merges custom data.
	 */
	public function test_prepare_transport_error_merges_data() {
		$error    = new WP_Error( 'http_request_failed', 'Connection failed' );
		$prepared = WP_MCP_AI_HTTP::prepare_transport_error(
			$error,
			'custom_code',
			'Custom message',
			'',
			array( 'custom_field' => 'custom_value' )
		);

		$data = $prepared->get_error_data();
		$this->assertEquals( 'custom_value', $data['custom_field'] );
		$this->assertArrayHasKey( 'error', $data );
	}

	/**
	 * Test prepare_transport_error with timeout preserves existing actions.
	 */
	public function test_prepare_transport_error_preserves_existing_actions() {
		$timeout_error = new WP_Error( 'http_request_timeout', 'Request timed out' );
		$prepared      = WP_MCP_AI_HTTP::prepare_transport_error(
			$timeout_error,
			'default_code',
			'Default message',
			'Test Service',
			array(
				'actions' => array(
					'custom_action' => 'Custom advice',
				),
			)
		);

		$data = $prepared->get_error_data();
		$this->assertArrayHasKey( 'actions', $data );
		$this->assertArrayHasKey( 'configure_request_timeout', $data['actions'] );
		$this->assertArrayHasKey( 'check_server_connectivity', $data['actions'] );
		$this->assertArrayHasKey( 'custom_action', $data['actions'] );
		$this->assertEquals( 'Custom advice', $data['actions']['custom_action'] );
	}

	/**
	 * Test timeout message building with service label.
	 */
	public function test_build_timeout_message_with_service_label() {
		$timeout_error = new WP_Error( 'http_request_timeout', 'Request timed out' );
		$prepared      = WP_MCP_AI_HTTP::prepare_transport_error(
			$timeout_error,
			'default_code',
			'Default message',
			'OpenAI API'
		);

		$message = $prepared->get_error_message();
		$this->assertStringContainsString( 'OpenAI API', $message );
		$this->assertStringContainsString( 'timed out', $message );
	}

	/**
	 * Test timeout message building without service label.
	 */
	public function test_build_timeout_message_without_service_label() {
		$timeout_error = new WP_Error( 'http_request_timeout', 'Request timed out' );
		$prepared      = WP_MCP_AI_HTTP::prepare_transport_error(
			$timeout_error,
			'default_code',
			'Default message',
			''
		);

		$message = $prepared->get_error_message();
		$this->assertStringContainsString( 'timed out', $message );
	}

	/**
	 * Test various timeout message patterns.
	 */
	public function test_message_indicates_timeout_patterns() {
		$timeout_messages = array(
			'Request timed out',
			'Operation timed out',
			'Connection timeout',
			'cURL error 28: Operation timed out',
			'The request has timed-out',
			'TIMEOUT error',
		);

		foreach ( $timeout_messages as $message ) {
			$error = new WP_Error( 'test_error', $message );
			$this->assertTrue(
				WP_MCP_AI_HTTP::is_wordpress_timeout_error( $error ),
				"Failed to detect timeout in message: {$message}"
			);
		}
	}

	/**
	 * Test non-timeout messages are not detected.
	 */
	public function test_message_does_not_indicate_timeout() {
		$non_timeout_messages = array(
			'Connection refused',
			'SSL certificate problem',
			'Could not resolve host',
			'HTTP 404 Not Found',
			'Server returned 500 error',
		);

		foreach ( $non_timeout_messages as $message ) {
			$error = new WP_Error( 'test_error', $message );
			$this->assertFalse(
				WP_MCP_AI_HTTP::is_wordpress_timeout_error( $error ),
				"Incorrectly detected timeout in message: {$message}"
			);
		}
	}

	/**
	 * Test timeout detection is case insensitive.
	 */
	public function test_timeout_detection_case_insensitive() {
		$error1 = new WP_Error( 'test', 'REQUEST TIMED OUT' );
		$error2 = new WP_Error( 'test', 'request timed out' );
		$error3 = new WP_Error( 'test', 'Request Timed Out' );

		$this->assertTrue( WP_MCP_AI_HTTP::is_wordpress_timeout_error( $error1 ) );
		$this->assertTrue( WP_MCP_AI_HTTP::is_wordpress_timeout_error( $error2 ) );
		$this->assertTrue( WP_MCP_AI_HTTP::is_wordpress_timeout_error( $error3 ) );
	}
}
