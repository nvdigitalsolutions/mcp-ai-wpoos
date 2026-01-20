<?php
/**
 * Tests for the simple settings page save functionality.
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

		// Simulate POST data from simple settings page with fields from both General and Providers tabs.
		$_POST = array(
			'_wpnonce'            => wp_create_nonce( 'wp_mcp_ai_save_settings' ),
			'action'              => 'wp_mcp_ai_save_settings',
			'active_tab'          => 'general',
			'save_all_tabs'       => '1',
			'redirect_page'       => 'wp-mcp-ai-simple-settings',
			'wp_mcp_ai_settings'  => array(
				// General tab fields.
				'enable_logging'       => '1',
				'default_provider'     => 'openai',
				// Providers tab fields.
				'openai_api_key'       => 'sk-test123',
				'gemini_api_key'       => 'test-gemini-key',
				'ollama_endpoint_url'  => 'http://localhost:11434',
			),
		);

		// Set SERVER variables needed for nonce verification.
		$_SERVER['REQUEST_METHOD'] = 'POST';

		// Capture redirect to prevent actual redirect.
		add_filter( 'wp_redirect', '__return_false' );

		// Call the save handler.
		try {
			$dashboard->handle_save_settings();
		} catch ( Exception $e ) {
			// Expected to throw exception due to exit/die, ignore it.
		}

		// Get saved settings.
		$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

		// Verify General tab fields were saved.
		$this->assertTrue( $settings['enable_logging'], 'General tab field should be saved' );
		$this->assertEquals( 'openai', $settings['default_provider'], 'General tab select should be saved' );

		// Verify Providers tab fields were saved.
		$this->assertEquals( 'sk-test123', $settings['openai_api_key'], 'Providers tab API key should be saved' );
		$this->assertEquals( 'test-gemini-key', $settings['gemini_api_key'], 'Gemini API key should be saved' );
		$this->assertEquals( 'http://localhost:11434', $settings['ollama_endpoint_url'], 'Ollama URL should be saved' );
	}

	/**
	 * Test that without save_all_tabs flag, only active tab fields are sanitized.
	 */
	public function test_without_save_all_tabs_only_active_tab_saved() {
		// Initialize settings dashboard.
		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Simulate POST data from main dashboard with only General tab.
		$_POST = array(
			'_wpnonce'            => wp_create_nonce( 'wp_mcp_ai_save_settings' ),
			'action'              => 'wp_mcp_ai_save_settings',
			'active_tab'          => 'general',
			'wp_mcp_ai_settings'  => array(
				// General tab fields.
				'enable_logging'       => '1',
				'default_provider'     => 'gemini',
			),
		);

		// Set SERVER variables needed for nonce verification.
		$_SERVER['REQUEST_METHOD'] = 'POST';

		// Capture redirect to prevent actual redirect.
		add_filter( 'wp_redirect', '__return_false' );

		// Call the save handler.
		try {
			$dashboard->handle_save_settings();
		} catch ( Exception $e ) {
			// Expected to throw exception due to exit/die, ignore it.
		}

		// Get saved settings.
		$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

		// Verify General tab fields were saved.
		$this->assertTrue( $settings['enable_logging'], 'General tab field should be saved' );
		$this->assertEquals( 'gemini', $settings['default_provider'], 'General tab select should be saved' );
	}

	/**
	 * Test that empty password fields don't overwrite existing values.
	 */
	public function test_empty_password_fields_preserve_existing_values() {
		// Set initial API keys.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'openai_api_key' => 'sk-existing-key',
				'gemini_api_key' => 'existing-gemini-key',
			)
		);

		// Initialize settings dashboard.
		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Simulate POST data with empty password fields (user didn't change them).
		$_POST = array(
			'_wpnonce'            => wp_create_nonce( 'wp_mcp_ai_save_settings' ),
			'action'              => 'wp_mcp_ai_save_settings',
			'active_tab'          => 'providers',
			'wp_mcp_ai_settings'  => array(
				'openai_api_key'      => '', // Empty - should preserve existing.
				'gemini_api_key'      => '', // Empty - should preserve existing.
				'enable_openai'       => '1',
			),
		);

		// Set SERVER variables.
		$_SERVER['REQUEST_METHOD'] = 'POST';

		// Capture redirect.
		add_filter( 'wp_redirect', '__return_false' );

		// Call the save handler.
		try {
			$dashboard->handle_save_settings();
		} catch ( Exception $e ) {
			// Expected.
		}

		// Get saved settings.
		$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

		// Verify existing keys were preserved.
		$this->assertEquals( 'sk-existing-key', $settings['openai_api_key'], 'Existing OpenAI key should be preserved' );
		$this->assertEquals( 'existing-gemini-key', $settings['gemini_api_key'], 'Existing Gemini key should be preserved' );
		$this->assertTrue( $settings['enable_openai'], 'New checkbox should be saved' );
	}
}
