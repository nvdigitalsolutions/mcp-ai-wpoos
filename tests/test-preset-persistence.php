<?php
/**
 * Tests for configuration preset persistence.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that configuration presets persist correctly.
 */
class WP_MCP_AI_Preset_Persistence_Test extends WP_UnitTestCase {

	/**
	 * Test that orchestration settings are in defaults.
	 */
	public function test_orchestration_settings_in_defaults() {
		$defaults = WP_MCP_AI_Admin_Settings_Base::get_default_settings();

		// Verify all orchestration settings have defaults.
		$orchestration_settings = array(
			'orchestration_preset',
			'enable_budget_management',
			'enable_predictive_optimization',
			'enable_capability_gating',
			'enable_cron_orchestration',
			'cron_job_retention_period',
			'memory_warning_threshold',
			'memory_critical_threshold',
			'error_rate_warning_threshold',
			'error_rate_critical_threshold',
			'high_priority_budget',
			'medium_priority_budget',
			'low_priority_budget',
			'critical_health_reduction',
			'warning_health_reduction',
			'low_tier_max_tokens',
			'medium_tier_max_tokens',
			'high_tier_max_tokens',
			'prediction_confidence_threshold',
			'prediction_safety_buffer',
		);

		foreach ( $orchestration_settings as $setting ) {
			$this->assertArrayHasKey(
				$setting,
				$defaults,
				"Orchestration setting '{$setting}' should be in defaults"
			);
		}
	}

	/**
	 * Test that preset application updates settings correctly.
	 */
	public function test_preset_application_updates_settings() {
		// Clear existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		// Apply the "balanced" preset.
		$result = WP_MCP_AI_Orchestration_Preset_Service::apply_preset( 'balanced' );

		$this->assertTrue( $result, 'Preset application should succeed' );

		// Verify settings were updated.
		$memory_threshold = WP_MCP_AI_Settings_Registry::get_setting( 'memory_warning_threshold' );
		$this->assertSame( 70, $memory_threshold, 'Memory warning threshold should be 70 for balanced preset' );

		$safety_buffer = WP_MCP_AI_Settings_Registry::get_setting( 'prediction_safety_buffer' );
		$this->assertSame( 15, $safety_buffer, 'Prediction safety buffer should be 15 for balanced preset' );

		// Verify active preset was set.
		$active_preset = WP_MCP_AI_Settings_Registry::get_setting( 'orchestration_preset' );
		$this->assertSame( 'balanced', $active_preset, 'Active preset should be "balanced"' );

		// Clean up.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
	}

	/**
	 * Test that settings persist after saving from different tab.
	 */
	public function test_settings_persist_after_partial_save() {
		// Clear existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		// Apply the "aggressive" preset.
		WP_MCP_AI_Orchestration_Preset_Service::apply_preset( 'aggressive' );

		// Verify preset settings were applied.
		$memory_threshold_before = WP_MCP_AI_Settings_Registry::get_setting( 'memory_warning_threshold' );
		$this->assertSame( 80, $memory_threshold_before, 'Memory warning threshold should be 80 for aggressive preset' );

		// Simulate saving settings from a different tab (e.g., general settings).
		// This should NOT reset orchestration settings.
		$settings_base    = new WP_MCP_AI_Admin_Settings_Base();
		$partial_settings = array(
			'default_model'  => 'gpt-4o',
			'enable_logging' => true,
			// Note: orchestration settings are NOT included here.
		);

		$sanitized = $settings_base->sanitize_settings( $partial_settings );

		// Verify orchestration settings were preserved, not reset to defaults.
		$this->assertArrayHasKey( 'memory_warning_threshold', $sanitized );
		$this->assertSame(
			80,
			$sanitized['memory_warning_threshold'],
			'Memory warning threshold should be preserved at 80, not reset to default of 70'
		);

		$this->assertArrayHasKey( 'prediction_safety_buffer', $sanitized );
		$this->assertSame(
			10,
			$sanitized['prediction_safety_buffer'],
			'Prediction safety buffer should be preserved at 10, not reset to default of 15'
		);

		// Clean up.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
	}

	/**
	 * Test that checkboxes still reset to false when not in form.
	 */
	public function test_checkboxes_reset_correctly() {
		// Clear existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		// Set some checkboxes to true.
		WP_MCP_AI_Settings_Registry::update_setting( 'enable_budget_management', true );
		WP_MCP_AI_Settings_Registry::update_setting( 'enable_logging', true );

		// Verify they're set.
		$this->assertTrue( WP_MCP_AI_Settings_Registry::get_setting( 'enable_budget_management' ) );
		$this->assertTrue( WP_MCP_AI_Settings_Registry::get_setting( 'enable_logging' ) );

		// Simulate form save without checkboxes (unchecked).
		$settings_base    = new WP_MCP_AI_Admin_Settings_Base();
		$partial_settings = array(
			'default_model' => 'gpt-4o',
			// Checkboxes are NOT included - should be treated as false.
		);

		$sanitized = $settings_base->sanitize_settings( $partial_settings );

		// Verify checkboxes were reset to false.
		$this->assertFalse(
			$sanitized['enable_budget_management'],
			'Unchecked checkboxes should be false'
		);
		$this->assertFalse(
			$sanitized['enable_logging'],
			'Unchecked checkboxes should be false'
		);

		// Clean up.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
	}

	/**
	 * Test that all presets can be applied successfully.
	 */
	public function test_all_presets_apply_successfully() {
		$presets = WP_MCP_AI_Orchestration_Preset_Service::get_presets();

		foreach ( $presets as $preset_id => $preset_config ) {
			// Clear settings.
			delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

			// Apply preset.
			$result = WP_MCP_AI_Orchestration_Preset_Service::apply_preset( $preset_id );

			$this->assertTrue(
				$result,
				"Preset '{$preset_id}' should apply successfully"
			);

			// Verify active preset was set.
			$active = WP_MCP_AI_Settings_Registry::get_setting( 'orchestration_preset' );
			$this->assertSame(
				$preset_id,
				$active,
				"Active preset should be '{$preset_id}'"
			);
		}

		// Clean up.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
	}
}
