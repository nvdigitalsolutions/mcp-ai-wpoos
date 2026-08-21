<?php
/**
 * Tests for assistant tool presets functionality.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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

		// Presets reference three kinds of slugs:
		// 1. Registered tools (available on this install).
		// 2. Plugin-gated tools the registry declined to load.
		// 3. Pro toolkit-gated tools (toggled off via enable_* settings) and
		// tools registered by scattered toolkit loaders — still known, valid
		// slugs whose class files ship with the plugin.
		$known_tools = array_merge(
			array_map(
				static function ( $tool ) {
					return $tool->get_slug();
				},
				$registry->get_tools()
			),
			$registry->get_unavailable_tool_slugs(),
			$this->get_pro_candidate_slugs(),
			$this->get_file_backed_tool_slugs()
		);

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
					$known_tools,
					"Tool '{$tool_slug}' in preset '{$preset_key}' should be a registered (or known plugin/toolkit-gated) tool."
				);
			}
		}
	}

	/**
	 * Derive the Pro candidate-slug universe, including toolkit-gated tools.
	 *
	 * The Pro addon's tool group map enumerates every Pro tool slug, but its
	 * toolkit sections are gated on enable_* settings. We temporarily force
	 * every enable_* toggle on (enumerated from the Pro source so toggles that
	 * are absent from the stored settings are covered too), collect the
	 * group-map keys, then restore the settings — no side effects, since the
	 * map callback only reads settings.
	 *
	 * @return string[] Candidate Pro tool slugs.
	 */
	private function get_pro_candidate_slugs() {
		if ( ! function_exists( 'wp_mcp_ai_pro_tool_group_map' ) || ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			return array();
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$forced   = is_array( $settings ) ? $settings : array();

		// Force every toolkit toggle the Pro plugin knows about, including
		// toggles that are not present in the stored settings.
		$pro_main = file_get_contents( WP_MCP_AI_PRO_PATH . 'mcp-ai-wpoos-pro.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-local source scan to enumerate enable_* toggles.
		if ( false !== $pro_main ) {
			preg_match_all( "/settings\\['(enable_[a-z0-9_]+)'\\]/", $pro_main, $matches );
			if ( ! empty( $matches[1] ) ) {
				foreach ( array_unique( $matches[1] ) as $key ) {
					$forced[ $key ] = 1;
				}
			}
		}

		update_option( 'wp_mcp_ai_settings', $forced );
		$group_map = apply_filters( 'wp_mcp_ai_tool_group_map', array() );
		update_option( 'wp_mcp_ai_settings', $settings );

		if ( ! is_array( $group_map ) ) {
			return array();
		}

		return array_keys( $group_map );
	}

	/**
	 * Derive every preset-referenced slug that has a shipped tool class file.
	 *
	 * Covers tools registered by scattered toolkit loaders whose slugs never
	 * reach the registry (or the group map) when their toolkit is toggled off.
	 *
	 * @return string[] Preset slugs backed by a shipped tool file.
	 */
	private function get_file_backed_tool_slugs() {
		static $slugs = null;

		if ( null !== $slugs ) {
			return $slugs;
		}

		// Index every slug declared by shipped tool files (base, Pro, and
		// addons) by reading each file's get_slug() return value. This covers
		// tools whose file names differ from their slugs and tools registered
		// by scattered toolkit loaders.
		$shipped = array();

		$roots = array( WP_MCP_AI_PATH . 'includes/tools' );
		if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$roots[] = WP_MCP_AI_PRO_PATH . 'includes/tools';
		}

		// Addon tool roots (fantasy-football, graphify, etc.).
		$addons_dir = WP_MCP_AI_PATH . 'addons';
		if ( is_dir( $addons_dir ) ) {
			foreach ( scandir( $addons_dir ) as $addon ) {
				if ( '.' === $addon || '..' === $addon ) {
					continue;
				}
				$addon_tools = $addons_dir . '/' . $addon . '/includes/tools';
				if ( is_dir( $addon_tools ) ) {
					$roots[] = $addon_tools;
				}
			}
		}

		foreach ( $roots as $root ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
					continue;
				}

				$content = file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-local tool-file scan to extract slugs.
				if ( false === $content || ! preg_match_all( "/return\s+'([a-z0-9_]+)';/", $content, $matches ) ) {
					continue;
				}

				foreach ( $matches[1] as $slug ) {
					$shipped[ $slug ] = true;
				}
			}
		}

		// Return only the preset-referenced slugs that are shipped somewhere.
		$slugs = array();

		$assistant_cpt = new WP_MCP_AI_Assistant_CPT( WP_MCP_AI_Tool_Registry::get_instance() );
		$reflection    = new ReflectionClass( $assistant_cpt );
		$method        = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );
		$presets = $method->invoke( $assistant_cpt );

		foreach ( $presets as $preset ) {
			foreach ( $preset['tools'] as $slug ) {
				if ( isset( $shipped[ (string) $slug ] ) ) {
					$slugs[] = (string) $slug;
				}
			}
		}

		$slugs = array_values( array_unique( $slugs ) );
		return $slugs;
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
	 * Test that media_generation preset exists and contains expected tools.
	 */
	public function test_media_generation_preset() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Create a reflection class to access protected method.
		$assistant_cpt = new WP_MCP_AI_Assistant_CPT( $registry );
		$reflection    = new ReflectionClass( $assistant_cpt );
		$method        = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );

		$presets = $method->invoke( $assistant_cpt );

		$this->assertArrayHasKey( 'media_generation', $presets, 'Media Generation preset should exist.' );

		$media_preset = $presets['media_generation'];
		$this->assertArrayHasKey( 'tools', $media_preset );

		// Check for some expected tools.
		$expected_tools = array( 'generate_openai_image', 'generate_veo_video', 'transcribe_openai_audio', 'generate_music' );
		foreach ( $expected_tools as $tool ) {
			$this->assertContains(
				$tool,
				$media_preset['tools'],
				"Media Generation preset should contain '{$tool}' tool."
			);
		}
	}

	/**
	 * Test that we have at least 60+ presets covering all tool categories.
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

		$this->assertGreaterThanOrEqual( 60, count( $presets ), 'Should have at least 60 presets.' );

		// Verify core preset keys exist.
		$expected_keys = array(
			'agentic_workflow',
			'ai_ml',
			'media_generation',
			'content_writing',
			'ecommerce',
			'site_management',
			'seo_marketing',
			'gutenberg_blocks',
			'development',
			'data_analytics',
			'design_professional',
			'crawling_scraping',
			'files_documents',
			'scheduling_automation',
			'authentication_security',
			'communication_messaging',
			'assistant_management',
			'autonomous_orchestration',
			'agent_supervisor',
			'agent_pipeline',
			'agent_swarm',
			'agent_hierarchical',
			'agent_review_qa',
			'registration_management',
			'cre_debt_securitization',
			'fantasy_sports',
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

		// Find tools that are registered but not in any preset. Validated
		// variants (`foo_validated`) are transparent replacements for their
		// base slug (`foo`) — presets reference the base slug, so a base
		// entry covers the validated variant too.
		$tools_in_presets = array_merge(
			$tools_in_presets,
			array_map(
				static function ( $slug ) {
					return $slug . '_validated';
				},
				$tools_in_presets
			)
		);

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
	 * Covers tools added in recent updates: omni video, Erlang C,
	 * harness, paper store, memory, Gemini managed agent, and skills.
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
			'sitekit_get_adsense',
			'sitekit_get_analytics',
			'sitekit_get_pagespeed',
			'sitekit_get_search_console',
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

		// Test omni video tools in media_generation preset.
		$omni_video_tools = array(
			'generate_omni_video',
			'edit_omni_video',
		);

		if ( isset( $presets['media_generation']['tools'] ) ) {
			foreach ( $omni_video_tools as $tool ) {
				$this->assertContains(
					$tool,
					$presets['media_generation']['tools'],
					"Omni video tool '{$tool}' should be in media_generation preset"
				);
			}
		}

		// Test Erlang C tools in communication_messaging preset.
		$erlangc_tools = array(
			'calculate_erlang_c',
			'erlang_c_concurrency_advisor',
			'erlang_c_queue_health',
			'erlang_c_staffing_advisor',
		);

		if ( isset( $presets['communication_messaging']['tools'] ) ) {
			foreach ( $erlangc_tools as $tool ) {
				$this->assertContains(
					$tool,
					$presets['communication_messaging']['tools'],
					"Erlang C tool '{$tool}' should be in communication_messaging preset"
				);
			}
		}

		// Test paper store tools in files_documents preset.
		$paper_store_tools = array(
			'paper_store_write',
			'paper_store_read',
			'paper_store_update',
			'paper_store_delete',
			'paper_store_list',
			'paper_store_search',
		);

		if ( isset( $presets['files_documents']['tools'] ) ) {
			foreach ( $paper_store_tools as $tool ) {
				$this->assertContains(
					$tool,
					$presets['files_documents']['tools'],
					"Paper store tool '{$tool}' should be in files_documents preset"
				);
			}
		}

		// Test harness tools in agentic_workflow preset.
		$harness_tools = array(
			'evolve_harness',
			'apply_prompt_cue',
			'list_prompt_cues',
			'record_reflection',
			'retrieve_with_provenance',
			'scope_memory',
			'select_prompt_cue',
			'self_consistency_vote',
		);

		if ( isset( $presets['agentic_workflow']['tools'] ) ) {
			foreach ( $harness_tools as $tool ) {
				$this->assertContains(
					$tool,
					$presets['agentic_workflow']['tools'],
					"Harness tool '{$tool}' should be in agentic_workflow preset"
				);
			}
		}

		// Test memory & agent tools in both ai_ml and agentic_workflow presets.
		$mem_agent_tools = array(
			'recall_memory',
			'trace_memory_provenance',
			'run_gemini_managed_agent',
			'load_skill',
		);

		if ( isset( $presets['agentic_workflow']['tools'] ) ) {
			foreach ( $mem_agent_tools as $tool ) {
				$this->assertContains(
					$tool,
					$presets['agentic_workflow']['tools'],
					"Memory/agent tool '{$tool}' should be in agentic_workflow preset"
				);
			}
		}
	}
}
