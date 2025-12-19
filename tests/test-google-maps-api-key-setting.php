<?php
/**
 * Tests for the Google Maps API key setting.
 *
 * @package WP_MCP_AI
 */

/**
 * Class WP_MCP_AI_Google_Maps_API_Key_Setting_Test
 *
 * Tests that the Google Maps API key setting is properly configured.
 */
class WP_MCP_AI_Google_Maps_API_Key_Setting_Test extends WP_UnitTestCase {

	/**
	 * Test that google_maps_api_key is in default settings.
	 */
	public function test_google_maps_api_key_in_default_settings() {
		$defaults = WP_MCP_AI_Admin_Settings::get_default_settings();

		$this->assertArrayHasKey( 'google_maps_api_key', $defaults, 'google_maps_api_key should be in default settings' );
		$this->assertSame( '', $defaults['google_maps_api_key'], 'google_maps_api_key should default to empty string' );
	}

	/**
	 * Test that the Google Maps client can retrieve the API key from settings.
	 */
	public function test_google_maps_client_can_retrieve_api_key() {
		// Set a test API key.
		$test_api_key = 'AIzaTestKey123';
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array_merge(
				WP_MCP_AI_Admin_Settings::get_default_settings(),
				array( 'google_maps_api_key' => $test_api_key )
			)
		);

		// Create a Google Maps client and verify it retrieves the key.
		$client = new WP_MCP_AI_Google_Maps_Client();
		$this->assertSame( $test_api_key, $client->get_api_key(), 'Google Maps client should retrieve the API key from settings' );

		// Clean up.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
	}

	/**
	 * Test that geocode tool returns error when API key is missing.
	 */
	public function test_geocode_tool_returns_error_when_api_key_missing() {
		// Ensure settings have empty Google Maps API key.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array_merge(
				WP_MCP_AI_Admin_Settings::get_default_settings(),
				array( 'google_maps_api_key' => '' )
			)
		);

		// Create the geocode tool.
		$tool = new WP_MCP_AI_Tool_Geocode_Address();

		// Execute with a test address but no API key configured.
		$result = $tool->execute(
			array( 'address' => '1600 Amphitheatre Parkway, Mountain View, CA' ),
			array(
				'user_id'             => 1,
				'token_authenticated' => false,
			)
		);

		// Verify error is returned.
		$this->assertInstanceOf( 'WP_Error', $result, 'Geocode tool should return WP_Error when API key is missing' );
		$this->assertSame( 'wp_mcp_ai_missing_google_maps_api_key', $result->get_error_code(), 'Error code should indicate missing API key' );
		$this->assertStringContainsString( 'No Google Maps API key', $result->get_error_message(), 'Error message should mention missing API key' );

		// Clean up.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
	}

	/**
	 * Test that the providers section includes Google Maps field.
	 */
	public function test_providers_section_includes_google_maps_field() {
		$providers_section = new WP_MCP_AI_Section_Providers();
		$fields            = $providers_section->get_fields();

		$this->assertArrayHasKey( 'google_maps_api_key', $fields, 'Providers section should include google_maps_api_key field' );
		$this->assertSame( 'password', $fields['google_maps_api_key']['type'], 'google_maps_api_key field should be password type' );
		$this->assertStringContainsString( 'Google Maps Platform', $fields['google_maps_api_key']['description'], 'Field description should mention Google Maps Platform' );
	}
}
