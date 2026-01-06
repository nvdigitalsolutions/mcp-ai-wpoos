<?php
/**
 * Tests for the Architectural Drawing Generation Tool.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for architectural drawing generation tool.
 */
class Test_Architectural_Drawing_Tool extends WP_UnitTestCase {

	/**
	 * Test tool registration and availability.
	 */
	public function test_tool_registered() {
		// Check if tool registry exists.
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Registry' ), 'Tool registry class should exist' );

		// Get the tool registry.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$this->assertNotNull( $registry, 'Tool registry instance should not be null' );

		// Check if tool is registered.
		$tool = $registry->get_tool( 'generate_architectural_drawing' );
		$this->assertNotNull( $tool, 'Architectural drawing tool should be registered' );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Generate_Architectural_Drawing', $tool );
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$tool = new WP_MCP_AI_Tool_Generate_Architectural_Drawing();

		$this->assertEquals( 'generate_architectural_drawing', $tool->get_slug() );
		$this->assertEquals( 'Generate Architectural Drawing', $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test parameter schema has required fields.
	 */
	public function test_parameter_schema() {
		$tool   = new WP_MCP_AI_Tool_Generate_Architectural_Drawing();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );

		// Check required prompt field.
		$this->assertContains( 'prompt', $schema['required'] );
		$this->assertArrayHasKey( 'prompt', $schema['properties'] );

		// Check drawing type field.
		$this->assertArrayHasKey( 'drawing_type', $schema['properties'] );
		$this->assertArrayHasKey( 'enum', $schema['properties']['drawing_type'] );
		$drawing_types = $schema['properties']['drawing_type']['enum'];
		$this->assertContains( 'floor_plan', $drawing_types );
		$this->assertContains( 'elevation', $drawing_types );
		$this->assertContains( 'section', $drawing_types );

		// Check presentation style field.
		$this->assertArrayHasKey( 'presentation_style', $schema['properties'] );
		$this->assertArrayHasKey( 'enum', $schema['properties']['presentation_style'] );
		$styles = $schema['properties']['presentation_style']['enum'];
		$this->assertContains( 'technical', $styles );
		$this->assertContains( 'sketched', $styles );
		$this->assertContains( 'rendered', $styles );

		// Check provider field.
		$this->assertArrayHasKey( 'provider', $schema['properties'] );
		$provider_enum = $schema['properties']['provider']['enum'];
		$this->assertContains( 'openai', $provider_enum );
		$this->assertContains( 'gemini', $provider_enum );
	}

	/**
	 * Test drawing types method returns all 10 types.
	 */
	public function test_drawing_types() {
		$tool = new WP_MCP_AI_Tool_Generate_Architectural_Drawing();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'get_drawing_types' );
		$method->setAccessible( true );

		$types = $method->invoke( $tool );

		$this->assertIsArray( $types );
		$this->assertCount( 10, $types, 'Should have exactly 10 drawing types' );

		$expected_types = array(
			'floor_plan',
			'elevation',
			'section',
			'detail',
			'site_plan',
			'reflected_ceiling_plan',
			'roof_plan',
			'3d_axonometric',
			'isometric',
			'construction_detail',
		);

		foreach ( $expected_types as $expected ) {
			$this->assertContains( $expected, $types, "Should contain $expected drawing type" );
		}
	}

	/**
	 * Test presentation styles method returns all 6 styles.
	 */
	public function test_presentation_styles() {
		$tool = new WP_MCP_AI_Tool_Generate_Architectural_Drawing();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'get_presentation_styles' );
		$method->setAccessible( true );

		$styles = $method->invoke( $tool );

		$this->assertIsArray( $styles );
		$this->assertCount( 6, $styles, 'Should have exactly 6 presentation styles' );

		$expected_styles = array(
			'technical',
			'sketched',
			'rendered',
			'line_drawing',
			'annotated',
			'schematic',
		);

