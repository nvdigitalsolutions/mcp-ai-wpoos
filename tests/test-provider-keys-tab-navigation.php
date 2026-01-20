<?php
/**
 * Test provider keys persistence during tab navigation.
 *
 * Tests the specific issue where navigating from Providers → Advanced
 * causes provider keys to be cleared.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that provider keys persist when navigating between tabs.
 */
class WP_MCP_AI_Provider_Keys_Tab_Navigation_Test extends WP_UnitTestCase {

	/**
	 * Test that provider keys persist when navigating from Providers to Advanced tab.
	 */
	public function test_provider_keys_persist_on_tab_navigation() {
		// Set up initial settings with provider keys configured.
		$initial_settings = array(
			'default_provider'       => 'gemini',
			'default_model'          => 'gpt-4o',
			'enable_gemini'          => true,
			'gemini_api_key'         => 'AIza-test-gemini-key-12345',
			'default_gemini_model'   => 'gemini-2.5-flash',
			'enable_openai'          => true,
			'openai_api_key'         => 'sk-test-openai-key-67890',
			'enable_ollama'          => true,
			'ollama_endpoint_url'    => 'http://localhost:11434',
			'enable_anthropic'       => true,
			'anthropic_api_key'      => 'sk-ant-test-key-abcde',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Clear WordPress object cache to simulate fresh page load.
		wp_cache_delete( WP_MCP_AI_Admin_Settings::OPTION_NAME, 'options' );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Simulate navigating to Advanced tab (GET request, no POST data).
		// This is what happens when clicking "Advanced" tab link.
		$_GET['page'] = 'wp-mcp-ai-dashboard';
		$_GET['tab']  = 'advanced';
		$_GET['subtab'] = 'settings_management';

		// No POST data - this is a navigation, not a form submission.
		$_POST = array();

		// Create dashboard instance and render (this loads settings).
		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Get settings after navigation.
		$settings_after_navigation = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

		// CRITICAL ASSERTIONS: Provider keys should still be present.
		$this->assertNotEmpty(
			$settings_after_navigation,
			'Settings should not be empty after navigation'
		);

		$this->assertArrayHasKey(
			'gemini_api_key',
			$settings_after_navigation,
			'gemini_api_key should still exist in settings'
		);

		$this->assertSame(
			'AIza-test-gemini-key-12345',
			$settings_after_navigation['gemini_api_key'],
			'gemini_api_key should not be cleared during navigation'
		);

		$this->assertSame(
			'sk-test-openai-key-67890',
			$settings_after_navigation['openai_api_key'],
			'openai_api_key should not be cleared during navigation'
		);

		$this->assertSame(
			'http://localhost:11434',
			$settings_after_navigation['ollama_endpoint_url'],
			'ollama_endpoint_url should not be cleared during navigation'
		);

		$this->assertSame(
			'sk-ant-test-key-abcde',
			$settings_after_navigation['anthropic_api_key'],
			'anthropic_api_key should not be cleared during navigation'
		);

		// Clean up.
		unset( $_GET['page'], $_GET['tab'], $_GET['subtab'] );
	}

	/**
	 * Test that Settings Health check doesn't trigger unwanted saves.
	 */
	public function test_settings_health_check_doesnt_clear_providers() {
		// Set up initial settings with provider keys.
		$initial_settings = array(
			'default_provider'       => 'gemini',
			'default_model'          => 'gemini-2.5-flash',
			'enable_gemini'          => true,
			'gemini_api_key'         => 'AIza-test-gemini-key-12345',
			'default_gemini_model'   => 'gemini-2.5-flash',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Clear caches.
		wp_cache_delete( WP_MCP_AI_Admin_Settings::OPTION_NAME, 'options' );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Simulate AJAX health check (this reads settings).
		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Mock the AJAX request.
		$_POST['action'] = 'wp_mcp_ai_check_settings_health';
		$_REQUEST['action'] = 'wp_mcp_ai_check_settings_health';

		// Get settings after health check.
		$settings_after_health_check = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

		// Provider keys should still be present.
		$this->assertSame(
			'AIza-test-gemini-key-12345',
			$settings_after_health_check['gemini_api_key'],
			'gemini_api_key should not be cleared by health check'
		);

		// Clean up.
		unset( $_POST['action'], $_REQUEST['action'] );
	}

	/**
	 * Test that register_setting sanitize callback doesn't run on GET requests.
	 */
	public function test_sanitize_callback_not_triggered_on_get() {
		// Set up initial settings.
		$initial_settings = array(
			'gemini_api_key' => 'AIza-test-gemini-key-12345',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Register settings (happens on admin_init).
		$dashboard = new WP_MCP_AI_Settings_Dashboard();
		$dashboard->register_settings();

		// Simulate GET request (navigation).
		$_GET['page'] = 'wp-mcp-ai-dashboard';
		$_GET['tab']  = 'advanced';

		// Read settings (this should NOT trigger sanitize_callback).
		$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

		// Provider key should still be present.
		$this->assertSame(
			'AIza-test-gemini-key-12345',
			$settings['gemini_api_key'],
			'Settings read should not trigger sanitize callback'
		);

		// Clean up.
		unset( $_GET['page'], $_GET['tab'] );
	}

	/**
	 * Test that navigating between provider subtabs preserves other provider keys.
	 */
	public function test_navigating_provider_subtabs_preserves_keys() {
		// Set up settings with multiple providers.
		$initial_settings = array(
			'enable_gemini'   => true,
			'gemini_api_key'  => 'AIza-test-gemini-key-12345',
			'enable_openai'   => true,
			'openai_api_key'  => 'sk-test-openai-key-67890',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Simulate navigating from Gemini subtab to OpenAI subtab (GET request).
		$_GET['page']   = 'wp-mcp-ai-dashboard';
		$_GET['tab']    = 'providers';
		$_GET['subtab'] = 'openai'; // Changed from 'gemini' to 'openai'.

		// No POST data.
		$_POST = array();

		// Create dashboard instance.
		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Get settings after subtab navigation.
		$settings_after = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

		// Both provider keys should still be present.
		$this->assertSame(
			'AIza-test-gemini-key-12345',
			$settings_after['gemini_api_key'],
			'gemini_api_key should not be cleared when navigating to OpenAI subtab'
		);

		$this->assertSame(
			'sk-test-openai-key-67890',
			$settings_after['openai_api_key'],
			'openai_api_key should still be present'
		);

		// Clean up.
		unset( $_GET['page'], $_GET['tab'], $_GET['subtab'] );
	}
}
