<?php
/**
 * Tests for Authentication section subtab settings persistence.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test that Authentication subtab settings persist correctly.
 */
class WP_MCP_AI_Authentication_Subtab_Settings_Test extends WP_UnitTestCase {

	/**
	 * Test that REST API subtab checkboxes persist when saved.
	 */
	public function test_rest_api_subtab_checkboxes_persist() {
		// Create the authentication section.
		$section = new WP_MCP_AI_Section_Authentication();

		// Simulate being on the rest_api subtab by setting $_POST['subtab'].
		$_POST['subtab'] = 'rest_api';

		// Simulate form submission with REST API checkboxes checked.
		$submitted = array(
			'rest_enable_assistant_create' => '1',
			'rest_enable_assistant_delete' => '1',
			'sse_enable_post_method'       => '1',
		);

		$sanitized = $section->sanitize( $submitted );

		// All three checkboxes should be in the sanitized output.
		$this->assertArrayHasKey( 'rest_enable_assistant_create', $sanitized, 'rest_enable_assistant_create should be in sanitized settings' );
		$this->assertArrayHasKey( 'rest_enable_assistant_delete', $sanitized, 'rest_enable_assistant_delete should be in sanitized settings' );
		$this->assertArrayHasKey( 'sse_enable_post_method', $sanitized, 'sse_enable_post_method should be in sanitized settings' );

		// All should be true.
		$this->assertTrue( $sanitized['rest_enable_assistant_create'], 'rest_enable_assistant_create should be true' );
		$this->assertTrue( $sanitized['rest_enable_assistant_delete'], 'rest_enable_assistant_delete should be true' );
		$this->assertTrue( $sanitized['sse_enable_post_method'], 'sse_enable_post_method should be true' );

		// Clean up.
		unset( $_POST['subtab'] );
	}

	/**
	 * Test that Auth0 GitHub Bridge checkbox persists when saved.
	 */
	public function test_auth0_github_bridge_checkbox_persists() {
		// Create the authentication section.
		$section = new WP_MCP_AI_Section_Authentication();

		// Simulate being on the auth0_github subtab.
		$_POST['subtab'] = 'auth0_github';

		// Simulate form submission with Auth0 GitHub Bridge enabled.
		$submitted = array(
			'enable_auth0_github_bridge'     => '1',
			'auth0_management_client_id'     => 'test-client-id',
			'auth0_management_client_secret' => 'test-secret',
		);

		$sanitized = $section->sanitize( $submitted );

		// Checkbox and text fields should be in the sanitized output.
		$this->assertArrayHasKey( 'enable_auth0_github_bridge', $sanitized, 'enable_auth0_github_bridge should be in sanitized settings' );
		$this->assertArrayHasKey( 'auth0_management_client_id', $sanitized, 'auth0_management_client_id should be in sanitized settings' );
		$this->assertArrayHasKey( 'auth0_management_client_secret', $sanitized, 'auth0_management_client_secret should be in sanitized settings' );

		// Checkbox should be true.
		$this->assertTrue( $sanitized['enable_auth0_github_bridge'], 'enable_auth0_github_bridge should be true' );

		// Text fields should match submitted values.
		$this->assertSame( 'test-client-id', $sanitized['auth0_management_client_id'], 'auth0_management_client_id should match' );
		$this->assertSame( 'test-secret', $sanitized['auth0_management_client_secret'], 'auth0_management_client_secret should match' );

		// Clean up.
		unset( $_POST['subtab'] );
	}

