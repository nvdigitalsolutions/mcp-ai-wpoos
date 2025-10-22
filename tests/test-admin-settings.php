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
}
