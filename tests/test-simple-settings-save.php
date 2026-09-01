<?php
/**
 * Tests for the simple settings page save functionality.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Simple_Settings_Save_Test extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create an admin user and log them in.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Clear any existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Test that settings from multiple tabs can be saved together with save_all_tabs flag.
	 */
	public function test_save_all_tabs_flag_enables_multi_tab_save() {
		// Initialize settings dashboard.
		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Simulate POST data from the simple settings page. Each section routes
		// through its own subtab field, so post the subtab selectors too.
		$_POST = array(
			'_wpnonce'           => wp_create_nonce( 'wp_mcp_ai_save_settings' ),
			'action'             => 'wp_mcp_ai_save_settings',
			'active_tab'         => 'general',
			'save_all_tabs'      => '1',
			'redirect_page'      => 'wp-mcp-ai-simple-settings',
			'subtab_general'     => 'logs',
			'subtab_providers'   => 'openai',
			'wp_mcp_ai_settings' => array(
				// General tab (logs subtab) fields.
				'enable_logging' => '1',
				// Providers tab (openai subtab) fields.
				'openai_api_key' => 'sk-test123',
				'enable_openai'  => '1',
			),
		);

		// check_admin_referer reads $_REQUEST, which does not merge POST data
		// under the test bootstrap — mirror the nonce there explicitly.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Test fixture for the save handler's nonce check.
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

		// Set SERVER variables needed for nonce verification.
		$_SERVER['REQUEST_METHOD'] = 'POST';

		// Capture redirect to prevent actual redirect.
		add_filter( 'wp_redirect', '__return_false' );

		// Call the save handler.
		try {
			$dashboard->handle_save_settings();
		} catch ( Exception $e ) {
			// Expected to throw exception due to exit/die, ignore it.
			unset( $e );
		}

		// Get saved settings. API keys are stored in the separate credentials
		// option; non-sensitive settings stay in the main option.
		$settings    = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$credentials = get_option( WP_MCP_AI_Admin_Settings_Base::CREDENTIALS_OPTION_NAME, array() );

		// Verify General tab fields were saved.
		$this->assertTrue( $settings['enable_logging'], 'General tab field should be saved' );

		// Verify Providers tab fields were saved.
		$this->assertEquals( 'sk-test123', $credentials['openai_api_key'], 'Providers tab API key should be saved' );
		$this->assertTrue( $settings['enable_openai'], 'Providers tab checkbox should be saved' );
	}

	/**
	 * Test that without save_all_tabs flag, only active tab fields are sanitized.
	 */
	public function test_without_save_all_tabs_only_active_tab_saved() {
		// Initialize settings dashboard.
		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Simulate POST data from the main dashboard with only the General tab.
		$_POST = array(
			'_wpnonce'           => wp_create_nonce( 'wp_mcp_ai_save_settings' ),
			'action'             => 'wp_mcp_ai_save_settings',
			'active_tab'         => 'general',
			'subtab_general'     => 'logs',
			'wp_mcp_ai_settings' => array(
				// General tab (logs subtab) fields.
				'enable_logging' => '1',
				// A Providers tab field that must NOT be saved.
				'openai_api_key' => 'sk-should-not-save',
			),
		);

		// check_admin_referer reads $_REQUEST, which does not merge POST data
		// under the test bootstrap — mirror the nonce there explicitly.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Test fixture for the save handler's nonce check.
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

		// Set SERVER variables needed for nonce verification.
		$_SERVER['REQUEST_METHOD'] = 'POST';

		// Capture redirect to prevent actual redirect.
		add_filter( 'wp_redirect', '__return_false' );

		// Call the save handler.
		try {
			$dashboard->handle_save_settings();
		} catch ( Exception $e ) {
			// Expected to throw exception due to exit/die, ignore it.
			unset( $e );
		}

		// Get saved settings.
		$settings    = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$credentials = get_option( WP_MCP_AI_Admin_Settings_Base::CREDENTIALS_OPTION_NAME, array() );

		// Verify General tab fields were saved.
		$this->assertTrue( $settings['enable_logging'], 'General tab field should be saved' );

		// Verify the Providers tab field was NOT saved (inactive tab).
		$this->assertArrayNotHasKey( 'openai_api_key', $credentials, 'Inactive Providers tab field should not be saved' );
	}

	/**
	 * Test that empty password fields don't overwrite existing values.
	 */
	public function test_empty_password_fields_preserve_existing_values() {
		// Set initial API keys in the credentials option (where production
		// stores sensitive keys after the settings split).
		update_option(
			WP_MCP_AI_Admin_Settings_Base::CREDENTIALS_OPTION_NAME,
			array(
				'openai_api_key' => 'sk-existing-key',
				'gemini_api_key' => 'existing-gemini-key',
			)
		);

		// Initialize settings dashboard.
		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Simulate POST data with empty password fields (user didn't change them).
		$_POST = array(
			'_wpnonce'           => wp_create_nonce( 'wp_mcp_ai_save_settings' ),
			'action'             => 'wp_mcp_ai_save_settings',
			'active_tab'         => 'providers',
			'subtab_providers'   => 'openai',
			'wp_mcp_ai_settings' => array(
				'openai_api_key' => '', // Empty - should preserve existing.
				'enable_openai'  => '1',
			),
		);

		// check_admin_referer reads $_REQUEST, which does not merge POST data
		// under the test bootstrap — mirror the nonce there explicitly.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Test fixture for the save handler's nonce check.
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

		// Set SERVER variables.
		$_SERVER['REQUEST_METHOD'] = 'POST';

		// Capture redirect.
		add_filter( 'wp_redirect', '__return_false' );

		// Call the save handler.
		try {
			$dashboard->handle_save_settings();
		} catch ( Exception $e ) {
			// Expected.
			unset( $e );
		}

		// Get saved settings.
		$settings    = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$credentials = get_option( WP_MCP_AI_Admin_Settings_Base::CREDENTIALS_OPTION_NAME, array() );

		// Verify existing keys were preserved.
		$this->assertEquals( 'sk-existing-key', $credentials['openai_api_key'], 'Existing OpenAI key should be preserved' );
		$this->assertEquals( 'existing-gemini-key', $credentials['gemini_api_key'], 'Existing Gemini key should be preserved' );
		$this->assertTrue( $settings['enable_openai'], 'New checkbox should be saved' );
	}
}
