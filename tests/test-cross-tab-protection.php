<?php
/**
 * Test cross-tab protection to verify General subtabs don't wipe Provider settings.
 *
 * @package WP_MCP_AI
 */

/**
 * Test cross-tab protection.
 */
class WP_MCP_AI_Cross_Tab_Protection_Test extends WP_UnitTestCase {

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
		unset( $_POST['subtab_general'] );
		unset( $_POST['subtab_providers'] );
		unset( $_POST['subtab'] );
		unset( $_GET['subtab'] );
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Test that saving General → Core doesn't wipe Provider → OpenAI settings.
	 */
	public function test_saving_general_core_preserves_provider_openai() {
		// Set up initial state with provider settings.
		$initial_settings = array(
			'enable_openai'     => true,
			'openai_api_key'    => 'sk-test-openai-key',
			'default_model'     => 'gpt-4o',
			'enable_gemini'     => true,
			'gemini_api_key'    => 'AIza-test-gemini-key',
			'default_provider'  => 'openai',
			'default_assistant' => 0,
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Simulate saving General → Core subtab.
		$_POST['subtab_general'] = 'core';
		$dashboard = new WP_MCP_AI_Settings_Dashboard();
		
		$posted_settings = array(
			'default_provider'  => 'gemini', // User changed this.
			'default_assistant' => 0,
		);

		// Sanitize only the general tab.
		$sanitized = $dashboard->sanitize_settings( $posted_settings, 'general' );

		// The sanitized output should only contain general fields.
		$this->assertArrayHasKey( 'default_provider', $sanitized );
		$this->assertEquals( 'gemini', $sanitized['default_provider'] );

		// Provider fields should NOT be in sanitized output.
		$this->assertArrayNotHasKey( 'enable_openai', $sanitized, 'enable_openai should not be in General sanitized output' );
		$this->assertArrayNotHasKey( 'openai_api_key', $sanitized, 'openai_api_key should not be in General sanitized output' );
		$this->assertArrayNotHasKey( 'enable_gemini', $sanitized, 'enable_gemini should not be in General sanitized output' );
		$this->assertArrayNotHasKey( 'gemini_api_key', $sanitized, 'gemini_api_key should not be in General sanitized output' );

		// Merge with existing settings (simulating the actual save flow).
		$existing = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$merged   = array_merge( $existing, $sanitized );

		// Verify provider settings are preserved.
		$this->assertTrue( $merged['enable_openai'], 'enable_openai should still be true' );
		$this->assertEquals( 'sk-test-openai-key', $merged['openai_api_key'], 'openai_api_key should be preserved' );
		$this->assertTrue( $merged['enable_gemini'], 'enable_gemini should still be true' );
		$this->assertEquals( 'AIza-test-gemini-key', $merged['gemini_api_key'], 'gemini_api_key should be preserved' );

		// Verify general setting was updated.
		$this->assertEquals( 'gemini', $merged['default_provider'], 'default_provider should be updated to gemini' );
	}

	/**
	 * Test that saving General → Behavior doesn't wipe Provider settings.
	 */
	public function test_saving_general_behavior_preserves_provider_settings() {
		// Set up initial state.
		$initial_settings = array(
			'enable_openai'       => true,
			'openai_api_key'      => 'sk-test-key',
			'max_history_messages' => 10,
			'request_timeout'     => 30,
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Simulate saving General → Behavior subtab.
		$_POST['subtab_general'] = 'behavior';
		$dashboard = new WP_MCP_AI_Settings_Dashboard();
		
		$posted_settings = array(
			'max_history_messages' => 20, // User changed this.
			'request_timeout'      => 60, // User changed this.
		);

		// Sanitize only the general tab.
		$sanitized = $dashboard->sanitize_settings( $posted_settings, 'general' );

		// Provider fields should NOT be in sanitized output.
		$this->assertArrayNotHasKey( 'enable_openai', $sanitized );
		$this->assertArrayNotHasKey( 'openai_api_key', $sanitized );

		// Merge and verify.
		$existing = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$merged   = array_merge( $existing, $sanitized );

		// Verify provider settings are preserved.
		$this->assertTrue( $merged['enable_openai'] );
		$this->assertEquals( 'sk-test-key', $merged['openai_api_key'] );

		// Verify behavior settings were updated.
		$this->assertEquals( 20, $merged['max_history_messages'] );
		$this->assertEquals( 60, $merged['request_timeout'] );
	}

	/**
	 * Test that saving Providers → OpenAI doesn't wipe General settings.
	 */
	public function test_saving_provider_openai_preserves_general_settings() {
		// Set up initial state.
		$initial_settings = array(
			'default_provider'     => 'openai',
			'max_history_messages' => 10,
			'enable_openai'        => true,
			'openai_api_key'       => 'sk-old-key',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Simulate saving Providers → OpenAI subtab.
		$_POST['subtab_providers'] = 'openai';
		$dashboard = new WP_MCP_AI_Settings_Dashboard();
		
		$posted_settings = array(
			'enable_openai'  => '1',
			'openai_api_key' => 'sk-new-key', // User changed this.
			'default_model'  => 'gpt-4.1',
		);

		// Sanitize only the providers tab.
		$sanitized = $dashboard->sanitize_settings( $posted_settings, 'providers' );

		// General fields should NOT be in sanitized output.
		$this->assertArrayNotHasKey( 'default_provider', $sanitized );
		$this->assertArrayNotHasKey( 'max_history_messages', $sanitized );

		// Merge and verify.
		$existing = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$merged   = array_merge( $existing, $sanitized );

		// Verify general settings are preserved.
		$this->assertEquals( 'openai', $merged['default_provider'] );
		$this->assertEquals( 10, $merged['max_history_messages'] );

		// Verify provider settings were updated.
		$this->assertEquals( 'sk-new-key', $merged['openai_api_key'] );
	}

	/**
	 * Test with empty protection layer to verify it catches cross-tab empty values.
	 */
	public function test_empty_value_protection_filters_cross_tab_empties() {
		// Set up initial state.
		$initial_settings = array(
			'openai_api_key' => 'sk-test-key',
			'gemini_api_key' => 'AIza-test-key',
			'enable_logging' => true,
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Simulate a scenario where somehow empty provider keys leaked into general sanitized output.
		// This shouldn't happen with proper subtab isolation, but we're testing the safety net.
		$sanitized_new = array(
			'enable_logging' => false, // User unchecked this (legitimate change).
			'openai_api_key' => '', // This shouldn't be here (cross-tab contamination).
		);

		// Get existing settings.
		$existing = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

		// Manually apply the sensitive keys protection (simulating handle_save_settings logic).
		$sensitive_keys = array(
			'openai_api_key',
			'gemini_api_key',
			'anthropic_api_key',
			'huggingface_api_key',
			'ollama_endpoint_url',
			'lm_studio_endpoint_url',
		);

		foreach ( $sensitive_keys as $key ) {
			if ( isset( $sanitized_new[ $key ] ) && empty( $sanitized_new[ $key ] ) ) {
				unset( $sanitized_new[ $key ] );
			}
		}

		// After sensitive keys filter, openai_api_key should be removed.
		$this->assertArrayNotHasKey( 'openai_api_key', $sanitized_new, 'Empty openai_api_key should be filtered out' );

		// Merge and verify.
		$merged = array_merge( $existing, $sanitized_new );

		// Verify the original API key is preserved.
		$this->assertEquals( 'sk-test-key', $merged['openai_api_key'], 'Original API key should be preserved' );

		// Verify the legitimate change was applied.
		$this->assertFalse( $merged['enable_logging'], 'enable_logging should be updated to false' );
	}

	/**
	 * Test is_setting_in_tab method to ensure it correctly identifies field membership.
	 */
	public function test_is_setting_in_tab_correctly_identifies_fields() {
		$dashboard = new WP_MCP_AI_Settings_Dashboard();
		$reflection = new ReflectionClass( $dashboard );
		$method = $reflection->getMethod( 'is_setting_in_tab' );
		$method->setAccessible( true );

		// Provider fields should belong to providers tab.
		$this->assertTrue(
			$method->invoke( $dashboard, 'openai_api_key', 'providers' ),
			'openai_api_key should belong to providers tab'
		);
		$this->assertTrue(
			$method->invoke( $dashboard, 'enable_openai', 'providers' ),
			'enable_openai should belong to providers tab'
		);
		$this->assertTrue(
			$method->invoke( $dashboard, 'gemini_api_key', 'providers' ),
			'gemini_api_key should belong to providers tab'
		);

		// Provider fields should NOT belong to general tab.
		$this->assertFalse(
			$method->invoke( $dashboard, 'openai_api_key', 'general' ),
			'openai_api_key should NOT belong to general tab'
		);
		$this->assertFalse(
			$method->invoke( $dashboard, 'enable_openai', 'general' ),
			'enable_openai should NOT belong to general tab'
		);

		// General fields should belong to general tab.
		$this->assertTrue(
			$method->invoke( $dashboard, 'default_provider', 'general' ),
			'default_provider should belong to general tab'
		);
		$this->assertTrue(
			$method->invoke( $dashboard, 'max_history_messages', 'general' ),
			'max_history_messages should belong to general tab'
		);

		// General fields should NOT belong to providers tab.
		$this->assertFalse(
			$method->invoke( $dashboard, 'default_provider', 'providers' ),
			'default_provider should NOT belong to providers tab'
		);
	}
}
