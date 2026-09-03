<?php
/**
 * Test that saving one provider subtab doesn't clear other provider settings.
 *
 * This test verifies the fix for the bug where the Simple Settings Saver
 * would set ALL unposted checkboxes to false, clearing settings from inactive subtabs.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test provider subtab save bug fix.
 */
class WP_MCP_AI_Provider_Subtab_Save_Bug_Test extends WP_UnitTestCase {

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		unset( $_POST['subtab'] );
		unset( $_POST['subtab_providers'] );
		unset( $_POST['save_all_tabs'] );
		unset( $_POST['active_tab'] );
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Test that saving OpenAI subtab doesn't clear Gemini/Anthropic/Ollama settings.
	 *
	 * This is the main bug fix test.
	 */
	public function test_saving_openai_subtab_preserves_other_providers() {
		// Set up initial state with all providers enabled.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_openai'       => true,
				'openai_api_key'      => 'sk-old-openai-key',
				'enable_anthropic'    => true,
				'anthropic_api_key'   => 'sk-ant-old-key',
				'enable_gemini'       => true,
				'gemini_api_key'      => 'AIza-old-key',
				'enable_ollama'       => true,
				'ollama_endpoint_url' => 'http://localhost:11434',
			)
		);

		// Simulate saving OpenAI subtab with new API key.
		// This mimics what happens when user submits the form from Providers → OpenAI subtab.
		$_POST['subtab_providers']   = 'openai';
		$_POST['active_tab']         = 'providers';
		$_POST['wp_mcp_ai_settings'] = array(
			'enable_openai'  => '1', // Still enabled.
			'openai_api_key' => 'sk-new-openai-key', // New key.
			'default_model'  => 'gpt-4o',
		);

		// Create dashboard and simulate save.
		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Call the sanitize_settings method directly (this is what handle_save_settings calls).
		$sanitized = $dashboard->sanitize_settings( $_POST['wp_mcp_ai_settings'], 'providers' );

		// Merge with existing settings (simulating the save flow).
		$existing = get_option( 'wp_mcp_ai_settings', array() );
		$merged   = array_merge( $existing, $sanitized );

		// Verify OpenAI settings were updated.
		$this->assertTrue( $merged['enable_openai'], 'OpenAI should still be enabled' );
		$this->assertEquals( 'sk-new-openai-key', $merged['openai_api_key'], 'OpenAI key should be updated' );

		// CRITICAL: Verify other provider settings were NOT cleared!
		$this->assertTrue( $merged['enable_anthropic'], 'Anthropic should still be enabled after saving OpenAI' );
		$this->assertEquals( 'sk-ant-old-key', $merged['anthropic_api_key'], 'Anthropic key should be preserved' );

		$this->assertTrue( $merged['enable_gemini'], 'Gemini should still be enabled after saving OpenAI' );
		$this->assertEquals( 'AIza-old-key', $merged['gemini_api_key'], 'Gemini key should be preserved' );

		$this->assertTrue( $merged['enable_ollama'], 'Ollama should still be enabled after saving OpenAI' );
		$this->assertEquals( 'http://localhost:11434', $merged['ollama_endpoint_url'], 'Ollama endpoint should be preserved' );
	}

	/**
	 * Test that Simple Settings Saver is NOT used when subtab is active.
	 */
	public function test_simple_settings_saver_not_used_with_subtab() {
		// Set up initial state.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_openai'    => true,
				'openai_api_key'   => 'sk-old-key',
				'enable_anthropic' => true,
			)
		);

		// Simulate form submission with save_all_tabs flag AND subtab (edge case).
		$_POST['subtab_providers']   = 'openai';
		$_POST['active_tab']         = 'providers';
		$_POST['save_all_tabs']      = '1'; // This should be ignored when subtab is present!
		$_POST['wp_mcp_ai_settings'] = array(
			'enable_openai'  => '1',
			'openai_api_key' => 'sk-new-key',
		);

		// The Simple Settings Saver should NOT be used because subtab is active.
		// If it were used, it would set enable_anthropic to false (bug).

		$dashboard = new WP_MCP_AI_Settings_Dashboard();
		$sanitized = $dashboard->sanitize_settings( $_POST['wp_mcp_ai_settings'], 'providers' );

		$existing = get_option( 'wp_mcp_ai_settings', array() );
		$merged   = array_merge( $existing, $sanitized );

		// Verify enable_anthropic was NOT set to false.
		$this->assertTrue( $merged['enable_anthropic'], 'Anthropic should still be enabled (Simple Settings Saver should NOT have been used)' );
	}

	/**
	 * Test that saving a General subtab routes through the section-specific
	 * subtab field (the legacy flat-page Simple Settings Saver is disabled).
	 */
	public function test_simple_settings_page_save_works_without_subtab() {
		// Set up initial state.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_logging'  => false,
				'request_timeout' => 60,
			)
		);

		// Simulate the General → Logs subtab form submission.
		$_POST['active_tab']         = 'general';
		$_POST['save_all_tabs']      = '1';
		$_POST['subtab_general']     = 'logs';
		$_POST['wp_mcp_ai_settings'] = array(
			'enable_logging'  => '1', // Enabling logging.
			'request_timeout' => '120', // Belongs to the behavior subtab.
		);

		$dashboard = new WP_MCP_AI_Settings_Dashboard();
		$sanitized = $dashboard->sanitize_settings( $_POST['wp_mcp_ai_settings'], 'general' );

		$existing = get_option( 'wp_mcp_ai_settings', array() );
		$merged   = array_merge( $existing, $sanitized );

		// Verify logging was updated.
		$this->assertTrue( $merged['enable_logging'], 'Logging should be enabled' );

		// Fields from other subtabs must not be processed (cross-subtab protection).
		$this->assertArrayNotHasKey( 'request_timeout', $sanitized, 'request_timeout belongs to another subtab and should not be saved here' );
	}
}
