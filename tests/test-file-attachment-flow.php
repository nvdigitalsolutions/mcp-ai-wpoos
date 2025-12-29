<?php
/**
 * Tests for file attachment flow from upload to AI provider.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_File_Attachment_Flow_Test extends WP_UnitTestCase {

	/**
	 * Test that attachment segments are properly created for images.
	 */
	public function test_image_attachment_segment_creation() {
		// Create a test image attachment.
		$attachment_id = $this->factory->attachment->create_upload_object( WP_MCP_AI_PATH . 'tests/fixtures/test-image.jpg' );
		$this->assertGreaterThan( 0, $attachment_id );

		// Create attachment helper.
		$helper = new WP_MCP_AI_Message_Attachments();

		// Prepare image segment with attachment_id.
		$segment = $helper->prepare_input_image_segment(
			array(
				'type'          => 'input_image',
				'attachment_id' => $attachment_id,
			)
		);

		// Should return a valid segment.
		$this->assertNotInstanceOf( WP_Error::class, $segment );
		$this->assertIsArray( $segment );
		$this->assertArrayHasKey( 'type', $segment );
		$this->assertSame( 'input_image', $segment['type'] );
		$this->assertArrayHasKey( 'file_id', $segment );
	}

	/**
	 * Test that attachment segments are properly created for files.
	 */
	public function test_file_attachment_segment_creation() {
		// Create a test file attachment.
		$upload = wp_upload_bits( 'test-file.txt', null, 'Test file content' );
		$this->assertFalse( $upload['error'] );

		$attachment_id = $this->factory->attachment->create_object(
			array(
				'file'           => $upload['file'],
				'post_mime_type' => 'text/plain',
			)
		);
		$this->assertGreaterThan( 0, $attachment_id );

		// Create attachment helper.
		$helper = new WP_MCP_AI_Message_Attachments();

		// Prepare file segment with attachment_id.
		$segment = $helper->prepare_input_file_segment(
			array(
				'type'          => 'input_file',
				'attachment_id' => $attachment_id,
			)
		);

		// Should return a valid segment.
		$this->assertNotInstanceOf( WP_Error::class, $segment );
		$this->assertIsArray( $segment );
		$this->assertArrayHasKey( 'type', $segment );
		$this->assertSame( 'input_file', $segment['type'] );
		$this->assertArrayHasKey( 'file_id', $segment );
	}

	/**
	 * Test that unsupported MIME types are rejected.
	 */
	public function test_unsupported_mime_type_rejected() {
		// Create a test file with unsupported MIME type.
		$upload = wp_upload_bits( 'test-file.exe', null, 'Binary content' );
		$this->assertFalse( $upload['error'] );

		$attachment_id = $this->factory->attachment->create_object(
			array(
				'file'           => $upload['file'],
				'post_mime_type' => 'application/x-msdownload',
			)
		);
		$this->assertGreaterThan( 0, $attachment_id );

		// Create attachment helper.
		$helper = new WP_MCP_AI_Message_Attachments();

		// Attempt to prepare file segment.
		$segment = $helper->prepare_input_file_segment(
			array(
				'type'          => 'input_file',
				'attachment_id' => $attachment_id,
			)
		);

		// Should return an error for unsupported MIME type.
		$this->assertInstanceOf( WP_Error::class, $segment );
		$this->assertSame( 'wp_mcp_ai_attachment_unsupported_mime', $segment->get_error_code() );
	}

	/**
	 * Test that attachment permissions are checked.
	 */
	public function test_attachment_permission_check() {
		// Create a private attachment by another user.
		$other_user    = $this->factory->user->create();
		$attachment_id = $this->factory->attachment->create_object(
			array(
				'file'        => WP_MCP_AI_PATH . 'tests/fixtures/test-image.jpg',
				'post_author' => $other_user,
				'post_status' => 'private',
			)
		);
		$this->assertGreaterThan( 0, $attachment_id );

		// Set current user to a different user without permissions.
		$current_user = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $current_user );

		// Create attachment helper.
		$helper = new WP_MCP_AI_Message_Attachments();

		// Attempt to prepare image segment.
		$segment = $helper->prepare_input_image_segment(
			array(
				'type'          => 'input_image',
				'attachment_id' => $attachment_id,
			)
		);

		// Should return an error for forbidden access.
		$this->assertInstanceOf( WP_Error::class, $segment );
		$this->assertSame( 'wp_mcp_ai_attachment_forbidden', $segment->get_error_code() );
	}

	/**
	 * Test that file size limits are enforced.
	 */
	public function test_file_size_limit_enforced() {
		// Create a test file.
		$upload = wp_upload_bits( 'test-large-file.txt', null, str_repeat( 'X', 1024 ) );
		$this->assertFalse( $upload['error'] );

		$attachment_id = $this->factory->attachment->create_object(
			array(
				'file'           => $upload['file'],
				'post_mime_type' => 'text/plain',
			)
		);
		$this->assertGreaterThan( 0, $attachment_id );

		// Create attachment helper.
		$helper = new WP_MCP_AI_Message_Attachments();

		// Set a very low file size limit via filter.
		add_filter(
			'wp_mcp_ai_max_attachment_bytes',
			function () {
				return 512; // 512 bytes limit.
			}
		);

		// Attempt to prepare file segment.
		$segment = $helper->prepare_input_file_segment(
			array(
				'type'          => 'input_file',
				'attachment_id' => $attachment_id,
			)
		);

		// Should return an error for file too large.
		$this->assertInstanceOf( WP_Error::class, $segment );
		$this->assertSame( 'wp_mcp_ai_attachment_too_large', $segment->get_error_code() );
	}
}
