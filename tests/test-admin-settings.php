<?php
/**
 * Tests for the admin settings class.
 */
class WP_MCP_AI_Admin_Settings_Test extends WP_UnitTestCase {

    /**
     * Ensure defaults include the uninstall cleanup flag.
     */
    public function test_default_settings_include_cleanup_flag() {
        $defaults = WP_MCP_AI_Admin_Settings::get_default_settings();

        $this->assertArrayHasKey( 'delete_on_uninstall', $defaults );
        $this->assertFalse( $defaults['delete_on_uninstall'] );
    }

    /**
     * Ensure defaults include the default model setting.
     */
    public function test_default_settings_define_default_model() {
        $defaults = WP_MCP_AI_Admin_Settings::get_default_settings();

        $this->assertArrayHasKey( 'default_model', $defaults );
        $this->assertSame( 'gpt-4o-mini', $defaults['default_model'] );
    }

    /**
     * Ensure sanitize_settings casts the cleanup flag to a boolean value.
     */
    public function test_sanitize_settings_casts_cleanup_flag() {
        $admin_settings = new WP_MCP_AI_Admin_Settings();

        $sanitized = $admin_settings->sanitize_settings(
            array(
                'delete_on_uninstall' => '1',
            )
        );

        $this->assertTrue( $sanitized['delete_on_uninstall'] );
    }

    /**
     * Ensure sanitize_settings enforces a minimum request timeout of five seconds.
     */
    public function test_sanitize_settings_clamps_request_timeout_floor() {
        $admin_settings = new WP_MCP_AI_Admin_Settings();

        $sanitized = $admin_settings->sanitize_settings(
            array(
                'request_timeout' => '3',
            )
        );

        $this->assertSame( 5, $sanitized['request_timeout'] );
    }

    /**
     * Ensure sanitize_settings leaves higher request timeout values untouched.
     */
    public function test_sanitize_settings_preserves_request_timeout_above_floor() {
        $admin_settings = new WP_MCP_AI_Admin_Settings();

        $sanitized = $admin_settings->sanitize_settings(
            array(
                'request_timeout' => '42',
            )
        );

        $this->assertSame( 42, $sanitized['request_timeout'] );
    }

    /**
     * Ensure sanitize_settings strips unsafe characters from the default model.
     */
    public function test_sanitize_settings_sanitizes_default_model() {
        $admin_settings = new WP_MCP_AI_Admin_Settings();

        $dirty_value = '  <b>gpt-4o-custom</b>  ';

        $sanitized = $admin_settings->sanitize_settings(
            array(
                'default_model' => $dirty_value,
            )
        );

        $this->assertSame( sanitize_text_field( $dirty_value ), $sanitized['default_model'] );
    }
}
