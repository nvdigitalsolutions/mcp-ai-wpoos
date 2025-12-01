<?php
/**
 * Tests for checkbox clearing issue in settings.
 *
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
			'enable_logging'      => '1',
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

	/**
	 * Test that saving settings from one tab doesn't clear checkboxes from another tab.
	 * This is the key test for the issue described in the problem statement.
	 */
	public function test_saving_advanced_tab_preserves_general_tab_checkboxes() {
		// First, set up initial settings with enable_logging turned on.
		$initial_settings = array(
			'enable_logging'          => true,
			'enable_extended_logging' => false,
			'openai_api_key'          => 'test-key',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Now simulate saving the Advanced tab with enable_extended_logging turned on.
		// The key here is that enable_logging is NOT in the posted settings because.
		// it's on a different tab (General tab).
		$dashboard       = new WP_MCP_AI_Settings_Dashboard();
		$posted_settings = array(
			'enable_extended_logging' => '1', // From Advanced tab.
			// Note: enable_logging is NOT here because we're on the Advanced tab.
		);

		// Sanitize only the advanced tab settings.
		$sanitized = $dashboard->sanitize_settings( $posted_settings, 'advanced' );

		// Get the existing settings from the database.
		$existing_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

		// Merge as the dashboard does.
		$merged = array_merge( $existing_settings, $sanitized );

		// The critical assertion: enable_logging should still be true.
		// It should NOT have been cleared just because we saved the Advanced tab.
		$this->assertTrue(
			$merged['enable_logging'],
			'enable_logging from General tab should remain true when saving Advanced tab'
		);

		// And enable_extended_logging should now be true.
		$this->assertTrue(
			$merged['enable_extended_logging'],
			'enable_extended_logging should be true after saving Advanced tab'
		);
	}

	/**
	 * Test that saving settings from general tab preserves advanced tab checkboxes.
	 */
	public function test_saving_general_tab_preserves_advanced_tab_checkboxes() {
		// First, set up initial settings with enable_extended_logging turned on.
		$initial_settings = array(
			'enable_logging'          => false,
			'enable_extended_logging' => true,
			'openai_api_key'          => 'test-key',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Now simulate saving the General tab with enable_logging turned on.
		$dashboard       = new WP_MCP_AI_Settings_Dashboard();
		$posted_settings = array(
			'enable_logging' => '1', // From General tab.
			// Note: enable_extended_logging is NOT here because we're on the General tab.
		);

		// Sanitize only the general tab settings.
		$sanitized = $dashboard->sanitize_settings( $posted_settings, 'general' );

		// Get the existing settings from the database.
		$existing_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

		// Merge as the dashboard does.
		$merged = array_merge( $existing_settings, $sanitized );

		// The critical assertion: enable_extended_logging should still be true.
		$this->assertTrue(
			$merged['enable_extended_logging'],
			'enable_extended_logging from Advanced tab should remain true when saving General tab'
		);

		// And enable_logging should now be true.
		$this->assertTrue(
			$merged['enable_logging'],
			'enable_logging should be true after saving General tab'
		);
	}

	/**
	 * Test that select fields with integer keys save correctly.
	 *
	 * This specifically tests the default_assistant field which has integer post IDs as option keys.
	 * Form submissions send all values as strings, but we need to convert them back to integers
	 * to match the option keys for proper validation.
	 */
	public function test_select_field_with_integer_keys_saves_correctly() {
		// Create a mock assistant post.
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		$section = new WP_MCP_AI_Section_General();

		// Simulate form submission - values come as strings from POST data.
		$submitted = array(
			'default_assistant' => (string) $assistant_id, // String representation of integer ID.
			'default_provider'  => 'openai',
			'enable_logging'    => '1',
		);

		$sanitized = $section->sanitize( $submitted );

		// The default_assistant should be in the sanitized output.
		$this->assertArrayHasKey( 'default_assistant', $sanitized, 'default_assistant should be in sanitized settings' );

		// The default_assistant should be converted to an integer.
		$this->assertIsInt( $sanitized['default_assistant'], 'default_assistant should be an integer' );

		// The default_assistant should have the correct value.
		$this->assertSame( $assistant_id, $sanitized['default_assistant'], 'default_assistant should have the correct assistant ID' );
	}

	/**
	 * Test that select fields with string keys still work correctly.
	 */
	public function test_select_field_with_string_keys_saves_correctly() {
		$section = new WP_MCP_AI_Section_General();

		// Simulate form submission with string-based select.
		$submitted = array(
			'default_provider'  => 'gemini', // String key.
			'default_assistant' => '0', // "None" option.
			'enable_logging'    => '1',
		);

		$sanitized = $section->sanitize( $submitted );

		// The default_provider should be in the sanitized output.
		$this->assertArrayHasKey( 'default_provider', $sanitized, 'default_provider should be in sanitized settings' );

		// The default_provider should be a string.
		$this->assertSame( 'gemini', $sanitized['default_provider'], 'default_provider should be "gemini"' );

		// The default_assistant should be 0 (integer, not string "0").
		$this->assertSame( 0, $sanitized['default_assistant'], 'default_assistant should be 0 when set to "None"' );
	}
}
