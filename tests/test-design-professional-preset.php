<?php
/**
 * Tests for Design Professional tool preset.
 *
 * @package WP_MCP_AI
 */

/**
 * Test design professional preset functionality.
 */
class Test_Design_Professional_Preset extends WP_UnitTestCase {

	/**
	 * Test that design_professional preset exists.
	 */
	public function test_design_professional_preset_exists() {
		$assistant_cpt = new WP_MCP_AI_Assistant_CPT( WP_MCP_AI_Tool_Registry::get_instance() );
		
		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $assistant_cpt );
		$method     = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );
		
		$presets = $method->invoke( $assistant_cpt );
		
		$this->assertArrayHasKey( 'design_professional', $presets, 'Design professional preset should exist' );
	}

	/**
	 * Test that design_professional preset has correct structure.
	 */
	public function test_design_professional_preset_structure() {
		$assistant_cpt = new WP_MCP_AI_Assistant_CPT( WP_MCP_AI_Tool_Registry::get_instance() );
		
		$reflection = new ReflectionClass( $assistant_cpt );
		$method     = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );
		
		$presets = $method->invoke( $assistant_cpt );
		$preset  = $presets['design_professional'];
		
		$this->assertArrayHasKey( 'name', $preset, 'Preset should have name' );
		$this->assertArrayHasKey( 'description', $preset, 'Preset should have description' );
		$this->assertArrayHasKey( 'tools', $preset, 'Preset should have tools array' );
		
		$this->assertNotEmpty( $preset['name'], 'Preset name should not be empty' );
		$this->assertNotEmpty( $preset['description'], 'Preset description should not be empty' );
		$this->assertIsArray( $preset['tools'], 'Preset tools should be an array' );
		$this->assertNotEmpty( $preset['tools'], 'Preset tools should not be empty' );
	}

	/**
	 * Test that design_professional preset includes expected tools.
	 */
	public function test_design_professional_preset_includes_expected_tools() {
		$assistant_cpt = new WP_MCP_AI_Assistant_CPT( WP_MCP_AI_Tool_Registry::get_instance() );
		
		$reflection = new ReflectionClass( $assistant_cpt );
		$method     = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );
		
		$presets = $method->invoke( $assistant_cpt );
		$tools   = $presets['design_professional']['tools'];
		
		// Image generation tools.
		$this->assertContains( 'generate_openai_image', $tools, 'Should include OpenAI image generation' );
		$this->assertContains( 'generate_gemini_image', $tools, 'Should include Gemini image generation' );
		$this->assertContains( 'edit_gemini_image', $tools, 'Should include Gemini image editing' );
		
		// Image manipulation tools.
		$this->assertContains( 'resize_image', $tools, 'Should include image resizing' );
		$this->assertContains( 'crop_image', $tools, 'Should include image cropping' );
		$this->assertContains( 'rotate_image', $tools, 'Should include image rotation' );
		$this->assertContains( 'convert_image_format', $tools, 'Should include format conversion' );
		
		// Video tools.
		$this->assertContains( 'generate_veo_video', $tools, 'Should include video generation' );
		$this->assertContains( 'check_video_status', $tools, 'Should include video status checking' );
		$this->assertContains( 'analyze_video', $tools, 'Should include video analysis' );
		
		// Vision tools.
		$this->assertContains( 'vision_object_localization', $tools, 'Should include object localization' );
		$this->assertContains( 'vision_product_search', $tools, 'Should include product search' );
		
		// Data visualization.
		$this->assertContains( 'create_chart', $tools, 'Should include chart creation' );
		
		// Audio.
		$this->assertContains( 'generate_music', $tools, 'Should include music generation' );
	}

	/**
	 * Test that all tools in design_professional preset are registered.
	 */
	public function test_design_professional_preset_tools_are_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();
		
		$assistant_cpt = new WP_MCP_AI_Assistant_CPT( $registry );
		
		$reflection = new ReflectionClass( $assistant_cpt );
		$method     = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );
		
		$presets = $method->invoke( $assistant_cpt );
		$tools   = $presets['design_professional']['tools'];
		
		$missing_tools = array();
		foreach ( $tools as $tool_slug ) {
			if ( ! $registry->is_tool_registered( $tool_slug ) ) {
				$missing_tools[] = $tool_slug;
			}
		}
		
		$this->assertEmpty(
			$missing_tools,
			'All design professional preset tools should be registered. Missing: ' . implode( ', ', $missing_tools )
		);
	}

	/**
	 * Test that design tools have appropriate token multipliers.
	 */
	public function test_design_tools_have_token_multipliers() {
		$multipliers = WP_MCP_AI_Tool_Token_Limits::get_tool_multipliers();
		
		// High-cost tools should have multipliers >= 3.0.
		$high_cost_tools = array(
			'generate_openai_image' => 3.0,
			'generate_gemini_image' => 3.0,
			'generate_veo_video'    => 5.0,
			'generate_music'        => 3.5,
		);
		
		foreach ( $high_cost_tools as $tool_slug => $expected_min ) {
			$this->assertArrayHasKey( $tool_slug, $multipliers, "$tool_slug should have a token multiplier" );
			$this->assertGreaterThanOrEqual(
				$expected_min,
				$multipliers[ $tool_slug ],
				"$tool_slug multiplier should be at least {$expected_min}x"
			);
		}
		
		// Medium-cost tools should have multipliers >= 2.0.
		$medium_cost_tools = array(
			'edit_gemini_image',
			'analyze_video',
			'vision_object_localization',
			'vision_product_search',
		);
		
		foreach ( $medium_cost_tools as $tool_slug ) {
			if ( isset( $multipliers[ $tool_slug ] ) ) {
				$this->assertGreaterThanOrEqual(
					2.0,
					$multipliers[ $tool_slug ],
					"$tool_slug multiplier should be at least 2.0x"
				);
			}
		}
	}

	/**
	 * Test that video generation has highest multiplier.
	 */
	public function test_video_generation_has_highest_multiplier() {
		$multipliers = WP_MCP_AI_Tool_Token_Limits::get_tool_multipliers();
		
		$this->assertArrayHasKey( 'generate_veo_video', $multipliers, 'Video generation should have multiplier' );
		
		// Video should have the highest multiplier among design tools.
		$video_multiplier = $multipliers['generate_veo_video'];
		
		foreach ( $multipliers as $tool_slug => $multiplier ) {
			if ( $tool_slug !== 'generate_veo_video' ) {
				$this->assertGreaterThanOrEqual(
					$multiplier,
					$video_multiplier,
					'Video generation should have highest or equal multiplier'
				);
			}
		}
	}

	/**
	 * Test that design tools can be retrieved by assistant.
	 */
	public function test_design_tools_accessible_to_assistant() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();
		
		// Get all registered tools.
		$all_tools = $registry->get_tools();
		$tool_slugs = array();
		
		foreach ( $all_tools as $tool ) {
			if ( is_object( $tool ) && method_exists( $tool, 'get_slug' ) ) {
				$tool_slugs[] = $tool->get_slug();
			}
		}
		
		// Key design tools should be accessible.
		$key_design_tools = array(
			'generate_openai_image',
			'generate_gemini_image',
			'generate_veo_video',
			'create_chart',
		);
		
		foreach ( $key_design_tools as $tool_slug ) {
			$this->assertContains(
				$tool_slug,
				$tool_slugs,
				"$tool_slug should be accessible to assistants"
			);
		}
	}

	/**
	 * Test that design preset can be applied via filter.
	 */
	public function test_design_preset_filterable() {
		$filter_called = false;
		
		add_filter(
			'wp_mcp_ai_tool_presets',
			function ( $presets ) use ( &$filter_called ) {
				$filter_called = true;
				$this->assertArrayHasKey( 'design_professional', $presets, 'Filter should receive design preset' );
				return $presets;
			}
		);
		
		$assistant_cpt = new WP_MCP_AI_Assistant_CPT( WP_MCP_AI_Tool_Registry::get_instance() );
		
		$reflection = new ReflectionClass( $assistant_cpt );
		$method     = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );
		
		$method->invoke( $assistant_cpt );
		
		$this->assertTrue( $filter_called, 'wp_mcp_ai_tool_presets filter should be called' );
		
		remove_all_filters( 'wp_mcp_ai_tool_presets' );
	}

	/**
	 * Test that design preset description mentions key features.
	 */
	public function test_design_preset_description_is_descriptive() {
		$assistant_cpt = new WP_MCP_AI_Assistant_CPT( WP_MCP_AI_Tool_Registry::get_instance() );
		
		$reflection = new ReflectionClass( $assistant_cpt );
		$method     = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );
		
		$presets     = $method->invoke( $assistant_cpt );
		$description = $presets['design_professional']['description'];
		
		// Description should mention key design capabilities.
		$this->assertStringContainsString( 'design', strtolower( $description ), 'Should mention design' );
		
		// Should mention at least one of the key features.
		$has_feature = (
			stripos( $description, 'CAD' ) !== false ||
			stripos( $description, 'rendering' ) !== false ||
			stripos( $description, '3D' ) !== false ||
			stripos( $description, 'branding' ) !== false ||
			stripos( $description, 'visual' ) !== false
		);
		
		$this->assertTrue( $has_feature, 'Description should mention at least one design feature' );
	}
}
