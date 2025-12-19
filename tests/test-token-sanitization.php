<?php
/**
 * Tests for token sanitization across external messaging tools.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-send-whatsapp-message.php';
require_once WP_MCP_AI_PATH . 'addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-send-telegram-message.php';

class WP_MCP_AI_Token_Sanitization_Test extends WP_UnitTestCase {
	/**
	 * Ensure the WhatsApp access token retains base64 characters after sanitization.
	 */
	public function test_whatsapp_access_token_preserves_base64_characters() {
		$tool   = new WP_MCP_AI_Pro_Tool_Send_WhatsApp_Message();
		$method = new ReflectionMethod( $tool, 'sanitize_access_token' );
		$method->setAccessible( true );

		$raw       = '  AbCd123+/=.-_~  ';
		$sanitized = $method->invoke( $tool, $raw );
		$this->assertSame( 'AbCd123+/=.-_~', $sanitized );
	}

	/**
	 * Ensure non-string WhatsApp tokens are rejected.
	 */
	public function test_whatsapp_access_token_requires_string() {
		$tool   = new WP_MCP_AI_Pro_Tool_Send_WhatsApp_Message();
		$method = new ReflectionMethod( $tool, 'sanitize_access_token' );
		$method->setAccessible( true );

		$this->assertSame( '', $method->invoke( $tool, array( 'token' ) ) );
	}

	/**
	 * Ensure Telegram bot tokens are not rewritten by sanitization.
	 */
	public function test_telegram_token_preserves_base64_characters() {
		$tool   = new WP_MCP_AI_Pro_Tool_Send_Telegram_Message();
		$method = new ReflectionMethod( $tool, 'sanitize_token' );
		$method->setAccessible( true );

		$raw       = '  123456:ABC+/=.-_~  ';
		$sanitized = $method->invoke( $tool, $raw );
		$this->assertSame( '123456:ABC+/=.-_~', $sanitized );
	}

	/**
	 * Ensure Telegram bot tokens are URL-encoded before building the request endpoint.
	 */
	public function test_telegram_token_is_url_encoded_in_endpoint() {
		$tool     = new WP_MCP_AI_Pro_Tool_Send_Telegram_Message();
		$token    = '123456:ABC+/=.-_~';
		$captured = null;

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$interceptor = function ( $preempt, $request, $url ) use ( &$captured ) {
			$captured = $url;

			return new WP_Error( 'intercepted_request', 'Prevented outbound HTTP request during test.' );
		};

		add_filter( 'pre_http_request', $interceptor, 10, 3 );

		$result = $tool->execute(
			array(
				'token'   => $token,
				'chat_id' => '123456789',
				'text'    => 'Hello world',
			)
		);

		remove_filter( 'pre_http_request', $interceptor, 10 );
		wp_set_current_user( 0 );

		$expected = sprintf( 'https://api.telegram.org/bot%s/sendMessage', rawurlencode( $token ) );
		$this->assertWPError( $result );
		$this->assertSame( $expected, $captured );
	}
}
