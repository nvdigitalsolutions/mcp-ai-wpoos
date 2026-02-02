<?php
/**
 * Test for mesh API key generation when enabling federation directory.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that mesh_inbound_api_key is auto-generated when enabling federation directory.
 */
class WP_MCP_AI_Mesh_API_Key_Generation_Test extends WP_UnitTestCase {

	/**
	 * Test that mesh_inbound_api_key is generated when enable_federation is enabled.
	 */
	public function test_mesh_api_key_generated_when_federation_directory_enabled() {
		// Clear existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		// Simulate enabling federation directory without mesh computing.
		$input = array(
			'enable_federation' => '1',
		);

		// Use the Admin Settings Base sanitizer (which has the key generation logic).
		$settings_base = new WP_MCP_AI_Admin_Settings_Base();
		$sanitized     = $settings_base->sanitize_settings( $input );

		// The mesh API key should be auto-generated.
		$this->assertArrayHasKey(
			'mesh_inbound_api_key',
			$sanitized,
			'mesh_inbound_api_key should be auto-generated when federation directory is enabled'
		);

		// The key should not be empty.
		$this->assertNotEmpty(
			$sanitized['mesh_inbound_api_key'],
			'Generated mesh_inbound_api_key should not be empty'
		);

		// The key should start with 'mesh_' prefix.
		$this->assertStringStartsWith(
			'mesh_',
			$sanitized['mesh_inbound_api_key'],
			'Generated mesh_inbound_api_key should start with "mesh_" prefix'
		);

		// The key should be long enough (mesh_ + 64 hex chars = 69 chars total).
		$this->assertGreaterThanOrEqual(
			69,
			strlen( $sanitized['mesh_inbound_api_key'] ),
			'Generated mesh_inbound_api_key should be at least 69 characters long'
		);
	}

	/**
	 * Test that mesh_inbound_api_key is generated when enable_mesh is enabled.
	 */
	public function test_mesh_api_key_generated_when_mesh_enabled() {
		// Clear existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		// Simulate enabling mesh computing.
		$input = array(
			'enable_mesh' => '1',
		);

		// Use the Admin Settings Base sanitizer.
		$settings_base = new WP_MCP_AI_Admin_Settings_Base();
		$sanitized     = $settings_base->sanitize_settings( $input );

		// The mesh API key should be auto-generated.
		$this->assertArrayHasKey(
			'mesh_inbound_api_key',
			$sanitized,
			'mesh_inbound_api_key should be auto-generated when mesh computing is enabled'
		);

		// The key should not be empty.
		$this->assertNotEmpty(
			$sanitized['mesh_inbound_api_key'],
			'Generated mesh_inbound_api_key should not be empty when mesh enabled'
		);
	}

	/**
	 * Test that existing mesh_inbound_api_key is preserved when re-saving.
	 */
	public function test_mesh_api_key_preserved_on_resave() {
		// Clear existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		// First save: enable federation directory.
		$input = array(
			'enable_federation' => '1',
		);

		$settings_base = new WP_MCP_AI_Admin_Settings_Base();
		$sanitized     = $settings_base->sanitize_settings( $input );

		// Save to database.
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $sanitized );

		// Get the generated key.
		$original_key = $sanitized['mesh_inbound_api_key'];

		// Second save: save other settings while federation directory is still enabled.
		$input = array(
			'enable_federation'  => '1',
			'federation_regions' => 'us-east',
		);

		// Clear the settings cache to force fresh read.
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$sanitized2 = $settings_base->sanitize_settings( $input );

		// The key should be the same as before (not regenerated).
		$this->assertEquals(
			$original_key,
			$sanitized2['mesh_inbound_api_key'],
			'mesh_inbound_api_key should be preserved on subsequent saves'
		);
	}

	/**
	 * Test that mesh_inbound_api_key is NOT generated when federation directory is disabled.
	 */
	public function test_mesh_api_key_not_generated_when_federation_disabled() {
		// Clear existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		// Simulate saving with federation directory disabled.
		$input = array(
			'enable_federation'  => false,
			'federation_regions' => 'global',
		);

		$settings_base = new WP_MCP_AI_Admin_Settings_Base();
		$sanitized     = $settings_base->sanitize_settings( $input );

		// The mesh API key should NOT be generated.
		$this->assertArrayNotHasKey(
			'mesh_inbound_api_key',
			$sanitized,
			'mesh_inbound_api_key should NOT be generated when federation directory is disabled'
		);
	}

	/**
	 * Test that mesh_inbound_api_key is generated when both enable_mesh and enable_federation are enabled.
	 */
	public function test_mesh_api_key_generated_when_both_enabled() {
		// Clear existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		// Simulate enabling both mesh and federation directory.
		$input = array(
			'enable_mesh'       => '1',
			'enable_federation' => '1',
		);

		$settings_base = new WP_MCP_AI_Admin_Settings_Base();
		$sanitized     = $settings_base->sanitize_settings( $input );

		// The mesh API key should be auto-generated.
		$this->assertArrayHasKey(
			'mesh_inbound_api_key',
			$sanitized,
			'mesh_inbound_api_key should be auto-generated when both are enabled'
		);

		// The key should not be empty.
		$this->assertNotEmpty(
			$sanitized['mesh_inbound_api_key'],
			'Generated mesh_inbound_api_key should not be empty'
		);
	}
}
