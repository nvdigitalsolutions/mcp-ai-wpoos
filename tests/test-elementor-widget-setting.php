<?php
/**
 * Tests for Elementor widget enable/disable setting.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Elementor widget setting control.
 */
class WP_MCP_AI_Elementor_Widget_Setting_Test extends WP_UnitTestCase {
	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that Elementor widgets are enabled by default (backward compatibility).
	 */
	public function test_elementor_widgets_enabled_by_default() {
		// Don't set any option - should default to enabled.
		$settings        = get_option( 'wp_mcp_ai_settings', array() );
		$widgets_enabled = isset( $settings['enable_elementor_widgets'] ) ? (bool) $settings['enable_elementor_widgets'] : true;

		$this->assertTrue( $widgets_enabled, 'Elementor widgets should be enabled by default for backward compatibility' );
	}

	/**
	 * Test that Elementor widgets can be disabled via setting.
	 */
	public function test_elementor_widgets_can_be_disabled() {
		// Set the option to false.
		update_option(
			'wp_mcp_ai_settings',
			array( 'enable_elementor_widgets' => false )
		);

		$settings        = get_option( 'wp_mcp_ai_settings', array() );
		$widgets_enabled = isset( $settings['enable_elementor_widgets'] ) ? (bool) $settings['enable_elementor_widgets'] : true;

		$this->assertFalse( $widgets_enabled, 'Elementor widgets should be disabled when setting is false' );
	}

	/**
	 * Test that Elementor widgets can be explicitly enabled via setting.
	 */
	public function test_elementor_widgets_can_be_enabled() {
		// Set the option to true.
		update_option(
			'wp_mcp_ai_settings',
			array( 'enable_elementor_widgets' => true )
		);

		$settings        = get_option( 'wp_mcp_ai_settings', array() );
		$widgets_enabled = isset( $settings['enable_elementor_widgets'] ) ? (bool) $settings['enable_elementor_widgets'] : true;

		$this->assertTrue( $widgets_enabled, 'Elementor widgets should be enabled when setting is true' );
	}

	/**
	 * Test that setting handles various truthy/falsy values correctly.
	 */
	public function test_elementor_widgets_setting_boolean_conversion() {
		// Test with string '1'.
		update_option(
			'wp_mcp_ai_settings',
			array( 'enable_elementor_widgets' => '1' )
		);

		$settings        = get_option( 'wp_mcp_ai_settings', array() );
		$widgets_enabled = isset( $settings['enable_elementor_widgets'] ) ? (bool) $settings['enable_elementor_widgets'] : true;

		$this->assertTrue( $widgets_enabled, 'String "1" should be truthy' );

		// Test with integer 0.
		update_option(
			'wp_mcp_ai_settings',
			array( 'enable_elementor_widgets' => 0 )
		);

		$settings        = get_option( 'wp_mcp_ai_settings', array() );
		$widgets_enabled = isset( $settings['enable_elementor_widgets'] ) ? (bool) $settings['enable_elementor_widgets'] : true;

		$this->assertFalse( $widgets_enabled, 'Integer 0 should be falsy' );
	}
}
