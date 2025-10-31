<?php
/**
 * Tests for token sanitization across external messaging tools.
 */
class WP_MCP_AI_Token_Sanitization_Test extends WP_UnitTestCase {
    /**
     * Ensure the WhatsApp access token retains base64 characters after sanitization.
     */
    public function test_whatsapp_access_token_preserves_base64_characters() {
        $tool   = new WP_MCP_AI_Tool_Send_WhatsApp_Message();
        $method = new ReflectionMethod( $tool, 'sanitize_access_token' );
        $method->setAccessible( true );

        $raw        = "  AbCd123+/=.-_~  ";
        $sanitized  = $method->invoke( $tool, $raw );
        $this->assertSame( 'AbCd123+/=.-_~', $sanitized );
    }

    /**
     * Ensure non-string WhatsApp tokens are rejected.
     */
    public function test_whatsapp_access_token_requires_string() {
        $tool   = new WP_MCP_AI_Tool_Send_WhatsApp_Message();
        $method = new ReflectionMethod( $tool, 'sanitize_access_token' );
        $method->setAccessible( true );

        $this->assertSame( '', $method->invoke( $tool, array( 'token' ) ) );
    }

    /**
     * Ensure Telegram bot tokens are not rewritten by sanitization.
     */
    public function test_telegram_token_preserves_base64_characters() {
        $tool   = new WP_MCP_AI_Tool_Send_Telegram_Message();
        $method = new ReflectionMethod( $tool, 'sanitize_token' );
        $method->setAccessible( true );

        $raw        = "  123456:ABC+/=.-_~  ";
        $sanitized  = $method->invoke( $tool, $raw );
        $this->assertSame( '123456:ABC+/=.-_~', $sanitized );
    }
}
