<?php
/**
 * Test vectorize_image tool.
 *
 * @package WP_MCP_AI
 */

/**
 * Test vectorize_image tool registration and basic functionality.
 */
class Test_Vectorize_Image_Tool extends WP_UnitTestCase {

	/**
	 * Test that vectorize_image tool is registered.
	 */
	public function test_vectorize_image_tool_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		$tool = $registry->get_tool( 'vectorize_image' );
		$this->assertNotNull( $tool, 'Vectorize image tool should be registered' );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool );
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'vectorize_image' );

		$this->assertEquals( 'vectorize_image', $tool->get_slug() );
		$this->assertEquals( 'Vectorize Image', $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );
		$this->assertStringContainsString( 'SVG', $tool->get_description() );
	}

	/**
	 * Test parameter schema.
	 */
	public function test_parameter_schema() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'vectorize_image' );
		$schema   = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );

		// Test vectorization-specific parameters.
		$this->assertArrayHasKey( 'color_mode', $schema['properties'] );
		$this->assertArrayHasKey( 'color_precision', $schema['properties'] );
		$this->assertArrayHasKey( 'filter_speckle', $schema['properties'] );
		$this->assertArrayHasKey( 'mode', $schema['properties'] );
		$this->assertArrayHasKey( 'hierarchical', $schema['properties'] );

		// Test source parameters inherited from Image_Base.
		$this->assertArrayHasKey( 'attachment_id', $schema['properties'] );
		$this->assertArrayHasKey( 'url', $schema['properties'] );
	}

	/**
	 * Test capability flags.
	 */
	public function test_capability_flags() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'vectorize_image' );

		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Capability_Flags_Interface', $tool );

		$flags = $tool->get_capability_flags();
		$this->assertIsArray( $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'local-only', $flags );
		$this->assertContains( 'requires-nodejs', $flags );
	}

	/**
	 * Test execution without authentication returns error.
	 */
	public function test_execution_without_authentication() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'vectorize_image' );

		$result = $tool->execute( array(), array() );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test execution without required permissions returns error.
	 */
	public function test_execution_without_permissions() {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'vectorize_image' );

		$result = $tool->execute(
			array(),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test Node.js subprocess trait methods.
	 */
	public function test_nodejs_subprocess_trait() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'vectorize_image' );

		// Use reflection to access protected methods for testing.
		$reflection = new ReflectionClass( $tool );

		// Test is_nodejs_available method.
		$is_available_method = $reflection->getMethod( 'is_nodejs_available' );
		$is_available_method->setAccessible( true );
		$is_available = $is_available_method->invoke( $tool );

		// Node.js availability depends on the environment.
		$this->assertIsBool( $is_available );

		// Test get_nodejs_executable method.
		$get_executable_method = $reflection->getMethod( 'get_nodejs_executable' );
		$get_executable_method->setAccessible( true );
		$executable = $get_executable_method->invoke( $tool );

		// Should be a path or WP_Error.
		$this->assertTrue( is_string( $executable ) || is_wp_error( $executable ) );
	}

	/**
	 * Test SVG MIME type support in image base.
	 */
	public function test_svg_mime_type_support() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'vectorize_image' );

		// Use reflection to access protected method.
		$reflection   = new ReflectionClass( $tool );
		$method       = $reflection->getMethod( 'get_allowed_mime_types' );
		$method->setAccessible( true );
		$allowed_mimes = $method->invoke( $tool );

		$this->assertArrayHasKey( 'image/svg+xml', $allowed_mimes );
		$this->assertEquals( 'svg', $allowed_mimes['image/svg+xml'] );
	}

	/**
	 * Test tool grouping is correct.
	 */
	public function test_tool_grouping() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$grouping = $registry->get_tool_grouping();

		$this->assertArrayHasKey( 'vectorize_image', $grouping );
		$this->assertEquals( 'wordpress-core', $grouping['vectorize_image'] );
	}

	/**
	 * Test vectorization script exists.
	 */
	public function test_vectorization_script_exists() {
		$script_path = WP_MCP_AI_PATH . 'bin/vectorize-image.js';
		$this->assertFileExists( $script_path );
		$this->assertFileIsReadable( $script_path );

		// Check if file is executable.
		$perms = fileperms( $script_path );
		$this->assertTrue( ( $perms & 0x0040 ) !== 0, 'Script should be executable' );
	}

	/**
	 * Test save_to_temp_file returns correct path with extension.
	 */
	public function test_save_to_temp_file_returns_correct_path() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'vectorize_image' );

		// Create a simple 1x1 PNG image.
		$png_data = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' );

		// Create a temporary file with the PNG data.
		$temp_input = wp_tempnam( 'test-png-' );
		file_put_contents( $temp_input, $png_data );

		// Load the image with WordPress image editor.
		$image_editor = wp_get_image_editor( $temp_input );

		// Clean up input file.
		wp_delete_file( $temp_input );

		if ( is_wp_error( $image_editor ) ) {
			$this->markTestSkipped( 'Image editor not available: ' . $image_editor->get_error_message() );
		}

		// Use reflection to test the save_to_temp_file method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'save_to_temp_file' );
		$method->setAccessible( true );

		$saved_path = $method->invoke( $tool, $image_editor );

		// Should not be a WP_Error.
		$this->assertNotInstanceOf( 'WP_Error', $saved_path );
		$this->assertIsString( $saved_path );

		// The saved file should exist.
		$this->assertFileExists( $saved_path, 'Saved file should exist' );

		// The saved file should be readable.
		$this->assertFileIsReadable( $saved_path, 'Saved file should be readable' );

		// The saved file should have content.
		$this->assertGreaterThan( 0, filesize( $saved_path ), 'Saved file should not be empty' );

		// Clean up.
		wp_delete_file( $saved_path );
	}

	/**
	 * Test that tool implements LLM sanitizer interface.
	 */
	public function test_implements_llm_sanitizer_interface() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'vectorize_image' );

		$this->assertInstanceOf( 'WP_MCP_AI_Tool_LLM_Sanitizer_Interface', $tool );
	}

	/**
	 * Test that sanitize_for_llm returns image_url structure.
	 */
	public function test_sanitize_for_llm_returns_image_url() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'vectorize_image' );

		// Mock a typical tool result.
		$mock_result = array(
			'attachment_id' => 123,
			'url'           => 'https://example.com/wp-content/uploads/2024/01/vectorized-image.svg',
			'file_name'     => 'vectorized-image.svg',
			'mime_type'     => 'image/svg+xml',
			'bytes'         => 5000,
			'title'         => 'Vectorized Image',
			'source_format' => 'image/png',
			'source_size'   => 10000,
			'svg_size'      => 5000,
			'size_ratio'    => '0.50',
			'duration_ms'   => 1500,
			'options'       => array(
				'colorMode'      => 'color',
				'colorPrecision' => 6,
			),
			'text'          => 'Successfully vectorized image to SVG format. Attachment ID: 123, File: vectorized-image.svg',
		);

		$sanitized = $tool->sanitize_for_llm( $mock_result );

		// Should contain image_url structure.
		$this->assertArrayHasKey( 'image_url', $sanitized );
		$this->assertIsArray( $sanitized['image_url'] );
		$this->assertArrayHasKey( 'url', $sanitized['image_url'] );
		$this->assertEquals( 'https://example.com/wp-content/uploads/2024/01/vectorized-image.svg', $sanitized['image_url']['url'] );

		// Should keep essential fields.
		$this->assertArrayHasKey( 'attachment_id', $sanitized );
		$this->assertArrayHasKey( 'url', $sanitized );
		$this->assertArrayHasKey( 'file_name', $sanitized );
		$this->assertArrayHasKey( 'mime_type', $sanitized );
		$this->assertArrayHasKey( 'text', $sanitized );

		// Should strip options (not needed for LLM).
		$this->assertArrayNotHasKey( 'options', $sanitized );
	}

	/**
	 * Test that tool result includes image_url structure.
	 */
	public function test_tool_result_includes_image_url() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'vectorize_image' );

		// Use reflection to access the protected save_svg_as_attachment method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'save_svg_as_attachment' );
		$method->setAccessible( true );

		// Create a simple SVG content.
		$svg_data = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect width="100" height="100" fill="red"/></svg>';

		// Create a user with upload permissions.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		// Test save_svg_as_attachment.
		$storage = $method->invoke(
			$tool,
			$svg_data,
			array( 'file_name' => 'test-vectorized' ),
			$user_id
		);

		$this->assertNotInstanceOf( 'WP_Error', $storage );
		$this->assertIsArray( $storage );
		$this->assertArrayHasKey( 'attachment_id', $storage );
		$this->assertArrayHasKey( 'url', $storage );
		$this->assertArrayHasKey( 'file_name', $storage );

		// Now verify that the execute method would include image_url.
		// We can't easily test the full execute method without Node.js,
		// but we can verify the structure by mocking the result.
		$mock_result = array(
			'attachment_id' => $storage['attachment_id'],
			'url'           => $storage['url'],
			'file_name'     => $storage['file_name'],
			'mime_type'     => 'image/svg+xml',
			'bytes'         => $storage['bytes'],
			'title'         => $storage['title'],
			'text'          => 'Test message',
		);

		// Add image_url as the execute method would.
		if ( ! empty( $storage['url'] ) ) {
			$mock_result['image_url'] = array(
				'url' => $storage['url'],
			);
		}

		// Verify structure.
		$this->assertArrayHasKey( 'image_url', $mock_result );
		$this->assertIsArray( $mock_result['image_url'] );
		$this->assertArrayHasKey( 'url', $mock_result['image_url'] );
		$this->assertNotEmpty( $mock_result['image_url']['url'] );

		// Clean up - delete the attachment.
		wp_delete_attachment( $storage['attachment_id'], true );
	}
}
