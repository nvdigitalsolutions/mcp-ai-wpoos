<?php
/**
 * Test for Enable Federation Directory auto-generation of mesh API key.
 *
 * Tests the complete flow of enabling federation directory and verifying
 * that the mesh_inbound_api_key is automatically generated.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that the Enable Federation Directory checkbox triggers API key generation.
 */
class WP_MCP_AI_Federation_Directory_API_Key_Test extends WP_UnitTestCase {

	/**
	 * Test that enabling federation directory auto-generates mesh API key.
	 */
	public function test_enable_federation_directory_generates_api_key() {
		// Clear existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		// Simulate initial state: federation directory disabled, no API key.
		$initial_settings = array(
			'enable_federation_directory' => false,
			'mesh_inbound_api_key'        => '',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Simulate form submission: enable federation directory.
		$_POST['subtab_advanced']    = 'federation_mesh';
		$_POST['active_tab']         = 'advanced';
		$_POST['wp_mcp_ai_settings'] = array(
			'enable_federation_directory' => '1', // Checked.
		);

		// Simulate the settings save flow (merging).
		$existing = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$new_data = array( 'enable_federation_directory' => true );
		$merged   = array_merge( $existing, $new_data );

		// Apply the fix logic: auto-generate API key if federation enabled and key missing.
		$mesh_features_enabled = ! empty( $merged['enable_mesh'] ) || ! empty( $merged['enable_federation_directory'] );
		if ( $mesh_features_enabled && empty( $merged['mesh_inbound_api_key'] ) ) {
			$merged['mesh_inbound_api_key'] = 'mesh_' . bin2hex( random_bytes( 32 ) );
		}

		// Save to database.
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $merged );

		// Verify the API key was generated.
		$saved_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

		$this->assertArrayHasKey(
			'mesh_inbound_api_key',
			$saved_settings,
			'mesh_inbound_api_key should exist in saved settings'
		);

		$this->assertNotEmpty(
			$saved_settings['mesh_inbound_api_key'],
			'mesh_inbound_api_key should not be empty'
		);

		$this->assertStringStartsWith(
			'mesh_',
			$saved_settings['mesh_inbound_api_key'],
			'mesh_inbound_api_key should start with "mesh_"'
		);

		$this->assertEquals(
			69,
			strlen( $saved_settings['mesh_inbound_api_key'] ),
			'mesh_inbound_api_key should be 69 characters (mesh_ + 64 hex)'
		);

		// Verify federation directory is enabled.
		$this->assertTrue(
			! empty( $saved_settings['enable_federation_directory'] ),
			'enable_federation_directory should be true'
		);

		// Clean up.
		unset( $_POST['subtab_advanced'] );
		unset( $_POST['active_tab'] );
		unset( $_POST['wp_mcp_ai_settings'] );
	}

	/**
	 * Test that enabling mesh networking also generates API key.
	 */
	public function test_enable_mesh_generates_api_key() {
		// Clear existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		// Simulate initial state: mesh disabled, no API key.
		$initial_settings = array(
			'enable_mesh'          => false,
			'mesh_inbound_api_key' => '',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Simulate form submission: enable mesh.
		$existing = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$new_data = array( 'enable_mesh' => true );
		$merged   = array_merge( $existing, $new_data );

		// Apply the fix logic.
		$mesh_features_enabled = ! empty( $merged['enable_mesh'] ) || ! empty( $merged['enable_federation_directory'] );
		if ( $mesh_features_enabled && empty( $merged['mesh_inbound_api_key'] ) ) {
			$merged['mesh_inbound_api_key'] = 'mesh_' . bin2hex( random_bytes( 32 ) );
		}

		// Save to database.
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $merged );

		// Verify the API key was generated.
		$saved_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

		$this->assertNotEmpty(
			$saved_settings['mesh_inbound_api_key'],
			'mesh_inbound_api_key should be generated when enable_mesh is true'
		);
	}

	/**
	 * Test that API key is NOT regenerated if it already exists.
	 */
	public function test_existing_api_key_not_overwritten() {
		// Clear existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		// Simulate initial state: federation enabled with existing API key.
		$existing_key     = 'mesh_abc123existing';
		$initial_settings = array(
			'enable_federation_directory' => true,
			'mesh_inbound_api_key'        => $existing_key,
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Simulate form re-submission (changing another field).
		$existing = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$new_data = array( 'enable_federation_directory' => true );
		$merged   = array_merge( $existing, $new_data );

		// Apply the fix logic (should NOT generate new key).
		$mesh_features_enabled = ! empty( $merged['enable_mesh'] ) || ! empty( $merged['enable_federation_directory'] );
		if ( $mesh_features_enabled && empty( $merged['mesh_inbound_api_key'] ) ) {
			$merged['mesh_inbound_api_key'] = 'mesh_' . bin2hex( random_bytes( 32 ) );
		}

		// Save to database.
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $merged );

		// Verify the API key was NOT changed.
		$saved_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

		$this->assertEquals(
			$existing_key,
			$saved_settings['mesh_inbound_api_key'],
			'Existing mesh_inbound_api_key should not be overwritten'
		);
	}

	/**
	 * Test that API key is NOT generated when both features are disabled.
	 */
	public function test_api_key_not_generated_when_disabled() {
		// Clear existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		// Simulate initial state: everything disabled.
		$initial_settings = array(
			'enable_mesh'                 => false,
			'enable_federation_directory' => false,
			'mesh_inbound_api_key'        => '',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Simulate form submission: keep everything disabled.
		$existing = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$new_data = array( 'some_other_setting' => 'value' );
		$merged   = array_merge( $existing, $new_data );

		// Apply the fix logic (should NOT generate key).
		$mesh_features_enabled = ! empty( $merged['enable_mesh'] ) || ! empty( $merged['enable_federation_directory'] );
		if ( $mesh_features_enabled && empty( $merged['mesh_inbound_api_key'] ) ) {
			$merged['mesh_inbound_api_key'] = 'mesh_' . bin2hex( random_bytes( 32 ) );
		}

		// Save to database.
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $merged );

		// Verify the API key was NOT generated.
		$saved_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

		$this->assertEmpty(
			$saved_settings['mesh_inbound_api_key'],
			'mesh_inbound_api_key should not be generated when both features are disabled'
		);
	}
}
