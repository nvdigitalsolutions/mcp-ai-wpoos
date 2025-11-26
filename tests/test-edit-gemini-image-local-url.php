<?php
/**
 * Test Edit Gemini Image Tool with Local WordPress URLs
 *
 * Tests that the edit_gemini_image tool can handle local WordPress URLs
 * by reading files directly from the filesystem instead of downloading via HTTP.
 * This prevents 403 errors when sites have authentication enabled.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for editing images using local WordPress URLs
 */
class Test_Edit_Gemini_Image_Local_URL extends WP_UnitTestCase {

	/**
	 * Test that is_local_wordpress_url correctly identifies local URLs
	 */
	public function test_is_local_wordpress_url_detects_upload_urls() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php';
		$tool = new WP_MCP_AI_Tool_Edit_Gemini_Image();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'is_local_wordpress_url' );
		$method->setAccessible( true );

		// Get WordPress upload directory URL.
		$upload_dir = wp_upload_dir();
		$base_url   = $upload_dir['baseurl'];

		// Test local WordPress upload URL.
		$local_url = $base_url . '/2024/01/test-image.png';
		$this->assertTrue( $method->invoke( $tool, $local_url ), 'Upload directory URL should be detected as local' );

		// Test home URL.
		$home_url = home_url( '/wp-content/uploads/image.jpg' );
		$this->assertTrue( $method->invoke( $tool, $home_url ), 'Home URL should be detected as local' );

		// Test external URL.
		$external_url = 'https://external-site.com/image.png';
		$this->assertFalse( $method->invoke( $tool, $external_url ), 'External URL should not be detected as local' );

		// Test empty URL.
		$this->assertFalse( $method->invoke( $tool, '' ), 'Empty URL should return false' );
	}

	/**
	 * Test that get_file_path_from_local_url converts URLs to file paths
	 */
	public function test_get_file_path_from_local_url_converts_urls() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php';
		$tool = new WP_MCP_AI_Tool_Edit_Gemini_Image();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'get_file_path_from_local_url' );
		$method->setAccessible( true );

		// Get WordPress upload directory info.
		$upload_dir = wp_upload_dir();
		$base_url   = $upload_dir['baseurl'];
		$base_dir   = $upload_dir['basedir'];

		// Test conversion of upload URL to file path.
		$url           = $base_url . '/2024/01/test-image.png';
		$expected_path = wp_normalize_path( $base_dir . '/2024/01/test-image.png' );
		$actual_path   = $method->invoke( $tool, $url );

		$this->assertEquals( $expected_path, $actual_path, 'URL should be converted to correct file path' );

		// Test empty URL.
		$this->assertFalse( $method->invoke( $tool, '' ), 'Empty URL should return false' );
	}

	/**
	 * Test that get_source_image reads local files directly
	 */
	public function test_get_source_image_reads_local_file_directly() {
		// Create a test image file.
		$upload_dir = wp_upload_dir();
		$test_file  = $upload_dir['basedir'] . '/test-image-' . time() . '.png';

		// Create a simple 1x1 PNG image (red pixel).
		$png_data = base64_decode(
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg=='
		);

		// Write test file.
		file_put_contents( $test_file, $png_data );

		// Ensure file exists.
		$this->assertTrue( file_exists( $test_file ), 'Test file should exist' );

		try {
			// Create tool instance.
			require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php';
			$tool = new WP_MCP_AI_Tool_Edit_Gemini_Image();

			// Use reflection to access protected method.
			$reflection = new ReflectionClass( $tool );
			$method     = $reflection->getMethod( 'get_source_image' );
			$method->setAccessible( true );

			// Build URL for the test file.
			$test_url = $upload_dir['baseurl'] . '/' . basename( $test_file );

			$arguments = array(
				'image_url' => $test_url,
			);

			$result = $method->invoke( $tool, $arguments, 0 );

			// Assert result is not an error.
			$this->assertNotInstanceOf( 'WP_Error', $result, 'Should not return error when reading local file' );

			// Assert result has expected structure.
			$this->assertIsArray( $result );
			$this->assertArrayHasKey( 'data', $result );
			$this->assertArrayHasKey( 'mime_type', $result );
			$this->assertArrayHasKey( 'source', $result );

			// Assert source type is local_url.
			$this->assertEquals( 'local_url', $result['source'], 'Source should be local_url when reading from filesystem' );

			// Assert MIME type is image.
			$this->assertStringContainsString( 'image/', $result['mime_type'], 'MIME type should be an image type' );

			// Assert data matches the file content.
			$this->assertEquals( $png_data, $result['data'], 'Data should match file content' );

		} finally {
			// Clean up test file.
			if ( file_exists( $test_file ) ) {
				unlink( $test_file );
			}
		}
	}

	/**
	 * Test that get_source_image falls back to HTTP for external URLs
	 */
	public function test_get_source_image_uses_http_for_external_urls() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php';
		$tool = new WP_MCP_AI_Tool_Edit_Gemini_Image();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'get_source_image' );
		$method->setAccessible( true );

		// Use a non-existent external URL (should fail with download error).
		$arguments = array(
			'image_url' => 'https://external-site-that-does-not-exist-12345.com/image.png',
		);

		$result = $method->invoke( $tool, $arguments, 0 );

		// Assert result is an error (because external URL doesn't exist).
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_download_error', $result->get_error_code() );
	}

	/**
	 * Test that get_source_image works with attachment created via wp_insert_attachment
	 */
	public function test_get_source_image_with_attachment_url() {
		// Create a test image file as a WordPress attachment.
		$upload_dir = wp_upload_dir();
		$test_file  = $upload_dir['basedir'] . '/test-attachment-' . time() . '.png';

		// Create a simple 1x1 PNG image (red pixel).
		$png_data = base64_decode(
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg=='
		);

		// Write test file.
		file_put_contents( $test_file, $png_data );

		// Create attachment.
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Test Image',
				'post_status'    => 'inherit',
			),
			$test_file
		);

		$this->assertNotInstanceOf( 'WP_Error', $attachment_id );
		$this->assertGreaterThan( 0, $attachment_id );

		try {
			// Create tool instance.
			require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php';
			$tool = new WP_MCP_AI_Tool_Edit_Gemini_Image();

			// Use reflection to access protected method.
			$reflection = new ReflectionClass( $tool );
			$method     = $reflection->getMethod( 'get_source_image' );
			$method->setAccessible( true );

			// Get the attachment URL.
			$attachment_url = wp_get_attachment_url( $attachment_id );
			$this->assertNotEmpty( $attachment_url );

			$arguments = array(
				'image_url' => $attachment_url,
			);

			$result = $method->invoke( $tool, $arguments, 0 );

			// Assert result is not an error.
			$this->assertNotInstanceOf( 'WP_Error', $result, 'Should successfully read attachment via local URL' );

			// Assert source is local_url.
			$this->assertEquals( 'local_url', $result['source'], 'Should use local file reading for attachment URL' );

			// Assert data is correct.
			$this->assertEquals( $png_data, $result['data'], 'Data should match original image data' );

		} finally {
			// Clean up attachment and file.
			if ( $attachment_id > 0 ) {
				wp_delete_attachment( $attachment_id, true );
			}
			if ( file_exists( $test_file ) ) {
				unlink( $test_file );
			}
		}
	}

	/**
	 * Test that local file reading falls back to HTTP if file doesn't exist
	 */
	public function test_get_source_image_falls_back_to_http_if_local_file_missing() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php';
		$tool = new WP_MCP_AI_Tool_Edit_Gemini_Image();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'get_source_image' );
		$method->setAccessible( true );

		// Create a local-looking URL that doesn't have a corresponding file.
		$upload_dir = wp_upload_dir();
		$fake_url   = $upload_dir['baseurl'] . '/non-existent-image-12345.png';

		$arguments = array(
			'image_url' => $fake_url,
		);

		$result = $method->invoke( $tool, $arguments, 0 );

		// Should attempt HTTP download and fail (since file doesn't exist).
		// The exact error depends on whether the HTTP request returns 404 or fails to connect.
		$this->assertInstanceOf( 'WP_Error', $result, 'Should return error when file not found' );
		$this->assertEquals( 'wp_mcp_ai_download_error', $result->get_error_code() );
	}
}
