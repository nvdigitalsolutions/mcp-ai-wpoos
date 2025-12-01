<?php
/**
 * Test image manipulation tools.
 *
 * @package WP_MCP_AI
 */

/**
 * Test image manipulation tools registration and basic functionality.
 */
class Test_Image_Manipulation_Tools extends WP_UnitTestCase {

	/**
	 * Test that image manipulation tools are registered.
	 */
	public function test_image_tools_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Test resize tool.
		$resize_tool = $registry->get_tool( 'resize_image' );
		$this->assertNotNull( $resize_tool, 'Resize image tool should be registered' );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $resize_tool );

		// Test crop tool.
		$crop_tool = $registry->get_tool( 'crop_image' );
		$this->assertNotNull( $crop_tool, 'Crop image tool should be registered' );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $crop_tool );

		// Test rotate tool.
		$rotate_tool = $registry->get_tool( 'rotate_image' );
		$this->assertNotNull( $rotate_tool, 'Rotate image tool should be registered' );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $rotate_tool );

		// Test convert tool.
		$convert_tool = $registry->get_tool( 'convert_image_format' );
		$this->assertNotNull( $convert_tool, 'Convert image format tool should be registered' );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $convert_tool );
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Test resize tool metadata.
		$resize_tool = $registry->get_tool( 'resize_image' );
		$this->assertEquals( 'resize_image', $resize_tool->get_slug() );
		$this->assertEquals( 'Resize Image', $resize_tool->get_name() );
		$this->assertNotEmpty( $resize_tool->get_description() );

		// Test crop tool metadata.
		$crop_tool = $registry->get_tool( 'crop_image' );
		$this->assertEquals( 'crop_image', $crop_tool->get_slug() );
		$this->assertEquals( 'Crop Image', $crop_tool->get_name() );

		// Test rotate tool metadata.
		$rotate_tool = $registry->get_tool( 'rotate_image' );
		$this->assertEquals( 'rotate_image', $rotate_tool->get_slug() );
		$this->assertEquals( 'Rotate Image', $rotate_tool->get_name() );

		// Test convert tool metadata.
		$convert_tool = $registry->get_tool( 'convert_image_format' );
		$this->assertEquals( 'convert_image_format', $convert_tool->get_slug() );
		$this->assertEquals( 'Convert Image Format', $convert_tool->get_name() );
	}

	/**
	 * Test parameter schemas are properly defined.
	 */
	public function test_parameter_schemas() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Test resize tool parameters.
		$resize_tool = $registry->get_tool( 'resize_image' );
		$schema      = $resize_tool->get_parameters_schema();
		$this->assertIsArray( $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'width', $schema['properties'] );
		$this->assertArrayHasKey( 'height', $schema['properties'] );
		$this->assertArrayHasKey( 'attachment_id', $schema['properties'] );

		// Test crop tool parameters.
		$crop_tool = $registry->get_tool( 'crop_image' );
		$schema    = $crop_tool->get_parameters_schema();
		$this->assertArrayHasKey( 'x', $schema['properties'] );
		$this->assertArrayHasKey( 'y', $schema['properties'] );
		$this->assertArrayHasKey( 'aspect_ratio', $schema['properties'] );

		// Test rotate tool parameters.
		$rotate_tool = $registry->get_tool( 'rotate_image' );
		$schema      = $rotate_tool->get_parameters_schema();
		$this->assertArrayHasKey( 'angle', $schema['properties'] );
		$this->assertArrayHasKey( 'flip_horizontal', $schema['properties'] );
		$this->assertArrayHasKey( 'flip_vertical', $schema['properties'] );

		// Test convert tool parameters.
		$convert_tool = $registry->get_tool( 'convert_image_format' );
		$schema       = $convert_tool->get_parameters_schema();
		$this->assertArrayHasKey( 'format', $schema['properties'] );
		$this->assertArrayHasKey( 'quality', $schema['properties'] );
		$this->assertContains( 'format', $schema['required'] );
	}

	/**
	 * Test capability flags are defined.
	 */
	public function test_capability_flags() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		$tools = array( 'resize_image', 'crop_image', 'rotate_image', 'convert_image_format' );

		foreach ( $tools as $tool_slug ) {
			$tool = $registry->get_tool( $tool_slug );
			$this->assertInstanceOf( 'WP_MCP_AI_Tool_Capability_Flags_Interface', $tool );

			$flags = $tool->get_capability_flags();
			$this->assertIsArray( $flags );
			$this->assertContains( 'requires-capability', $flags );
			$this->assertContains( 'write', $flags );
			$this->assertContains( 'local-only', $flags );
		}
	}

	/**
	 * Test LLM sanitizer interface is implemented.
	 */
	public function test_llm_sanitizer() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		$tools = array( 'resize_image', 'crop_image', 'rotate_image', 'convert_image_format' );

		foreach ( $tools as $tool_slug ) {
			$tool = $registry->get_tool( $tool_slug );
			$this->assertInstanceOf( 'WP_MCP_AI_Tool_LLM_Sanitizer_Interface', $tool );

			// Test sanitization removes base64 data.
			$result = array(
				'attachment_id' => 123,
				'url'           => 'http://example.com/image.jpg',
				'content'       => array(
					'data'     => 'base64_encoded_data_here',
					'data_url' => 'data:image/png;base64,xxx',
					'encoding' => 'base64',
				),
			);

			$sanitized = $tool->sanitize_for_llm( $result );
			$this->assertArrayNotHasKey( 'content', $sanitized );
		}
	}

	/**
	 * Test authentication is required.
	 */
	public function test_requires_authentication() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		$tools = array( 'resize_image', 'crop_image', 'rotate_image', 'convert_image_format' );

		foreach ( $tools as $tool_slug ) {
			$tool = $registry->get_tool( $tool_slug );

			// Test with no user and no token.
			$result = $tool->execute( array(), array() );
			$this->assertWPError( $result );
			$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
		}
	}
}