	/**
	 * Test that saving one subtab doesn't clear settings from another subtab.
	 */
	public function test_saving_one_subtab_preserves_other_subtabs() {
		// First, set up initial settings with REST API checkboxes on.
		$initial_settings = array(
			'rest_enable_assistant_create' => true,
			'rest_enable_assistant_delete' => true,
			'auth0_domain'                 => 'test.auth0.com',
			'auth0_audience'               => 'https://api.example.com',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Now save the auth0 subtab with new values.
		$section         = new WP_MCP_AI_Section_Authentication();
		$_POST['subtab'] = 'auth0';

		$submitted = array(
			'auth0_domain'         => 'new-domain.auth0.com',
			'auth0_audience'       => 'https://new-api.example.com',
			'auth0_required_scope' => 'read:mcp',
		);

		$sanitized = $section->sanitize( $submitted );

		// Get existing settings.
		$existing_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

		// Merge as the dashboard does.
		$merged = array_merge( $existing_settings, $sanitized );

		// REST API checkboxes should still be true (not cleared).
		$this->assertTrue(
			$merged['rest_enable_assistant_create'],
			'rest_enable_assistant_create should remain true when saving auth0 subtab'
		);
		$this->assertTrue(
			$merged['rest_enable_assistant_delete'],
			'rest_enable_assistant_delete should remain true when saving auth0 subtab'
		);

		// Auth0 settings should be updated.
		$this->assertSame( 'new-domain.auth0.com', $merged['auth0_domain'], 'auth0_domain should be updated' );
		$this->assertSame( 'https://new-api.example.com', $merged['auth0_audience'], 'auth0_audience should be updated' );

		// Clean up.
		unset( $_POST['subtab'] );
	}

	/**
	 * Test that unchecked checkboxes in REST API subtab save as false.
	 */
	public function test_unchecked_rest_api_checkboxes_save_as_false() {
		// Create the authentication section.
		$section = new WP_MCP_AI_Section_Authentication();

		// Simulate being on the rest_api subtab.
		$_POST['subtab'] = 'rest_api';

		// Simulate form submission with checkboxes unchecked (not in array).
		$submitted = array(
			// All checkboxes unchecked.
		);

		$sanitized = $section->sanitize( $submitted );

		// All checkboxes should be explicitly set to false.
		$this->assertArrayHasKey( 'rest_enable_assistant_create', $sanitized );
		$this->assertArrayHasKey( 'rest_enable_assistant_delete', $sanitized );
		$this->assertArrayHasKey( 'sse_enable_post_method', $sanitized );

		$this->assertFalse( $sanitized['rest_enable_assistant_create'], 'rest_enable_assistant_create should be false when unchecked' );
		$this->assertFalse( $sanitized['rest_enable_assistant_delete'], 'rest_enable_assistant_delete should be false when unchecked' );
		$this->assertFalse( $sanitized['sse_enable_post_method'], 'sse_enable_post_method should be false when unchecked' );

		// Clean up.
		unset( $_POST['subtab'] );
	}

	/**
	 * Test that get_active_subtab() prefers POST over GET.
	 */
	public function test_get_active_subtab_prefers_post() {
		// Create the authentication section.
		$section = new WP_MCP_AI_Section_Authentication();

		// Set both GET and POST with different values.
		$_GET['subtab']  = 'auth0';
		$_POST['subtab'] = 'rest_api';

		// Use reflection to access the private method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_active_subtab' );
		$method->setAccessible( true );

		$active_subtab = $method->invoke( $section );

		// Should prefer POST over GET.
		$this->assertSame( 'rest_api', $active_subtab, 'get_active_subtab should prefer POST over GET' );

		// Clean up.
		unset( $_GET['subtab'], $_POST['subtab'] );
	}

	/**
	 * Test that get_active_subtab() falls back to GET when POST is not set.
	 */
	public function test_get_active_subtab_falls_back_to_get() {
		// Create the authentication section.
		$section = new WP_MCP_AI_Section_Authentication();

		// Set only GET.
		$_GET['subtab'] = 'jwt';

		// Use reflection to access the private method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_active_subtab' );
		$method->setAccessible( true );

		$active_subtab = $method->invoke( $section );

		// Should use GET value.
		$this->assertSame( 'jwt', $active_subtab, 'get_active_subtab should use GET when POST is not set' );

		// Clean up.
		unset( $_GET['subtab'] );
	}

	/**
	 * Test that get_active_subtab() defaults to auth0 when neither is set.
	 */
	public function test_get_active_subtab_defaults_to_auth0() {
		// Create the authentication section.
		$section = new WP_MCP_AI_Section_Authentication();

		// Ensure neither GET nor POST are set.
		unset( $_GET['subtab'], $_POST['subtab'] );

		// Use reflection to access the private method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_active_subtab' );
		$method->setAccessible( true );

		$active_subtab = $method->invoke( $section );

		// Should default to auth0.
		$this->assertSame( 'auth0', $active_subtab, 'get_active_subtab should default to auth0' );
	}

	/**
	 * Test that invalid subtab values default to auth0.
	 */
	public function test_get_active_subtab_rejects_invalid_values() {
		// Create the authentication section.
		$section = new WP_MCP_AI_Section_Authentication();

		// Set an invalid subtab value.
		$_POST['subtab'] = 'invalid_subtab_name';

		// Use reflection to access the private method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_active_subtab' );
		$method->setAccessible( true );

		$active_subtab = $method->invoke( $section );

		// Should default to auth0 when invalid.
		$this->assertSame( 'auth0', $active_subtab, 'get_active_subtab should default to auth0 for invalid values' );

		// Clean up.
		unset( $_POST['subtab'] );
	}
}
