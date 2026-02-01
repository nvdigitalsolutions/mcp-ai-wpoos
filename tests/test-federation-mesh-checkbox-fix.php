<?php
/**
 * Test Federation Mesh Checkbox Fix
 *
 * Tests that the fix for unchecked checkboxes works correctly.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for federation mesh checkbox fix.
 */
class WP_MCP_AI_Federation_Mesh_Checkbox_Fix_Test extends WP_UnitTestCase {

	/**
	 * Section instance for testing.
	 *
	 * @var WP_MCP_AI_Section_Advanced
	 */
	private $section;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->section = new WP_MCP_AI_Section_Advanced();
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		unset( $_POST['subtab_advanced'] );
		unset( $_POST['active_tab'] );
		unset( $_POST['wp_mcp_ai_settings'] );
		parent::tearDown();
	}

	/**
	 * Test that unchecked checkboxes are properly set to false when submitted with value="0".
	 */
	public function test_unchecked_checkbox_with_value_zero() {
		// Simulate form submission with value="0" for unchecked checkboxes.
		// This mimics what the JavaScript fix does by adding hidden fields.
		$_POST['subtab_advanced']    = 'federation_mesh';
		$_POST['active_tab']         = 'advanced';
		$_POST['wp_mcp_ai_settings'] = array(
			'enable_mesh'                 => '0', // Unchecked (from hidden field).
			'enable_federation'           => '0', // Unchecked (from hidden field).
			'enable_federation_directory' => '1', // Checked.
			// Other fields...
			'federation_regions'          => 'us-east',
		);

		// Sanitize the settings.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Test file simulating POST data for testing sanitization logic.
		$sanitized = $this->section->sanitize( $_POST['wp_mcp_ai_settings'] );

		// Verify enable_mesh is set to false.
		$this->assertArrayHasKey( 'enable_mesh', $sanitized, 'enable_mesh should be in sanitized output' );
		$this->assertFalse( $sanitized['enable_mesh'], 'enable_mesh should be false when value=0' );

		// Verify enable_federation is set to false.
		$this->assertArrayHasKey( 'enable_federation', $sanitized, 'enable_federation should be in sanitized output' );
		$this->assertFalse( $sanitized['enable_federation'], 'enable_federation should be false when value=0' );

		// Verify enable_federation_directory is set to true.
		$this->assertArrayHasKey( 'enable_federation_directory', $sanitized, 'enable_federation_directory should be in sanitized output' );
		$this->assertTrue( $sanitized['enable_federation_directory'], 'enable_federation_directory should be true when value=1' );
	}

	/**
	 * Test that all checkboxes can be unchecked (all have value="0").
	 */
	public function test_all_checkboxes_unchecked() {
		$_POST['subtab_advanced']    = 'federation_mesh';
		$_POST['active_tab']         = 'advanced';
		$_POST['wp_mcp_ai_settings'] = array(
			'enable_mesh'                 => '0',
			'enable_federation'           => '0',
			'enable_federation_directory' => '0',
		);

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Test file simulating POST data for testing sanitization logic.
		$sanitized = $this->section->sanitize( $_POST['wp_mcp_ai_settings'] );

		// All should be false.
		$this->assertFalse( $sanitized['enable_mesh'], 'enable_mesh should be false' );
		$this->assertFalse( $sanitized['enable_federation'], 'enable_federation should be false' );
		$this->assertFalse( $sanitized['enable_federation_directory'], 'enable_federation_directory should be false' );
	}

	/**
	 * Test that all checkboxes can be checked (all have value="1").
	 */
	public function test_all_checkboxes_checked() {
		$_POST['subtab_advanced']    = 'federation_mesh';
		$_POST['active_tab']         = 'advanced';
		$_POST['wp_mcp_ai_settings'] = array(
			'enable_mesh'                 => '1',
			'enable_federation'           => '1',
			'enable_federation_directory' => '1',
		);

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Test file simulating POST data for testing sanitization logic.
		$sanitized = $this->section->sanitize( $_POST['wp_mcp_ai_settings'] );

		// All should be true.
		$this->assertTrue( $sanitized['enable_mesh'], 'enable_mesh should be true' );
		$this->assertTrue( $sanitized['enable_federation'], 'enable_federation should be true' );
		$this->assertTrue( $sanitized['enable_federation_directory'], 'enable_federation_directory should be true' );
	}

	/**
	 * Test the exact scenario from the bug report:
	 * - enable_mesh: checked → unchecked
	 * - enable_federation: checked → unchecked
	 * - enable_federation_directory: unchecked → checked
	 */
	public function test_bug_report_scenario() {
		// Initial state: first 2 checked, 3rd unchecked.
		$initial_settings = array(
			'enable_mesh'                 => true,
			'enable_federation'           => true,
			'enable_federation_directory' => false,
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// User changes: first 2 unchecked, 3rd checked.
		$_POST['subtab_advanced']    = 'federation_mesh';
		$_POST['active_tab']         = 'advanced';
		$_POST['wp_mcp_ai_settings'] = array(
			'enable_mesh'                 => '0', // Unchecked (from JS hidden field fix).
			'enable_federation'           => '0', // Unchecked (from JS hidden field fix).
			'enable_federation_directory' => '1', // Checked.
		);

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Test file simulating POST data for testing sanitization logic.
		$sanitized = $this->section->sanitize( $_POST['wp_mcp_ai_settings'] );

		// Verify the new state matches user's changes.
		$this->assertFalse( $sanitized['enable_mesh'], 'enable_mesh should be unchecked' );
		$this->assertFalse( $sanitized['enable_federation'], 'enable_federation should be unchecked' );
		$this->assertTrue( $sanitized['enable_federation_directory'], 'enable_federation_directory should be checked' );

		// Simulate saving to database.
		$existing_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$updated_settings  = array_merge( $existing_settings, $sanitized );
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $updated_settings );

		// Verify persistence.
		$saved_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$this->assertFalse( $saved_settings['enable_mesh'], 'enable_mesh should persist as false' );
		$this->assertFalse( $saved_settings['enable_federation'], 'enable_federation should persist as false' );
		$this->assertTrue( $saved_settings['enable_federation_directory'], 'enable_federation_directory should persist as true' );

		// Clean up database option.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
	}
}
