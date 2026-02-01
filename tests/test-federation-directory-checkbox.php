<?php
/**
 * Test for Enable Federation Directory checkbox persistence.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that the Enable Federation Directory checkbox saves correctly.
 */
class WP_MCP_AI_Federation_Directory_Checkbox_Test extends WP_UnitTestCase {

	/**
	 * Test that enable_federation_directory checkbox saves correctly when checked.
	 */
	public function test_enable_federation_directory_checkbox_saves_when_checked() {
		// Clear existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		// Simulate saving the federation_mesh subtab with the checkbox checked.
		$_POST['subtab_advanced']    = 'federation_mesh';
		$_POST['wp_mcp_ai_settings'] = array(
			'enable_federation_directory' => '1', // Checked.
		);

		// Sanitize using the Advanced section.
		$section   = new WP_MCP_AI_Section_Advanced();
		$sanitized = $section->sanitize( $_POST['wp_mcp_ai_settings'] );

		// The checkbox should be in the sanitized output.
		$this->assertArrayHasKey(
			'enable_federation_directory',
			$sanitized,
			'enable_federation_directory should be in sanitized settings'
		);

		// The checkbox should be true.
		$this->assertTrue(
			$sanitized['enable_federation_directory'],
			'enable_federation_directory should be true when checked'
		);

		// Clean up.
		unset( $_POST['subtab_advanced'] );
		unset( $_POST['wp_mcp_ai_settings'] );
	}

	/**
	 * Test that enable_federation_directory checkbox saves correctly when unchecked.
	 */
	public function test_enable_federation_directory_checkbox_saves_when_unchecked() {
		// Set up initial settings with the checkbox checked.
		$initial_settings = array(
			'enable_federation_directory' => true,
			'federation_regions'          => 'global',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Simulate saving the federation_mesh subtab with the checkbox unchecked.
		$_POST['subtab_advanced']    = 'federation_mesh';
		$_POST['wp_mcp_ai_settings'] = array(
			// enable_federation_directory is NOT here (unchecked).
			'federation_regions' => 'us-east',
		);

		// Sanitize using the Advanced section.
		$section   = new WP_MCP_AI_Section_Advanced();
		$sanitized = $section->sanitize( $_POST['wp_mcp_ai_settings'] );

		// The checkbox should be in the sanitized output.
		$this->assertArrayHasKey(
			'enable_federation_directory',
			$sanitized,
			'enable_federation_directory should be in sanitized settings even when unchecked'
		);

		// The checkbox should be false.
		$this->assertFalse(
			$sanitized['enable_federation_directory'],
			'enable_federation_directory should be false when unchecked'
		);

		// Merge with existing and update.
		$existing = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$merged   = array_merge( $existing, $sanitized );
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $merged );

		// Verify the setting was persisted correctly.
		$saved_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$this->assertFalse(
			$saved_settings['enable_federation_directory'],
			'enable_federation_directory should remain false in database after save'
		);

		// Clean up.
		unset( $_POST['subtab_advanced'] );
		unset( $_POST['wp_mcp_ai_settings'] );
	}

	/**
	 * Test full save flow through Settings Dashboard.
	 */
	public function test_enable_federation_directory_through_dashboard_save() {
		// Clear existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		// Simulate the full dashboard save flow.
		$_POST['subtab_advanced']    = 'federation_mesh';
		$_POST['active_tab']         = 'advanced';
		$_POST['wp_mcp_ai_settings'] = array(
			'enable_federation_directory' => '1',
			'federation_regions'          => 'global',
		);

		// Use the Settings Dashboard to sanitize (this is what handles tab-based saves).
		$dashboard = new WP_MCP_AI_Settings_Dashboard();
		$sanitized = $dashboard->sanitize_settings( $_POST['wp_mcp_ai_settings'], 'advanced' );

		// Get existing settings and merge.
		$existing = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$merged   = array_merge( $existing, $sanitized );

		// Save to database.
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $merged );

		// Verify the setting was saved.
		$saved_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$this->assertTrue(
			! empty( $saved_settings['enable_federation_directory'] ),
			'enable_federation_directory should be true in database after dashboard save'
		);

		// Clean up.
		unset( $_POST['subtab_advanced'] );
		unset( $_POST['active_tab'] );
		unset( $_POST['wp_mcp_ai_settings'] );
	}

	/**
	 * Test that toggling the checkbox persists correctly across multiple saves.
	 */
	public function test_enable_federation_directory_toggle_persistence() {
		// Clear existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		// First save: enable the checkbox.
		$_POST['subtab_advanced']    = 'federation_mesh';
		$_POST['wp_mcp_ai_settings'] = array(
			'enable_federation_directory' => '1',
		);

		$section   = new WP_MCP_AI_Section_Advanced();
		$sanitized = $section->sanitize( $_POST['wp_mcp_ai_settings'] );
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $sanitized );

		$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$this->assertTrue(
			! empty( $settings['enable_federation_directory'] ),
			'First save: enable_federation_directory should be true'
		);

		// Second save: disable the checkbox.
		$_POST['wp_mcp_ai_settings'] = array(
			// enable_federation_directory is NOT here (unchecked).
		);

		$sanitized = $section->sanitize( $_POST['wp_mcp_ai_settings'] );
		$existing  = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$merged    = array_merge( $existing, $sanitized );
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $merged );

		$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$this->assertFalse(
			! empty( $settings['enable_federation_directory'] ),
			'Second save: enable_federation_directory should be false'
		);

		// Third save: enable again.
		$_POST['wp_mcp_ai_settings'] = array(
			'enable_federation_directory' => '1',
		);

		$sanitized = $section->sanitize( $_POST['wp_mcp_ai_settings'] );
		$existing  = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$merged    = array_merge( $existing, $sanitized );
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $merged );

		$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$this->assertTrue(
			! empty( $settings['enable_federation_directory'] ),
			'Third save: enable_federation_directory should be true again'
		);

		// Clean up.
		unset( $_POST['subtab_advanced'] );
		unset( $_POST['wp_mcp_ai_settings'] );
	}
}
