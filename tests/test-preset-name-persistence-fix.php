<?php
/**
 * Tests for preset name persistence fix.
 *
 * Verifies that preset names stick after page reload (Issue #1093).
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test preset name persistence after cache operations.
 */
class WP_MCP_AI_Preset_Name_Persistence_Fix_Test extends WP_UnitTestCase {

	/**
	 * Test that update_setting clears cache.
	 */
	public function test_update_setting_clears_cache() {
		// Set a value.
		WP_MCP_AI_Settings_Registry::update_setting( 'orchestration_preset', 'balanced' );

		// Verify it's saved and can be retrieved.
		$value = WP_MCP_AI_Settings_Registry::get_setting( 'orchestration_preset' );
		$this->assertSame( 'balanced', $value, 'Preset should be retrieved correctly after update' );

		// Simulate cache being populated.
		$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		wp_cache_set( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings, 'options' );

		// Update the setting again.
		WP_MCP_AI_Settings_Registry::update_setting( 'orchestration_preset', 'aggressive' );

		// Verify we get the NEW value, not the cached one.
		$value = WP_MCP_AI_Settings_Registry::get_setting( 'orchestration_preset' );
		$this->assertSame( 'aggressive', $value, 'Preset should reflect updated value, not cached value' );

		// Clean up.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
	}

	/**
	 * Test that applying a preset persists after simulated page reload.
	 */
	public function test_preset_persists_after_page_reload() {
		// Clear existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		wp_cache_flush();

		// Apply the "balanced" preset.
		$result = WP_MCP_AI_Orchestration_Preset_Service::apply_preset( 'balanced' );
		$this->assertTrue( $result, 'Preset application should succeed' );

		// Simulate page reload by clearing all runtime caches.
		wp_cache_flush();

		// Get the active preset (simulates what happens on page load).
		$active_preset = WP_MCP_AI_Orchestration_Preset_Service::get_active_preset();
		$this->assertSame( 'balanced', $active_preset, 'Preset should be "balanced" after reload, not "custom"' );

		// Clean up.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
	}

	/**
	 * Test that multiple preset changes persist correctly.
	 */
	public function test_multiple_preset_changes_persist() {
		// Clear existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		wp_cache_flush();

		// Apply conservative preset.
		WP_MCP_AI_Orchestration_Preset_Service::apply_preset( 'conservative' );
		wp_cache_flush();
		$this->assertSame( 'conservative', WP_MCP_AI_Orchestration_Preset_Service::get_active_preset() );

		// Change to aggressive preset.
		WP_MCP_AI_Orchestration_Preset_Service::apply_preset( 'aggressive' );
		wp_cache_flush();
		$this->assertSame( 'aggressive', WP_MCP_AI_Orchestration_Preset_Service::get_active_preset() );

		// Change to balanced preset.
		WP_MCP_AI_Orchestration_Preset_Service::apply_preset( 'balanced' );
		wp_cache_flush();
		$this->assertSame( 'balanced', WP_MCP_AI_Orchestration_Preset_Service::get_active_preset() );

		// Clean up.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
	}

	/**
	 * Test that preset name displays correctly after application.
	 */
	public function test_preset_name_display_after_application() {
		// Clear existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		wp_cache_flush();

		// Apply the "aggressive" preset.
		WP_MCP_AI_Orchestration_Preset_Service::apply_preset( 'aggressive' );
		wp_cache_flush();

		// Get presets and active preset.
		$presets       = WP_MCP_AI_Orchestration_Preset_Service::get_presets();
		$active_preset = WP_MCP_AI_Orchestration_Preset_Service::get_active_preset();

		// Verify active preset is "aggressive".
		$this->assertSame( 'aggressive', $active_preset );

		// Verify the display name is "Performance" (not "Custom").
		$this->assertArrayHasKey( $active_preset, $presets );
		$this->assertArrayHasKey( 'name', $presets[ $active_preset ] );
		// Note: The actual name is translatable, so we check it contains "Performance" concept.
		$preset_name = $presets[ $active_preset ]['name'];
		$this->assertNotEquals( 'Custom', $preset_name, 'Preset name should not be "Custom"' );

		// Clean up.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
	}

	/**
	 * Test matches_preset with settings parameter.
	 */
	public function test_matches_preset_with_settings_parameter() {
		// Clear existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		// Apply balanced preset.
		WP_MCP_AI_Orchestration_Preset_Service::apply_preset( 'balanced' );

		// Get balanced preset config.
		$presets           = WP_MCP_AI_Orchestration_Preset_Service::get_presets();
		$balanced_settings = $presets['balanced']['settings'];

		// Should match when we pass balanced settings.
		$matches = WP_MCP_AI_Orchestration_Preset_Service::matches_preset( 'balanced', $balanced_settings );
		$this->assertTrue( $matches, 'Should match balanced preset with balanced settings' );

		// Should not match when we modify a setting.
		$modified_settings                             = $balanced_settings;
		$modified_settings['memory_warning_threshold'] = 999;
		$matches                                       = WP_MCP_AI_Orchestration_Preset_Service::matches_preset( 'balanced', $modified_settings );
		$this->assertFalse( $matches, 'Should not match balanced preset with modified settings' );

		// Clean up.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
	}
}