		foreach ( $expected_styles as $expected ) {
			$this->assertContains( $expected, $styles, "Should contain $expected presentation style" );
		}
	}

	/**
	 * Test authentication requirement.
	 */
	public function test_authentication_required() {
		$tool = new WP_MCP_AI_Tool_Generate_Architectural_Drawing();

		// Execute without authentication.
		$result = $tool->execute(
			array( 'prompt' => 'test floor plan' ),
			array()
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test missing prompt error.
	 */
	public function test_missing_prompt() {
		$tool    = new WP_MCP_AI_Tool_Generate_Architectural_Drawing();
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Execute with empty prompt.
		$result = $tool->execute(
			array( 'prompt' => '' ),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_prompt', $result->get_error_code() );
	}

	/**
	 * Test capability flags.
	 */
	public function test_capability_flags() {
		$tool  = new WP_MCP_AI_Tool_Generate_Architectural_Drawing();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'requires-credentials', $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'async', $flags );
		$this->assertContains( 'pro-tool', $flags );
	}

	/**
	 * Test model requirements.
	 */
	public function test_model_requirements() {
		$tool         = new WP_MCP_AI_Tool_Generate_Architectural_Drawing();
		$requirements = $tool->get_model_requirements();

		$this->assertIsArray( $requirements );
		$this->assertContains( 'image-generation', $requirements );
	}

	/**
	 * Test tool rules structure.
	 */
	public function test_tool_rules() {
		$tool  = new WP_MCP_AI_Tool_Generate_Architectural_Drawing();
		$rules = $tool->get_tool_rules();

		$this->assertIsArray( $rules );
		$this->assertArrayHasKey( 'model_requirements', $rules );
		$this->assertArrayHasKey( 'parameter_constraints', $rules );
		$this->assertArrayHasKey( 'rate_limits', $rules );
		$this->assertArrayHasKey( 'timeout_constraints', $rules );

		// Check model requirements.
		$model_reqs = $rules['model_requirements'];
		$this->assertArrayHasKey( 'providers', $model_reqs );
		$this->assertContains( 'openai', $model_reqs['providers'] );
		$this->assertContains( 'gemini', $model_reqs['providers'] );

		// Check rate limits.
		$rate_limits = $rules['rate_limits'];
		$this->assertArrayHasKey( 'requests_per_minute', $rate_limits );
		$this->assertArrayHasKey( 'requests_per_hour', $rate_limits );
		$this->assertEquals( 3, $rate_limits['requests_per_minute'] );
		$this->assertEquals( 20, $rate_limits['requests_per_hour'] );
	}

	/**
	 * Test architectural prompt building.
	 */
	public function test_prompt_building() {
		$tool = new WP_MCP_AI_Tool_Generate_Architectural_Drawing();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'build_architectural_prompt' );
		$method->setAccessible( true );

		$base_prompt = 'residential floor plan';
		$arguments   = array(
			'drawing_type'       => 'floor_plan',
			'presentation_style' => 'technical',
			'scale'              => '1/4"=1\'-0"',
			'dimensions'         => array(
				'width' => 40,
				'depth' => 30,
				'unit'  => 'feet',
			),
			'materials'          => array( 'wood flooring', 'drywall' ),
			'building_code'      => 'ibc',
			'annotations'        => true,
		);

		$enhanced_prompt = $method->invoke( $tool, $base_prompt, $arguments );

		$this->assertIsString( $enhanced_prompt );
		$this->assertStringContainsString( 'residential floor plan', $enhanced_prompt );
		$this->assertStringContainsString( 'floor plan', $enhanced_prompt );
		$this->assertStringContainsString( '1/4"=1\'-0"', $enhanced_prompt );
		$this->assertStringContainsString( '40', $enhanced_prompt );
		$this->assertStringContainsString( 'feet', $enhanced_prompt );
		$this->assertStringContainsString( 'wood flooring', $enhanced_prompt );
		$this->assertStringContainsString( 'IBC', $enhanced_prompt );
		$this->assertStringContainsString( 'dimension', $enhanced_prompt );
	}

	/**
	 * Test shortcut tasks are provided.
	 */
	public function test_shortcut_tasks() {
		$tool      = new WP_MCP_AI_Tool_Generate_Architectural_Drawing();
		$shortcuts = $tool->get_shortcut_tasks();

		$this->assertIsArray( $shortcuts );
		$this->assertNotEmpty( $shortcuts );
		$this->assertGreaterThanOrEqual( 3, count( $shortcuts ) );

		foreach ( $shortcuts as $shortcut ) {
			$this->assertArrayHasKey( 'label', $shortcut );
			$this->assertArrayHasKey( 'payload', $shortcut );
		}
	}

	/**
	 * Test LLM sanitization.
	 */
	public function test_llm_sanitization() {
		$tool = new WP_MCP_AI_Tool_Generate_Architectural_Drawing();

		$result = array(
			'attachment_id'      => 123,
			'url'                => 'https://example.com/image.png',
			'file_name'          => 'drawing.png',
			'drawing_type'       => 'floor_plan',
			'presentation_style' => 'technical',
			'provider'           => 'openai',
			'content'            => array(
				'data'     => base64_encode( 'fake_image_data' ),
				'data_url' => 'data:image/png;base64,fake',
			),
		);

		$sanitized = $tool->sanitize_for_llm( $result );

		$this->assertIsArray( $sanitized );
		$this->assertArrayHasKey( 'attachment_id', $sanitized );
		$this->assertArrayHasKey( 'url', $sanitized );
		$this->assertArrayHasKey( 'drawing_type', $sanitized );
		$this->assertArrayHasKey( 'image_url', $sanitized );

		// Content data should be removed.
		$this->assertArrayNotHasKey( 'content', $sanitized );
	}

	/**
	 * Test attachment title generation.
	 */
	public function test_attachment_title_generation() {
		$tool = new WP_MCP_AI_Tool_Generate_Architectural_Drawing();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'generate_attachment_title' );
		$method->setAccessible( true );

		// Test with normal prompt.
		$title = $method->invoke( $tool, 'residential floor plan with 3 bedrooms' );
		$this->assertStringContainsString( 'Architectural Drawing:', $title );
		$this->assertStringContainsString( 'residential', $title );

		// Test with empty prompt.
		$title = $method->invoke( $tool, '' );
		$this->assertEquals( 'Architectural Drawing', $title );

		// Test with very long prompt.
		$long_prompt = str_repeat( 'word ', 50 );
		$title       = $method->invoke( $tool, $long_prompt );
		$this->assertStringContainsString( 'Architectural Drawing:', $title );
		$this->assertStringContainsString( '…', $title ); // Should be truncated.
	}
}
