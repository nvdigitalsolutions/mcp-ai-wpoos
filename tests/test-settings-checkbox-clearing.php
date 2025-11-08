<?php
/**
 * Tests for checkbox clearing issue in settings.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that unchecked checkboxes properly save as false.
 */
class WP_MCP_AI_Settings_Checkbox_Clearing_Test extends WP_UnitTestCase {

	/**
	 * Test that unchecked checkboxes are set to false, not skipped.
	 */
	public function test_unchecked_checkbox_saves_as_false() {
		$admin_settings = new WP_MCP_AI_Admin_Settings();
		$settings_base  = new WP_MCP_AI_Admin_Settings_Base();

		// Simulate saving settings with a checkbox that WAS checked, now UNchecked.
		// When unchecked, the checkbox won't be in the submitted array.
		$submitted = array(
			'openai_api_key' => 'test-key',
			// Note: 'enable_logging' is NOT in this array (unchecked).
		);

		$sanitized = $settings_base->sanitize_settings( $submitted );

		// The unchecked checkbox should be explicitly set to false, not skipped.
		$this->assertArrayHasKey( 'enable_logging', $sanitized, 'enable_logging should be in sanitized settings' );
		$this->assertFalse( $sanitized['enable_logging'], 'Unchecked checkbox should be false' );
	}

	/**
	 * Test that checked checkboxes are set to true.
	 */
	public function test_checked_checkbox_saves_as_true() {
		$settings_base = new WP_MCP_AI_Admin_Settings_Base();

		// Simulate saving settings with a checkbox that IS checked.
		$submitted = array(
			'openai_api_key' => 'test-key',
			'enable_logging' => '1', // Checked.
		);

		$sanitized = $settings_base->sanitize_settings( $submitted );

		$this->assertArrayHasKey( 'enable_logging', $sanitized );
		$this->assertTrue( $sanitized['enable_logging'], 'Checked checkbox should be true' );
	}

	/**
	 * Test that all boolean defaults are included even when not submitted.
	 */
	public function test_all_boolean_defaults_are_included() {
		$settings_base = new WP_MCP_AI_Admin_Settings_Base();

		// Submit minimal settings.
		$submitted = array(
			'openai_api_key' => 'test-key',
		);

		$sanitized = $settings_base->sanitize_settings( $submitted );
		$defaults  = WP_MCP_AI_Admin_Settings_Base::get_default_settings();

		// Find all boolean defaults.
		$boolean_keys = array();
		foreach ( $defaults as $key => $value ) {
			if ( is_bool( $value ) ) {
				$boolean_keys[] = $key;
			}
		}

		// All boolean keys should be in the sanitized output.
		foreach ( $boolean_keys as $key ) {
			$this->assertArrayHasKey(
				$key,
				$sanitized,
				"Boolean setting '$key' should be in sanitized settings even when not submitted"
			);
			$this->assertIsBool( $sanitized[ $key ], "Setting '$key' should be a boolean" );
		}
	}

	/**
	 * Test that empty text fields are preserved as empty strings, not skipped.
	 */
	public function test_empty_text_fields_are_preserved() {
		$settings_base = new WP_MCP_AI_Admin_Settings_Base();

		// Submit settings with an empty text field.
		$submitted = array(
			'openai_api_key' => '', // Empty string.
			'enable_logging' => '1',
		);

		$sanitized = $settings_base->sanitize_settings( $submitted );

		$this->assertArrayHasKey( 'openai_api_key', $sanitized );
		$this->assertSame( '', $sanitized['openai_api_key'], 'Empty text field should be empty string' );
	}

	/**
	 * Test that settings not in submission get default values.
	 */
	public function test_unsubmitted_settings_get_defaults() {
		$settings_base = new WP_MCP_AI_Admin_Settings_Base();
		$defaults      = WP_MCP_AI_Admin_Settings_Base::get_default_settings();

		// Submit minimal settings.
		$submitted = array(
			'openai_api_key' => 'test-key',
		);

		$sanitized = $settings_base->sanitize_settings( $submitted );

		// Check that unsubmitted settings get their defaults.
		$this->assertArrayHasKey( 'request_timeout', $sanitized );
		$this->assertSame( $defaults['request_timeout'], $sanitized['request_timeout'] );

		$this->assertArrayHasKey( 'default_model', $sanitized );
		$this->assertSame( $defaults['default_model'], $sanitized['default_model'] );
	}

	/**
	 * Test multiple checkboxes can be properly toggled.
	 */
	public function test_multiple_checkboxes_toggle() {
		$settings_base = new WP_MCP_AI_Admin_Settings_Base();

		// First save: some checkboxes on.
		$submitted1 = array(
			'enable_logging'    => '1',
			'delete_on_uninstall' => '1',
		);
		$sanitized1 = $settings_base->sanitize_settings( $submitted1 );

		$this->assertTrue( $sanitized1['enable_logging'] );
		$this->assertTrue( $sanitized1['delete_on_uninstall'] );

		// Second save: toggle them off (not in submission).
		$submitted2 = array(
			// Both checkboxes unchecked (not in array).
		);
		$sanitized2 = $settings_base->sanitize_settings( $submitted2 );

		$this->assertFalse( $sanitized2['enable_logging'], 'enable_logging should be false when unchecked' );
		$this->assertFalse( $sanitized2['delete_on_uninstall'], 'delete_on_uninstall should be false when unchecked' );
	}

	/**
	 * Test that array defaults are preserved.
	 */
	public function test_array_defaults_are_preserved() {
		$settings_base = new WP_MCP_AI_Admin_Settings_Base();
		$defaults      = WP_MCP_AI_Admin_Settings_Base::get_default_settings();

		// Submit minimal settings.
		$submitted = array(
			'openai_api_key' => 'test-key',
		);

		$sanitized = $settings_base->sanitize_settings( $submitted );

		// Check array defaults like chat_colors are preserved.
		if ( isset( $defaults['chat_colors'] ) && is_array( $defaults['chat_colors'] ) ) {
			$this->assertArrayHasKey( 'chat_colors', $sanitized );
			$this->assertIsArray( $sanitized['chat_colors'] );
		}
	}
}
