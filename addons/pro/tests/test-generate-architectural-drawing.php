<?php
/**
 * Tests for the Generate Architectural Drawing tool.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test class for WP_MCP_AI_Tool_Generate_Architectural_Drawing.
 *
 * @group pro
 * @group tools
 * @group architectural-drawing
 */
class Test_WP_MCP_AI_Tool_Generate_Architectural_Drawing extends WP_UnitTestCase {
	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Generate_Architectural_Drawing
	 */
	protected $tool;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load the tool class.
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-architectural-drawing.php';

		$this->tool = new WP_MCP_AI_Tool_Generate_Architectural_Drawing();
	}

	/**
	 * Test tool slug.
	 */
	public function test_get_slug() {
		$this->assertEquals( 'generate_architectural_drawing', $this->tool->get_slug() );
	}

	/**
	 * Test tool name.
	 */
	public function test_get_name() {
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertStringContainsString( 'Architectural', $this->tool->get_name() );
	}

	/**
	 * Test tool description.
	 */
	public function test_get_description() {
		$description = $this->tool->get_description();
		$this->assertNotEmpty( $description );
		$this->assertStringContainsString( 'architectural', strtolower( $description ) );
	}

	/**
	 * Test parameters schema.
	 */
	public function test_get_parameters_schema() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'drawing_type', $schema['properties'] );
		$this->assertArrayHasKey( 'description', $schema['properties'] );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'drawing_type', $schema['required'] );
		$this->assertContains( 'description', $schema['required'] );
	}

	/**
	 * Test drawing types enum.
	 */
	public function test_drawing_types_enum() {
		$schema = $this->tool->get_parameters_schema();
		$drawing_types = $schema['properties']['drawing_type']['enum'];

		$expected_types = array(
			'floor_plan',
			'site_plan',
			'elevation',
			'section',
			'detail',
			'reflected_ceiling_plan',
			'roof_plan',
			'3d_axonometric',
			'isometric',
			'construction_detail',
		);

		foreach ( $expected_types as $type ) {
			$this->assertContains( $type, $drawing_types, "Drawing type '{$type}' should be in enum" );
		}
	}

	/**
	 * Test style enum.
	 */
	public function test_style_enum() {
		$schema = $this->tool->get_parameters_schema();
		$styles = $schema['properties']['style']['enum'];

		$expected_styles = array(
			'technical',
			'sketched',
			'rendered',
			'line_drawing',
			'annotated',
			'schematic',
		);

		foreach ( $expected_styles as $style ) {
			$this->assertContains( $style, $styles, "Style '{$style}' should be in enum" );
		}
	}

	/**
	 * Test capability flags.
	 */
	public function test_get_capability_flags() {
		$flags = $this->tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'requires-api-key', $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'costs-money', $flags );
	}

	/**
	 * Test model requirements.
	 */
	public function test_get_model_requirements() {
		$requirements = $this->tool->get_model_requirements();

		$this->assertIsArray( $requirements );
		$this->assertArrayHasKey( 'providers', $requirements );
		$this->assertArrayHasKey( 'models', $requirements );
		$this->assertContains( 'openai', $requirements['providers'] );
		$this->assertContains( 'gemini', $requirements['providers'] );
	}

	/**
	 * Test validation rules.
	 */
	public function test_get_validation_rules() {
		$rules = $this->tool->get_validation_rules();

		$this->assertIsArray( $rules );
		$this->assertArrayHasKey( 'parameter_constraints', $rules );
		$this->assertArrayHasKey( 'dependencies', $rules );
	}

	/**
	 * Test shortcut tasks.
	 */
	public function test_get_shortcut_tasks() {
		$shortcuts = $this->tool->get_shortcut_tasks();

		$this->assertIsArray( $shortcuts );
		$this->assertNotEmpty( $shortcuts );
		
		foreach ( $shortcuts as $shortcut ) {
			$this->assertArrayHasKey( 'label', $shortcut );
			$this->assertArrayHasKey( 'payload', $shortcut );
		}
	}

	/**
	 * Test execute requires authentication.
	 */
	public function test_execute_requires_authentication() {
		$arguments = array(
			'drawing_type' => 'floor_plan',
			'description'  => 'Test floor plan',
		);

		$context = array();

		$result = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test execute requires required parameters.
	 */
	public function test_execute_requires_parameters() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		// Missing description.
		$arguments = array(
			'drawing_type' => 'floor_plan',
		);

		$context = array(
			'user_id' => $user_id,
		);

		$result = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'missing_parameters', $result->get_error_code() );
	}

	/**
	 * Test execute validates drawing_type.
	 */
	public function test_execute_validates_drawing_type() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$arguments = array(
			'drawing_type' => 'floor_plan',
			'description'  => 'A 2-bedroom residential floor plan with kitchen, living room, and bathroom',
		);

		$context = array(
			'user_id' => $user_id,
		);

		// Note: This will fail at the API call stage since we don't have real API keys in tests.
		// We're just testing that the validation passes and it gets to the API stage.
		$result = $this->tool->execute( $arguments, $context );

		// Should get an API error, not a validation error.
		if ( is_wp_error( $result ) ) {
			$this->assertNotEquals( 'missing_parameters', $result->get_error_code() );
		}
	}

	/**
	 * Test prompt building for floor plan.
	 */
	public function test_build_prompt_floor_plan() {
		$arguments = array(
			'drawing_type'  => 'floor_plan',
			'description'   => 'A 2-bedroom residential home',
			'building_type' => 'residential',
			'style'         => 'technical',
		);

		$method = new ReflectionMethod( $this->tool, 'build_architectural_prompt' );
		$method->setAccessible( true );

		$prompt = $method->invoke( $this->tool, $arguments );

		$this->assertIsString( $prompt );
		$this->assertStringContainsString( 'floor plan', strtolower( $prompt ) );
		$this->assertStringContainsString( 'residential', strtolower( $prompt ) );
		$this->assertStringContainsString( 'technical', strtolower( $prompt ) );
	}

	/**
	 * Test prompt building with dimensions.
	 */
	public function test_build_prompt_with_dimensions() {
		$arguments = array(
			'drawing_type' => 'floor_plan',
			'description'  => 'Test building',
			'dimensions'   => array(
				'width'  => '50 feet',
				'length' => '40 feet',
				'height' => '10 feet',
			),
		);

		$method = new ReflectionMethod( $this->tool, 'build_architectural_prompt' );
		$method->setAccessible( true );

		$prompt = $method->invoke( $this->tool, $arguments );

		$this->assertStringContainsString( 'Width: 50 feet', $prompt );
		$this->assertStringContainsString( 'Length: 40 feet', $prompt );
		$this->assertStringContainsString( 'Height: 10 feet', $prompt );
	}

	/**
	 * Test prompt building with materials.
	 */
	public function test_build_prompt_with_materials() {
		$arguments = array(
			'drawing_type' => 'elevation',
			'description'  => 'Test building elevation',
			'materials'    => array( 'brick', 'concrete', 'glass' ),
		);

		$method = new ReflectionMethod( $this->tool, 'build_architectural_prompt' );
		$method->setAccessible( true );

		$prompt = $method->invoke( $this->tool, $arguments );

		$this->assertStringContainsString( 'Materials: brick, concrete, glass', $prompt );
	}
}
