<?php
/**
 * Tests for F-AUTHZ-01 / R-S-01 — webhook and discovery endpoint permission callbacks.
 *
 * Verifies that:
 *  1. A2A agent-card endpoints return 403 when A2A is disabled.
 *  2. A2A agent-card endpoints return true when A2A is enabled.
 *  3. Telegram Login permission callback rejects requests missing required params.
 *  4. Telegram Login permission callback rejects requests with invalid HMAC.
 *  5. Telegram Login permission callback rejects requests with expired auth_date.
 *  6. Telegram Login permission callback allows requests with a valid HMAC.
 *  7. Legacy Google Chat permission callback defaults to true (allows) when no filter is hooked.
 *  8. Legacy Google Chat permission callback defers to the filter for custom verification.
 *
 * @package WP_MCP_AI
 * @group   security
 * @group   authz
 */

/**
 * Tests for webhook/discovery permission callbacks (F-AUTHZ-01 / R-S-01).
 */
class Test_Webhook_Permission_Callbacks extends WP_UnitTestCase {

	/**
	 * Option name used by plugin settings.
	 */
	const SETTINGS_OPTION = 'wp_mcp_ai_settings';

	/**
	 * Clean up options between tests.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( self::SETTINGS_OPTION );
	}

	/**
	 * Tear down tests.
	 */
	public function tearDown(): void {
		delete_option( self::SETTINGS_OPTION );
		remove_all_filters( 'wp_mcp_ai_verify_google_chat_legacy_webhook' );
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// 1–2. A2A agent-card permission check
	// -----------------------------------------------------------------------

	/**
	 * When A2A is disabled the agent-card permission callback must return WP_Error 403.
	 *
	 * @group a2a
	 */
	public function test_agent_card_blocked_when_a2a_disabled() {
		if ( ! class_exists( 'WP_MCP_AI_REST_A2A_Controller' ) ) {
			$file = defined( 'WP_MCP_AI_PATH' )
				? WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-a2a-controller.php'
				: '';
			if ( '' === $file || ! file_exists( $file ) ) {
				$this->markTestSkipped( 'WP_MCP_AI_REST_A2A_Controller class not available.' );
			}
			require_once $file;
		}

		update_option( self::SETTINGS_OPTION, array( 'enable_a2a_server' => 0 ) );

		$controller = new WP_MCP_AI_REST_A2A_Controller( WP_MCP_AI_REST::get_instance() );
		$request    = new WP_REST_Request( 'GET', '/mcp-ai/v1/a2a/agent-card' );
		$result     = $controller->permissions_check_agent_card( $request );

		$this->assertInstanceOf( WP_Error::class, $result, 'Should return WP_Error when A2A is disabled' );
		$this->assertEquals( 'a2a_disabled', $result->get_error_code() );
		$this->assertEquals( 403, $result->get_error_data()['status'] );
	}

	/**
	 * When A2A is enabled the agent-card permission callback must return true.
	 *
	 * @group a2a
	 */
	public function test_agent_card_allowed_when_a2a_enabled() {
		if ( ! class_exists( 'WP_MCP_AI_REST_A2A_Controller' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_REST_A2A_Controller class not available.' );
		}

		update_option( self::SETTINGS_OPTION, array( 'enable_a2a_server' => 1 ) );

		$controller = new WP_MCP_AI_REST_A2A_Controller( WP_MCP_AI_REST::get_instance() );
		$request    = new WP_REST_Request( 'GET', '/mcp-ai/v1/a2a/agent-card' );
		$result     = $controller->permissions_check_agent_card( $request );

		$this->assertTrue( $result, 'Should return true when A2A is enabled' );
	}

	// -----------------------------------------------------------------------
	// 3–6. Telegram Login permission check
	// -----------------------------------------------------------------------

	/**
	 * Build a valid Telegram auth HMAC for the supplied data and bot token.
	 *
	 * @param array  $data      Key–value pairs (must include 'hash').
	 * @param string $bot_token Plaintext bot token.
	 * @return string Hex HMAC.
	 */
	private function build_telegram_hmac( array $data, $bot_token ) {
		$fields = array();
		foreach ( $data as $key => $value ) {
			if ( 'hash' === $key || '' === (string) $value ) {
				continue;
			}
			$fields[] = $key . '=' . $value;
		}
		sort( $fields );
		$data_check = implode( "\n", $fields );
		$secret_key = hash( 'sha256', $bot_token, true );
		return hash_hmac( 'sha256', $data_check, $secret_key );
	}

	/**
	 * Load the Telegram login controller class if needed.
	 *
	 * @return bool True if class is available.
	 */
	private function load_telegram_controller() {
		if ( class_exists( 'WP_MCP_AI_Telegram_Login_Controller' ) ) {
			return true;
		}

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PRO_PATH constant not defined — Pro addon not available.' );
		}

		$file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-telegram-login-controller.php';
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Telegram login controller file not found.' );
		}

		require_once $file;
		return class_exists( 'WP_MCP_AI_Telegram_Login_Controller' );
	}

	/**
	 * Missing `hash` parameter must cause a 400 WP_Error.
	 *
	 * @group telegram
	 */
	public function test_telegram_permission_rejects_missing_hash() {
		if ( ! $this->load_telegram_controller() ) {
			$this->markTestSkipped( 'WP_MCP_AI_Telegram_Login_Controller not available.' );
		}

		$controller = new WP_MCP_AI_Telegram_Login_Controller();
		$request    = new WP_REST_Request( 'GET', '/mcp-ai/v1/telegram-login' );
		$request->set_param( 'id', 12345 );
		$request->set_param( 'auth_date', time() );
		// 'hash' param intentionally omitted.

		$result = $controller->verify_telegram_auth_permission( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 400, $result->get_error_data()['status'] );
	}

