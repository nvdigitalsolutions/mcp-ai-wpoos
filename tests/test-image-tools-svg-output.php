<?php
/**
 * Test SVG output support for image manipulation tools.
 *
 * @package WP_MCP_AI
 */

/**
 * Test SVG output functionality for image tools.
 */
class Test_Image_Tools_SVG_Output extends WP_UnitTestCase {

	/**
	 * Test that output_format parameter is present in schemas.
	 */
	public function test_output_format_parameter_in_schemas() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Test resize tool.
		$resize_tool = $registry->get_tool( 'resize_image' );
		$schema      = $resize_tool->get_parameters_schema();
		$this->assertArrayHasKey( 'output_format', $schema['properties'], 'Resize tool should have output_format parameter' );
		$this->assertContains( 'svg', $schema['properties']['output_format']['enum'], 'Resize tool should support SVG output' );
		$this->assertContains( 'default', $schema['properties']['output_format']['enum'], 'Resize tool should support default output' );

		// Test crop tool.
		$crop_tool = $registry->get_tool( 'crop_image' );
		$schema    = $crop_tool->get_parameters_schema();
		$this->assertArrayHasKey( 'output_format', $schema['properties'], 'Crop tool should have output_format parameter' );
		$this->assertContains( 'svg', $schema['properties']['output_format']['enum'], 'Crop tool should support SVG output' );

