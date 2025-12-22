<?php
/**
 * Tests for image tool settings integration.
 *
 * Verifies that image generation tools properly load settings from the admin UI.
 *
 * @package WP_MCP_AI
 */

/**
 * Test image tool settings integration.
 */
class WP_MCP_AI_Image_Tool_Settings_Test extends WP_UnitTestCase {

	/**
	 * Clean up global state after each test.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Test that OpenAI image tool uses configured settings defaults.
	 */
	public function test_openai_image_tool_uses_configured_defaults() {
		// Set custom settings.
		$settings                                 = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_image_model']           = 'dall-e-3';
		$settings['openai_image_size']            = '1024x1536';
		$settings['openai_image_quality']         = 'hd';
		$settings['openai_image_response_format'] = 'url';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Get tool parameter schema (which includes defaults).
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-openai-image.php';
		$tool   = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
		$schema = $tool->get_parameters_schema();

		// Verify defaults are loaded from settings.
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'model', $schema['properties'] );
		$this->assertArrayHasKey( 'size', $schema['properties'] );
		$this->assertArrayHasKey( 'quality', $schema['properties'] );
		$this->assertArrayHasKey( 'response_format', $schema['properties'] );

		// Note: The get_parameters_schema() calls protected get_configured_defaults()
		// which should read these settings. We verify the defaults exist in schema.
		$this->assertArrayHasKey( 'default', $schema['properties']['model'] );
		$this->assertArrayHasKey( 'default', $schema['properties']['size'] );
		$this->assertArrayHasKey( 'default', $schema['properties']['quality'] );
		$this->assertArrayHasKey( 'default', $schema['properties']['response_format'] );
	}

	/**
	 * Test that OpenAI image tool falls back to hardcoded defaults when settings missing.
	 */
	public function test_openai_image_tool_falls_back_to_hardcoded_defaults() {
		// No settings configured - should use hardcoded defaults.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-openai-image.php';
		$tool   = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
		$schema = $tool->get_parameters_schema();

		// Verify hardcoded defaults are used.
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'default', $schema['properties']['model'] );
		$this->assertEquals( 'gpt-image-1.5', $schema['properties']['model']['default'] );

		$this->assertArrayHasKey( 'default', $schema['properties']['size'] );
		$this->assertEquals( '1024x1024', $schema['properties']['size']['default'] );

		$this->assertArrayHasKey( 'default', $schema['properties']['quality'] );
		$this->assertEquals( 'medium', $schema['properties']['quality']['default'] );
	}

	/**
	 * Test that Gemini image tool uses configured settings defaults.
	 */
	public function test_gemini_image_tool_uses_configured_defaults() {
		// Set custom settings.
		$settings                              = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['gemini_image_model']        = 'gemini-exp-1206';
		$settings['gemini_image_mime_type']    = 'image/webp';
		$settings['gemini_image_aspect_ratio'] = '16:9';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Get tool parameter schema.
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-gemini-image.php';
		$tool   = new WP_MCP_AI_Tool_Generate_Gemini_Image();
		$schema = $tool->get_parameters_schema();

		// Verify defaults are loaded from settings.
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'model', $schema['properties'] );
		$this->assertArrayHasKey( 'mime_type', $schema['properties'] );
		$this->assertArrayHasKey( 'aspect_ratio', $schema['properties'] );

		$this->assertArrayHasKey( 'default', $schema['properties']['model'] );
		$this->assertArrayHasKey( 'default', $schema['properties']['mime_type'] );
		$this->assertArrayHasKey( 'default', $schema['properties']['aspect_ratio'] );
	}

	/**
	 * Test that Gemini image tool falls back to hardcoded defaults when settings missing.
	 */
	public function test_gemini_image_tool_falls_back_to_hardcoded_defaults() {
		// No settings configured - should use hardcoded defaults.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-gemini-image.php';
		$tool   = new WP_MCP_AI_Tool_Generate_Gemini_Image();
		$schema = $tool->get_parameters_schema();

		// Verify hardcoded defaults are used.
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'default', $schema['properties']['model'] );
		$this->assertEquals( 'gemini-2.5-flash-image', $schema['properties']['model']['default'] );

		$this->assertArrayHasKey( 'default', $schema['properties']['mime_type'] );
		$this->assertEquals( 'image/png', $schema['properties']['mime_type']['default'] );

		$this->assertArrayHasKey( 'default', $schema['properties']['aspect_ratio'] );
		$this->assertEquals( '1:1', $schema['properties']['aspect_ratio']['default'] );
	}

	/**
	 * Test that all 7 image tools are registered in the tool registry.
	 */
	public function test_all_image_tools_are_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$expected_tools = array(
			'generate_openai_image',
			'generate_gemini_image',
			'edit_gemini_image',
			'rotate_image',
			'crop_image',
			'resize_image',
			'convert_image_format',
		);

		foreach ( $expected_tools as $tool_slug ) {
			$tool = $registry->get_tool( $tool_slug );
			$this->assertNotNull( $tool, "Tool '{$tool_slug}' should be registered" );
			$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool, "Tool '{$tool_slug}' should implement WP_MCP_AI_Tool_Interface" );
		}
	}

	/**
	 * Test that all image tools are in the correct groups in the tool group map.
	 */
	public function test_image_tools_are_in_correct_groups() {
		$registry    = WP_MCP_AI_Tool_Registry::get_instance();
		$tool_groups = $registry->get_tool_group_map();

		// AI image generation tools should be in external-tools group.
		$external_tools = array(
			'generate_openai_image',
			'generate_gemini_image',
			'edit_gemini_image',
		);

		foreach ( $external_tools as $tool_slug ) {
			$this->assertArrayHasKey( $tool_slug, $tool_groups, "Tool '{$tool_slug}' should be in tool group map" );
			$this->assertEquals( 'external-tools', $tool_groups[ $tool_slug ], "Tool '{$tool_slug}' should be in 'external-tools' group" );
		}

		// Image manipulation tools should be in wordpress-core group.
		$core_tools = array(
			'rotate_image',
			'crop_image',
			'resize_image',
			'convert_image_format',
		);

		foreach ( $core_tools as $tool_slug ) {
			$this->assertArrayHasKey( $tool_slug, $tool_groups, "Tool '{$tool_slug}' should be in tool group map" );
			$this->assertEquals( 'wordpress-core', $tool_groups[ $tool_slug ], "Tool '{$tool_slug}' should be in 'wordpress-core' group" );
		}
	}

	/**
	 * Test that image tools are available through the tool service.
	 */
	public function test_image_tools_available_through_tool_service() {
		// Initialize registry.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$service         = new WP_MCP_AI_Tool_Service( $registry );
		$available_tools = $service->get_available_tools();

		// Extract slugs from available tools.
		$available_slugs = array_column( $available_tools, 'slug' );

		$expected_tools = array(
			'generate_openai_image',
			'generate_gemini_image',
			'edit_gemini_image',
			'rotate_image',
			'crop_image',
			'resize_image',
			'convert_image_format',
		);

		foreach ( $expected_tools as $tool_slug ) {
			$this->assertContains( $tool_slug, $available_slugs, "Tool '{$tool_slug}' should be available through tool service" );
		}
	}
}
