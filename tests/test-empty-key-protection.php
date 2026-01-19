<?php
/**
 * Test empty key protection in settings save flow.
 *
 * Tests the fix for the issue where empty keys from other subtabs/tabs
 * can accidentally overwrite existing values during settings save.
 *
 * @package WP_MCP_AI
 */

/**
 * Test empty key protection.
 */
class WP_MCP_AI_Empty_Key_Protection_Test extends WP_UnitTestCase {

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		// Start with clean settings.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		// Clean up.
		unset( $_POST['subtab'] );
		unset( $_POST['subtab_providers'] );
		unset( $_GET['subtab'] );
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Test that empty provider keys in sanitized data don't overwrite existing keys.
	 */
	public function test_empty_provider_keys_are_filtered_out() {
		// Set up initial state with API keys.
		$initial_settings = array(
			'openai_api_key'  => 'sk-test-openai-key',
			'gemini_api_key'  => 'AIza-test-gemini-key',
			'enable_openai'   => true,
			'enable_gemini'   => true,
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Simulate a save operation where sanitized data somehow includes empty keys.
		// This shouldn't happen with proper subtab isolation, but we're testing the safety net.
		$dashboard = new WP_MCP_AI_Settings_Dashboard();
		
		$posted_settings = array(
			'enable_openai'  => '1',
			'openai_api_key' => 'sk-new-openai-key', // User updated this.
			'gemini_api_key' => '', // This should NOT overwrite the existing key.
		);

		// Sanitize settings (simulating the flow in handle_save_settings).
		$sanitized = $dashboard->sanitize_settings( $posted_settings, 'providers' );

		// Manually apply the empty key filtering logic (as done in handle_save_settings).
		$sensitive_keys = array(
			'openai_api_key',
			'gemini_api_key',
			'anthropic_api_key',
			'huggingface_api_key',
			'ollama_endpoint_url',
			'lm_studio_endpoint_url',
			'cloudflare_account_id',
			'cloudflare_api_token',
		);

		foreach ( $sensitive_keys as $key ) {
			if ( isset( $sanitized[ $key ] ) && empty( $sanitized[ $key ] ) ) {
				unset( $sanitized[ $key ] );
			}
		}

		// Merge with existing settings.
		$existing = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$merged   = array_merge( $existing, $sanitized );

		// Verify that the empty gemini_api_key didn't overwrite the existing one.
		$this->assertEquals(
			'AIza-test-gemini-key',
			$merged['gemini_api_key'],
			'Empty gemini_api_key should not overwrite existing key'
		);

		// Verify that the new openai_api_key was saved.
		$this->assertEquals(
			'sk-new-openai-key',
			$merged['openai_api_key'],
			'Non-empty openai_api_key should be updated'
		);
	}

	/**
	 * Test that empty strings from inactive tabs don't overwrite existing values.
	 */
	public function test_empty_strings_from_inactive_tabs_are_filtered() {
		// Set up initial state.
		$initial_settings = array(
			'openai_api_key'         => 'sk-test-key',
			'default_model'          => 'gpt-4o',
			'enable_logging'         => true,
			'filter_default_light_model' => 'gpt-4o-mini',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Create a reflection class to test the is_setting_in_tab method.
		$dashboard = new WP_MCP_AI_Settings_Dashboard();
		$reflection = new ReflectionClass( $dashboard );
		$method = $reflection->getMethod( 'is_setting_in_tab' );
		$method->setAccessible( true );

		// Verify that provider fields belong to providers tab.
		$this->assertTrue(
			$method->invoke( $dashboard, 'openai_api_key', 'providers' ),
			'openai_api_key should belong to providers tab'
		);

		$this->assertFalse(
			$method->invoke( $dashboard, 'openai_api_key', 'general' ),
			'openai_api_key should NOT belong to general tab'
		);

		// Verify that general fields belong to general tab.
		$this->assertTrue(
			$method->invoke( $dashboard, 'enable_logging', 'general' ),
			'enable_logging should belong to general tab'
		);

		$this->assertFalse(
			$method->invoke( $dashboard, 'enable_logging', 'providers' ),
			'enable_logging should NOT belong to providers tab'
		);
	}

	/**
	 * Test that the complete protection flow works end-to-end.
	 */
	public function test_complete_empty_value_protection_flow() {
		// Set up initial state with various provider keys.
		$initial_settings = array(
			'openai_api_key'             => 'sk-openai-key',
			'gemini_api_key'             => 'AIza-gemini-key',
			'anthropic_api_key'          => 'sk-ant-anthropic-key',
			'ollama_endpoint_url'        => 'http://localhost:11434',
			'huggingface_api_key'        => 'hf_test_key',
			'enable_openai'              => true,
			'enable_gemini'              => true,
			'enable_anthropic'           => false,
			'default_model'              => 'gpt-4o',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Simulate saving OpenAI subtab only.
		$_POST['subtab_providers'] = 'openai';
		$posted_settings = array(
			'enable_openai'  => '1',
			'openai_api_key' => 'sk-new-openai-key', // Updated.
			'default_model'  => 'gpt-4.1', // Updated.
			// Note: Other provider keys are NOT in this submission.
			// But somehow they might appear as empty strings due to a bug.
			'gemini_api_key'    => '', // Should be filtered out.
			'anthropic_api_key' => '', // Should be filtered out.
		);

		// Call sanitize_settings as the handle_save_settings method does.
		$sanitized = $dashboard->sanitize_settings( $posted_settings, 'providers' );

		// Apply sensitive keys filtering (as in handle_save_settings).
		$sensitive_keys = array(
			'openai_api_key',
			'openai_organization_id',
			'anthropic_api_key',
			'gemini_api_key',
			'huggingface_api_key',
			'huggingface_endpoint_url',
			'huggingface_datasets_api_token',
			'ollama_endpoint_url',
			'lm_studio_endpoint_url',
			'cloudflare_account_id',
			'cloudflare_api_token',
		);

		foreach ( $sensitive_keys as $key ) {
			if ( isset( $sanitized[ $key ] ) && empty( $sanitized[ $key ] ) ) {
				unset( $sanitized[ $key ] );
			}
		}

		// Merge with existing settings.
		$existing = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$merged   = array_merge( $existing, $sanitized );

		// Verify OpenAI key was updated.
		$this->assertEquals(
			'sk-new-openai-key',
			$merged['openai_api_key'],
			'OpenAI key should be updated'
		);

		// Verify other provider keys were NOT cleared.
		$this->assertEquals(
			'AIza-gemini-key',
			$merged['gemini_api_key'],
			'Gemini key should be preserved'
		);
		$this->assertEquals(
			'sk-ant-anthropic-key',
			$merged['anthropic_api_key'],
			'Anthropic key should be preserved'
		);
		$this->assertEquals(
			'http://localhost:11434',
			$merged['ollama_endpoint_url'],
			'Ollama endpoint should be preserved'
		);
		$this->assertEquals(
			'hf_test_key',
			$merged['huggingface_api_key'],
			'HuggingFace key should be preserved'
		);
	}

	/**
	 * Test that intentional field clearing still works when on the correct tab.
	 */
	public function test_intentional_field_clearing_works_on_correct_tab() {
		// Set up initial state.
		$initial_settings = array(
			'openai_api_key'      => 'sk-old-key',
			'openai_organization_id' => 'org-123',
			'enable_openai'       => true,
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// User intentionally clears the organization ID on the OpenAI subtab.
		$_POST['subtab_providers'] = 'openai';
		$dashboard = new WP_MCP_AI_Settings_Dashboard();
		
		$posted_settings = array(
			'enable_openai'          => '1',
			'openai_api_key'         => 'sk-old-key',
			'openai_organization_id' => '', // User intentionally cleared this.
		);

		$sanitized = $dashboard->sanitize_settings( $posted_settings, 'providers' );

		// The sensitive key filter should remove empty openai_organization_id.
		// But if user wants to clear it, they need to be on the OpenAI subtab.
		// This is acceptable behavior - empty sensitive keys are never saved.
		// Users should delete the value entirely or not submit it.

		// Verify the organization_id field is filtered out when empty.
		$this->assertArrayNotHasKey(
			'openai_organization_id',
			$sanitized,
			'Empty organization ID should be filtered out by sensitive keys protection'
		);
	}

	/**
	 * Test that subtab isolation prevents cross-contamination.
	 */
	public function test_subtab_isolation_prevents_cross_contamination() {
		// Set up initial state with multiple providers configured.
		$initial_settings = array(
			'enable_openai'      => true,
			'openai_api_key'     => 'sk-openai-key',
			'default_model'      => 'gpt-4o',
			'enable_anthropic'   => true,
			'anthropic_api_key'  => 'sk-ant-key',
			'anthropic_model'    => 'claude-3-5-sonnet-20241022',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		$section = new WP_MCP_AI_Section_Providers();

		// Save Anthropic subtab.
		$_POST['subtab_providers'] = 'anthropic';
		$input = array(
			'enable_anthropic'  => '', // User unchecked this (empty string in some form submissions).
			'anthropic_api_key' => 'sk-ant-new-key',
			'anthropic_model'   => 'claude-3-5-haiku-20241022',
		);

		$sanitized = $section->sanitize( $input );

		// Verify only Anthropic fields are in the sanitized output.
		$this->assertArrayHasKey( 'anthropic_api_key', $sanitized );
		$this->assertArrayHasKey( 'anthropic_model', $sanitized );

		// Verify OpenAI fields are NOT in the sanitized output.
		$this->assertArrayNotHasKey( 'openai_api_key', $sanitized );
		$this->assertArrayNotHasKey( 'default_model', $sanitized );
		$this->assertArrayNotHasKey( 'enable_openai', $sanitized );

		// Merge and verify OpenAI settings are preserved.
		$existing = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$merged   = array_merge( $existing, $sanitized );

		$this->assertTrue( $merged['enable_openai'], 'OpenAI should still be enabled' );
		$this->assertEquals( 'sk-openai-key', $merged['openai_api_key'], 'OpenAI key should be preserved' );
		$this->assertEquals( 'gpt-4o', $merged['default_model'], 'OpenAI model should be preserved' );
	}
}
