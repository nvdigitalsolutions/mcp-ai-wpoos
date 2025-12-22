<?php
/**
 * Test Enhanced Tool Selection Presets
 *
 * @package WP_MCP_AI
 */

/**
 * Test tool selection presets enhancement.
 */
class Test_Enhanced_Tool_Presets extends WP_UnitTestCase {

	/**
	 * Test that all 9 presets are defined.
	 */
	public function test_all_presets_exist() {
		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$assistant = new WP_MCP_AI_Assistant_CPT( $registry );

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $assistant );
		$method     = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );

		$presets = $method->invoke( $assistant );

		$expected_presets = array(
			'content_writing',
			'ecommerce',
			'site_management',
			'seo_marketing',
			'development',
			'data_analytics',
			'design_professional',
			'ai_ml_operations',
			'media_production',
		);

		foreach ( $expected_presets as $preset_key ) {
			$this->assertArrayHasKey( $preset_key, $presets, "Preset '$preset_key' should exist" );
		}

		$this->assertCount( 9, $presets, 'Should have exactly 9 presets' );
	}

	/**
	 * Test that all presets have required structure.
	 */
	public function test_preset_structure() {
		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$assistant = new WP_MCP_AI_Assistant_CPT( $registry );

		$reflection = new ReflectionClass( $assistant );
		$method     = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );

		$presets = $method->invoke( $assistant );

		foreach ( $presets as $preset_key => $preset_data ) {
			$this->assertIsArray( $preset_data, "Preset '$preset_key' should be an array" );
			$this->assertArrayHasKey( 'name', $preset_data, "Preset '$preset_key' should have 'name'" );
			$this->assertArrayHasKey( 'description', $preset_data, "Preset '$preset_key' should have 'description'" );
			$this->assertArrayHasKey( 'tools', $preset_data, "Preset '$preset_key' should have 'tools'" );
			$this->assertIsArray( $preset_data['tools'], "Preset '$preset_key' tools should be an array" );
			$this->assertNotEmpty( $preset_data['tools'], "Preset '$preset_key' should have at least one tool" );
		}
	}

	/**
	 * Test that new presets have expected tool counts.
	 */
	public function test_enhanced_preset_tool_counts() {
		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$assistant = new WP_MCP_AI_Assistant_CPT( $registry );

		$reflection = new ReflectionClass( $assistant );
		$method     = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );

		$presets = $method->invoke( $assistant );

		// Test expected minimum tool counts for enhanced presets.
		$expected_counts = array(
			'content_writing'     => 14,
			'ecommerce'           => 12,
			'site_management'     => 17,
			'seo_marketing'       => 17,
			'development'         => 24,
			'data_analytics'      => 26,
			'design_professional' => 28,
			'ai_ml_operations'    => 20,
			'media_production'    => 22,
		);

		foreach ( $expected_counts as $preset_key => $expected_count ) {
			$actual_count = count( $presets[ $preset_key ]['tools'] );
			$this->assertEquals(
				$expected_count,
				$actual_count,
				"Preset '$preset_key' should have $expected_count tools, got $actual_count"
			);
		}
	}

	/**
	 * Test that new AI/ML Operations preset exists.
	 */
	public function test_ai_ml_operations_preset() {
		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$assistant = new WP_MCP_AI_Assistant_CPT( $registry );

		$reflection = new ReflectionClass( $assistant );
		$method     = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );

		$presets = $method->invoke( $assistant );

		$this->assertArrayHasKey( 'ai_ml_operations', $presets );
		$this->assertEquals( 'AI/ML Operations', $presets['ai_ml_operations']['name'] );

		// Check for key AI/ML tools.
		$ai_ml_tools = $presets['ai_ml_operations']['tools'];
		$expected_tools = array(
			'create_vector_store',
			'get_vector_store',
			'list_vector_stores',
			'create_text_embeddings',
			'batch_embed_content',
			'create_batch',
			'moderate_content',
		);

		foreach ( $expected_tools as $tool ) {
			$this->assertContains( $tool, $ai_ml_tools, "AI/ML preset should include '$tool'" );
		}
	}

	/**
	 * Test that new Media Production preset exists.
	 */
	public function test_media_production_preset() {
		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$assistant = new WP_MCP_AI_Assistant_CPT( $registry );

		$reflection = new ReflectionClass( $assistant );
		$method     = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );

		$presets = $method->invoke( $assistant );

		$this->assertArrayHasKey( 'media_production', $presets );
		$this->assertEquals( 'Media Production', $presets['media_production']['name'] );

		// Check for key media production tools.
		$media_tools = $presets['media_production']['tools'];
		$expected_tools = array(
			'generate_veo_video',
			'generate_sora_video',
			'analyze_video',
			'generate_openai_speech',
			'generate_music',
			'transcribe_openai_audio',
		);

		foreach ( $expected_tools as $tool ) {
			$this->assertContains( $tool, $media_tools, "Media Production preset should include '$tool'" );
		}
	}

	/**
	 * Test that Content Writing preset has new tools.
	 */
	public function test_content_writing_enhancements() {
		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$assistant = new WP_MCP_AI_Assistant_CPT( $registry );

		$reflection = new ReflectionClass( $assistant );
		$method     = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );

		$presets = $method->invoke( $assistant );

		$content_tools = $presets['content_writing']['tools'];

		// Check for newly added tools.
		$new_tools = array(
			'moderate_content',
			'analyze_comment_content',
			'generate_video_caption',
			'transcribe_openai_audio',
			'semantic_content_search',
			'create_post',
		);

		foreach ( $new_tools as $tool ) {
			$this->assertContains( $tool, $content_tools, "Content Writing should now include '$tool'" );
		}
	}

	/**
	 * Test that E-commerce preset has new tools.
	 */
	public function test_ecommerce_enhancements() {
		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$assistant = new WP_MCP_AI_Assistant_CPT( $registry );

		$reflection = new ReflectionClass( $assistant );
		$method     = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );

		$presets = $method->invoke( $assistant );

		$ecommerce_tools = $presets['ecommerce']['tools'];

		// Check for newly added e-commerce tools.
		$new_tools = array(
			'woo_products',
			'woo_orders',
			'scrape_product',
			'crawl4ai_price_lookup',
			'lookup_product_price',
			'get_import_duty',
		);

		foreach ( $new_tools as $tool ) {
			$this->assertContains( $tool, $ecommerce_tools, "E-commerce should now include '$tool'" );
		}
	}

	/**
	 * Test that Development preset has substantial enhancements.
	 */
	public function test_development_enhancements() {
		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$assistant = new WP_MCP_AI_Assistant_CPT( $registry );

		$reflection = new ReflectionClass( $assistant );
		$method     = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );

		$presets = $method->invoke( $assistant );

		$dev_tools = $presets['development']['tools'];

		// Check for key new development tools.
		$new_tools = array(
			'get_model_information',
			'create_assistant',
			'create_batch',
			'github_repository_operations',
			'site_creator',
			'install_and_activate_plugin',
			'install_and_activate_theme',
		);

		foreach ( $new_tools as $tool ) {
			$this->assertContains( $tool, $dev_tools, "Development should now include '$tool'" );
		}
	}

	/**
	 * Test that tool coverage has improved significantly.
	 */
	public function test_coverage_improvement() {
		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$assistant = new WP_MCP_AI_Assistant_CPT( $registry );

		$reflection = new ReflectionClass( $assistant );
		$method     = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );

		$presets = $method->invoke( $assistant );

		// Count unique tools across all presets.
		$all_tools = array();
		foreach ( $presets as $preset_data ) {
			$all_tools = array_merge( $all_tools, $preset_data['tools'] );
		}

		$unique_tools = array_unique( $all_tools );

		// Should have at least 130 unique tools across all presets.
		$this->assertGreaterThanOrEqual(
			130,
			count( $unique_tools ),
			'Presets should collectively cover at least 130 tools'
		);
	}
}
