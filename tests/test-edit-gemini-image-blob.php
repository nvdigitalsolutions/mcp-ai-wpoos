<?php
/**
 * Test Edit Gemini Image Tool with Blob Data
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for editing images using blob data
 */
class Test_Edit_Gemini_Image_Blob extends WP_UnitTestCase {

	/**
	 * Test that get_source_image accepts base64-encoded blob data
	 */
	public function test_get_source_image_with_blob_data() {
		// Create a simple 1x1 PNG image (red pixel).
		$png_data = base64_decode(
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg=='
		);

		// Base64 encode it (simulating data from generate_gemini_image tool).
		$base64_image = base64_encode( $png_data );

		// Create tool instance.
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php';
		$tool = new WP_MCP_AI_Tool_Edit_Gemini_Image();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'get_source_image' );
		$method->setAccessible( true );

		$arguments = array(
			'image_data'       => $base64_image,
			'source_mime_type' => 'image/png',
		);

		$result = $method->invoke( $tool, $arguments, 0 );

		// Assert result is not an error.
		$this->assertNotInstanceOf( 'WP_Error', $result );

		// Assert result has expected structure.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'mime_type', $result );
		$this->assertArrayHasKey( 'source', $result );

		// Assert source type is blob.
		$this->assertEquals( 'blob', $result['source'] );

		// Assert MIME type is correct.
		$this->assertEquals( 'image/png', $result['mime_type'] );

		// Assert data is decoded correctly.
		$this->assertEquals( $png_data, $result['data'] );
	}

	/**
	 * Test that invalid base64 data returns error
	 */
	public function test_get_source_image_with_invalid_blob_data() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php';
		$tool = new WP_MCP_AI_Tool_Edit_Gemini_Image();

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'get_source_image' );
		$method->setAccessible( true );

		$arguments = array(
			'image_data'       => 'this is not base64!@#$%',
			'source_mime_type' => 'image/png',
		);

		$result = $method->invoke( $tool, $arguments, 0 );

		// Assert result is an error.
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_image_data', $result->get_error_code() );
	}

	/**
	 * Test that empty image_data returns error
	 */
	public function test_get_source_image_with_empty_blob_data() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php';
		$tool = new WP_MCP_AI_Tool_Edit_Gemini_Image();

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'get_source_image' );
		$method->setAccessible( true );

		// Empty base64 string decodes to empty string.
		$arguments = array(
			'image_data'       => '',
			'source_mime_type' => 'image/png',
		);

		$result = $method->invoke( $tool, $arguments, 0 );

		// Assert result is an error (no source provided).
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_missing_source', $result->get_error_code() );
	}

	/**
	 * Test that MIME type defaults to image/png if not provided
	 */
	public function test_get_source_image_blob_defaults_mime_type() {
		$png_data = base64_decode(
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg=='
		);

		$base64_image = base64_encode( $png_data );

		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php';
		$tool = new WP_MCP_AI_Tool_Edit_Gemini_Image();

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'get_source_image' );
		$method->setAccessible( true );

		$arguments = array(
			'image_data' => $base64_image,
			// No source_mime_type provided.
		);

		$result = $method->invoke( $tool, $arguments, 0 );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'image/png', $result['mime_type'] );
	}

	/**
	 * Test that invalid MIME type defaults to image/png
	 */
	public function test_get_source_image_blob_invalid_mime_type_defaults() {
		$png_data = base64_decode(
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg=='
		);

		$base64_image = base64_encode( $png_data );

		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php';
		$tool = new WP_MCP_AI_Tool_Edit_Gemini_Image();

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'get_source_image' );
		$method->setAccessible( true );

		$arguments = array(
			'image_data'       => $base64_image,
			'source_mime_type' => 'text/plain', // Invalid for images.
		);

		$result = $method->invoke( $tool, $arguments, 0 );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'image/png', $result['mime_type'] );
	}

	/**
	 * Test parameter schema includes new blob-related parameters
	 */
	public function test_parameter_schema_includes_blob_parameters() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php';
		$tool = new WP_MCP_AI_Tool_Edit_Gemini_Image();

		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'image_data', $schema['properties'] );
		$this->assertArrayHasKey( 'source_mime_type', $schema['properties'] );

		// Verify descriptions mention blob/base64.
		$this->assertStringContainsString( 'base64', strtolower( $schema['properties']['image_data']['description'] ) );
	}

	/**
	 * Test that tool rules specify Gemini provider
	 */
	public function test_tool_rules_specify_gemini_provider() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php';
		$tool = new WP_MCP_AI_Tool_Edit_Gemini_Image();

		$rules = $tool->get_tool_rules();

		$this->assertArrayHasKey( 'model_requirements', $rules );
		$this->assertArrayHasKey( 'providers', $rules['model_requirements'] );
		$this->assertContains( 'gemini', $rules['model_requirements']['providers'] );
		$this->assertTrue( $rules['model_requirements']['required'] );
	}
}
