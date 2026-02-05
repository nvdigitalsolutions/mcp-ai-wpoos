<?php
/**
 * Test to reproduce the checkbox persistence bug
 *
 * @package WP_MCP_AI
 */

/**
 * Test class to reproduce the checkbox bug reported by user.
 */
class WP_MCP_AI_Checkbox_Bug_Reproduction_Test extends WP_UnitTestCase {

	/**
	 * Test: Try to uncheck enable_mesh and enable_federation
	 * Expected: Both should become false
	 * Bug: They stay true (checked)
	 */
	public function test_uncheck_first_two_checkboxes() {
		// Initial state: All three checkboxes are enabled.
		$initial_settings = array(
			'enable_mesh'                 => true,
			'enable_federation'           => true,
			'enable_federation_directory' => true,
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Simulate unchecking the first two checkboxes.
		// When a checkbox is unchecked, it's NOT present in POST data.
		$_POST['subtab_advanced']    = 'federation_mesh';
		$_POST['active_tab']         = 'advanced';
		$_POST['wp_mcp_ai_settings'] = array(
			'enable_federation_directory' => '1', // Still checked.
			// enable_mesh and enable_federation are NOT in POST (unchecked).
		);

		// Get the Advanced section instance.
		$section = new WP_MCP_AI_Section_Advanced();

		// Sanitize the settings - this is what the form submission does.
		$sanitized = $section->sanitize( $_POST['wp_mcp_ai_settings'] );

		// Verify that enable_mesh should be set to false.
		$this->assertArrayHasKey(
			'enable_mesh',
			$sanitized,
			'enable_mesh should be present in sanitized output'
		);
		$this->assertFalse(
			$sanitized['enable_mesh'],
			'enable_mesh should be false when unchecked'
		);

		// Verify that enable_federation should be set to false.
		$this->assertArrayHasKey(
			'enable_federation',
			$sanitized,
			'enable_federation should be present in sanitized output'
		);
		$this->assertFalse(
			$sanitized['enable_federation'],
			'enable_federation should be false when unchecked'
		);

		// Clean up.
		unset( $_POST['subtab_advanced'] );
		unset( $_POST['active_tab'] );
		unset( $_POST['wp_mcp_ai_settings'] );
	}

	/**
	 * Test: Try to check enable_federation_directory
	 * Expected: It should persist as true
	 * Bug: It doesn't persist
	 */
	public function test_check_third_checkbox() {
		// Initial state: All three checkboxes are disabled.
		$initial_settings = array(
			'enable_mesh'                 => false,
			'enable_federation'           => false,
			'enable_federation_directory' => false,
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Simulate checking only the third checkbox.
		$_POST['subtab_advanced']    = 'federation_mesh';
		$_POST['active_tab']         = 'advanced';
		$_POST['wp_mcp_ai_settings'] = array(
			'enable_federation_directory' => '1', // Checked.
			// enable_mesh and enable_federation are NOT in POST (unchecked).
		);

		// Get the Advanced section instance.
		$section = new WP_MCP_AI_Section_Advanced();

		// Sanitize the settings.
		$sanitized = $section->sanitize( $_POST['wp_mcp_ai_settings'] );

		// Verify that enable_federation_directory should be true.
		$this->assertArrayHasKey(
			'enable_federation_directory',
			$sanitized,
			'enable_federation_directory should be present in sanitized output'
		);
		$this->assertTrue(
			$sanitized['enable_federation_directory'],
			'enable_federation_directory should be true when checked'
		);

		// Verify that the other two should be false.
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

		// Clean up.
		unset( $_POST['subtab_advanced'] );
		unset( $_POST['active_tab'] );
		unset( $_POST['wp_mcp_ai_settings'] );
	}
}
