<?php
/**
 * Tests for the centralized error handler.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for WP_MCP_AI_Error_Handler.
 */
class WP_MCP_AI_Error_Handler_Test extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Enable logging for tests.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_logging' => true,
			)
		);

		delete_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );
		WP_MCP_AI_Logger::reset_log_file_cache();
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );
		WP_MCP_AI_Logger::reset_log_file_cache();

		parent::tearDown();
	}

	/**
	 * Test basic error creation.
	 */
	public function test_create_error() {
		$error = WP_MCP_AI_Error_Handler::create_error(
			'test_error',
			'This is a test error',
			array( 'test_data' => 'value' )
		);

		$this->assertInstanceOf( 'WP_Error', $error );
		$this->assertEquals( 'test_error', $error->get_error_code() );
		$this->assertEquals( 'This is a test error', $error->get_error_message() );

		$data = $error->get_error_data();
		$this->assertArrayHasKey( 'test_data', $data );
		$this->assertEquals( 'value', $data['test_data'] );
	}

	/**
	 * Test error with user-friendly suggestions.
	 */
	public function test_error_with_suggestions() {
		$error = WP_MCP_AI_Error_Handler::create_error(
			'openai_api_error',
			'API request failed',
			array(),
			WP_MCP_AI_Logger::LEVEL_ERROR,
			true
		);

		$data = $error->get_error_data();
		$this->assertArrayHasKey( 'user_message', $data );
		$this->assertArrayHasKey( 'suggestions', $data );
		$this->assertNotEmpty( $data['suggestions'] );
		$this->assertIsArray( $data['suggestions'] );
	}

	/**
	 * Test REST API error creation.
	 */
	public function test_create_rest_error() {
		$error = WP_MCP_AI_Error_Handler::create_rest_error(
			'invalid_request',
			'Invalid request parameters',
			400,
			array( 'field' => 'assistant_id' )
		);

		$this->assertInstanceOf( 'WP_Error', $error );
		$data = $error->get_error_data();
		$this->assertEquals( 400, $data['status'] );
		$this->assertEquals( 'assistant_id', $data['field'] );
	}

	/**
	 * Test API error creation.
	 */
	public function test_create_api_error() {
		$api_response = array(
			'error' => array(
				'message' => 'Invalid API key',
				'type'    => 'invalid_request_error',
				'code'    => 'invalid_api_key',
			),
		);

		$error = WP_MCP_AI_Error_Handler::create_api_error(
			'openai',
			'OpenAI request failed',
			$api_response,
			401
		);

		$this->assertEquals( 'openai_api_error', $error->get_error_code() );
		$data = $error->get_error_data();
		$this->assertEquals( 500, $data['status'] );
		$this->assertEquals( 'openai', $data['provider'] );
		$this->assertEquals( 401, $data['status_code'] );
		$this->assertArrayHasKey( 'api_response', $data );
	}

	/**
	 * Test validation error creation.
	 */
	public function test_create_validation_error() {
		$error = WP_MCP_AI_Error_Handler::create_validation_error(
			'email',
			'Invalid email address',
			array( 'provided_value' => 'not-an-email' )
		);

		$this->assertEquals( 'validation_error_email', $error->get_error_code() );
		$data = $error->get_error_data();
		$this->assertEquals( 400, $data['status'] );
		$this->assertEquals( 'email', $data['field'] );
		$this->assertEquals( 'not-an-email', $data['provided_value'] );
	}

	/**
	 * Test auth error creation.
	 */
	public function test_create_auth_error() {
		$error = WP_MCP_AI_Error_Handler::create_auth_error(
			'Invalid credentials'
		);

		$this->assertEquals( 'authentication_failed', $error->get_error_code() );
		$data = $error->get_error_data();
		$this->assertEquals( 401, $data['status'] );
	}

	/**
	 * Test permission error creation.
	 */
	public function test_create_permission_error() {
		$error = WP_MCP_AI_Error_Handler::create_permission_error(
			'You do not have permission to perform this action',
			'manage_options'
		);

		$this->assertEquals( 'permission_denied', $error->get_error_code() );
		$data = $error->get_error_data();
		$this->assertEquals( 403, $data['status'] );
		$this->assertEquals( 'manage_options', $data['required_capability'] );
	}

	/**
	 * Test rate limit error creation.
	 */
	public function test_create_rate_limit_error() {
		$error = WP_MCP_AI_Error_Handler::create_rate_limit_error(
			'Too many requests',
			120
		);

		$this->assertEquals( 'rate_limit_exceeded', $error->get_error_code() );
		$data = $error->get_error_data();
		$this->assertEquals( 429, $data['status'] );
		$this->assertEquals( 120, $data['retry_after'] );
	}

	/**
	 * Test error logging.
	 */
	public function test_error_is_logged() {
		WP_MCP_AI_Error_Handler::create_error(
			'test_logged_error',
			'Error that should be logged',
			array(),
			WP_MCP_AI_Logger::LEVEL_ERROR
		);

		$recent_errors = WP_MCP_AI_Logger::get_recent_error_messages( 10 );
		$this->assertNotEmpty( $recent_errors );

		$found = false;
		foreach ( $recent_errors as $entry ) {
			if ( false !== strpos( $entry['message'], 'Error that should be logged' ) ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'Error should be logged' );
	}

	/**
	 * Test critical error severity.
	 */
	public function test_critical_error_logged_correctly() {
		WP_MCP_AI_Error_Handler::create_error(
			'critical_test_error',
			'Critical system failure',
			array(),
			WP_MCP_AI_Logger::LEVEL_CRITICAL
		);

		$recent_errors = WP_MCP_AI_Logger::get_recent_error_messages( 10 );
		$this->assertNotEmpty( $recent_errors );

		$found = false;
		foreach ( $recent_errors as $entry ) {
			if ( 'critical' === $entry['type'] && false !== strpos( $entry['message'], 'Critical system failure' ) ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'Critical error should be logged with correct severity' );
	}

	/**
	 * Test error formatting for display.
	 */
	public function test_format_error_for_display() {
		$error = WP_MCP_AI_Error_Handler::create_error(
			'openai_api_error',
			'Technical error message',
			array(),
			WP_MCP_AI_Logger::LEVEL_ERROR,
			true
		);

		$formatted = WP_MCP_AI_Error_Handler::format_error_for_display( $error );

		$this->assertIsArray( $formatted );
		$this->assertArrayHasKey( 'message', $formatted );
		$this->assertArrayHasKey( 'suggestions', $formatted );
		$this->assertNotEmpty( $formatted['message'] );
		$this->assertIsArray( $formatted['suggestions'] );
	}

	/**
	 * Test error formatting without user message.
	 */
	public function test_format_error_without_user_message() {
		$error = WP_MCP_AI_Error_Handler::create_error(
			'test_error',
			'Technical message',
			array(),
			WP_MCP_AI_Logger::LEVEL_ERROR,
			false
		);

		$formatted = WP_MCP_AI_Error_Handler::format_error_for_display( $error );

		$this->assertEquals( 'Technical message', $formatted['message'] );
		$this->assertEmpty( $formatted['suggestions'] );
	}

	/**
	 * Test API response sanitization.
	 */
	public function test_api_response_sanitization() {
		$response_with_secrets = array(
			'error'        => array(
				'message' => 'Invalid API key',
				'type'    => 'auth_error',
			),
			'api_key'      => 'sk-secret-key-1234',
			'access_token' => 'secret-token',
			'message'      => 'Error message',
		);

		$error = WP_MCP_AI_Error_Handler::create_api_error(
			'test',
			'API Error',
			$response_with_secrets
		);

		$data = $error->get_error_data();
		$this->assertArrayHasKey( 'api_response', $data );

		// Ensure sensitive fields are not included.
		$this->assertArrayNotHasKey( 'api_key', $data['api_response'] );
		$this->assertArrayNotHasKey( 'access_token', $data['api_response'] );

		// Ensure safe fields are included.
		$this->assertArrayHasKey( 'error', $data['api_response'] );
		$this->assertArrayHasKey( 'message', $data['api_response'] );
	}

	/**
	 * Test should_log_error filter.
	 */
	public function test_should_log_error() {
		$error1 = new WP_Error( 'test_error', 'This should be logged' );
		$this->assertTrue( WP_MCP_AI_Error_Handler::should_log_error( $error1 ) );

		$error2 = new WP_Error( 'rest_invalid_param', 'This should not be logged' );
		$this->assertFalse( WP_MCP_AI_Error_Handler::should_log_error( $error2 ) );
	}

	/**
	 * Test should_log_error with custom filter.
	 */
	public function test_should_log_error_with_filter() {
		$filter = function ( $skip_codes ) {
			$skip_codes[] = 'custom_skip_code';
			return $skip_codes;
		};

		add_filter( 'wp_mcp_ai_skip_error_logging', $filter );

		$error      = new WP_Error( 'custom_skip_code', 'Should be skipped' );
		$should_log = WP_MCP_AI_Error_Handler::should_log_error( $error );

		remove_filter( 'wp_mcp_ai_skip_error_logging', $filter );

		$this->assertFalse( $should_log );
	}
}
