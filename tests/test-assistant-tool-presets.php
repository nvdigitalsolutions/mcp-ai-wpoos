<?php
/**
 * Tests for assistant tool presets functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Tests for assistant tool presets functionality.
 */
class WP_MCP_AI_Assistant_Tool_Presets_Test extends WP_UnitTestCase {

	/**
	 * Test that tool presets are properly defined.
	 */
	public function test_tool_presets_structure() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Create a reflection class to access protected method.
		$assistant_cpt = new WP_MCP_AI_Assistant_CPT( $registry );
		$reflection    = new ReflectionClass( $assistant_cpt );
		$method        = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );

		$presets = $method->invoke( $assistant_cpt );

		// Verify presets is an array.
		$this->assertIsArray( $presets );
		$this->assertNotEmpty( $presets, 'At least one preset should be defined.' );

		// Verify each preset has required fields.
		foreach ( $presets as $preset_key => $preset_data ) {
			$this->assertIsArray( $preset_data, "Preset {$preset_key} should be an array." );
			$this->assertArrayHasKey( 'name', $preset_data, "Preset {$preset_key} should have a name." );
			$this->assertArrayHasKey( 'description', $preset_data, "Preset {$preset_key} should have a description." );
			$this->assertArrayHasKey( 'tools', $preset_data, "Preset {$preset_key} should have tools array." );
			$this->assertIsArray( $preset_data['tools'], "Preset {$preset_key} tools should be an array." );
			$this->assertNotEmpty( $preset_data['tools'], "Preset {$preset_key} should have at least one tool." );
		}
	}

	/**
	 * Test that preset tools reference valid tool slugs.
	 */
	public function test_preset_tools_are_valid() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Get all registered tool slugs.
		$registered_tools = array();
		foreach ( $registry->get_tools() as $tool ) {
			$registered_tools[] = $tool->get_slug();
		}

		// Create a reflection class to access protected method.
		$assistant_cpt = new WP_MCP_AI_Assistant_CPT( $registry );
		$reflection    = new ReflectionClass( $assistant_cpt );
		$method        = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );

		$presets = $method->invoke( $assistant_cpt );

		// Check each tool in each preset.
		foreach ( $presets as $preset_key => $preset_data ) {
			foreach ( $preset_data['tools'] as $tool_slug ) {
				$this->assertContains(
					$tool_slug,
					$registered_tools,
					"Tool '{$tool_slug}' in preset '{$preset_key}' should be a registered tool."
				);
			}
		}
	}

	/**
	 * Test that the preset filter hook works.
	 */
	public function test_preset_filter_hook() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Add a custom preset via filter.
		add_filter(
			'wp_mcp_ai_tool_presets',
			function ( $presets ) {
				$presets['test_preset'] = array(
					'name'        => 'Test Preset',
					'description' => 'A test preset',
					'tools'       => array( 'search_content' ),
				);
				return $presets;
			}
		);

		// Create a reflection class to access protected method.
		$assistant_cpt = new WP_MCP_AI_Assistant_CPT( $registry );
		$reflection    = new ReflectionClass( $assistant_cpt );
		$method        = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );

		$presets = $method->invoke( $assistant_cpt );

		$this->assertArrayHasKey( 'test_preset', $presets, 'Custom preset should be added via filter.' );
		$this->assertEquals( 'Test Preset', $presets['test_preset']['name'] );

		// Clean up filter.
		remove_all_filters( 'wp_mcp_ai_tool_presets' );
	}

	/**
	 * Test that content_writing preset exists and contains expected tools.
	 */
	public function test_content_writing_preset() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Create a reflection class to access protected method.
		$assistant_cpt = new WP_MCP_AI_Assistant_CPT( $registry );
		$reflection    = new ReflectionClass( $assistant_cpt );
		$method        = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );

		$presets = $method->invoke( $assistant_cpt );

		$this->assertArrayHasKey( 'content_writing', $presets, 'Content Writing preset should exist.' );

		$content_preset = $presets['content_writing'];
		$this->assertArrayHasKey( 'tools', $content_preset );

		// Check for some expected tools.
		$expected_tools = array( 'search_content', 'save_post', 'get_recent_posts' );
		foreach ( $expected_tools as $tool ) {
			$this->assertContains(
				$tool,
				$content_preset['tools'],
				"Content Writing preset should contain '{$tool}' tool."
			);
		}
	}

	/**
	 * Test that ai_ml preset exists and contains expected tools.
	 */
	public function test_ai_ml_preset() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Create a reflection class to access protected method.
		$assistant_cpt = new WP_MCP_AI_Assistant_CPT( $registry );
		$reflection    = new ReflectionClass( $assistant_cpt );
		$method        = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );

		$presets = $method->invoke( $assistant_cpt );

		$this->assertArrayHasKey( 'ai_ml', $presets, 'AI/ML preset should exist.' );

		$ai_ml_preset = $presets['ai_ml'];
		$this->assertArrayHasKey( 'tools', $ai_ml_preset );

		// Check for some expected tools.
		$expected_tools = array( 'list_available_models', 'count_tokens', 'create_vector_store', 'semantic_content_search' );
		foreach ( $expected_tools as $tool ) {
			$this->assertContains(
				$tool,
				$ai_ml_preset['tools'],
				"AI/ML preset should contain '{$tool}' tool."
			);
		}
	}

	/**
	 * Test that media preset exists and contains expected tools.
	 */
	public function test_media_preset() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Create a reflection class to access protected method.
		$assistant_cpt = new WP_MCP_AI_Assistant_CPT( $registry );
		$reflection    = new ReflectionClass( $assistant_cpt );
		$method        = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );

		$presets = $method->invoke( $assistant_cpt );

		$this->assertArrayHasKey( 'media', $presets, 'Media preset should exist.' );

		$media_preset = $presets['media'];
		$this->assertArrayHasKey( 'tools', $media_preset );

		// Check for some expected tools.
		$expected_tools = array( 'generate_openai_image', 'generate_veo_video', 'transcribe_openai_audio', 'generate_music' );
		foreach ( $expected_tools as $tool ) {
			$this->assertContains(
				$tool,
				$media_preset['tools'],
				"Media preset should contain '{$tool}' tool."
			);
		}
	}

	/**
	 * Test that we have exactly 9 presets (2 new + 7 existing).
	 */
	public function test_preset_count() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Create a reflection class to access protected method.
		$assistant_cpt = new WP_MCP_AI_Assistant_CPT( $registry );
		$reflection    = new ReflectionClass( $assistant_cpt );
		$method        = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );

		$presets = $method->invoke( $assistant_cpt );

		$this->assertCount( 9, $presets, 'Should have exactly 9 presets (2 new + 7 existing).' );

		// Verify all expected preset keys exist.
		$expected_keys = array(
			'ai_ml',
			'media',
			'content_writing',
			'ecommerce',
			'site_management',
			'seo_marketing',
			'development',
			'data_analytics',
			'design_professional',
		);

		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $presets, "Preset '{$key}' should exist." );
		}
	}

	/**
	 * Test that all tool files are accounted for in presets.
	 *
	 * This test ensures every tool file in includes/tools/ is referenced
	 * in at least one preset. This prevents tools from being orphaned.
	 */
	public function test_all_tools_accounted_for_in_presets() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Get all registered tool slugs.
		$registered_tools = array();
		foreach ( $registry->get_tools() as $tool ) {
			$registered_tools[] = $tool->get_slug();
		}

		// Get all tools from all presets.
		$assistant_cpt = new WP_MCP_AI_Assistant_CPT( $registry );
		$reflection    = new ReflectionClass( $assistant_cpt );
		$method        = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );

		$presets = $method->invoke( $assistant_cpt );

		// Collect all tools mentioned in presets.
		$tools_in_presets = array();
		foreach ( $presets as $preset_key => $preset_data ) {
			if ( isset( $preset_data['tools'] ) && is_array( $preset_data['tools'] ) ) {
				$tools_in_presets = array_merge( $tools_in_presets, $preset_data['tools'] );
			}
		}
		$tools_in_presets = array_unique( $tools_in_presets );

		// Find tools that are registered but not in any preset.
		$missing_tools = array_diff( $registered_tools, $tools_in_presets );

		// Assert that no tools are missing from presets.
		$this->assertEmpty(
			$missing_tools,
			'All registered tools should be included in at least one preset. Missing tools: ' . implode( ', ', $missing_tools )
		);

		// Report summary.
		$this->assertGreaterThan(
			200,
			count( $registered_tools ),
			'Should have over 200 registered tools'
		);
		$this->assertEquals(
			count( $registered_tools ),
			count( array_intersect( $registered_tools, $tools_in_presets ) ),
			'All registered tools should be in presets'
		);
	}

	/**
	 * Test that newly added tools are in appropriate presets.
	 *
	 * Specifically tests that the 26 tools added in the latest update
	 * are present in the presets.
	 */
	public function test_newly_added_tools_in_presets() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$assistant_cpt = new WP_MCP_AI_Assistant_CPT( $registry );
		$reflection    = new ReflectionClass( $assistant_cpt );
		$method        = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );

		$presets = $method->invoke( $assistant_cpt );

		// Test client-side AI tools in ai_ml preset.
		$client_tools = array(
			'client_analyze_sentiment',
			'client_extract_entities',
			'client_question_answering',
			'client_semantic_search',
			'client_summarize_text',
			'client_translate_text',
		);

		if ( isset( $presets['ai_ml']['tools'] ) ) {
			foreach ( $client_tools as $tool ) {
				$this->assertContains(
					$tool,
					$presets['ai_ml']['tools'],
					"Client tool '{$tool}' should be in ai_ml preset"
				);
			}
		}

		// Test workflow tools in agentic_workflow preset.
		$workflow_tools = array(
			'check_workflow_health',
			'validate_workflow',
			'visualize_workflow_metrics',
		);

		if ( isset( $presets['agentic_workflow']['tools'] ) ) {
			foreach ( $workflow_tools as $tool ) {
				$this->assertContains(
					$tool,
					$presets['agentic_workflow']['tools'],
					"Workflow tool '{$tool}' should be in agentic_workflow preset"
				);
			}
		}

		// Test Google Site Kit tools in seo_marketing preset.
		$sitekit_tools = array(
			'sitekit_adsense',
			'sitekit_analytics',
			'sitekit_pagespeed',
			'sitekit_search_console',
		);

		if ( isset( $presets['seo_marketing']['tools'] ) ) {
			foreach ( $sitekit_tools as $tool ) {
				$this->assertContains(
					$tool,
					$presets['seo_marketing']['tools'],
					"Site Kit tool '{$tool}' should be in seo_marketing preset"
				);
			}
		}
	}
}
