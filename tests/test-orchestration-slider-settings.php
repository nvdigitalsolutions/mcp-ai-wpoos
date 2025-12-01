<?php
/**
 * Tests for orchestration slider settings save and load functionality.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test that slider field values are properly saved and loaded in orchestration settings.
 */
class WP_MCP_AI_Orchestration_Slider_Settings_Test extends WP_UnitTestCase {

	/**
	 * Test that slider values are properly sanitized and saved.
	 */
	public function test_slider_values_are_sanitized() {
		// Get the orchestration section.
		$section = WP_MCP_AI_Settings_Registry::get_section( 'orchestration' );

		$this->assertNotNull( $section, 'Orchestration section should be registered' );

		// Simulate form submission with slider values.
		$input = array(
			'enable_budget_management'        => '1',
			'memory_warning_threshold'        => '70',
			'memory_critical_threshold'       => '85',
			'error_rate_warning_threshold'    => '5',
			'error_rate_critical_threshold'   => '10',
			'high_priority_budget'            => '100',
			'medium_priority_budget'          => '75',
			'low_priority_budget'             => '50',
			'critical_health_reduction'       => '50',
			'warning_health_reduction'        => '75',
			'low_tier_max_tokens'             => '2000',
			'medium_tier_max_tokens'          => '8000',
			'high_tier_max_tokens'            => '32000',
			'prediction_confidence_threshold' => '40',
			'prediction_safety_buffer'        => '15',
		);

		// Sanitize the input.
		$sanitized = $section->sanitize( $input );

		// Verify all slider values are present and correctly converted to integers.
		$this->assertArrayHasKey( 'memory_warning_threshold', $sanitized );
		$this->assertSame( 70, $sanitized['memory_warning_threshold'] );

		$this->assertArrayHasKey( 'memory_critical_threshold', $sanitized );
		$this->assertSame( 85, $sanitized['memory_critical_threshold'] );

		$this->assertArrayHasKey( 'high_priority_budget', $sanitized );
		$this->assertSame( 100, $sanitized['high_priority_budget'] );

		$this->assertArrayHasKey( 'low_tier_max_tokens', $sanitized );
		$this->assertSame( 2000, $sanitized['low_tier_max_tokens'] );
	}

	/**
	 * Test that slider values respect min/max boundaries.
	 */
	public function test_slider_values_respect_boundaries() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'orchestration' );

		// Try to submit values outside the allowed range.
		$input = array(
			'memory_warning_threshold'  => '200', // Max is 95.
			'memory_critical_threshold' => '10',  // Min is 75.
			'low_tier_max_tokens'       => '100', // Min is 500.
			'high_tier_max_tokens'      => '50000', // Max is 32000.
		);

		$sanitized = $section->sanitize( $input );

		// Values should be clamped to min/max.
		$this->assertSame( 95, $sanitized['memory_warning_threshold'], 'Value should be clamped to max' );
		$this->assertSame( 75, $sanitized['memory_critical_threshold'], 'Value should be clamped to min' );
		$this->assertSame( 500, $sanitized['low_tier_max_tokens'], 'Value should be clamped to min' );
		$this->assertSame( 32000, $sanitized['high_tier_max_tokens'], 'Value should be clamped to max' );
	}

	/**
	 * Test that slider values are retrieved correctly with defaults.
	 */
	public function test_slider_values_use_defaults_when_not_set() {
		// Clear any existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		// Get a slider value that hasn't been set.
		$value = WP_MCP_AI_Settings_Registry::get_setting( 'memory_warning_threshold', 70 );

		$this->assertSame( 70, $value, 'Should return default value when setting not saved' );
	}

	/**
	 * Test that saved slider values persist and can be retrieved.
	 */
	public function test_slider_values_persist_after_save() {
		// Save some slider settings.
		WP_MCP_AI_Settings_Registry::update_setting( 'memory_warning_threshold', 80 );
		WP_MCP_AI_Settings_Registry::update_setting( 'high_priority_budget', 90 );

		// Retrieve them.
		$memory_threshold = WP_MCP_AI_Settings_Registry::get_setting( 'memory_warning_threshold', 70 );
		$priority_budget  = WP_MCP_AI_Settings_Registry::get_setting( 'high_priority_budget', 100 );

		$this->assertSame( 80, $memory_threshold, 'Saved slider value should be retrieved correctly' );
		$this->assertSame( 90, $priority_budget, 'Saved slider value should be retrieved correctly' );

		// Clean up.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
	}

	/**
	 * Test that checkboxes and sliders work together in the same section.
	 */
	public function test_checkboxes_and_sliders_together() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'orchestration' );

		// Submit both checkboxes and sliders.
		$input = array(
			'enable_budget_management'       => '1', // Checkbox checked.
			'enable_predictive_optimization' => '',  // Checkbox unchecked (or not present).
			'memory_warning_threshold'       => '75', // Slider.
			'high_priority_budget'           => '95', // Slider.
		);

		$sanitized = $section->sanitize( $input );

		// Checkboxes should be boolean.
		$this->assertTrue( $sanitized['enable_budget_management'] );
		$this->assertFalse( $sanitized['enable_predictive_optimization'] );

		// Sliders should be integers.
		$this->assertSame( 75, $sanitized['memory_warning_threshold'] );
		$this->assertSame( 95, $sanitized['high_priority_budget'] );
	}

	/**
	 * Test that html and html-type fields don't interfere with slider sanitization.
	 */
	public function test_html_fields_do_not_interfere() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'orchestration' );

		// Submit slider values (html fields like 'orchestration_intro' shouldn't be in input).
		$input = array(
			'memory_warning_threshold' => '70',
			// HTML fields are not submitted in forms, they're display-only.
		);

		$sanitized = $section->sanitize( $input );

		// HTML fields should not appear in sanitized output.
		$this->assertArrayNotHasKey( 'orchestration_intro', $sanitized );
		$this->assertArrayNotHasKey( 'health_status', $sanitized );
		$this->assertArrayNotHasKey( 'configuration_presets', $sanitized );

		// Slider value should be present.
		$this->assertArrayHasKey( 'memory_warning_threshold', $sanitized );
		$this->assertSame( 70, $sanitized['memory_warning_threshold'] );
	}
}
