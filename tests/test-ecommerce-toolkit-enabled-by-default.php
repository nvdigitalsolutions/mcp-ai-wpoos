<?php
/**
 * Tests for E-commerce Toolkit opt-in behavior.
 *
 * Validates that the e-commerce toolkit must be explicitly enabled in settings,
 * following the same pattern as Quiz and Regulatory Registration toolkits.
 *
 * @package WP_MCP_AI
 */

/**
 * Test E-commerce Toolkit opt-in behavior.
 */
class WP_MCP_AI_Ecommerce_Toolkit_Default_Enabled_Test extends WP_UnitTestCase {

	/**
	 * Test that helper function exists.
	 */
	public function test_helper_function_exists() {
		$this->assertTrue(
			function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ),
			'Helper function wp_mcp_ai_is_ecommerce_toolkit_enabled should exist'
		);
	}

	/**
	 * Test that toolkit is disabled by default.
	 */
	public function test_ecommerce_toolkit_disabled_by_default() {
		// Clear settings to simulate fresh install.
		delete_option( 'wp_mcp_ai_settings' );

		// Toolkit should be disabled by default (opt-in like other toolkits).
		$this->assertFalse(
			wp_mcp_ai_is_ecommerce_toolkit_enabled(),
			'E-commerce toolkit should be disabled by default when setting does not exist (opt-in model)'
		);
	}

	/**
	 * Test that toolkit respects explicit disable.
	 */
	public function test_ecommerce_toolkit_respects_disabled_setting() {
		// Set explicit disabled setting.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ecommerce_toolkit' => false,
			)
		);

		// Toolkit should be disabled.
		$this->assertFalse(
			wp_mcp_ai_is_ecommerce_toolkit_enabled(),
			'E-commerce toolkit should be disabled when explicitly set to false'
		);

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that toolkit can be explicitly enabled.
	 */
	public function test_ecommerce_toolkit_can_be_explicitly_enabled() {
		// Set explicit enabled setting.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ecommerce_toolkit' => true,
			)
		);

		// Toolkit should be enabled.
		$this->assertTrue(
			wp_mcp_ai_is_ecommerce_toolkit_enabled(),
			'E-commerce toolkit should be enabled when explicitly set to true'
		);

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test the setting handles various data types correctly.
	 */
	public function test_ecommerce_toolkit_setting_handles_various_types() {
		// Test with string "1" (checkbox value from form).
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ecommerce_toolkit' => '1',
			)
		);

		$this->assertTrue(
			wp_mcp_ai_is_ecommerce_toolkit_enabled(),
			'Should handle string "1" as enabled'
		);

		// Test with integer 1.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ecommerce_toolkit' => 1,
			)
		);

		$this->assertTrue(
			wp_mcp_ai_is_ecommerce_toolkit_enabled(),
			'Should handle integer 1 as enabled'
		);

		// Test with string "0".
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ecommerce_toolkit' => '0',
			)
		);

		$this->assertFalse(
			wp_mcp_ai_is_ecommerce_toolkit_enabled(),
			'Should handle string "0" as disabled (empty check)'
		);

		// Test with integer 0.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ecommerce_toolkit' => 0,
			)
		);

		$this->assertFalse(
			wp_mcp_ai_is_ecommerce_toolkit_enabled(),
			'Should handle integer 0 as disabled (empty check)'
		);

		// Test with boolean false.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ecommerce_toolkit' => false,
			)
		);

		$this->assertFalse(
			wp_mcp_ai_is_ecommerce_toolkit_enabled(),
			'Should handle boolean false as disabled'
		);

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that E-commerce admin pages load when WooCommerce is available and toolkit is enabled.
	 */
	public function test_ecommerce_pages_load_with_woocommerce() {
		// Skip if WooCommerce is not available or we're in base version mode.
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available' );
		}

		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			$this->markTestSkipped( 'Running in base version mode' );
		}

		// Enable the toolkit explicitly.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ecommerce_toolkit' => true,
			)
		);

		// E-commerce settings page class should exist.
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Ecommerce_Settings_Page' ),
			'E-commerce Settings Page class should be loaded when toolkit is enabled'
		);

		// Product Research page class should exist.
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Product_Research_Page' ),
			'Product Research Page class should be loaded when toolkit is enabled'
		);

		// Product Consolidate page class should exist.
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Product_Consolidate_Page' ),
			'Product Consolidate Page class should be loaded when toolkit is enabled'
		);

		// Product Settings page class should exist.
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Product_Settings_Page' ),
			'Product Settings Page class should be loaded when toolkit is enabled'
		);

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}
}
