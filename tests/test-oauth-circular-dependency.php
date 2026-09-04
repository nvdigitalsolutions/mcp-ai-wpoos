<?php
/**
 * Test OAuth Manager Circular Dependency Fix
 *
 * Tests that WP_MCP_AI_OAuth_Manager can be instantiated without causing
 * a circular dependency with WP_MCP_AI_Admin_Settings.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for OAuth manager circular dependency fix.
 */
class Test_OAuth_Circular_Dependency extends WP_UnitTestCase {

	/**
	 * Test that WP_MCP_AI_OAuth_Manager can be instantiated.
	 *
	 * This test verifies that the circular dependency between
	 * WP_MCP_AI_Admin_Settings and WP_MCP_AI_OAuth_Manager has been fixed.
	 */
	public function test_oauth_manager_can_be_instantiated() {
		// This should not cause a fatal error or infinite loop.
		$oauth_manager = new WP_MCP_AI_OAuth_Manager();
		$this->assertInstanceOf( 'WP_MCP_AI_OAuth_Manager', $oauth_manager );
	}

	/**
	 * Test that admin settings can be instantiated.
	 *
	 * This test verifies that WP_MCP_AI_Admin_Settings can be instantiated
	 * without causing a circular dependency.
	 */
	public function test_admin_settings_can_be_instantiated() {
		// This should not cause a fatal error or infinite loop.
		$admin_settings = new WP_MCP_AI_Admin_Settings();
		$this->assertInstanceOf( 'WP_MCP_AI_Admin_Settings', $admin_settings );
	}

	/**
	 * Test that settings base class has sanitize method.
	 *
	 * This test verifies that WP_MCP_AI_Admin_Settings_Base has the
	 * sanitize_settings method that the OAuth manager depends on.
	 */
	public function test_settings_base_has_sanitize_method() {
		$settings_base = new WP_MCP_AI_Admin_Settings_Base();
		$this->assertTrue( method_exists( $settings_base, 'sanitize_settings' ) );
	}

	/**
	 * Test that settings base sanitize method works.
	 *
	 * This test verifies that the sanitize_settings method in the base class
	 * functions correctly.
	 */
	public function test_settings_base_sanitize_works() {
		$settings_base = new WP_MCP_AI_Admin_Settings_Base();

		// Test with empty array.
		$result = $settings_base->sanitize_settings( array() );
		$this->assertIsArray( $result );

		// Test with some settings.
		$test_settings = array(
			'openai_api_key' => '  test_key  ',
			'enable_logging' => '1',
		);

		$result = $settings_base->sanitize_settings( $test_settings );
		$this->assertIsArray( $result );

		// Verify the API key is sanitized (whitespace removed) and encrypted at
		// rest by the sensitive-settings path, and decrypts back to the input.
		$stored_key = $result['openai_api_key'];
		$this->assertStringStartsWith( 'v2:', (string) $stored_key, 'Sensitive settings must be encrypted at rest' );
		$this->assertEquals(
			'test_key',
			WP_MCP_AI_Admin_Settings_Base::maybe_decrypt_sensitive_setting_value( $stored_key ),
			'Encrypted API key must decrypt back to the sanitized value'
		);

		// Verify boolean is converted properly.
		$this->assertTrue( $result['enable_logging'] );
	}
}
