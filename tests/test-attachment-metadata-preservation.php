<?php
/**
 * Tests for attachment metadata preservation in segments.
 *
 * Verifies that complete file metadata (url, file_name, mime_type, bytes)
 * is preserved when processing attachment segments for agentic workflows.
 *
 * @package WP_MCP_AI
 */

class WP_MCP_AI_Attachment_Metadata_Preservation_Test extends WP_UnitTestCase {

	/**
	 * Test that image attachment segments include complete metadata.
	 */
	public function test_image_segment_includes_complete_metadata() {
		// Create a test image attachment.
		$filename      = WP_MCP_AI_PATH . 'tests/fixtures/test-image.jpg';
		$attachment_id = $this->factory->attachment->create_upload_object( $filename );
		$this->assertGreaterThan( 0, $attachment_id );

		// Get the expected metadata.
		$expected_url       = wp_get_attachment_url( $attachment_id );
		$expected_mime_type = get_post_mime_type( $attachment_id );
		$file_path          = get_attached_file( $attachment_id );
		$expected_bytes     = filesize( $file_path );

		// Create attachment helper.
		$helper = new WP_MCP_AI_Message_Attachments();

		// Prepare image segment with attachment_id.
		$segment = $helper->prepare_input_image_segment(
			array(
				'type'          => 'input_image',
				'attachment_id' => $attachment_id,
			)
		);

		// Should return a valid segment with complete metadata.
		$this->assertNotInstanceOf( WP_Error::class, $segment );
		$this->assertIsArray( $segment );
		
		// Check basic fields.
		$this->assertArrayHasKey( 'type', $segment );
		$this->assertSame( 'input_image', $segment['type'] );
		$this->assertArrayHasKey( 'file_id', $segment );

		// Check that complete metadata is present for agentic workflows.
		$this->assertArrayHasKey( 'url', $segment, 'Image segment should have url field' );
		$this->assertSame( $expected_url, $segment['url'], 'URL should match attachment URL' );

		$this->assertArrayHasKey( 'file_name', $segment, 'Image segment should have file_name field' );
		$this->assertNotEmpty( $segment['file_name'], 'file_name should not be empty' );

		$this->assertArrayHasKey( 'mime_type', $segment, 'Image segment should have mime_type field' );
		$this->assertSame( $expected_mime_type, $segment['mime_type'], 'mime_type should match attachment mime type' );

		$this->assertArrayHasKey( 'bytes', $segment, 'Image segment should have bytes field' );
		$this->assertSame( $expected_bytes, $segment['bytes'], 'bytes should match file size' );
	}

	/**
	 * Test that file attachment segments include complete metadata.
	 */
	public function test_file_segment_includes_complete_metadata() {
		// Create a test file attachment.
		$file_content = 'Test file content for metadata preservation test';
		$upload       = wp_upload_bits( 'test-metadata-file.txt', null, $file_content );
		$this->assertFalse( $upload['error'] );

		$attachment_id = $this->factory->attachment->create_object(
			array(
				'file'           => $upload['file'],
				'post_mime_type' => 'text/plain',
			)
		);
		$this->assertGreaterThan( 0, $attachment_id );

		// Get the expected metadata.
		$expected_url       = wp_get_attachment_url( $attachment_id );
		$expected_mime_type = get_post_mime_type( $attachment_id );
		$file_path          = get_attached_file( $attachment_id );
		$expected_bytes     = filesize( $file_path );

		// Create attachment helper.
		$helper = new WP_MCP_AI_Message_Attachments();

		// Prepare file segment with attachment_id.
		$segment = $helper->prepare_input_file_segment(
			array(
				'type'          => 'input_file',
				'attachment_id' => $attachment_id,
			)
		);

		// Should return a valid segment with complete metadata.
		$this->assertNotInstanceOf( WP_Error::class, $segment );
		$this->assertIsArray( $segment );
		
		// Check basic fields.
		$this->assertArrayHasKey( 'type', $segment );
		$this->assertSame( 'input_file', $segment['type'] );
		$this->assertArrayHasKey( 'file_id', $segment );

		// Check that complete metadata is present for agentic workflows.
		$this->assertArrayHasKey( 'url', $segment, 'File segment should have url field' );
		$this->assertSame( $expected_url, $segment['url'], 'URL should match attachment URL' );

		$this->assertArrayHasKey( 'file_name', $segment, 'File segment should have file_name field' );
		$this->assertNotEmpty( $segment['file_name'], 'file_name should not be empty' );

		$this->assertArrayHasKey( 'name', $segment, 'File segment should have name field for compatibility' );
		$this->assertSame( $segment['file_name'], $segment['name'], 'name should match file_name' );

		$this->assertArrayHasKey( 'mime_type', $segment, 'File segment should have mime_type field' );
		$this->assertSame( $expected_mime_type, $segment['mime_type'], 'mime_type should match attachment mime type' );

		$this->assertArrayHasKey( 'bytes', $segment, 'File segment should have bytes field' );
		$this->assertSame( $expected_bytes, $segment['bytes'], 'bytes should match file size' );
	}