	/**
	 * An invalid HMAC must cause a 403 WP_Error (or 503/500 when connection
	 * is not configured — both signal "denied before body processed").
	 *
	 * @group telegram
	 */
	public function test_telegram_permission_rejects_invalid_hash() {
		if ( ! $this->load_telegram_controller() ) {
			$this->markTestSkipped( 'WP_MCP_AI_Telegram_Login_Controller not available.' );
		}

		$controller = new WP_MCP_AI_Telegram_Login_Controller();
		$request    = new WP_REST_Request( 'GET', '/mcp-ai/v1/telegram-login' );
		$request->set_param( 'id', 12345 );
		$request->set_param( 'first_name', 'Test' );
		$request->set_param( 'auth_date', time() );
		$request->set_param( 'hash', 'badhash000000000000000000000000000000000000000000000000000000000' );

		$result = $controller->verify_telegram_auth_permission( $request );

		$this->assertInstanceOf( WP_Error::class, $result, 'Should return WP_Error for invalid hash' );
		$status = $result->get_error_data()['status'];
		$this->assertContains( $status, array( 400, 403, 500, 503 ), 'Status must signal a denial, not 200/201' );
	}

	/**
	 * A valid HMAC with a fresh auth_date must return true (requires a real
	 * connection to be set up, so this test skips when no connection is found).
	 *
	 * This test exercises the happy path by directly calling verify_auth_data()
	 * which is the underlying verifier used by verify_telegram_auth_permission().
	 *
	 * @group telegram
	 */
	public function test_telegram_verify_auth_data_passes_valid_hmac() {
		if ( ! $this->load_telegram_controller() ) {
			$this->markTestSkipped( 'WP_MCP_AI_Telegram_Login_Controller not available.' );
		}

		$bot_token = 'test-bot-token-for-unit-test';
		$auth_data = array(
			'id'        => '99999',
			'auth_date' => (string) time(),
		);
		$auth_data['hash'] = $this->build_telegram_hmac( $auth_data, $bot_token );

		$controller = new WP_MCP_AI_Telegram_Login_Controller();
		$result     = $controller->verify_auth_data( $auth_data, $bot_token );

		$this->assertTrue( $result, 'verify_auth_data() must return true for a valid HMAC' );
	}

	/**
	 * An expired auth_date (> AUTH_DATE_MAX_AGE seconds old) must be rejected.
	 *
	 * @group telegram
	 */
	public function test_telegram_verify_auth_data_rejects_expired_auth_date() {
		if ( ! $this->load_telegram_controller() ) {
			$this->markTestSkipped( 'WP_MCP_AI_Telegram_Login_Controller not available.' );
		}

		$bot_token = 'test-bot-token-for-unit-test';
		// Set auth_date to well before the allowed window.
		$auth_data = array(
			'id'        => '99999',
			'auth_date' => (string) ( time() - 86401 ), // > 24 h old.
		);
		$auth_data['hash'] = $this->build_telegram_hmac( $auth_data, $bot_token );

		$controller = new WP_MCP_AI_Telegram_Login_Controller();
		$result     = $controller->verify_auth_data( $auth_data, $bot_token );

		$this->assertInstanceOf( WP_Error::class, $result, 'Expired auth_date must be rejected' );
		$this->assertEquals( 403, $result->get_error_data()['status'] );
	}

	// -----------------------------------------------------------------------
	// 7–8. Legacy Google Chat permission check
	// -----------------------------------------------------------------------

	/**
	 * Without any filter hook the legacy Google Chat permission callback must
	 * return true (allows) to preserve backward compatibility.
	 *
	 * @group google-chat
	 */
	public function test_legacy_google_chat_permission_allows_by_default() {
		if ( ! class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Handler' ) ) {
			if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
				$this->markTestSkipped( 'WP_MCP_AI_PRO_PATH constant not defined.' );
			}
			$file = WP_MCP_AI_PRO_PATH . 'includes/src/ChatChannels/class-wp-mcp-ai-google-chat-webhook-handler.php';
			if ( ! file_exists( $file ) ) {
				$this->markTestSkipped( 'Google Chat webhook handler file not found.' );
			}
			require_once $file;
		}

		if ( ! class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Handler' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Google_Chat_Webhook_Handler class not available.' );
		}

		$handler = new WP_MCP_AI_Google_Chat_Webhook_Handler();
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/google-chat' );
		$result  = $handler->verify_google_chat_webhook( $request );

		$this->assertTrue( $result, 'Default behaviour must be to allow (backward-compatible)' );
	}

	/**
	 * When a filter hook rejects the request, the permission callback must
	 * return a WP_Error.
	 *
	 * @group google-chat
	 */
	public function test_legacy_google_chat_permission_defers_to_filter() {
		if ( ! class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Handler' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Google_Chat_Webhook_Handler class not available.' );
		}

		$expected_error = new WP_Error( 'invalid_token', 'Bad token', array( 'status' => 403 ) );

		add_filter(
			'wp_mcp_ai_verify_google_chat_legacy_webhook',
			function ( $result, $request ) use ( $expected_error ) {
				return $expected_error;
			},
			10,
			2
		);

		$handler = new WP_MCP_AI_Google_Chat_Webhook_Handler();
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/google-chat' );
		$result  = $handler->verify_google_chat_webhook( $request );

		$this->assertInstanceOf( WP_Error::class, $result, 'Filter-supplied WP_Error must be returned' );
		$this->assertEquals( 'invalid_token', $result->get_error_code() );
		$this->assertEquals( 403, $result->get_error_data()['status'] );
	}
}
