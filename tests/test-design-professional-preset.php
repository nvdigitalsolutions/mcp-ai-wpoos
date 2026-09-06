<?php
/**
 * Tests for Design Professional tool preset.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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

		// Image generation.
		$this->assertContains( 'cloudflareai_text_to_image', $tools, 'Should include Cloudflare AI image generation' );

		// Image editing.
		$this->assertContains( 'edit_gemini_image', $tools, 'Should include Gemini image editing' );
		$this->assertContains( 'edit_openai_image', $tools, 'Should include OpenAI image editing' );

		// Image manipulation tools.
		$this->assertContains( 'resize_image', $tools, 'Should include image resizing' );
		$this->assertContains( 'crop_image', $tools, 'Should include image cropping' );
		$this->assertContains( 'rotate_image', $tools, 'Should include image rotation' );
		$this->assertContains( 'convert_image_format', $tools, 'Should include format conversion' );
		$this->assertContains( 'remove_background', $tools, 'Should include background removal' );

		// Video tools.
		$this->assertContains( 'generate_veo_video', $tools, 'Should include video generation' );
		$this->assertContains( 'generate_sora_video', $tools, 'Should include Sora video generation' );
		$this->assertContains( 'check_video_status', $tools, 'Should include video status checking' );
		$this->assertContains( 'analyze_video', $tools, 'Should include video analysis' );
		$this->assertContains( 'extract_video_frames', $tools, 'Should include frame extraction' );

		// Vision tools.
		$this->assertContains( 'vision_object_localization', $tools, 'Should include object localization' );
		$this->assertContains( 'vision_product_search', $tools, 'Should include product search' );

		// Audio.
		$this->assertContains( 'generate_music', $tools, 'Should include music generation' );

		// Elementor.
		$this->assertContains( 'get_elementor_templates', $tools, 'Should include Elementor templates' );
		$this->assertContains( 'import_elementor_template_kit', $tools, 'Should include template kit import' );

		// Architectural Design (Pro) — Phase A.
		$this->assertContains( 'generate_floor_plan', $tools, 'Should include floor plan generation' );
		$this->assertContains( 'generate_3d_model', $tools, 'Should include 3D model generation' );

		// Architectural Design (Pro) — Phase B.
		$this->assertContains( 'calculate_wind_loads', $tools, 'Should include wind load calculation' );
		$this->assertContains( 'check_us_ibc_irc_compliance', $tools, 'Should include IBC/IRC compliance' );

		// Architectural Design (Pro) — Phase C.
		$this->assertContains( 'score_leed_v4_certification', $tools, 'Should include LEED scoring' );

		// Architectural Design (Pro) — Phase D.
		$this->assertContains( 'export_to_ifc', $tools, 'Should include IFC export' );

		// Architectural Design (Pro) — Phase E.
		$this->assertContains( 'search_architectural_precedents', $tools, 'Should include precedent search' );

		// Harmonization Sub-Toolkit (Pro).
		$this->assertContains( 'generate_scene_background', $tools, 'Should include scene background generation' );
		$this->assertContains( 'harmonize_color', $tools, 'Should include color harmonization' );
		$this->assertContains( 'harmonize_batch', $tools, 'Should include batch harmonization' );
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

		// The preset deliberately mixes base tools with Pro-toolkit tools that
		// are only registered when their toolkits are enabled (architectural
		// design, image production) or when Elementor is active. Exempt those
		// so the check still catches base-tool drift.
		$pro_conditional_tools = array(
			'elementor',
			'get_elementor_templates',
			'import_elementor_template_kit',
			// Architectural Design (Pro) — Phases A–E.
			'generate_floor_plan',
			'optimize_space_layout',
			'create_floor_plan_variations',
			'convert_sketch_to_floor_plan',
			'generate_3d_model',
			'render_architectural_view',
			'create_walkthrough_animation',
			'generate_construction_drawings',
			'generate_detail_drawings',
			'export_architectural_documents',
			'check_building_code_compliance',
			'analyze_structural_feasibility',
			'calculate_sustainability_metrics',
			'generate_material_schedule',
			'estimate_construction_cost',
			'generate_construction_timeline',
			'calculate_wind_loads',
			'calculate_seismic_loads',
			'validate_setbacks_and_far',
			'check_uda_planning_compliance',
			'check_jnbc_hurricane_compliance',
			'check_us_ibc_irc_compliance',
			'generate_compliance_dossier',
			'analyze_natural_ventilation',
			'analyze_daylight_and_solar_gain',
			'simulate_thermal_comfort',
			'score_edge_certification',
			'score_leed_v4_certification',
			'generate_bill_of_quantities',
			'propose_value_engineering_options',
			'import_dwg_floor_plan',
			'import_ifc_model',
			'export_to_ifc',
			'export_to_gbxml',
			'generate_bim_execution_plan',
			'manage_rfi_log',
			'manage_submittal_log',
			'manage_architectural_precedents',
			'search_architectural_precedents',
			// Harmonization Sub-Toolkit (Pro - Image Production).
			'generate_scene_background',
			'adapt_background_for_subject',
			'outpaint_background',
			'refine_subject_matte',
			'auto_clean_white_background',
			'harmonize_color',
			'relight_subject',
			'generate_shadow',
			'generate_reflection',
			'refine_composite_boundary',
			'analyze_scene_lighting',
			'suggest_placement',
			'harmonize_image_into_background',
			'harmonize_batch',
		);

		$missing_tools = array();
		foreach ( $tools as $tool_slug ) {
			if ( in_array( $tool_slug, $pro_conditional_tools, true ) ) {
				continue;
			}
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
			if ( 'generate_veo_video' !== $tool_slug ) {
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
		$all_tools  = $registry->get_tools();
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