	/**
	 * Test that image segments with URL preserve metadata.
	 */
	public function test_image_segment_with_url_preserves_metadata() {
		// Create attachment helper.
		$helper = new WP_MCP_AI_Message_Attachments();

		// Prepare image segment with URL and metadata.
		$test_url       = 'https://example.com/image.jpg';
		$test_file_name = 'example-image.jpg';
		$test_mime_type = 'image/jpeg';
		$test_bytes     = 12345;

		$segment = $helper->prepare_input_image_segment(
			array(
				'type'      => 'input_image',
				'url'       => $test_url,
				'file_name' => $test_file_name,
				'mime_type' => $test_mime_type,
				'bytes'     => $test_bytes,
			)
		);

		// Should return a valid segment with metadata preserved.
		$this->assertNotInstanceOf( WP_Error::class, $segment );
		$this->assertIsArray( $segment );
		
		// Check that metadata was preserved.
		$this->assertArrayHasKey( 'url', $segment );
		$this->assertSame( $test_url, $segment['url'] );

		$this->assertArrayHasKey( 'file_name', $segment );
		$this->assertSame( $test_file_name, $segment['file_name'] );

		$this->assertArrayHasKey( 'mime_type', $segment );
		$this->assertSame( $test_mime_type, $segment['mime_type'] );

		$this->assertArrayHasKey( 'bytes', $segment );
		$this->assertSame( $test_bytes, $segment['bytes'] );
	}

	/**
	 * Test that file segments with URL preserve metadata.
	 */
	public function test_file_segment_with_url_preserves_metadata() {
		// Create attachment helper.
		$helper = new WP_MCP_AI_Message_Attachments();

		// Prepare file segment with URL and metadata.
		$test_url       = 'https://example.com/document.pdf';
		$test_file_name = 'document.pdf';
		$test_mime_type = 'application/pdf';
		$test_bytes     = 54321;

		$segment = $helper->prepare_input_file_segment(
			array(
				'type'      => 'input_file',
				'url'       => $test_url,
				'file_name' => $test_file_name,
				'mime_type' => $test_mime_type,
				'bytes'     => $test_bytes,
			)
		);

		// Should return a valid segment with metadata preserved.
		$this->assertNotInstanceOf( WP_Error::class, $segment );
		$this->assertIsArray( $segment );
		
		// Check that metadata was preserved.
		$this->assertArrayHasKey( 'url', $segment );
		$this->assertSame( $test_url, $segment['url'] );

		$this->assertArrayHasKey( 'file_name', $segment );
		$this->assertSame( $test_file_name, $segment['file_name'] );

		$this->assertArrayHasKey( 'name', $segment );
		$this->assertSame( $test_file_name, $segment['name'] );

		$this->assertArrayHasKey( 'mime_type', $segment );
		$this->assertSame( $test_mime_type, $segment['mime_type'] );

		$this->assertArrayHasKey( 'bytes', $segment );
		$this->assertSame( $test_bytes, $segment['bytes'] );
	}
}
