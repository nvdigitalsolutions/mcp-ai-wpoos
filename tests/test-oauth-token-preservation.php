<?php
/**
 * Tests for OAuth token preservation in settings.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that OAuth tokens are preserved when saving settings.
 *
 * This test verifies the fix for the issue where github_access_token and
 * github_username were being cleared when saving settings on the external_tools subtab.
 * The root cause was that OAuth tokens saved by handlers were not defined as fields,
 * so they were lost during the field sanitization process.
 */
class WP_MCP_AI_OAuth_Token_Preservation_Test extends WP_UnitTestCase {

	/**
	 * Test that GitHub OAuth tokens are preserved when saving external_tools subtab.
	 */
	public function test_github_oauth_tokens_preserved_when_saving_external_tools() {
		// Set up initial settings with GitHub OAuth tokens from OAuth callback.
		$initial_settings = array(
			'github_client_id'     => 'test-client-id',
			'github_client_secret' => 'test-client-secret',
			'github_access_token'  => 'gho_test_access_token_12345',
			'github_username'      => 'testuser',
			'openai_api_key'       => 'test-openai-key',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Simulate saving the external_tools subtab with updated credentials.
		// The critical issue: github_access_token and github_username are NOT in the form.
		// because they're hidden fields populated by OAuth, not user input.
		$dashboard       = new WP_MCP_AI_Settings_Dashboard();
		$_POST['subtab'] = 'external_tools'; // Simulate subtab being submitted.

		$posted_settings = array(
			'github_client_id'     => 'updated-client-id',
			'github_client_secret' => 'updated-client-secret',
			'brave_search_api_key' => 'test-brave-key',
			// Note: github_access_token and github_username are NOT in POST data.
			// because they're hidden fields, not editable by user.
		);

		// Sanitize only the tools tab settings.
		$sanitized = $dashboard->sanitize_settings( $posted_settings, 'tools' );

		// Get the existing settings from the database.
		$existing_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

		// Merge as the dashboard does.
		$merged = array_merge( $existing_settings, $sanitized );

		// CRITICAL ASSERTIONS: OAuth tokens should be preserved.
		$this->assertArrayHasKey( 'github_access_token', $merged, 'github_access_token should exist after save' );
		$this->assertSame(
			'gho_test_access_token_12345',
			$merged['github_access_token'],
			'github_access_token should be preserved when saving external_tools'
		);

		$this->assertArrayHasKey( 'github_username', $merged, 'github_username should exist after save' );
		$this->assertSame(
			'testuser',
			$merged['github_username'],
			'github_username should be preserved when saving external_tools'
		);

		// User-editable fields should be updated.
		$this->assertSame( 'updated-client-id', $merged['github_client_id'] );
		$this->assertSame( 'updated-client-secret', $merged['github_client_secret'] );
		$this->assertSame( 'test-brave-key', $merged['brave_search_api_key'] );

		unset( $_POST['subtab'] );
	}

	/**
	 * Test that Gmail OAuth tokens are preserved when saving external_tools subtab.
	 */
	public function test_gmail_oauth_tokens_preserved_when_saving_external_tools() {
		// Set up initial settings with Gmail OAuth tokens.
		$initial_settings = array(
			'gmail_client_id'     => 'test-gmail-client-id',
			'gmail_client_secret' => 'test-gmail-secret',
			'gmail_refresh_token' => 'test-refresh-token-12345',
			'gmail_user_email'    => 'test@example.com',
			'openai_api_key'      => 'test-openai-key',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Simulate saving the external_tools subtab.
		$dashboard       = new WP_MCP_AI_Settings_Dashboard();
		$_POST['subtab'] = 'external_tools';

		$posted_settings = array(
			'gmail_client_id'     => 'updated-gmail-client-id',
			'gmail_client_secret' => 'updated-gmail-secret',
			// gmail_refresh_token and gmail_user_email are NOT in POST.
		);

		$sanitized         = $dashboard->sanitize_settings( $posted_settings, 'tools' );
		$existing_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$merged            = array_merge( $existing_settings, $sanitized );

		// OAuth tokens should be preserved.
		$this->assertArrayHasKey( 'gmail_refresh_token', $merged );
		$this->assertSame( 'test-refresh-token-12345', $merged['gmail_refresh_token'] );

		$this->assertArrayHasKey( 'gmail_user_email', $merged );
		$this->assertSame( 'test@example.com', $merged['gmail_user_email'] );

		unset( $_POST['subtab'] );
	}

	/**
	 * Test that OAuth tokens are in default settings.
	 */
	public function test_oauth_tokens_in_default_settings() {
		$defaults = WP_MCP_AI_Admin_Settings_Base::get_default_settings();

		// GitHub OAuth tokens.
		$this->assertArrayHasKey( 'github_client_id', $defaults );
		$this->assertArrayHasKey( 'github_client_secret', $defaults );
		$this->assertArrayHasKey( 'github_access_token', $defaults );
		$this->assertArrayHasKey( 'github_username', $defaults );

		// Gmail OAuth tokens.
		$this->assertArrayHasKey( 'gmail_client_id', $defaults );
		$this->assertArrayHasKey( 'gmail_client_secret', $defaults );
		$this->assertArrayHasKey( 'gmail_refresh_token', $defaults );
		$this->assertArrayHasKey( 'gmail_user_email', $defaults );

		// All should default to empty string.
		$this->assertSame( '', $defaults['github_access_token'] );
		$this->assertSame( '', $defaults['github_username'] );
		$this->assertSame( '', $defaults['gmail_refresh_token'] );
		$this->assertSame( '', $defaults['gmail_user_email'] );
	}

	/**
	 * Test that OAuth tokens are defined in Tools section fields.
	 */
	public function test_oauth_tokens_defined_in_tools_section() {
		$section = new WP_MCP_AI_Section_Tools();
		$fields  = $section->get_fields();

		// GitHub OAuth tokens should be defined as hidden fields.
		$this->assertArrayHasKey( 'github_access_token', $fields );
		$this->assertArrayHasKey( 'github_username', $fields );
		$this->assertSame( 'hidden', $fields['github_access_token']['type'] );
		$this->assertSame( 'hidden', $fields['github_username']['type'] );

		// Gmail OAuth tokens should be defined as hidden fields.
		$this->assertArrayHasKey( 'gmail_refresh_token', $fields );
		$this->assertArrayHasKey( 'gmail_user_email', $fields );
		$this->assertSame( 'hidden', $fields['gmail_refresh_token']['type'] );
		$this->assertSame( 'hidden', $fields['gmail_user_email']['type'] );
	}

	/**
	 * Test that hidden fields are skipped during sanitization.
	 */
	public function test_hidden_fields_skipped_during_sanitization() {
		$section         = new WP_MCP_AI_Section_Tools();
		$_POST['subtab'] = 'external_tools';

		// Simulate form submission without hidden fields.
		$posted_settings = array(
			'github_client_id' => 'test-client-id',
			// github_access_token is NOT submitted (it's hidden).
		);

		$sanitized = $section->sanitize( $posted_settings );

		// The sanitized output should NOT include github_access_token because it's hidden.
		// It will be preserved from existing settings via the merge in handle_save_settings.
		$this->assertArrayNotHasKey( 'github_access_token', $sanitized );

		// But github_client_id should be included (it's a text field).
		$this->assertArrayHasKey( 'github_client_id', $sanitized );
		$this->assertSame( 'test-client-id', $sanitized['github_client_id'] );

		unset( $_POST['subtab'] );
	}

	/**
	 * Test full OAuth flow simulation - connecting GitHub then saving settings.
	 */
	public function test_github_oauth_flow_then_save_settings() {
		// Step 1: User enters OAuth credentials and saves.
		$initial_settings = array(
			'github_client_id'     => 'oauth-app-client-id',
			'github_client_secret' => 'oauth-app-secret',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Step 2: User clicks "Connect GitHub Account" and completes OAuth flow.
		// OAuth handler saves access token and username.
		$settings_after_oauth                        = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$settings_after_oauth['github_access_token'] = 'gho_real_oauth_token';
		$settings_after_oauth['github_username']     = 'octocat';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings_after_oauth );

		// Verify OAuth tokens are saved.
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$this->assertSame( 'gho_real_oauth_token', $settings['github_access_token'] );
		$this->assertSame( 'octocat', $settings['github_username'] );

		// Step 3: User updates another field on the external_tools subtab (e.g., adds Brave API key).
		$dashboard       = new WP_MCP_AI_Settings_Dashboard();
		$_POST['subtab'] = 'external_tools';

		$posted_settings = array(
			'github_client_id'     => 'oauth-app-client-id', // Unchanged.
			'github_client_secret' => 'oauth-app-secret', // Unchanged.
			'brave_search_api_key' => 'new-brave-key', // New field.
			// github_access_token and github_username are NOT in POST.
		);

		$sanitized = $dashboard->sanitize_settings( $posted_settings, 'tools' );
		$existing  = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$merged    = array_merge( $existing, $sanitized );
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $merged );

		// Step 4: Verify OAuth tokens are STILL preserved after the save.
		$final_settings = WP_MCP_AI_Admin_Settings::get_settings();
		$this->assertSame( 'gho_real_oauth_token', $final_settings['github_access_token'], 'OAuth token should survive settings save' );
		$this->assertSame( 'octocat', $final_settings['github_username'], 'OAuth username should survive settings save' );
		$this->assertSame( 'new-brave-key', $final_settings['brave_search_api_key'], 'New field should be saved' );

		unset( $_POST['subtab'] );
	}
}
