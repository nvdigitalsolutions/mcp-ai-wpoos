<?php
/**
 * Test mesh API key whitespace handling.
 *
 * Verifies that mesh_inbound_api_key is properly trimmed during sanitization
 * to prevent authentication failures caused by leading/trailing whitespace.
 *
 * @package WP_MCP_AI
 */

/**
 * Test mesh API key whitespace handling.
 */
class Test_Mesh_API_Key_Whitespace extends WP_UnitTestCase {

	/**
	 * Test that mesh_inbound_api_key is trimmed when saved.
	 */
	public function test_mesh_api_key_trimmed_on_save() {
		$settings_class = new WP_MCP_AI_Admin_Settings();

		// Test key with leading/trailing whitespace.
		$test_key = '  mesh_test123456789012345678901234567890  ';

		$input = array(
			'enable_mesh'          => true,
			'mesh_inbound_api_key' => $test_key,
		);

		// Sanitize settings.
		$sanitized = $settings_class->sanitize_settings( $input );

		// Verify the key is trimmed.
		$this->assertEquals(
			'mesh_test123456789012345678901234567890',
			$sanitized['mesh_inbound_api_key'],
			'mesh_inbound_api_key should be trimmed of leading/trailing whitespace'
		);
	}

	/**
	 * Test that mesh_inbound_api_key with tabs and newlines is trimmed.
	 */
	public function test_mesh_api_key_with_tabs_and_newlines() {
		$settings_class = new WP_MCP_AI_Admin_Settings();

		// Test key with various whitespace characters.
		$test_key = "\t\nmesh_test123456789012345678901234567890\r\n\t";

		$input = array(
			'enable_mesh'          => true,
			'mesh_inbound_api_key' => $test_key,
		);

		// Sanitize settings.
		$sanitized = $settings_class->sanitize_settings( $input );

		// Verify the key is trimmed.
		$this->assertEquals(
			'mesh_test123456789012345678901234567890',
			$sanitized['mesh_inbound_api_key'],
			'mesh_inbound_api_key should be trimmed of tabs, newlines, and carriage returns'
		);
	}

	/**
	 * Test that authentication works with properly trimmed keys.
	 */
	public function test_authentication_with_trimmed_keys() {
		// Set up settings with a test key.
		$test_key = 'mesh_test123456789012345678901234567890';
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_mesh'          => true,
				'mesh_inbound_api_key' => $test_key,
			)
		);

		// Create authenticator instance.
		$authenticator = new WP_MCP_AI_REST_Authenticator();

		// Test authentication with the correctly trimmed and stored key.
		$result = $authenticator->validate_mesh_key( $test_key );

		$this->assertTrue(
			$result,
			'Authentication should succeed with properly trimmed key'
		);

		// Clean up.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
	}

	/**
	 * Test that authentication fails with mismatched whitespace.
	 *
	 * This test demonstrates the bug that was fixed: if the stored key has whitespace
	 * (because it wasn't trimmed during sanitization), authentication fails even with
	 * the correct key value.
	 */
	public function test_authentication_fails_with_whitespace_mismatch() {
		// Intentionally store a key with whitespace to demonstrate the issue.
		// This simulates the old behavior before the trim() fix was applied.
		$stored_key = '  mesh_test123456789012345678901234567890  ';
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_mesh'          => true,
				'mesh_inbound_api_key' => $stored_key, // Intentionally not trimmed to demonstrate the issue.
			)
		);

		// Create authenticator instance.
		$authenticator = new WP_MCP_AI_REST_Authenticator();

		// Incoming key (from peer test) is trimmed.
		$incoming_key = 'mesh_test123456789012345678901234567890';

		// Validate the key.
		$result = $authenticator->validate_mesh_key( $incoming_key );

		// This should fail because of the whitespace mismatch.
		$this->assertInstanceOf(
			'WP_Error',
			$result,
			'Authentication should fail when stored key has whitespace but incoming key does not'
		);

		$this->assertEquals(
			'wp_mcp_ai_invalid_mesh_key',
			$result->get_error_code(),
			'Error code should indicate invalid mesh key'
		);

		// Clean up.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
	}

	/**
	 * Test consistency with other API key sanitization.
	 */
	public function test_consistency_with_other_api_keys() {
		$settings_class = new WP_MCP_AI_Admin_Settings();

		// Test multiple API keys with whitespace.
		$input = array(
			'openai_api_key'       => '  sk-test123  ',
			'gemini_api_key'       => "\tgemini-test123\n",
			'mesh_inbound_api_key' => '  mesh_test123  ',
		);

		// Sanitize settings.
		$sanitized = $settings_class->sanitize_settings( $input );

		// All API keys should be trimmed consistently.
		$this->assertEquals( 'sk-test123', $sanitized['openai_api_key'] );
		$this->assertEquals( 'gemini-test123', $sanitized['gemini_api_key'] );
		$this->assertEquals( 'mesh_test123', $sanitized['mesh_inbound_api_key'] );
	}
}
