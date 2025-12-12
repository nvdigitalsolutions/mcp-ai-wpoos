<?php
/**
 * Test that file attachments include image_url structure for agentic workflows.
 *
 * This test validates that when users attach files via the "attach file" button
 * in the shortcode chat client, the backend properly adds the image_url structure
 * that allows AI vision models to "see" the attached images.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-message-attachments.php';

/**
 * Test image_url structure in attachment segments.
 */
class WP_MCP_AI_Attachment_Image_URL_Structure_Test extends WP_UnitTestCase {

	/**
	 * Test that image segments with attachment_id include image_url structure.
	 *
	 * This simulates the "attach file" button workflow where users attach an image
	 * and the backend processes it for the agentic loop.
	 */
	public function test_image_segment_with_attachment_id_includes_image_url() {
		// Create a test image attachment.
		$filename      = WP_MCP_AI_PATH . 'tests/fixtures/test-image.jpg';
		$attachment_id = $this->factory->attachment->create_upload_object( $filename );
		$this->assertGreaterThan( 0, $attachment_id );

		// Get the expected URL.
		$expected_url = wp_get_attachment_url( $attachment_id );
		$this->assertNotEmpty( $expected_url );

		// Create attachment helper.
		$helper = new WP_MCP_AI_Message_Attachments();

		// Prepare image segment with attachment_id (simulates attach file button).
		$segment = $helper->prepare_input_image_segment(
			array(
				'type'          => 'input_image',
				'attachment_id' => $attachment_id,
			)
		);

		// Should return a valid segment.
		$this->assertNotInstanceOf( WP_Error::class, $segment );
		$this->assertIsArray( $segment );

		// Critical assertion: image_url structure must be present.
		$this->assertArrayHasKey( 'image_url', $segment, 'Image segment must have image_url structure for agentic workflows' );
		$this->assertIsArray( $segment['image_url'], 'image_url must be an array' );
		$this->assertArrayHasKey( 'url', $segment['image_url'], 'image_url must have a url field' );
		$this->assertEquals( $expected_url, $segment['image_url']['url'], 'image_url.url must match attachment URL' );

		// Also verify other essential fields are present.
		$this->assertArrayHasKey( 'url', $segment, 'Must have direct url field' );
		$this->assertArrayHasKey( 'attachment_id', $segment, 'Must preserve attachment_id' );
		$this->assertArrayHasKey( 'file_id', $segment, 'Must have file_id' );
	}

	/**
	 * Test that image segments with URL include image_url structure.
	 *
	 * This tests the URL-based path when segments have a direct URL.
	 */
	public function test_image_segment_with_url_includes_image_url() {
		$test_url = 'https://example.com/test-image.jpg';

		// Create attachment helper.
		$helper = new WP_MCP_AI_Message_Attachments();

		// Prepare image segment with URL.
		$segment = $helper->prepare_input_image_segment(
			array(
				'type' => 'input_image',
				'url'  => $test_url,
			)
		);

		// Should return a valid segment.
		$this->assertNotInstanceOf( WP_Error::class, $segment );
		$this->assertIsArray( $segment );

		// Critical assertion: image_url structure must be present.
		$this->assertArrayHasKey( 'image_url', $segment, 'Image segment must have image_url structure' );
		$this->assertIsArray( $segment['image_url'], 'image_url must be an array' );
		$this->assertArrayHasKey( 'url', $segment['image_url'], 'image_url must have a url field' );
		$this->assertEquals( $test_url, $segment['image_url']['url'], 'image_url.url must match provided URL' );
	}

	/**
	 * Test that image segments with file_id include image_url structure.
	 *
	 * This tests the file_id resolution path when segments have an OpenAI/Gemini file_id.
	 */
	public function test_image_segment_with_file_id_includes_image_url() {
		// Create a test image attachment with OpenAI file metadata.
		$filename      = WP_MCP_AI_PATH . 'tests/fixtures/test-image.jpg';
		$attachment_id = $this->factory->attachment->create_upload_object( $filename );
		$this->assertGreaterThan( 0, $attachment_id );

		// Simulate OpenAI file metadata.
		$file_id = 'file-test123';
		update_post_meta( $attachment_id, '_wp_mcp_ai_openai_file', $file_id );

		// Get the expected URL.
		$expected_url = wp_get_attachment_url( $attachment_id );
		$this->assertNotEmpty( $expected_url );

		// Create attachment helper.
		$helper = new WP_MCP_AI_Message_Attachments();

		// Use reflection to register the file_id mapping.
		$reflection = new ReflectionClass( $helper );
		$property   = $reflection->getProperty( 'file_id_index' );
		$property->setAccessible( true );
		$property->setValue( $helper, array( $file_id => $attachment_id ) );

		// Also set up the attachments array.
		$attachments_property = $reflection->getProperty( 'attachments' );
		$attachments_property->setAccessible( true );
		$attachments_property->setValue(
			$helper,
			array(
				$file_id => array(
					'attachment_id' => $attachment_id,
					'title'         => 'Test Image',
					'caption'       => 'Test caption',
					'metadata'      => array(
						'filename'  => 'test-image.jpg',
						'mime_type' => 'image/jpeg',
						'bytes'     => filesize( get_attached_file( $attachment_id ) ),
					),
				),
			)
		);

		// Prepare image segment with file_id.
		$segment = $helper->prepare_input_image_segment(
			array(
				'type'    => 'input_image',
				'file_id' => $file_id,
			)
		);

		// Should return a valid segment.
		$this->assertNotInstanceOf( WP_Error::class, $segment );
		$this->assertIsArray( $segment );

		// Critical assertion: image_url structure must be present.
		$this->assertArrayHasKey( 'image_url', $segment, 'Image segment must have image_url structure when resolved from file_id' );
		$this->assertIsArray( $segment['image_url'], 'image_url must be an array' );
		$this->assertArrayHasKey( 'url', $segment['image_url'], 'image_url must have a url field' );
		$this->assertEquals( $expected_url, $segment['image_url']['url'], 'image_url.url must match attachment URL' );

		// Also verify other fields.
		$this->assertArrayHasKey( 'url', $segment, 'Must have direct url field' );
		$this->assertArrayHasKey( 'attachment_id', $segment, 'Must preserve attachment_id' );
		$this->assertArrayHasKey( 'file_id', $segment, 'Must have file_id' );
	}

