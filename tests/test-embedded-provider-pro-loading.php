<?php
/**
 * Test to verify embedded provider loads correctly in Pro plugin.
 *
 * @package WP_MCP_AI
 */

/**
 * Test embedded provider configuration in Pro vs Base versions.
 */
class WP_MCP_AI_Embedded_Provider_Pro_Loading_Test extends WP_UnitTestCase {

	/**
	 * Test that embedded provider fields are available in Pro (full) version.
	 */
	public function test_embedded_fields_available_in_pro_version() {
		// Simulate Pro version (WP_MCP_AI_BASE_VERSION should be false or undefined).
		$this->assertFalse( WP_MCP_AI_BASE_VERSION, 'This test expects Pro version (BASE_VERSION should be false)' );

		$section = new WP_MCP_AI_Section_Providers();
		$fields  = $section->get_fields();

		// Embedded fields should exist in Pro version.
		$this->assertArrayHasKey( 'enable_embedded', $fields, 'enable_embedded field should exist in Pro version' );
		$this->assertArrayHasKey( 'embedded_model', $fields, 'embedded_model field should exist in Pro version' );
		$this->assertArrayHasKey( 'embedded_model_management', $fields, 'embedded_model_management field should exist in Pro version' );

		// Verify field types.
		$this->assertEquals( 'checkbox', $fields['enable_embedded']['type'], 'enable_embedded should be a checkbox' );
		$this->assertEquals( 'select', $fields['embedded_model']['type'], 'embedded_model should be a select dropdown' );
		$this->assertEquals( 'custom', $fields['embedded_model_management']['type'], 'embedded_model_management should be custom' );

		// Verify default value (should be false - requires manual enabling).
		$this->assertFalse( $fields['enable_embedded']['default'], 'enable_embedded should default to false (requires manual enabling)' );
	}

	/**
	 * Test that embedded provider subtab is available in Pro version.
	 */
	public function test_embedded_subtab_available_in_pro_version() {
		// Simulate Pro version.
		$this->assertFalse( WP_MCP_AI_BASE_VERSION, 'This test expects Pro version (BASE_VERSION should be false)' );

		$section = new WP_MCP_AI_Section_Providers();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );

		$subtabs = $method->invoke( $section );

		// Embedded subtab should exist in Pro version.
		$this->assertArrayHasKey( 'embedded', $subtabs, 'Embedded subtab should exist in Pro version' );
		$this->assertIsArray( $subtabs['embedded'], 'Embedded subtab should be an array' );
		$this->assertEquals( 'embedded', $subtabs['embedded']['id'], 'Embedded subtab ID should be "embedded"' );
		$this->assertEquals( 'Embedded LLM', $subtabs['embedded']['label'], 'Embedded subtab label should be "Embedded LLM"' );
	}

	/**
	 * Test that embedded provider is NOT auto-enabled (requires manual checkbox).
	 */
	public function test_embedded_provider_not_auto_enabled() {
		// Simulate Pro version.
		$this->assertFalse( WP_MCP_AI_BASE_VERSION, 'This test expects Pro version (BASE_VERSION should be false)' );

		// Get current settings.
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		// Embedded should not be enabled by default.
		$this->assertFalse(
			isset( $settings['enable_embedded'] ) && $settings['enable_embedded'],
			'enable_embedded should not be auto-enabled (requires manual checkbox)'
		);
	}

	/**
	 * Test that embedded provider can be manually enabled.
	 */
	public function test_embedded_provider_can_be_enabled() {
		// Simulate Pro version.
		$this->assertFalse( WP_MCP_AI_BASE_VERSION, 'This test expects Pro version (BASE_VERSION should be false)' );

		// Enable embedded provider.
		$settings = array(
			'enable_embedded' => true,
			'embedded_model'  => 'Hermes-2-Pro-Llama-3-8B-q4f16_1-MLC',
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		// Verify it's enabled.
		$retrieved = get_option( 'wp_mcp_ai_settings', array() );
		$this->assertTrue( $retrieved['enable_embedded'], 'enable_embedded should be true after enabling' );
		$this->assertEquals( 'Hermes-2-Pro-Llama-3-8B-q4f16_1-MLC', $retrieved['embedded_model'], 'embedded_model should be set' );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test the complete flow: subtab visible, fields present, can be enabled.
	 */
	public function test_complete_embedded_provider_flow() {
		// Simulate Pro version.
		$this->assertFalse( WP_MCP_AI_BASE_VERSION, 'This test expects Pro version (BASE_VERSION should be false)' );

		$section = new WP_MCP_AI_Section_Providers();

		// 1. Verify subtab exists.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );
		$subtabs = $method->invoke( $section );
		$this->assertArrayHasKey( 'embedded', $subtabs, 'Step 1: Embedded subtab should exist' );

		// 2. Verify fields exist.
		$fields = $section->get_fields();
		$this->assertArrayHasKey( 'enable_embedded', $fields, 'Step 2: enable_embedded field should exist' );

		// 3. Verify default is disabled.
		$this->assertFalse( $fields['enable_embedded']['default'], 'Step 3: Should be disabled by default' );

		// 4. Verify can be enabled.
		$settings = array( 'enable_embedded' => true );
		update_option( 'wp_mcp_ai_settings', $settings );
		$retrieved = get_option( 'wp_mcp_ai_settings', array() );
		$this->assertTrue( $retrieved['enable_embedded'], 'Step 4: Should be enabled after setting' );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that embedded provider fields have correct Pro label.
	 */
	public function test_embedded_fields_have_pro_label() {
		// Simulate Pro version.
		$this->assertFalse( WP_MCP_AI_BASE_VERSION, 'This test expects Pro version (BASE_VERSION should be false)' );

		$section = new WP_MCP_AI_Section_Providers();
		$fields  = $section->get_fields();

		// Check that the checkbox label mentions "(Pro)".
		$this->assertStringContainsString(
			'(Pro)',
			$fields['enable_embedded']['checkbox_label'],
			'Checkbox label should mention "(Pro)" to indicate it\'s a Pro feature'
		);
	}

	/**
	 * Test that Pro addon constant is defined when Pro is loaded.
	 */
	public function test_pro_addon_constant_defined() {
		// When repository is cloned and Pro addon is present, WP_MCP_AI_PRO_VERSION should be defined.
		if ( file_exists( WP_MCP_AI_PATH . 'addons/pro/mcp-ai-wpoos-pro.php' ) ) {
			$this->assertTrue(
				defined( 'WP_MCP_AI_PRO_VERSION' ),
				'WP_MCP_AI_PRO_VERSION should be defined when Pro addon is present'
			);
		} else {
			$this->markTestSkipped( 'Pro addon not present in this installation' );
		}
	}
}
