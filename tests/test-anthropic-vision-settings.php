<?php
/**
 * Tests for multi-provider vision tools and settings.
 *
 * Ensures that the new multi-provider vision tools (analyze_image, extract_image_text)
 * are properly registered and Anthropic vision settings work correctly.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that multi-provider vision tools and settings work correctly.
 */
class WP_MCP_AI_Vision_Tools_Settings_Test extends WP_UnitTestCase {

	/**
	 * Test that Anthropic vision settings can be saved and retrieved.
	 */
	public function test_anthropic_vision_settings_persistence() {
		// Set up Anthropic vision settings.
		$settings = array(
			'enable_anthropic'           => true,
			'anthropic_api_key'          => 'sk-ant-test-key',
			'anthropic_model'            => 'claude-3-5-sonnet-20241022',
			'anthropic_vision_model'     => 'claude-3-5-sonnet-20241022',
			'anthropic_max_image_tokens' => '2048',
		);

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Retrieve settings.
		$retrieved = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

		// Assert vision settings are present.
		$this->assertSame(
			'claude-3-5-sonnet-20241022',
			$retrieved['anthropic_vision_model'],
			'anthropic_vision_model should be saved'
		);

		$this->assertSame(
			'2048',
			$retrieved['anthropic_max_image_tokens'],
			'anthropic_max_image_tokens should be saved'
		);
	}

	/**
	 * Test that Anthropic vision settings are included in provider tabs.
	 */
	public function test_anthropic_vision_fields_in_provider_tabs() {
		$section = new WP_MCP_AI_Section_Providers();

		// Get provider tabs.
		$tabs = $section->get_provider_tabs();

		// Assert Anthropic tab exists.
		$this->assertArrayHasKey( 'anthropic', $tabs, 'Anthropic tab should exist' );

		// Assert vision fields are included in Anthropic tab.
		$anthropic_fields = $tabs['anthropic']['fields'];

		$this->assertContains(
			'anthropic_vision_model',
			$anthropic_fields,
			'anthropic_vision_model should be in Anthropic tab fields'
		);

		$this->assertContains(
			'anthropic_max_image_tokens',
			$anthropic_fields,
			'anthropic_max_image_tokens should be in Anthropic tab fields'
		);
	}

	/**
	 * Test that multi-provider vision tools are registered.
	 */
	public function test_multi_provider_vision_tools_registered() {
		// Get tool registry.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Check if multi-provider vision tools are registered.
		$analyze_tool = $registry->get_tool( 'analyze_image' );
		$extract_tool = $registry->get_tool( 'extract_image_text' );

		$this->assertNotNull(
			$analyze_tool,
			'analyze_image tool should be registered'
		);

		$this->assertNotNull(
			$extract_tool,
			'extract_image_text tool should be registered'
		);

		// Check tool names.
		if ( $analyze_tool ) {
			$this->assertSame(
				'Analyze Image',
				$analyze_tool->get_name(),
				'analyze_image should have correct name'
			);
		}

		if ( $extract_tool ) {
			$this->assertSame(
				'Extract Text from Image (OCR)',
				$extract_tool->get_name(),
				'extract_image_text should have correct name'
			);
		}
	}

	/**
	 * Test that saving Anthropic vision settings preserves other provider settings.
	 */
	public function test_saving_anthropic_preserves_other_providers() {
		// Set up initial settings with multiple providers.
		$initial_settings = array(
			'enable_openai'              => true,
			'openai_api_key'             => 'sk-test-openai-key',
			'default_model'              => 'gpt-4o',
			'enable_anthropic'           => true,
			'anthropic_api_key'          => 'sk-ant-test-key',
			'anthropic_model'            => 'claude-3-5-sonnet-20241022',
			'anthropic_vision_model'     => 'claude-3-5-sonnet-20241022',
			'anthropic_max_image_tokens' => '1568',
		);

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Simulate saving the Anthropic subtab with modified settings.
		$dashboard       = new WP_MCP_AI_Settings_Dashboard();
		$posted_settings = array(
			'enable_anthropic'           => '1',
			'anthropic_api_key'          => 'sk-ant-test-key-updated',
			'anthropic_model'            => 'claude-3-5-haiku-20241022',
			'anthropic_vision_model'     => 'claude-3-5-haiku-20241022',
			'anthropic_max_image_tokens' => '2048',
		);

		// Sanitize with providers tab context.
		$sanitized = $dashboard->sanitize_settings( $posted_settings, 'providers' );

		// Merge with existing settings as the dashboard does.
		$existing = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$merged   = array_merge( $existing, $sanitized );

		// OpenAI settings should be preserved.
		$this->assertTrue(
			$merged['enable_openai'],
			'enable_openai should remain when saving Anthropic subtab'
		);

		$this->assertSame(
			'sk-test-openai-key',
			$merged['openai_api_key'],
			'openai_api_key should be preserved when saving Anthropic subtab'
		);

		// Anthropic vision settings should be updated.
		$this->assertSame(
			'claude-3-5-haiku-20241022',
			$merged['anthropic_vision_model'],
			'anthropic_vision_model should be updated'
		);

		$this->assertSame(
			'2048',
			$merged['anthropic_max_image_tokens'],
			'anthropic_max_image_tokens should be updated'
		);
	}
}
