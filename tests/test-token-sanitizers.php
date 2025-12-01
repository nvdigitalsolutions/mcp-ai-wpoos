<?php
/**
 * tests/test-token-sanitizers.php
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-send-whatsapp-message.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-send-telegram-message.php';

/**
 * Tests for OAuth and API token sanitizers used by messaging tools.
 */
class WP_MCP_AI_Token_Sanitizer_Test extends WP_UnitTestCase {
	/**
	 * Ensure the WhatsApp token sanitizer only trims whitespace and keeps token characters intact.
	 */
	public function test_whatsapp_token_sanitizer_preserves_token_characters() {
		$tool   = new WP_MCP_AI_Tool_Send_WhatsApp_Message();
		$method = new ReflectionMethod( $tool, 'sanitize_access_token' );
		$method->setAccessible( true );

		$raw      = '  AbC123+/=~._-|  ';
		$expected = 'AbC123+/=~._-|';

		$this->assertSame( $expected, $method->invoke( $tool, $raw ) );
	}

	/**
	 * Ensure the WhatsApp token sanitizer rejects non-scalar inputs.
	 */
	public function test_whatsapp_token_sanitizer_rejects_non_scalar_input() {
		$tool   = new WP_MCP_AI_Tool_Send_WhatsApp_Message();
		$method = new ReflectionMethod( $tool, 'sanitize_access_token' );
		$method->setAccessible( true );

		$this->assertSame( '', $method->invoke( $tool, array( 'invalid' ) ) );
	}

	/**
	 * Ensure the Telegram token sanitizer retains valid characters.
	 */
	public function test_telegram_token_sanitizer_preserves_token_characters() {
		$tool   = new WP_MCP_AI_Tool_Send_Telegram_Message();
		$method = new ReflectionMethod( $tool, 'sanitize_token' );
		$method->setAccessible( true );

		$raw      = ' 123456:ABC-DEF_ghi+/=~ ';
		$expected = '123456:ABC-DEF_ghi+/=~';

		$this->assertSame( $expected, $method->invoke( $tool, $raw ) );
	}

	/**
	 * Ensure the Telegram token sanitizer rejects non-scalar inputs.
	 */
	public function test_telegram_token_sanitizer_rejects_non_scalar_input() {
		$tool   = new WP_MCP_AI_Tool_Send_Telegram_Message();
		$method = new ReflectionMethod( $tool, 'sanitize_token' );
		$method->setAccessible( true );

		$this->assertSame( '', $method->invoke( $tool, array( 'invalid' ) ) );
	}
}
