<?php
/**
 * Tests for enhanced logging functionality.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for enhanced logger methods.
 */
class WP_MCP_AI_Logger_Enhancements_Test extends WP_UnitTestCase {

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

		// Clean up existing log entries.
		delete_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );
		delete_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION );

		WP_MCP_AI_Logger::reset_log_file_cache();
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );
		delete_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION );
		WP_MCP_AI_Logger::reset_log_file_cache();

		parent::tearDown();
	}

	/**
	 * Test critical log level.
	 */
	public function test_log_critical() {
		$captured_entry = null;
		$filter         = function ( $entry ) use ( &$captured_entry ) {
			$captured_entry = $entry;
			return false; // Prevent actual logging.
		};

		add_filter( 'wp_mcp_ai_log_entry', $filter, 10, 1 );

		WP_MCP_AI_Logger::log_critical( 'Critical system failure', array( 'component' => 'database' ) );

		remove_filter( 'wp_mcp_ai_log_entry', $filter, 10 );

		$this->assertNotNull( $captured_entry );
		$this->assertEquals( WP_MCP_AI_Logger::LEVEL_CRITICAL, $captured_entry['type'] );
		$this->assertEquals( 'Critical system failure', $captured_entry['message'] );
		$this->assertEquals( 'database', $captured_entry['context']['component'] );
	}

	/**
	 * Test warning log level.
	 */
	public function test_log_warning() {
		$captured_entry = null;
		$filter         = function ( $entry ) use ( &$captured_entry ) {
			$captured_entry = $entry;
			return false;
		};

		add_filter( 'wp_mcp_ai_log_entry', $filter, 10, 1 );

		WP_MCP_AI_Logger::log_warning( 'Deprecated function called' );

		remove_filter( 'wp_mcp_ai_log_entry', $filter, 10 );

		$this->assertNotNull( $captured_entry );
		$this->assertEquals( WP_MCP_AI_Logger::LEVEL_WARNING, $captured_entry['type'] );
		$this->assertEquals( 'Deprecated function called', $captured_entry['message'] );
	}

	/**
	 * Test info log level.
	 */
	public function test_log_info() {
		$captured_entry = null;
		$filter         = function ( $entry ) use ( &$captured_entry ) {
			$captured_entry = $entry;
			return false;
		};

		add_filter( 'wp_mcp_ai_log_entry', $filter, 10, 1 );

		WP_MCP_AI_Logger::log_info( 'Cache cleared successfully' );

		remove_filter( 'wp_mcp_ai_log_entry', $filter, 10 );

		$this->assertNotNull( $captured_entry );
		$this->assertEquals( WP_MCP_AI_Logger::LEVEL_INFO, $captured_entry['type'] );
		$this->assertEquals( 'Cache cleared successfully', $captured_entry['message'] );
	}

	/**
	 * Test debug log level.
	 */
	public function test_log_debug() {
		$captured_entry = null;
		$filter         = function ( $entry ) use ( &$captured_entry ) {
			$captured_entry = $entry;
			return false;
		};

		add_filter( 'wp_mcp_ai_log_entry', $filter, 10, 1 );

		WP_MCP_AI_Logger::log_debug( 'Variable value', array( 'value' => 42 ) );

		remove_filter( 'wp_mcp_ai_log_entry', $filter, 10 );

		$this->assertNotNull( $captured_entry );
		$this->assertEquals( WP_MCP_AI_Logger::LEVEL_DEBUG, $captured_entry['type'] );
		$this->assertEquals( 'Variable value', $captured_entry['message'] );
		$this->assertEquals( 42, $captured_entry['context']['value'] );
	}

	/**
	 * Test critical errors are stored in recent errors.
	 */
	public function test_critical_errors_stored() {
		WP_MCP_AI_Logger::log_critical( 'Critical error occurred' );

		$recent_errors = WP_MCP_AI_Logger::get_recent_error_messages( 10 );

		$this->assertNotEmpty( $recent_errors );
		$this->assertEquals( WP_MCP_AI_Logger::LEVEL_CRITICAL, $recent_errors[0]['type'] );
		$this->assertEquals( 'Critical error occurred', $recent_errors[0]['message'] );
	}

	/**
	 * Test user-friendly error messages for OpenAI API errors.
	 */
	public function test_user_friendly_error_openai() {
		$result = WP_MCP_AI_Logger::get_user_friendly_error(
			'openai_api_error',
			'API request failed with status 401'
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertArrayHasKey( 'suggestions', $result );
		$this->assertNotEmpty( $result['message'] );
		$this->assertIsArray( $result['suggestions'] );
		$this->assertNotEmpty( $result['suggestions'] );
		$this->assertStringContainsString( 'AI service', $result['message'] );
	}

	/**
	 * Test user-friendly error messages for rate limiting.
	 */
	public function test_user_friendly_error_rate_limit() {
		$result = WP_MCP_AI_Logger::get_user_friendly_error(
			'rate_limit_exceeded',
			'Too many requests'
		);

		$this->assertStringContainsString( 'rate limit', $result['message'] );
		$this->assertNotEmpty( $result['suggestions'] );
		$this->assertGreaterThan( 0, count( $result['suggestions'] ) );
	}

	/**
	 * Test user-friendly error messages for network errors.
	 */
	public function test_user_friendly_error_network() {
		$result = WP_MCP_AI_Logger::get_user_friendly_error(
			'network_error',
			'Connection timed out'
		);

		$this->assertStringContainsString( 'Network', $result['message'] );
		$this->assertNotEmpty( $result['suggestions'] );
	}

	/**
	 * Test user-friendly error messages for authentication failures.
	 */
	public function test_user_friendly_error_auth() {
		$result = WP_MCP_AI_Logger::get_user_friendly_error(
			'invalid_api_key',
			'Invalid API key provided'
		);

		$this->assertStringContainsString( 'authentication', $result['message'] );
		$this->assertNotEmpty( $result['suggestions'] );
	}

	/**
	 * Test user-friendly error messages for tool execution failures.
	 */
	public function test_user_friendly_error_tool() {
		$result = WP_MCP_AI_Logger::get_user_friendly_error(
			'tool_execution_failed',
			'Tool failed',
			array( 'tool_slug' => 'test_tool' )
		);

		$this->assertStringContainsString( 'test_tool', $result['message'] );
		$this->assertNotEmpty( $result['suggestions'] );
	}

	/**
	 * Test user-friendly error messages for unknown errors.
	 */
	public function test_user_friendly_error_unknown() {
		$result = WP_MCP_AI_Logger::get_user_friendly_error(
			'unknown_error_code',
			'Something went wrong'
		);

		$this->assertEquals( 'Something went wrong', $result['message'] );
		$this->assertNotEmpty( $result['suggestions'] );
	}

	/**
	 * Test user-friendly error filter.
	 */
	public function test_user_friendly_error_filter() {
		$filter = function ( $result, $error_code ) {
			if ( 'custom_error' === $error_code ) {
				$result['message']     = 'Custom error message';
				$result['suggestions'] = array( 'Custom suggestion' );
			}
			return $result;
		};

		add_filter( 'wp_mcp_ai_user_friendly_error', $filter, 10, 2 );

		$result = WP_MCP_AI_Logger::get_user_friendly_error( 'custom_error', 'Original message' );

		remove_filter( 'wp_mcp_ai_user_friendly_error', $filter, 10 );

		$this->assertEquals( 'Custom error message', $result['message'] );
		$this->assertEquals( array( 'Custom suggestion' ), $result['suggestions'] );
	}

	/**
	 * Test that sensitive data is redacted from context.
	 */
	public function test_sensitive_data_redaction() {
		$captured_entry = null;
		$filter         = function ( $entry ) use ( &$captured_entry ) {
			$captured_entry = $entry;
			return false;
		};

		add_filter( 'wp_mcp_ai_log_entry', $filter, 10, 1 );

		WP_MCP_AI_Logger::log_error(
			'API error',
			array(
				'api_key'      => 'sk-1234567890',
				'safe_data'    => 'visible',
				'access_token' => 'secret-token',
			)
		);

		remove_filter( 'wp_mcp_ai_log_entry', $filter, 10 );

		$this->assertNotNull( $captured_entry );
		$this->assertEquals( '[redacted]', $captured_entry['context']['api_key'] );
		$this->assertEquals( '[redacted]', $captured_entry['context']['access_token'] );
		$this->assertEquals( 'visible', $captured_entry['context']['safe_data'] );
	}
}
