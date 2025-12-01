<?php
/**
 * Test Edit Gemini Image Tool with Local WordPress URLs
 *
 * Tests that the edit_gemini_image tool can handle local WordPress URLs
 * by reading files directly from the filesystem instead of downloading via HTTP.
 * This prevents 403 errors when sites have authentication enabled.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for editing images using local WordPress URLs
 */
class Test_Edit_Gemini_Image_Local_URL extends WP_UnitTestCase {

	/**
	 * Get a simple 1x1 red pixel PNG image as binary data.
	 *
	 * @return string Binary PNG image data.
	 */
	protected function get_test_png_data() {
		return base64_decode(
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg=='
		);
	}

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
		$png_data = $this->get_test_png_data();

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
		$png_data = $this->get_test_png_data();

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

			// Assert source is attachment_url or local_url (the fix prioritizes attachment_url_to_postid resolution).
			$this->assertContains(
				$result['source'],
				array( 'attachment_url', 'local_url' ),
				'Should use attachment resolution or local file reading for attachment URL'
			);

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

	/**
	 * Test that local files from image_url are not marked as temporary.
	 *
	 * This prevents data loss when image manipulation tools process local URLs
	 * without attachment_id - the original file should not be deleted.
	 */
	public function test_local_url_files_not_marked_as_temp() {
		// Create a test image file in uploads directory.
		$upload_dir = wp_upload_dir();
		$test_file  = $upload_dir['basedir'] . '/test-local-not-temp-' . time() . '.png';

		// Create a simple 1x1 PNG image (red pixel).
		$png_data = $this->get_test_png_data();

		// Write test file.
		file_put_contents( $test_file, $png_data );
		$this->assertTrue( file_exists( $test_file ), 'Test file should exist' );

		try {
			// Load using image base class (used by rotate, crop, resize tools).
			require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-image-base.php';

			// Create a test subclass to access protected method.
			$test_class = new class() extends WP_MCP_AI_Tool_Image_Base {
				public function get_slug() {
					return 'test'; }
				public function get_name() {
					return 'Test'; }
				public function get_description() {
					return 'Test'; }
				public function get_parameters_schema() {
					return array(); }
				public function execute( array $arguments = array(), array $context = array() ) {
					return array(); }

				public function test_load_source_image( array $arguments, $user_id = 0 ) {
					return $this->load_source_image( $arguments, $user_id );
				}
			};

			// Build URL for the test file.
			$test_url = $upload_dir['baseurl'] . '/' . basename( $test_file );

			$arguments = array(
				'image_url' => $test_url,
			);

			$image_editor = $test_class->test_load_source_image( $arguments, 0 );

			// Verify image editor was loaded successfully.
			$this->assertInstanceOf( 'WP_Image_Editor', $image_editor, 'Should return WP_Image_Editor instance' );

			// CRITICAL: Verify the file is NOT marked as temporary.
			$this->assertObjectNotHasProperty( 'temp_file', $image_editor, 'Local upload file should NOT be marked as temp' );

			// Verify the original file still exists (wasn't deleted during loading).
			$this->assertTrue( file_exists( $test_file ), 'Original upload file should still exist after loading' );

		} finally {
			// Clean up test file.
			if ( file_exists( $test_file ) ) {
				unlink( $test_file );
			}
		}
	}

	/**
	 * Test that downloaded files (external URLs) ARE marked as temporary.
	 *
	 * This ensures the fix doesn't break the existing behavior for external URLs.
	 */
	public function test_external_url_files_marked_as_temp() {
		// We can't easily test actual HTTP downloads in unit tests,.
		// but we can verify that base64 data creates temp files.
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-image-base.php';

		// Create a test subclass.
		$test_class = new class() extends WP_MCP_AI_Tool_Image_Base {
			public function get_slug() {
				return 'test'; }
			public function get_name() {
				return 'Test'; }
			public function get_description() {
				return 'Test'; }
			public function get_parameters_schema() {
				return array(); }
			public function execute( array $arguments = array(), array $context = array() ) {
				return array(); }

			public function test_load_source_image( array $arguments, $user_id = 0 ) {
				return $this->load_source_image( $arguments, $user_id );
			}
		};

		// Test with base64 data (which creates a temp file).
		$png_data     = $this->get_test_png_data();
		$base64_image = base64_encode( $png_data );

		$arguments = array(
			'image_data' => $base64_image,
		);

		$image_editor = $test_class->test_load_source_image( $arguments, 0 );

		// Verify image editor was loaded successfully.
		$this->assertInstanceOf( 'WP_Image_Editor', $image_editor, 'Should return WP_Image_Editor instance' );

		// Verify the file IS marked as temporary (base64 data creates temp files).
		$this->assertObjectHasProperty( 'temp_file', $image_editor, 'Base64 image should be marked as temp' );
		$this->assertNotEmpty( $image_editor->temp_file, 'temp_file property should contain file path' );

		// Clean up the temp file.
		if ( isset( $image_editor->temp_file ) && file_exists( $image_editor->temp_file ) ) {
			unlink( $image_editor->temp_file );
		}
	}

