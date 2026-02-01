<?php
/**
 * Test Checkbox Rendering Fix
 *
 * Tests that checkboxes render correctly regardless of how values are stored in the database.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for checkbox rendering fix.
 */
class WP_MCP_AI_Checkbox_Rendering_Fix_Test extends WP_UnitTestCase {

	/**
	 * Test that boolean true value renders checkbox as checked.
	 */
	public function test_boolean_true_renders_checked() {
		// Store as boolean true.
		$settings = array(
			'enable_federation_directory' => true,
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Get the value back.
		$value = WP_MCP_AI_Settings_Registry::get_setting( 'enable_federation_directory', false );

		// Test the normalization logic from the fix.
		$is_checked = ! empty( $value ) && '0' !== $value && 0 !== $value;

		$this->assertTrue( $is_checked, 'Boolean true should result in checkbox being checked' );
	}

	/**
	 * Test that string "1" value renders checkbox as checked.
	 */
	public function test_string_one_renders_checked() {
		// Store as string "1".
		$settings = array(
			'enable_federation_directory' => '1',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Get the value back.
		$value = WP_MCP_AI_Settings_Registry::get_setting( 'enable_federation_directory', false );

		// Test the normalization logic from the fix.
		$is_checked = ! empty( $value ) && '0' !== $value && 0 !== $value;

		$this->assertTrue( $is_checked, 'String "1" should result in checkbox being checked' );
	}

	/**
	 * Test that integer 1 value renders checkbox as checked.
	 */
	public function test_integer_one_renders_checked() {
		// Store as integer 1.
		$settings = array(
			'enable_federation_directory' => 1,
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Get the value back.
		$value = WP_MCP_AI_Settings_Registry::get_setting( 'enable_federation_directory', false );

		// Test the normalization logic from the fix.
		$is_checked = ! empty( $value ) && '0' !== $value && 0 !== $value;

		$this->assertTrue( $is_checked, 'Integer 1 should result in checkbox being checked' );
	}

	/**
	 * Test that boolean false value renders checkbox as unchecked.
	 */
	public function test_boolean_false_renders_unchecked() {
		// Store as boolean false.
		$settings = array(
			'enable_federation_directory' => false,
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Get the value back.
		$value = WP_MCP_AI_Settings_Registry::get_setting( 'enable_federation_directory', false );

		// Test the normalization logic from the fix.
		$is_checked = ! empty( $value ) && '0' !== $value && 0 !== $value;

		$this->assertFalse( $is_checked, 'Boolean false should result in checkbox being unchecked' );
	}

	/**
	 * Test that string "0" value renders checkbox as unchecked.
	 */
	public function test_string_zero_renders_unchecked() {
		// Store as string "0".
		$settings = array(
			'enable_federation_directory' => '0',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Get the value back.
		$value = WP_MCP_AI_Settings_Registry::get_setting( 'enable_federation_directory', false );

		// Test the normalization logic from the fix.
		$is_checked = ! empty( $value ) && '0' !== $value && 0 !== $value;

		$this->assertFalse( $is_checked, 'String "0" should result in checkbox being unchecked' );
	}

	/**
	 * Test that integer 0 value renders checkbox as unchecked.
	 */
	public function test_integer_zero_renders_unchecked() {
		// Store as integer 0.
		$settings = array(
			'enable_federation_directory' => 0,
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Get the value back.
		$value = WP_MCP_AI_Settings_Registry::get_setting( 'enable_federation_directory', false );

		// Test the normalization logic from the fix.
		$is_checked = ! empty( $value ) && '0' !== $value && 0 !== $value;

		$this->assertFalse( $is_checked, 'Integer 0 should result in checkbox being unchecked' );
	}

	/**
	 * Test that null/missing value renders checkbox as unchecked.
	 */
	public function test_null_value_renders_unchecked() {
		// Store empty settings (null/missing value).
		$settings = array();
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Get the value back (will use default of false).
		$value = WP_MCP_AI_Settings_Registry::get_setting( 'enable_federation_directory', false );

		// Test the normalization logic from the fix.
		$is_checked = ! empty( $value ) && '0' !== $value && 0 !== $value;

		$this->assertFalse( $is_checked, 'Null/missing value should result in checkbox being unchecked' );
	}

	/**
	 * Test the full checkbox save and render cycle.
	 */
	public function test_full_checkbox_cycle() {
		// Step 1: Save checkboxes as checked (string "1" from form POST).
		$_POST['subtab_advanced']    = 'federation_mesh';
		$_POST['active_tab']         = 'advanced';
		$_POST['wp_mcp_ai_settings'] = array(
			'enable_mesh'                 => '1',
			'enable_federation'           => '1',
			'enable_federation_directory' => '1',
		);

		// Get the Advanced section and sanitize.
		$section   = new WP_MCP_AI_Section_Advanced();
		$sanitized = $section->sanitize( $_POST['wp_mcp_ai_settings'] );

		// Verify sanitization converts to boolean.
		$this->assertTrue( $sanitized['enable_mesh'], 'enable_mesh should be boolean true' );
		$this->assertTrue( $sanitized['enable_federation'], 'enable_federation should be boolean true' );
		$this->assertTrue( $sanitized['enable_federation_directory'], 'enable_federation_directory should be boolean true' );

		// Step 2: Save to database.
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $sanitized );

		// Step 3: Retrieve and test rendering normalization.
		$enable_mesh_value                 = WP_MCP_AI_Settings_Registry::get_setting( 'enable_mesh', false );
		$enable_federation_value           = WP_MCP_AI_Settings_Registry::get_setting( 'enable_federation', false );
		$enable_federation_directory_value = WP_MCP_AI_Settings_Registry::get_setting( 'enable_federation_directory', false );

		// Apply rendering normalization.
		$enable_mesh_checked                 = ! empty( $enable_mesh_value ) && '0' !== $enable_mesh_value && 0 !== $enable_mesh_value;
		$enable_federation_checked           = ! empty( $enable_federation_value ) && '0' !== $enable_federation_value && 0 !== $enable_federation_value;
		$enable_federation_directory_checked = ! empty( $enable_federation_directory_value ) && '0' !== $enable_federation_directory_value && 0 !== $enable_federation_directory_value;

		// All should be checked.
		$this->assertTrue( $enable_mesh_checked, 'enable_mesh checkbox should render as checked' );
		$this->assertTrue( $enable_federation_checked, 'enable_federation checkbox should render as checked' );
		$this->assertTrue( $enable_federation_directory_checked, 'enable_federation_directory checkbox should render as checked' );

		// Step 4: Now uncheck them (string "0" from JavaScript-added hidden fields).
		$_POST['wp_mcp_ai_settings'] = array(
			'enable_mesh'                 => '0',
			'enable_federation'           => '0',
			'enable_federation_directory' => '0',
		);

		// Sanitize again.
		$sanitized = $section->sanitize( $_POST['wp_mcp_ai_settings'] );

		// Verify sanitization converts to boolean false.
		$this->assertFalse( $sanitized['enable_mesh'], 'enable_mesh should be boolean false' );
		$this->assertFalse( $sanitized['enable_federation'], 'enable_federation should be boolean false' );
		$this->assertFalse( $sanitized['enable_federation_directory'], 'enable_federation_directory should be boolean false' );

		// Step 5: Save to database.
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $sanitized );

		// Step 6: Retrieve and test rendering normalization.
		$enable_mesh_value                 = WP_MCP_AI_Settings_Registry::get_setting( 'enable_mesh', false );
		$enable_federation_value           = WP_MCP_AI_Settings_Registry::get_setting( 'enable_federation', false );
		$enable_federation_directory_value = WP_MCP_AI_Settings_Registry::get_setting( 'enable_federation_directory', false );

		// Apply rendering normalization.
		$enable_mesh_checked                 = ! empty( $enable_mesh_value ) && '0' !== $enable_mesh_value && 0 !== $enable_mesh_value;
		$enable_federation_checked           = ! empty( $enable_federation_value ) && '0' !== $enable_federation_value && 0 !== $enable_federation_value;
		$enable_federation_directory_checked = ! empty( $enable_federation_directory_value ) && '0' !== $enable_federation_directory_value && 0 !== $enable_federation_directory_value;

		// All should be unchecked.
		$this->assertFalse( $enable_mesh_checked, 'enable_mesh checkbox should render as unchecked' );
		$this->assertFalse( $enable_federation_checked, 'enable_federation checkbox should render as unchecked' );
		$this->assertFalse( $enable_federation_directory_checked, 'enable_federation_directory checkbox should render as unchecked' );

		// Clean up.
		unset( $_POST['subtab_advanced'] );
		unset( $_POST['active_tab'] );
		unset( $_POST['wp_mcp_ai_settings'] );
	}
}
