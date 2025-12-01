<?php
/**
 * Tests for provider subtab settings persistence.
 *
 * Ensures that saving one provider subtab (e.g., OpenAI) doesn't wipe
 * settings from other subtabs (e.g., Gemini, Anthropic, etc.).
 *
 * This test validates the fix for issue #1296 where the conflicting
 * register_settings() method in WP_MCP_AI_Admin_Settings_Base was
 * wiping provider subtab settings.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that provider subtab settings persist when saving other subtabs.
 */
class WP_MCP_AI_Provider_Subtab_Settings_Test extends WP_UnitTestCase {

	/**
	 * Test that saving OpenAI subtab preserves Gemini settings.
	 */
	public function test_saving_openai_preserves_gemini_settings() {
		// Set up initial settings with both OpenAI and Gemini configured.
		$initial_settings = array(
			'enable_openai'        => true,
			'openai_api_key'       => 'sk-test-openai-key',
			'default_model'        => 'gpt-4o',
			'enable_gemini'        => true,
			'gemini_api_key'       => 'AIza-test-gemini-key',
			'default_gemini_model' => 'gemini-1.5-pro',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Simulate saving the OpenAI subtab with modified settings.
		$dashboard       = new WP_MCP_AI_Settings_Dashboard();
		$posted_settings = array(
			'enable_openai'  => '1',
			'openai_api_key' => 'sk-test-openai-key-updated',
			'default_model'  => 'gpt-4o-mini',
			// Note: Gemini settings are NOT in this submission (different subtab).
		);

		// Sanitize with providers tab context.
		$sanitized = $dashboard->sanitize_settings( $posted_settings, 'providers' );

		// Merge with existing settings as the dashboard does.
		$existing = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$merged   = array_merge( $existing, $sanitized );

		// Critical assertions: Gemini settings should still be present.
		$this->assertTrue(
			$merged['enable_gemini'],
			'enable_gemini should remain true when saving OpenAI subtab'
		);
		$this->assertSame(
			'AIza-test-gemini-key',
			$merged['gemini_api_key'],
			'gemini_api_key should be preserved when saving OpenAI subtab'
		);
		$this->assertSame(
			'gemini-1.5-pro',
			$merged['default_gemini_model'],
			'default_gemini_model should be preserved when saving OpenAI subtab'
		);

		// OpenAI settings should be updated.
		$this->assertSame(
			'sk-test-openai-key-updated',
			$merged['openai_api_key'],
			'openai_api_key should be updated'
		);
		$this->assertSame(
			'gpt-4o-mini',
			$merged['default_model'],
			'default_model should be updated'
		);
	}

	/**
	 * Test that saving Gemini subtab preserves OpenAI settings.
	 */
	public function test_saving_gemini_preserves_openai_settings() {
		// Set up initial settings with both OpenAI and Gemini configured.
		$initial_settings = array(
			'enable_openai'        => true,
			'openai_api_key'       => 'sk-test-openai-key',
			'default_model'        => 'gpt-4o',
			'enable_gemini'        => true,
			'gemini_api_key'       => 'AIza-test-gemini-key',
			'default_gemini_model' => 'gemini-1.5-pro',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Simulate saving the Gemini subtab with modified settings.
		$dashboard       = new WP_MCP_AI_Settings_Dashboard();
		$posted_settings = array(
			'enable_gemini'        => '1',
			'gemini_api_key'       => 'AIza-test-gemini-key-updated',
			'default_gemini_model' => 'gemini-2.5-flash',
			// Note: OpenAI settings are NOT in this submission (different subtab).
		);

		// Sanitize with providers tab context.
		$sanitized = $dashboard->sanitize_settings( $posted_settings, 'providers' );

		// Merge with existing settings as the dashboard does.
		$existing = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$merged   = array_merge( $existing, $sanitized );

		// Critical assertions: OpenAI settings should still be present.
		$this->assertTrue(
			$merged['enable_openai'],
			'enable_openai should remain true when saving Gemini subtab'
		);
		$this->assertSame(
			'sk-test-openai-key',
			$merged['openai_api_key'],
			'openai_api_key should be preserved when saving Gemini subtab'
		);
		$this->assertSame(
			'gpt-4o',
			$merged['default_model'],
			'default_model should be preserved when saving Gemini subtab'
		);

		// Gemini settings should be updated.
		$this->assertSame(
			'AIza-test-gemini-key-updated',
			$merged['gemini_api_key'],
			'gemini_api_key should be updated'
		);
		$this->assertSame(
			'gemini-2.5-flash',
			$merged['default_gemini_model'],
			'default_gemini_model should be updated'
		);
	}

	/**
	 * Test that saving Ollama subtab preserves all other provider settings.
	 */
	public function test_saving_ollama_preserves_other_providers() {
		// Set up initial settings with multiple providers.
		$initial_settings = array(
			'enable_openai'        => true,
			'openai_api_key'       => 'sk-test-openai-key',
			'default_model'        => 'gpt-4o',
			'enable_gemini'        => true,
			'gemini_api_key'       => 'AIza-test-gemini-key',
			'default_gemini_model' => 'gemini-1.5-pro',
			'enable_anthropic'     => true,
			'anthropic_api_key'    => 'sk-ant-test-key',
			'anthropic_model'      => 'claude-3-5-sonnet-20241022',
			'enable_ollama'        => false,
			'ollama_endpoint_url'  => 'http://localhost:11434',
			'ollama_model'         => 'llama3',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Simulate saving the Ollama subtab with modified settings.
		$dashboard       = new WP_MCP_AI_Settings_Dashboard();
		$posted_settings = array(
			'enable_ollama'       => '1',
			'ollama_endpoint_url' => 'http://192.168.1.100:11434',
			'ollama_model'        => 'mistral',
			// Note: Other provider settings are NOT in this submission.
		);

		// Sanitize with providers tab context.
		$sanitized = $dashboard->sanitize_settings( $posted_settings, 'providers' );

		// Merge with existing settings as the dashboard does.
		$existing = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$merged   = array_merge( $existing, $sanitized );

		// Critical assertions: All other provider settings should be preserved.
		$this->assertTrue( $merged['enable_openai'], 'OpenAI should remain enabled' );
		$this->assertSame( 'sk-test-openai-key', $merged['openai_api_key'], 'OpenAI API key should be preserved' );
		$this->assertTrue( $merged['enable_gemini'], 'Gemini should remain enabled' );
		$this->assertSame( 'AIza-test-gemini-key', $merged['gemini_api_key'], 'Gemini API key should be preserved' );
		$this->assertTrue( $merged['enable_anthropic'], 'Anthropic should remain enabled' );
		$this->assertSame( 'sk-ant-test-key', $merged['anthropic_api_key'], 'Anthropic API key should be preserved' );

		// Ollama settings should be updated.
		$this->assertTrue( $merged['enable_ollama'], 'Ollama should now be enabled' );
		$this->assertSame( 'http://192.168.1.100:11434', $merged['ollama_endpoint_url'], 'Ollama endpoint should be updated' );
		$this->assertSame( 'mistral', $merged['ollama_model'], 'Ollama model should be updated' );
	}

	/**
	 * Test that unchecking a provider checkbox in one subtab doesn't affect others.
	 */
	public function test_unchecking_provider_preserves_other_providers() {
		// Set up initial settings with all providers enabled.
		$initial_settings = array(
			'enable_openai'     => true,
			'openai_api_key'    => 'sk-test-openai-key',
			'enable_gemini'     => true,
			'gemini_api_key'    => 'AIza-test-gemini-key',
			'enable_anthropic'  => true,
			'anthropic_api_key' => 'sk-ant-test-key',
			'enable_ollama'     => true,
			'ollama_model'      => 'llama3',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Simulate saving the OpenAI subtab with enable_openai UNCHECKED.
		$dashboard       = new WP_MCP_AI_Settings_Dashboard();
		$posted_settings = array(
			// enable_openai is NOT here (unchecked).
			'openai_api_key' => 'sk-test-openai-key',
		);

		// Sanitize with providers tab context.
		$sanitized = $dashboard->sanitize_settings( $posted_settings, 'providers' );

		// Merge with existing settings as the dashboard does.
		$existing = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$merged   = array_merge( $existing, $sanitized );

		// OpenAI should now be disabled.
		$this->assertFalse( $merged['enable_openai'], 'enable_openai should be false when unchecked' );

		// All other providers should remain enabled.
		$this->assertTrue( $merged['enable_gemini'], 'Gemini should remain enabled' );
		$this->assertTrue( $merged['enable_anthropic'], 'Anthropic should remain enabled' );
		$this->assertTrue( $merged['enable_ollama'], 'Ollama should remain enabled' );
	}

	/**
	 * Test that provider priority list is preserved when saving individual provider subtabs.
	 */
	public function test_provider_priority_preserved_when_saving_provider_subtabs() {
		// Set up initial settings with a custom provider priority.
		$initial_settings = array(
			'provider_priority_list' => array( 'gemini', 'openai', 'anthropic', 'ollama', 'lm_studio' ),
			'enable_openai'          => true,
			'openai_api_key'         => 'sk-test-openai-key',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Simulate saving the OpenAI subtab.
		$dashboard       = new WP_MCP_AI_Settings_Dashboard();
		$posted_settings = array(
			'enable_openai'  => '1',
			'openai_api_key' => 'sk-test-openai-key-updated',
			// Note: provider_priority_list is NOT in this submission (different subtab).
		);

		// Sanitize with providers tab context.
		$sanitized = $dashboard->sanitize_settings( $posted_settings, 'providers' );

		// Merge with existing settings as the dashboard does.
		$existing = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$merged   = array_merge( $existing, $sanitized );

		// Provider priority should be preserved.
		$this->assertArrayHasKey( 'provider_priority_list', $merged, 'provider_priority_list should be preserved' );
		$this->assertSame(
			array( 'gemini', 'openai', 'anthropic', 'ollama', 'lm_studio' ),
			$merged['provider_priority_list'],
			'Provider priority list should be preserved when saving OpenAI subtab'
		);
	}
}
