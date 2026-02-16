<?php
/**
 * Tests for embedded provider appearing in dropdown.
 *
 * @package WP_MCP_AI
 */

/**
 * Test embedded provider dropdown functionality.
 */
class Test_Embedded_Provider_Dropdown extends WP_UnitTestCase {

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Clear settings cache.
		delete_option( 'wp_mcp_ai_settings' );

		// Set up admin user.
		$this->admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user );
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		// Clean up settings.
		delete_option( 'wp_mcp_ai_settings' );

		parent::tearDown();
	}

	/**
	 * Helper method to capture output of a callback.
	 *
	 * @param callable $callback Callback to capture output from.
	 * @return string Captured output.
	 */
	private function capture_field_output( $callback ) {
		ob_start();
		call_user_func( $callback, array() );
		return ob_get_clean();
	}

	/**
	 * Test that embedded provider is included in the fallback provider list.
	 */
	public function test_general_section_fallback_includes_embedded() {
		// Skip if base version.
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			$this->markTestSkipped( 'Embedded LLM is not available in base version.' );
		}

		// Clear any settings to force fallback.
		delete_option( 'wp_mcp_ai_settings' );

		// Create general section instance.
		$section = new WP_MCP_AI_Section_General();
		$fields  = $section->get_fields();

		// Get provider options from default_provider field.
		$this->assertArrayHasKey( 'default_provider', $fields );
		$provider_options = $fields['default_provider']['options'];

		// Check that embedded is included in the fallback list.
		$this->assertArrayHasKey( 'embedded', $provider_options, 'Embedded provider should be in fallback provider list' );
		$this->assertEquals( 'Embedded LLM', $provider_options['embedded'], 'Embedded provider should have correct label' );
	}

	/**
	 * Test that embedded provider appears when enabled and configured.
	 */
	public function test_embedded_appears_when_enabled_and_configured() {
		// Skip if base version.
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			$this->markTestSkipped( 'Embedded LLM is not available in base version.' );
		}

		// Configure embedded provider with enable flag and model selected.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_embedded' => true,
				'embedded_model'  => 'Llama-3.2-1B-Instruct-q4f16_1-MLC', // A model from the available WebLLM models list.
			)
		);

		// Get available providers using the filtering method.
		$available_providers = WP_MCP_AI_Admin_Settings::get_available_providers();

		// Check that embedded is in the available providers list.
		$this->assertArrayHasKey( 'embedded', $available_providers, 'Embedded provider should appear when enabled and configured' );
		$this->assertEquals( 'Embedded LLM', $available_providers['embedded'], 'Embedded provider should have correct label' );
	}

	/**
	 * Test that embedded provider does NOT appear when enabled but not configured.
	 */
	public function test_embedded_not_shown_when_enabled_but_no_model() {
		// Skip if base version.
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			$this->markTestSkipped( 'Embedded LLM is not available in base version.' );
		}

		// Enable embedded but don't set a model.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_embedded' => true,
				'embedded_model'  => '', // No model selected.
			)
		);

		// Get available providers.
		$available_providers = WP_MCP_AI_Admin_Settings::get_available_providers();

		// Check that embedded is NOT in the available providers list.
		$this->assertArrayNotHasKey( 'embedded', $available_providers, 'Embedded provider should NOT appear when enabled but no model selected' );
	}

	/**
	 * Test that embedded provider does NOT appear when disabled.
	 */
	public function test_embedded_not_shown_when_disabled() {
		// Skip if base version.
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			$this->markTestSkipped( 'Embedded LLM is not available in base version.' );
		}

		// Disable embedded even with model selected.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_embedded' => false,
				'embedded_model'  => 'Llama-3.2-1B-Instruct-q4f16_1-MLC',
			)
		);

		// Get available providers.
		$available_providers = WP_MCP_AI_Admin_Settings::get_available_providers();

		// Check that embedded is NOT in the available providers list.
		$this->assertArrayNotHasKey( 'embedded', $available_providers, 'Embedded provider should NOT appear when disabled' );
	}

	/**
	 * Test that the general section uses dynamic provider filtering.
	 */
	public function test_general_section_uses_dynamic_filtering() {
		// Skip if base version.
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			$this->markTestSkipped( 'Embedded LLM is not available in base version.' );
		}

		// Enable only embedded provider with a model.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_openai'   => false,
				'enable_gemini'   => false,
				'enable_embedded' => true,
				'embedded_model'  => 'Llama-3.2-1B-Instruct-q4f16_1-MLC',
			)
		);

		// Create general section instance.
		$section = new WP_MCP_AI_Section_General();
		$fields  = $section->get_fields();

		// Get provider options.
		$provider_options = $fields['default_provider']['options'];

		// When using dynamic filtering, only embedded should appear.
		// (OpenAI and Gemini are disabled and have no API keys).
		$this->assertArrayHasKey( 'embedded', $provider_options, 'Embedded provider should appear in general section' );

		// Since we disabled OpenAI and it has no API key, it should not appear.
		// However, if the fallback is triggered (empty result), all will appear.
		// So we need to check if we're getting filtered results or fallback.
		$available_from_model_config = WP_MCP_AI_Model_Config::get_available_providers();

		if ( ! empty( $available_from_model_config ) ) {
			// Dynamic filtering is working - check it only returns enabled providers.
			$this->assertEquals( $available_from_model_config, $provider_options, 'General section should use same filtering as Model_Config' );
		}
	}

	/**
	 * Test that Model_Config properly filters embedded provider.
	 */
	public function test_model_config_filters_embedded_provider() {
		// Skip if base version.
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			$this->markTestSkipped( 'Embedded LLM is not available in base version.' );
		}

		// Test 1: Enabled with model - should appear.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_embedded' => true,
				'embedded_model'  => 'Llama-3.2-1B-Instruct-q4f16_1-MLC',
			)
		);

		$providers = WP_MCP_AI_Model_Config::get_available_providers();
		$this->assertArrayHasKey( 'embedded', $providers, 'Embedded should appear when enabled with model' );

		// Test 2: Enabled without model - should NOT appear.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_embedded' => true,
				'embedded_model'  => '',
			)
		);

		$providers = WP_MCP_AI_Model_Config::get_available_providers();
		$this->assertArrayNotHasKey( 'embedded', $providers, 'Embedded should NOT appear when enabled without model' );

		// Test 3: Disabled with model - should NOT appear.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_embedded' => false,
				'embedded_model'  => 'Llama-3.2-1B-Instruct-q4f16_1-MLC',
			)
		);

		$providers = WP_MCP_AI_Model_Config::get_available_providers();
		$this->assertArrayNotHasKey( 'embedded', $providers, 'Embedded should NOT appear when disabled' );

		// Test 4: Not set (defaults to true when Pro is active) - should appear with default model.
		update_option( 'wp_mcp_ai_settings', array() );

		$providers = WP_MCP_AI_Model_Config::get_available_providers();
		$this->assertArrayHasKey( 'embedded', $providers, 'Embedded should auto-enable when Pro is active and settings not set (defaults to true with default model)' );
	}

	/**
	 * Test that embedded provider auto-enables when Pro is active and no settings exist.
	 */
	public function test_embedded_auto_enables_on_fresh_install() {
		// Skip if base version.
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			$this->markTestSkipped( 'Embedded LLM is not available in base version.' );
		}

		// Clear all settings to simulate fresh install.
		delete_option( 'wp_mcp_ai_settings' );

		// Get available providers.
		$providers = WP_MCP_AI_Model_Config::get_available_providers();

		// Check that embedded is auto-enabled with default model.
		$this->assertArrayHasKey( 'embedded', $providers, 'Embedded provider should auto-enable on fresh install when Pro is active' );
	}

	/**
	 * Test that embedded provider appears when base+pro are both active.
	 *
	 * This test verifies that when WP_MCP_AI_BASE_VERSION is true but
	 * WP_MCP_AI_PRO_VERSION is also defined, the embedded provider is available.
	 */
	public function test_embedded_appears_in_base_plus_pro_mode() {
		// This test only makes sense when Pro is actually active.
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'This test requires Pro addon to be active.' );
		}

		// Configure embedded provider with enable flag and model selected.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_embedded' => true,
				'embedded_model'  => 'Llama-3.2-1B-Instruct-q4f16_1-MLC',
			)
		);

		// Test Model_Config::get_available_providers().
		$providers = WP_MCP_AI_Model_Config::get_available_providers();
		$this->assertArrayHasKey(
			'embedded',
			$providers,
			'Embedded provider should appear when base+pro are active (get_available_providers)'
		);

		// Test Model_Service::get_models_for_provider().
		$model_service = new WP_MCP_AI_Model_Service();
		$models        = $model_service->get_models_for_provider( 'embedded' );
		$this->assertNotEmpty(
			$models,
			'Embedded provider should return models when base+pro are active (get_models_for_provider)'
		);
		$this->assertArrayHasKey(
			'Hermes-2-Pro-Llama-3-8B-q4f16_1-MLC',
			$models,
			'Embedded provider should include Hermes model when base+pro are active'
		);
	}
}
