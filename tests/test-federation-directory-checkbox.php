<?php
/**
 * Test for Federation Mesh checkbox persistence.
 *
 * Tests all three federation mesh checkboxes:
 * - enable_mesh (Enable Mesh Computing)
 * - enable_federation (Enable Federation)
 * - enable_federation_directory (Enable Federation Directory)
 *
 * @package WP_MCP_AI
 */

/**
 * Test that the Federation Mesh checkboxes save correctly.
 */
class WP_MCP_AI_Federation_Directory_Checkbox_Test extends WP_UnitTestCase {

	/**
	 * Test that enable_federation checkbox saves correctly when checked.
	 */
	public function test_enable_federation_checkbox_saves_when_checked() {
		// Clear existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		// Simulate saving the federation_mesh subtab with the checkbox checked.
		$_POST['subtab_advanced']    = 'federation_mesh';
		$_POST['wp_mcp_ai_settings'] = array(
			'enable_federation' => '1', // Checked.
		);

		// Sanitize using the Advanced section.
		$section   = new WP_MCP_AI_Section_Advanced();
		$sanitized = $section->sanitize( $_POST['wp_mcp_ai_settings'] );

		// The checkbox should be in the sanitized output.
		$this->assertArrayHasKey(
			'enable_federation',
			$sanitized,
			'enable_federation should be in sanitized settings'
		);

		// The checkbox should be true.
		$this->assertTrue(
			$sanitized['enable_federation'],
			'enable_federation should be true when checked'
		);

		// Clean up.
		unset( $_POST['subtab_advanced'] );
		unset( $_POST['wp_mcp_ai_settings'] );
	}

	/**
	 * Test that enable_federation checkbox saves correctly when unchecked.
	 */
	public function test_enable_federation_checkbox_saves_when_unchecked() {
		// Set up initial settings with the checkbox checked.
		$initial_settings = array(
			'enable_federation' => true,
			'federation_regions'          => 'global',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Simulate saving the federation_mesh subtab with the checkbox unchecked.
		$_POST['subtab_advanced']    = 'federation_mesh';
		$_POST['wp_mcp_ai_settings'] = array(
			// enable_federation is NOT here (unchecked).
			'federation_regions' => 'us-east',
		);

		// Sanitize using the Advanced section.
		$section   = new WP_MCP_AI_Section_Advanced();
		$sanitized = $section->sanitize( $_POST['wp_mcp_ai_settings'] );

		// The checkbox should be in the sanitized output.
		$this->assertArrayHasKey(
			'enable_federation',
			$sanitized,
			'enable_federation should be in sanitized settings even when unchecked'
		);

		// The checkbox should be false.
		$this->assertFalse(
			$sanitized['enable_federation'],
			'enable_federation should be false when unchecked'
		);

		// Merge with existing and update.
		$existing = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$merged   = array_merge( $existing, $sanitized );
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $merged );

		// Verify the setting was persisted correctly.
		$saved_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$this->assertFalse(
			$saved_settings['enable_federation'],
			'enable_federation should remain false in database after save'
		);

		// Clean up.
		unset( $_POST['subtab_advanced'] );
		unset( $_POST['wp_mcp_ai_settings'] );
	}

	/**
	 * Test full save flow through Settings Dashboard.
	 */
	public function test_enable_federation_through_dashboard_save() {
		// Clear existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		// Simulate the full dashboard save flow.
		$_POST['subtab_advanced']    = 'federation_mesh';
		$_POST['active_tab']         = 'advanced';
		$_POST['wp_mcp_ai_settings'] = array(
			'enable_federation' => '1',
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
			! empty( $saved_settings['enable_federation'] ),
			'enable_federation should be true in database after dashboard save'
		);

		// Clean up.
		unset( $_POST['subtab_advanced'] );
		unset( $_POST['active_tab'] );
		unset( $_POST['wp_mcp_ai_settings'] );
	}

	/**
	 * Test that toggling the checkbox persists correctly across multiple saves.
	 */
	public function test_enable_federation_toggle_persistence() {
		// Clear existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		// First save: enable the checkbox.
		$_POST['subtab_advanced']    = 'federation_mesh';
		$_POST['wp_mcp_ai_settings'] = array(
			'enable_federation' => '1',
		);

		$section   = new WP_MCP_AI_Section_Advanced();
		$sanitized = $section->sanitize( $_POST['wp_mcp_ai_settings'] );
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $sanitized );

		$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$this->assertTrue(
			! empty( $settings['enable_federation'] ),
			'First save: enable_federation should be true'
		);

		// Second save: disable the checkbox.
		$_POST['wp_mcp_ai_settings'] = array(
			// enable_federation is NOT here (unchecked).
		);