		// Test rotate tool.
		$rotate_tool = $registry->get_tool( 'rotate_image' );
		$schema      = $rotate_tool->get_parameters_schema();
		$this->assertArrayHasKey( 'output_format', $schema['properties'], 'Rotate tool should have output_format parameter' );
		$this->assertContains( 'svg', $schema['properties']['output_format']['enum'], 'Rotate tool should support SVG output' );
	}

	/**
	 * Test that convert_image_format tool supports SVG format.
	 */
	public function test_convert_tool_svg_format_support() {
		$registry     = WP_MCP_AI_Tool_Registry::get_instance();
		$convert_tool = $registry->get_tool( 'convert_image_format' );
		$schema       = $convert_tool->get_parameters_schema();

		$this->assertArrayHasKey( 'format', $schema['properties'], 'Convert tool should have format parameter' );
		$this->assertContains( 'svg', $schema['properties']['format']['enum'], 'Convert tool should support SVG format' );
		$this->assertContains( 'png', $schema['properties']['format']['enum'], 'Convert tool should support PNG format' );
		$this->assertContains( 'jpeg', $schema['properties']['format']['enum'], 'Convert tool should support JPEG format' );
	}

	/**
	 * Test that image base class has NodeJS subprocess trait.
	 */
	public function test_image_base_has_nodejs_trait() {
		$registry    = WP_MCP_AI_Tool_Registry::get_instance();
		$resize_tool = $registry->get_tool( 'resize_image' );

		// Check that the tool has the NodeJS subprocess methods.
		$this->assertTrue(
			method_exists( $resize_tool, 'execute_nodejs_script' ),
			'Image tools should have execute_nodejs_script method from NodeJS trait'
		);
		$this->assertTrue(
			method_exists( $resize_tool, 'is_nodejs_available' ),
			'Image tools should have is_nodejs_available method from NodeJS trait'
		);
	}

	/**
	 * Test that image base class has SVG conversion methods.
	 */
	public function test_image_base_has_svg_methods() {
		$resize_tool_class = new ReflectionClass( 'WP_MCP_AI_Tool_Resize_Image' );

		// Check for convert_to_svg method in parent class.
		$parent_class = $resize_tool_class->getParentClass();
		$this->assertEquals( 'WP_MCP_AI_Tool_Image_Base', $parent_class->getName(), 'Tool should extend image base class' );

		// Check that convert_to_svg method exists in base class.
		$this->assertTrue(
			$parent_class->hasMethod( 'convert_to_svg' ),
			'Image base class should have convert_to_svg method'
		);

		// Check that save_svg_as_attachment method exists in base class.
		$this->assertTrue(
			$parent_class->hasMethod( 'save_svg_as_attachment' ),
			'Image base class should have save_svg_as_attachment method'
		);

		// Check that get_output_format_parameter_schema method exists in base class.
		$this->assertTrue(
			$parent_class->hasMethod( 'get_output_format_parameter_schema' ),
			'Image base class should have get_output_format_parameter_schema method'
		);
	}

	/**
	 * Test that SVG MIME type is in allowed MIME types.
	 */
	public function test_svg_mime_type_allowed() {
		$registry    = WP_MCP_AI_Tool_Registry::get_instance();
		$resize_tool = $registry->get_tool( 'resize_image' );

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $resize_tool );
		$method     = $reflection->getMethod( 'get_allowed_mime_types' );
		$method->setAccessible( true );

		$allowed_mime_types = $method->invoke( $resize_tool );

		$this->assertArrayHasKey( 'image/svg+xml', $allowed_mime_types, 'SVG MIME type should be in allowed MIME types' );
		$this->assertEquals( 'svg', $allowed_mime_types['image/svg+xml'], 'SVG MIME type should map to svg extension' );
	}

	/**
	 * Test backward compatibility - default output format.
	 */
	public function test_default_output_format_backward_compatibility() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Test resize tool.
		$resize_tool = $registry->get_tool( 'resize_image' );
		$schema      = $resize_tool->get_parameters_schema();
		$this->assertEquals( 'default', $schema['properties']['output_format']['default'], 'Default output format should be "default" for backward compatibility' );

		// Test crop tool.
		$crop_tool = $registry->get_tool( 'crop_image' );
		$schema    = $crop_tool->get_parameters_schema();
		$this->assertEquals( 'default', $schema['properties']['output_format']['default'], 'Default output format should be "default" for backward compatibility' );

		// Test rotate tool.
		$rotate_tool = $registry->get_tool( 'rotate_image' );
		$schema      = $rotate_tool->get_parameters_schema();
		$this->assertEquals( 'default', $schema['properties']['output_format']['default'], 'Default output format should be "default" for backward compatibility' );
	}

	/**
	 * Test parameter descriptions mention SVG.
	 */
	public function test_parameter_descriptions_mention_svg() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Test resize tool.
		$resize_tool = $registry->get_tool( 'resize_image' );
		$schema      = $resize_tool->get_parameters_schema();
		$this->assertStringContainsString(
			'svg',
			strtolower( $schema['properties']['output_format']['description'] ),
			'Output format description should mention SVG'
		);

		// Test convert tool format parameter.
		$convert_tool = $registry->get_tool( 'convert_image_format' );
		$schema       = $convert_tool->get_parameters_schema();
		$this->assertContains( 'svg', $schema['properties']['format']['enum'], 'Convert tool format enum should include svg' );
	}

	/**
	 * Test that tools maintain existing capability flags.
	 */
	public function test_capability_flags_maintained() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Test resize tool.
		$resize_tool = $registry->get_tool( 'resize_image' );
		$flags       = $resize_tool->get_capability_flags();
		$this->assertContains( 'requires-capability', $flags, 'Resize tool should require capability' );
		$this->assertContains( 'write', $flags, 'Resize tool should have write flag' );
		$this->assertContains( 'local-only', $flags, 'Resize tool should be local-only' );

		// Test crop tool.
		$crop_tool = $registry->get_tool( 'crop_image' );
		$flags     = $crop_tool->get_capability_flags();
		$this->assertContains( 'requires-capability', $flags, 'Crop tool should require capability' );
		$this->assertContains( 'write', $flags, 'Crop tool should have write flag' );

		// Test rotate tool.
		$rotate_tool = $registry->get_tool( 'rotate_image' );
		$flags       = $rotate_tool->get_capability_flags();
		$this->assertContains( 'requires-capability', $flags, 'Rotate tool should require capability' );
		$this->assertContains( 'write', $flags, 'Rotate tool should have write flag' );

		// Test convert tool.
		$convert_tool = $registry->get_tool( 'convert_image_format' );
		$flags        = $convert_tool->get_capability_flags();
		$this->assertContains( 'requires-capability', $flags, 'Convert tool should require capability' );
		$this->assertContains( 'write', $flags, 'Convert tool should have write flag' );
	}

	/**
	 * Test that vectorize_image tool still exists and works.
	 */
	public function test_vectorize_tool_still_exists() {
		$registry       = WP_MCP_AI_Tool_Registry::get_instance();
		$vectorize_tool = $registry->get_tool( 'vectorize_image' );

		$this->assertNotNull( $vectorize_tool, 'Vectorize image tool should still be registered' );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $vectorize_tool );
		$this->assertEquals( 'vectorize_image', $vectorize_tool->get_slug() );
		$this->assertEquals( 'Vectorize Image', $vectorize_tool->get_name() );
	}

	/**
	 * Test SVG conversion requires Node.js check.
	 */
	public function test_svg_conversion_checks_nodejs() {
		$registry    = WP_MCP_AI_Tool_Registry::get_instance();
		$resize_tool = $registry->get_tool( 'resize_image' );

		// Verify the tool has is_nodejs_available method.
		$this->assertTrue(
			method_exists( $resize_tool, 'is_nodejs_available' ),
			'Tool should have is_nodejs_available method to check Node.js availability'
		);
	}

	/**
	 * Test that mime_to_format method supports SVG in convert tool.
	 */
	public function test_convert_tool_mime_to_format_supports_svg() {
		$registry     = WP_MCP_AI_Tool_Registry::get_instance();
		$convert_tool = $registry->get_tool( 'convert_image_format' );

		// Use reflection to test protected method.
		$reflection = new ReflectionClass( $convert_tool );
		$method     = $reflection->getMethod( 'mime_to_format' );
		$method->setAccessible( true );

		$result = $method->invoke( $convert_tool, 'image/svg+xml' );
		$this->assertEquals( 'svg', $result, 'mime_to_format should convert image/svg+xml to svg' );
	}
}
