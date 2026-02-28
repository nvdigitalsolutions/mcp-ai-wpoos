<?php
/**
 * Tests for Telegram Mini App (WebApp) Authentication
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test Telegram WebApp Auth functionality.
 *
 * @group rest
 * @group telegram
 * @group auth
 */
class Test_Telegram_WebApp_Auth extends WP_UnitTestCase {

	/**
	 * A known bot token used for testing.
	 *
	 * @var string
	 */
	protected $test_bot_token = '123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefgh';

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-telegram-webapp-auth.php';
	}

	/**
	 * Helper: build a valid initData query string.
	 *
	 * @param array  $params    Key-value pairs to include (excluding hash).
	 * @param string $bot_token Bot token used for signing.
	 * @return string Query string with valid hash.
	 */
	protected function build_init_data( $params, $bot_token ) {
		// Sort params alphabetically by key.
		ksort( $params );

		$data_check_parts = array();
		foreach ( $params as $key => $value ) {
			$data_check_parts[] = $key . '=' . $value;
		}
		$data_check_string = implode( "\n", $data_check_parts );

		// Compute the secret key.
		$secret_key = hash_hmac( 'sha256', $bot_token, 'WebAppData', true );

		// Compute the hash.
		$hash = hash_hmac( 'sha256', $data_check_string, $secret_key );

		$params['hash'] = $hash;

		return http_build_query( $params );
	}

	/**
	 * Test that validate_init_data succeeds with valid data.
	 */
	public function test_validate_init_data_valid() {
		$user_json = wp_json_encode(
			array(
				'id'         => 12345678,
				'first_name' => 'Test',
				'last_name'  => 'User',
				'username'   => 'testuser',
			)
		);

		$params = array(
			'user'      => $user_json,
			'auth_date' => (string) time(),
			'query_id'  => 'test_query_123',
		);

		$init_data = $this->build_init_data( $params, $this->test_bot_token );

		$result = WP_MCP_AI_Telegram_WebApp_Auth::validate_init_data( $init_data, $this->test_bot_token );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['valid'] );
		$this->assertArrayHasKey( 'user', $result );
		$this->assertEquals( 12345678, $result['user']['id'] );
		$this->assertEquals( 'Test', $result['user']['first_name'] );
		$this->assertEquals( 'User', $result['user']['last_name'] );
		$this->assertEquals( 'testuser', $result['user']['username'] );
	}

	/**
	 * Test that validate_init_data fails with invalid hash.
	 */
	public function test_validate_init_data_invalid_hash() {
		$params = array(
			'user'      => '{"id":12345678,"first_name":"Test"}',
			'auth_date' => (string) time(),
			'hash'      => 'invalid_hash_value_that_should_not_match',
		);

		$init_data = http_build_query( $params );

		$result = WP_MCP_AI_Telegram_WebApp_Auth::validate_init_data( $init_data, $this->test_bot_token );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_telegram_invalid_hash', $result->get_error_code() );
	}

	/**
	 * Test that validate_init_data fails with wrong bot token.
	 */
	public function test_validate_init_data_wrong_token() {
		$params = array(
			'user'      => '{"id":12345678,"first_name":"Test"}',
			'auth_date' => (string) time(),
		);

		$init_data  = $this->build_init_data( $params, $this->test_bot_token );
		$wrong_token = '987654321:ZYXWVUTSRQPONMLKJIHGFEDCBAzyxwvuts';

		$result = WP_MCP_AI_Telegram_WebApp_Auth::validate_init_data( $init_data, $wrong_token );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_telegram_invalid_hash', $result->get_error_code() );
	}

	/**
	 * Test that validate_init_data fails with empty initData.
	 */
	public function test_validate_init_data_empty() {
		$result = WP_MCP_AI_Telegram_WebApp_Auth::validate_init_data( '', $this->test_bot_token );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_telegram_missing_data', $result->get_error_code() );
	}

	/**
	 * Test that validate_init_data fails with empty bot token.
	 */
	public function test_validate_init_data_empty_bot_token() {
		$result = WP_MCP_AI_Telegram_WebApp_Auth::validate_init_data( 'user=test', '' );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_telegram_missing_data', $result->get_error_code() );
	}

	/**
	 * Test that validate_init_data fails with missing hash.
	 */
	public function test_validate_init_data_missing_hash() {
		$params = array(
			'user'      => '{"id":12345678}',
			'auth_date' => (string) time(),
		);

		// Build query string without hash.
		$init_data = http_build_query( $params );

		$result = WP_MCP_AI_Telegram_WebApp_Auth::validate_init_data( $init_data, $this->test_bot_token );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_telegram_missing_hash', $result->get_error_code() );
	}

	/**
	 * Test that validate_init_data fails with expired auth_date.
	 */
	public function test_validate_init_data_expired() {
		$params = array(
			'user'      => '{"id":12345678}',
			'auth_date' => (string) ( time() - 2 * DAY_IN_SECONDS ),
		);

		$init_data = $this->build_init_data( $params, $this->test_bot_token );

		$result = WP_MCP_AI_Telegram_WebApp_Auth::validate_init_data( $init_data, $this->test_bot_token );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_telegram_expired', $result->get_error_code() );
	}

	/**
	 * Test that validate_init_data succeeds without user object.
	 */
	public function test_validate_init_data_without_user() {
		$params = array(
			'auth_date' => (string) time(),
			'query_id'  => 'test_query',
		);

		$init_data = $this->build_init_data( $params, $this->test_bot_token );

		$result = WP_MCP_AI_Telegram_WebApp_Auth::validate_init_data( $init_data, $this->test_bot_token );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['valid'] );
		$this->assertIsArray( $result['user'] );
		$this->assertEmpty( $result['user'] );
	}

	/**
	 * Test auth_date max age filter.
	 */
	public function test_validate_init_data_custom_max_age() {
		// Set auth_date to 2 hours ago.
		$params = array(
			'user'      => '{"id":12345678}',
			'auth_date' => (string) ( time() - 2 * HOUR_IN_SECONDS ),
		);

		$init_data = $this->build_init_data( $params, $this->test_bot_token );

		// Default max age is 24 hours - should pass.
		$result = WP_MCP_AI_Telegram_WebApp_Auth::validate_init_data( $init_data, $this->test_bot_token );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['valid'] );

		// Set max age to 1 hour - should fail.
		add_filter(
			'wp_mcp_ai_telegram_auth_max_age',
			function () {
				return HOUR_IN_SECONDS;
			}
		);

		$result = WP_MCP_AI_Telegram_WebApp_Auth::validate_init_data( $init_data, $this->test_bot_token );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_telegram_expired', $result->get_error_code() );
	}
}
