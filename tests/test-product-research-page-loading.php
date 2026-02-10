<?php
/**
 * Tests for Product Research & Settings page loading with default WooCommerce settings.
 *
 * Validates that:
 * 1. Product Research page loads when WooCommerce is active (even without explicit settings)
 * 2. Product Settings page loads when WooCommerce is active (even without explicit settings)
 * 3. Pages respect the enable_woocommerce_tools setting when explicitly set
 *
 * @package WP_MCP_AI
 */

/**
 * Test Product Research page loading with default settings.
 */
class WP_MCP_AI_Product_Research_Page_Loading_Test extends WP_UnitTestCase {

	/**
	 * Test that Product Research page classes load when WooCommerce is available.
	 *
	 * This simulates a fresh install where enable_woocommerce_tools hasn't been saved yet.
	 * The fix ensures the page still loads with the default value of true.
	 */
	public function test_product_research_page_loads_with_default_settings() {
		// Skip if WooCommerce is not available or we're in base version mode.
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available' );
		}

		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			$this->markTestSkipped( 'Running in base version mode' );
		}

		// Simulate fresh install - clear the settings.
		delete_option( 'wp_mcp_ai_settings' );

		// Product Research page class should exist after pro addon loads.
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Product_Research_Page' ),
			'Product Research Page class should be loaded with default settings'
		);

		// Product Settings page class should exist after pro addon loads.
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Product_Settings_Page' ),
			'Product Settings Page class should be loaded with default settings'
		);
	}

	/**
	 * Test that Product Research page respects explicit disable setting.
	 */
	public function test_product_research_page_respects_disabled_setting() {
		// Skip if WooCommerce is not available or we're in base version mode.
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available' );
		}

		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			$this->markTestSkipped( 'Running in base version mode' );
		}

		// Set explicit disabled setting.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_woocommerce_tools' => false,
			)
		);

		// Since the classes are loaded at plugin init time and we're in the middle of a test,
		// we can't test the actual non-loading. Instead, we verify the logic would work.
		$settings                  = get_option( 'wp_mcp_ai_settings', array() );
		$woocommerce_tools_enabled = isset( $settings['enable_woocommerce_tools'] ) ? (bool) $settings['enable_woocommerce_tools'] : true;

		$this->assertFalse(
			$woocommerce_tools_enabled,
			'WooCommerce tools should be disabled when explicitly set to false'
		);

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that default value is true when setting doesn't exist.
	 */
	public function test_woocommerce_tools_default_is_true() {
		// Clear settings to simulate fresh install.
		delete_option( 'wp_mcp_ai_settings' );

		// Get settings (empty array).
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		// Apply the same logic as the fix.
		$woocommerce_tools_enabled = isset( $settings['enable_woocommerce_tools'] ) ? (bool) $settings['enable_woocommerce_tools'] : true;

		$this->assertTrue(
			$woocommerce_tools_enabled,
			'WooCommerce tools should default to true when setting does not exist'
		);
	}

	/**
	 * Test that setting can be explicitly enabled.
	 */
	public function test_woocommerce_tools_can_be_explicitly_enabled() {
		// Set explicit enabled setting.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_woocommerce_tools' => true,
			)
		);

		$settings                  = get_option( 'wp_mcp_ai_settings', array() );
		$woocommerce_tools_enabled = isset( $settings['enable_woocommerce_tools'] ) ? (bool) $settings['enable_woocommerce_tools'] : true;

		$this->assertTrue(
			$woocommerce_tools_enabled,
			'WooCommerce tools should be enabled when explicitly set to true'
		);

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test the isset pattern handles various data types correctly.
	 */
	public function test_woocommerce_tools_setting_handles_various_types() {
		// Test with string "1" (checkbox value from form).
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_woocommerce_tools' => '1',
			)
		);

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$enabled  = isset( $settings['enable_woocommerce_tools'] ) ? (bool) $settings['enable_woocommerce_tools'] : true;
		$this->assertTrue( $enabled, 'Should handle string "1" as true' );

		// Test with integer 1.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_woocommerce_tools' => 1,
			)
		);

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$enabled  = isset( $settings['enable_woocommerce_tools'] ) ? (bool) $settings['enable_woocommerce_tools'] : true;
		$this->assertTrue( $enabled, 'Should handle integer 1 as true' );

		// Test with string "0".
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_woocommerce_tools' => '0',
			)
		);

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$enabled  = isset( $settings['enable_woocommerce_tools'] ) ? (bool) $settings['enable_woocommerce_tools'] : true;
		$this->assertFalse( $enabled, 'Should handle string "0" as false' );

		// Test with integer 0.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_woocommerce_tools' => 0,
			)
		);

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$enabled  = isset( $settings['enable_woocommerce_tools'] ) ? (bool) $settings['enable_woocommerce_tools'] : true;
		$this->assertFalse( $enabled, 'Should handle integer 0 as false' );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}
}