	/**
	 * Test that attachment_url_to_postid resolution works even with scheme variations.
	 *
	 * This is the key fix for the issue where images attached via the chat client's
	 * attach file button fail with HTTP 404 when tools try to use them.
	 */
	public function test_get_source_image_resolves_attachment_url_first() {
		// Create a test image file as a WordPress attachment.
		$upload_dir = wp_upload_dir();
		$test_file  = $upload_dir['basedir'] . '/test-url-resolution-' . time() . '.png';

		// Create a simple 1x1 PNG image (red pixel).
		$png_data = $this->get_test_png_data();

		// Write test file.
		file_put_contents( $test_file, $png_data );

		// Create attachment.
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Test URL Resolution Image',
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
			$this->assertNotInstanceOf( 'WP_Error', $result, 'Should successfully read attachment via URL resolution' );

			// Assert source is attachment_url (new source type from the fix).
			// The fix prioritizes attachment_url_to_postid() resolution.
			$this->assertContains(
				$result['source'],
				array( 'attachment_url', 'local_url' ),
				'Should use attachment resolution or local file reading'
			);

			// Assert data is correct.
			$this->assertEquals( $png_data, $result['data'], 'Data should match original image data' );

			// Assert MIME type is correct.
			$this->assertEquals( 'image/png', $result['mime_type'], 'MIME type should be image/png' );

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
	 * Test that WP_MCP_AI_Tool_Image_Base also resolves attachment URLs first.
	 */
	public function test_image_base_resolves_attachment_url_first() {
		// Create a test image file as a WordPress attachment.
		$upload_dir = wp_upload_dir();
		$test_file  = $upload_dir['basedir'] . '/test-base-url-resolution-' . time() . '.png';

		// Create a simple 1x1 PNG image (red pixel).
		$png_data = $this->get_test_png_data();

		// Write test file.
		file_put_contents( $test_file, $png_data );

		// Create attachment.
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Test Base URL Resolution Image',
				'post_status'    => 'inherit',
			),
			$test_file
		);

		$this->assertNotInstanceOf( 'WP_Error', $attachment_id );
		$this->assertGreaterThan( 0, $attachment_id );

		try {
			// Load using image base class.
			require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-image-base.php';

			// Create a test subclass to access protected method.
			$test_class = new class() extends WP_MCP_AI_Tool_Image_Base {
				public function get_slug() {
					return 'test'; }
				public function get_name() {
					return 'Test'; }
				public function get_description() {
					return 'Test'; }
				public function get_parameters_schema() {
					return array(); }
				public function execute( array $arguments = array(), array $context = array() ) {
					return array(); }

				public function test_load_source_image( array $arguments, $user_id = 0 ) {
					return $this->load_source_image( $arguments, $user_id );
				}
			};

			// Get the attachment URL.
			$attachment_url = wp_get_attachment_url( $attachment_id );
			$this->assertNotEmpty( $attachment_url );

			$arguments = array(
				'image_url' => $attachment_url,
			);

			$image_editor = $test_class->test_load_source_image( $arguments, 0 );

			// Verify image editor was loaded successfully.
			$this->assertInstanceOf( 'WP_Image_Editor', $image_editor, 'Should return WP_Image_Editor instance' );

			// CRITICAL: Verify the file is NOT marked as temporary.
			// This means the attachment was resolved successfully.
			$this->assertObjectNotHasProperty( 'temp_file', $image_editor, 'Attachment file should NOT be marked as temp' );

			// Verify the original file still exists.
			$this->assertTrue( file_exists( $test_file ), 'Original file should still exist' );

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
	 * Test that resolve_attachment_id_from_url tries alternate schemes.
	 *
	 * This ensures that if the URL has a different scheme (http vs https)
	 * than what WordPress is configured with, the attachment can still be resolved.
	 */
	public function test_resolve_attachment_id_from_url_tries_alternate_scheme() {
		// Create a test image file as a WordPress attachment.
		$upload_dir = wp_upload_dir();
		$test_file  = $upload_dir['basedir'] . '/test-scheme-resolution-' . time() . '.png';

		// Create a simple 1x1 PNG image (red pixel).
		$png_data = $this->get_test_png_data();

		// Write test file.
		file_put_contents( $test_file, $png_data );

		// Create attachment.
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Test Scheme Resolution Image',
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
			$method     = $reflection->getMethod( 'resolve_attachment_id_from_url' );
			$method->setAccessible( true );

			// Get the attachment URL.
			$attachment_url = wp_get_attachment_url( $attachment_id );
			$this->assertNotEmpty( $attachment_url );

			// Test with the original URL - should work.
			$result = $method->invoke( $tool, $attachment_url );
			$this->assertEquals( $attachment_id, $result, 'Should resolve attachment ID from original URL' );

			// Create an alternate-scheme URL.
			$alternate_url = '';
			if ( 0 === strpos( $attachment_url, 'https://' ) ) {
				$alternate_url = 'http://' . substr( $attachment_url, 8 );
			} else {
				$alternate_url = 'https://' . substr( $attachment_url, 7 );
			}

			// Test with alternate scheme URL - should still work due to the fallback.
			$result = $method->invoke( $tool, $alternate_url );
			$this->assertEquals( $attachment_id, $result, 'Should resolve attachment ID from alternate-scheme URL' );

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
	 * Test that resolve_attachment_id_from_url returns 0 for non-existent URLs.
	 */
	public function test_resolve_attachment_id_from_url_returns_zero_for_missing() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php';
		$tool = new WP_MCP_AI_Tool_Edit_Gemini_Image();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'resolve_attachment_id_from_url' );
		$method->setAccessible( true );

		// Test with non-existent URL.
		$result = $method->invoke( $tool, 'https://example.com/non-existent-image.png' );
		$this->assertEquals( 0, $result, 'Should return 0 for non-existent URL' );

		// Test with empty URL.
		$result = $method->invoke( $tool, '' );
		$this->assertEquals( 0, $result, 'Should return 0 for empty URL' );
	}
}