	/**
	 * Test that the image_url structure matches OpenAI image tool pattern.
	 *
	 * This ensures consistency with how generate_openai_image returns results.
	 */
	public function test_image_url_structure_matches_openai_pattern() {
		// Create a test image attachment.
		$filename      = WP_MCP_AI_PATH . 'tests/fixtures/test-image.jpg';
		$attachment_id = $this->factory->attachment->create_upload_object( $filename );
		$this->assertGreaterThan( 0, $attachment_id );

		$expected_url = wp_get_attachment_url( $attachment_id );

		// Create attachment helper.
		$helper = new WP_MCP_AI_Message_Attachments();

		// Prepare image segment.
		$segment = $helper->prepare_input_image_segment(
			array(
				'type'          => 'input_image',
				'attachment_id' => $attachment_id,
			)
		);

		// The image_url structure should match the OpenAI image tool pattern:
		// array( 'url' => 'https://...' )
		$this->assertIsArray( $segment['image_url'] );
		$this->assertArrayHasKey( 'url', $segment['image_url'] );
		$this->assertIsString( $segment['image_url']['url'] );
		$this->assertNotEmpty( $segment['image_url']['url'] );

		// Should not have extra fields (keep it simple like OpenAI pattern).
		$this->assertCount( 1, $segment['image_url'], 'image_url should only contain url field' );
	}

	/**
	 * Test that segments from attach file button work with OpenAI vision models.
	 *
	 * This is an integration test that verifies the complete flow from
	 * attach file button to OpenAI-compatible message structure.
	 */
	public function test_attached_file_creates_openai_compatible_structure() {
		// Create a test image attachment (simulates file upload).
		$filename      = WP_MCP_AI_PATH . 'tests/fixtures/test-image.jpg';
		$attachment_id = $this->factory->attachment->create_upload_object( $filename );
		$this->assertGreaterThan( 0, $attachment_id );

		$file_url       = wp_get_attachment_url( $attachment_id );
		$file_mime_type = get_post_mime_type( $attachment_id );
		$file_path      = get_attached_file( $attachment_id );
		$file_bytes     = filesize( $file_path );

		// Simulate what the frontend sends when user attaches a file.
		$frontend_segment = array(
			'type'          => 'input_image',
			'attachment_id' => $attachment_id,
			'url'           => $file_url,
			'file_name'     => basename( $file_path ),
			'mime_type'     => $file_mime_type,
			'bytes'         => $file_bytes,
		);

		// Process through backend.
		$helper  = new WP_MCP_AI_Message_Attachments();
		$segment = $helper->prepare_input_image_segment( $frontend_segment );

		// Should produce an OpenAI-compatible structure.
		$this->assertNotInstanceOf( WP_Error::class, $segment );

		// Must have image_url for vision models.
		$this->assertArrayHasKey( 'image_url', $segment );
		$this->assertArrayHasKey( 'url', $segment['image_url'] );

		// Must preserve all metadata for agentic workflows.
		$this->assertArrayHasKey( 'attachment_id', $segment );
		$this->assertArrayHasKey( 'url', $segment );
		$this->assertArrayHasKey( 'file_name', $segment );
		$this->assertArrayHasKey( 'mime_type', $segment );
		$this->assertArrayHasKey( 'bytes', $segment );

		// Verify the structure can be used by OpenAI's vision API.
		// OpenAI expects messages with content array containing image_url blocks.
		$message_content = array(
			array(
				'type'      => 'image_url',
				'image_url' => $segment['image_url'],
			),
		);

		$this->assertIsArray( $message_content );
		$this->assertEquals( 'image_url', $message_content[0]['type'] );
		$this->assertArrayHasKey( 'url', $message_content[0]['image_url'] );
	}
}