		$sanitized = $section->sanitize( $_POST['wp_mcp_ai_settings'] );
		$existing  = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$merged    = array_merge( $existing, $sanitized );
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $merged );

		$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$this->assertFalse(
			! empty( $settings['enable_federation'] ),
			'Second save: enable_federation should be false'
		);

		// Third save: enable again.
		$_POST['wp_mcp_ai_settings'] = array(
			'enable_federation' => '1',
		);

		$sanitized = $section->sanitize( $_POST['wp_mcp_ai_settings'] );
		$existing  = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$merged    = array_merge( $existing, $sanitized );
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $merged );

		$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$this->assertTrue(
			! empty( $settings['enable_federation'] ),
			'Third save: enable_federation should be true again'
		);

		// Clean up.
		unset( $_POST['subtab_advanced'] );
		unset( $_POST['wp_mcp_ai_settings'] );
	}

	/**
	 * Test that all three federation mesh checkboxes can be unchecked together.
	 *
	 * This is the actual bug scenario reported by the user.
	 */
	public function test_all_three_checkboxes_can_be_unchecked() {
		// Set up initial settings with all three checkboxes enabled.
		$initial_settings = array(
			'enable_mesh'                 => true,
			'enable_federation'           => true,
			'enable_federation_directory' => true,
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Simulate saving the federation_mesh subtab with ALL checkboxes unchecked.
		$_POST['subtab_advanced']    = 'federation_mesh';
		$_POST['wp_mcp_ai_settings'] = array(
			// All three checkboxes are NOT here (all unchecked).
		);

		// Sanitize using the Advanced section.
		$section   = new WP_MCP_AI_Section_Advanced();
		$sanitized = $section->sanitize( $_POST['wp_mcp_ai_settings'] );

		// All three checkboxes should be in the sanitized output.
		$this->assertArrayHasKey(
			'enable_mesh',
			$sanitized,
			'enable_mesh should be in sanitized settings even when unchecked'
		);
		$this->assertArrayHasKey(
			'enable_federation',
			$sanitized,
			'enable_federation should be in sanitized settings even when unchecked'
		);
		$this->assertArrayHasKey(
			'enable_federation_directory',
			$sanitized,
			'enable_federation_directory should be in sanitized settings even when unchecked'
		);

		// All three checkboxes should be false.
		$this->assertFalse(
			$sanitized['enable_mesh'],
			'enable_mesh should be false when unchecked'
		);
		$this->assertFalse(
			$sanitized['enable_federation'],
			'enable_federation should be false when unchecked'
		);
		$this->assertFalse(
			$sanitized['enable_federation_directory'],
			'enable_federation_directory should be false when unchecked'
		);

		// Merge with existing and update (simulating full save flow).
		$existing = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$merged   = array_merge( $existing, $sanitized );
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $merged );

		// Verify all three settings were persisted correctly.
		$saved_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$this->assertFalse(
			$saved_settings['enable_mesh'],
			'enable_mesh should remain false in database after save'
		);
		$this->assertFalse(
			$saved_settings['enable_federation'],
			'enable_federation should remain false in database after save'
		);
		$this->assertFalse(
			$saved_settings['enable_federation_directory'],
			'enable_federation_directory should remain false in database after save'
		);

		// Clean up.
		unset( $_POST['subtab_advanced'] );
		unset( $_POST['wp_mcp_ai_settings'] );
	}

	/**
	 * Test that enable_mesh checkbox can be unchecked independently.
	 */
	public function test_enable_mesh_can_be_unchecked_independently() {
		// Set up initial settings with all three enabled.
		$initial_settings = array(
			'enable_mesh'                 => true,
			'enable_federation'           => true,
			'enable_federation_directory' => true,
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Simulate unchecking only enable_mesh.
		$_POST['subtab_advanced']    = 'federation_mesh';
		$_POST['wp_mcp_ai_settings'] = array(
			// enable_mesh is NOT here (unchecked).
			'enable_federation'           => '1', // Still checked.
			'enable_federation_directory' => '1', // Still checked.
		);

		$section   = new WP_MCP_AI_Section_Advanced();
		$sanitized = $section->sanitize( $_POST['wp_mcp_ai_settings'] );

		$this->assertFalse(
			$sanitized['enable_mesh'],
			'enable_mesh should be false when unchecked'
		);
		$this->assertTrue(
			$sanitized['enable_federation'],
			'enable_federation should remain true'
		);
		$this->assertTrue(
			$sanitized['enable_federation_directory'],
			'enable_federation_directory should remain true'
		);

		// Clean up.
		unset( $_POST['subtab_advanced'] );
		unset( $_POST['wp_mcp_ai_settings'] );
	}

	/**
	 * Test that enable_federation_directory can be unchecked independently.
	 */
	public function test_enable_federation_directory_can_be_unchecked_independently() {
		// Set up initial settings with all three enabled.
		$initial_settings = array(
			'enable_mesh'                 => true,
			'enable_federation'           => true,
			'enable_federation_directory' => true,
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Simulate unchecking only enable_federation_directory.
		$_POST['subtab_advanced']    = 'federation_mesh';
		$_POST['wp_mcp_ai_settings'] = array(
			'enable_mesh'       => '1', // Still checked.
			'enable_federation' => '1', // Still checked.
			// enable_federation_directory is NOT here (unchecked).
		);

		$section   = new WP_MCP_AI_Section_Advanced();
		$sanitized = $section->sanitize( $_POST['wp_mcp_ai_settings'] );

		$this->assertTrue(
			$sanitized['enable_mesh'],
			'enable_mesh should remain true'
		);
		$this->assertTrue(
			$sanitized['enable_federation'],
			'enable_federation should remain true'
		);
		$this->assertFalse(
			$sanitized['enable_federation_directory'],
			'enable_federation_directory should be false when unchecked'
		);

		// Clean up.
		unset( $_POST['subtab_advanced'] );
		unset( $_POST['wp_mcp_ai_settings'] );
	}
}
