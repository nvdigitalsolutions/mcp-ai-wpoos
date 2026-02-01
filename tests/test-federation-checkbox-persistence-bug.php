<?php
/**
 * Test Federation Checkbox Persistence Bug
 *
 * Tests the specific bug where enable_mesh and enable_federation
 * cannot be unchecked when enable_federation_directory is enabled.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for federation checkbox persistence bug.
 */
class WP_MCP_AI_Federation_Checkbox_Persistence_Bug_Test extends WP_UnitTestCase {

	/**
	 * Test that enable_mesh and enable_federation can be unchecked
	 * even when enable_federation_directory remains checked.
	 */
	public function test_can_uncheck_mesh_and_federation_with_directory_enabled() {
		// Set up initial state: All three checkboxes enabled.
		$initial_settings = array(
			'enable_mesh'                 => true,
			'enable_federation'           => true,
			'enable_federation_directory' => true,
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Simulate form submission: Uncheck enable_mesh and enable_federation,
		// but keep enable_federation_directory checked.
		$_POST['subtab_advanced']    = 'federation_mesh';
		$_POST['active_tab']         = 'advanced';
		$_POST['wp_mcp_ai_settings'] = array(
			// enable_mesh is NOT in POST data (unchecked).
			// enable_federation is NOT in POST data (unchecked).
			'enable_federation_directory' => '1', // Still checked.
		);

		// Get the Advanced section instance.
		$section = new WP_MCP_AI_Section_Advanced();

		// Sanitize the settings.
		$sanitized = $section->sanitize( $_POST['wp_mcp_ai_settings'] );

		// Verify that enable_mesh and enable_federation are set to false.
		$this->assertArrayHasKey(
			'enable_mesh',
			$sanitized,
			'enable_mesh should be present in sanitized output'
		);
		$this->assertFalse(
			$sanitized['enable_mesh'],
			'enable_mesh should be false when unchecked'
		);

		$this->assertArrayHasKey(
			'enable_federation',
			$sanitized,
			'enable_federation should be present in sanitized output'
		);
		$this->assertFalse(
			$sanitized['enable_federation'],
			'enable_federation should be false when unchecked'
		);

		// Verify that enable_federation_directory remains true.
		$this->assertArrayHasKey(
			'enable_federation_directory',
			$sanitized,
			'enable_federation_directory should be present in sanitized output'
		);
		$this->assertTrue(
			$sanitized['enable_federation_directory'],
			'enable_federation_directory should still be true'
		);

		// Clean up.
		unset( $_POST['subtab_advanced'] );
		unset( $_POST['active_tab'] );
		unset( $_POST['wp_mcp_ai_settings'] );
	}

	/**
	 * Test the full save flow through the settings dashboard.
	 */
	public function test_full_save_flow_with_directory_enabled() {
		// Set up initial state: All three checkboxes enabled.
		$initial_settings = array(
			'enable_mesh'                 => true,
			'enable_federation'           => true,
			'enable_federation_directory' => true,
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Simulate form submission: Uncheck enable_mesh and enable_federation.
		$_POST['subtab_advanced']    = 'federation_mesh';
		$_POST['active_tab']         = 'advanced';
		$_POST['wp_mcp_ai_settings'] = array(
			'enable_federation_directory' => '1', // Still checked.
			// enable_mesh and enable_federation are NOT in POST (unchecked).
		);

		// Get the Advanced section and sanitize.
		$section   = new WP_MCP_AI_Section_Advanced();
		$sanitized = $section->sanitize( $_POST['wp_mcp_ai_settings'] );

		// Merge with existing settings (simulating what the dashboard does).
		$existing = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$merged   = array_merge( $existing, $sanitized );

		// Save to database.
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $merged );

		// Retrieve saved settings.
		$saved_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

		// Verify the saved values.
		$this->assertFalse(
			! empty( $saved_settings['enable_mesh'] ),
			'enable_mesh should be false in saved settings'
		);
		$this->assertFalse(
			! empty( $saved_settings['enable_federation'] ),
			'enable_federation should be false in saved settings'
		);
		$this->assertTrue(
			! empty( $saved_settings['enable_federation_directory'] ),
			'enable_federation_directory should still be true in saved settings'
		);

		// Clean up.
		unset( $_POST['subtab_advanced'] );
		unset( $_POST['active_tab'] );
		unset( $_POST['wp_mcp_ai_settings'] );
	}
}
