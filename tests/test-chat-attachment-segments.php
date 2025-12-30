<?php
/**
 * Test chat attachment segment handling
 *
 * @package WP_MCP_AI
 */
class Test_Chat_Attachment_Segments extends WP_UnitTestCase {

	/**
	 * Test that attachment segments are properly created and processed.
	 */
	public function test_attachment_segment_processing() {
		// Create a test image attachment.
		$filename      = DIR_TESTDATA . '/images/test-image.jpg';
		$attachment_id = $this->factory->attachment->create_upload_object( $filename );

		$this->assertGreaterThan( 0, $attachment_id, 'Attachment should be created' );

		// Create a message with an attachment segment.
		$message = array(
			'role'    => 'user',
			'content' => array(
				array(
					'type' => 'text',
					'text' => 'What is in this image?',
				),
				array(
					'type'          => 'input_image',
					'attachment_id' => $attachment_id,
				),
			),
		);

		// Process the message through the REST validator.
		if ( ! class_exists( 'WP_MCP_AI_REST_Validator' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-validator.php';
		}

		$validator = new WP_MCP_AI_REST_Validator();
		$sanitized = $validator->sanitize_messages( array( $message ) );

		$this->assertIsArray( $sanitized, 'Sanitized messages should be an array' );
		$this->assertCount( 1, $sanitized, 'Should have one message' );

		$sanitized_message = $sanitized[0];
		$this->assertEquals( 'user', $sanitized_message['role'], 'Role should be user' );
		$this->assertIsArray( $sanitized_message['content'], 'Content should be an array' );

		// Find the attachment segment.
		$attachment_segment = null;
		foreach ( $sanitized_message['content'] as $segment ) {
			if ( isset( $segment['type'] ) && 'input_image' === $segment['type'] ) {
				$attachment_segment = $segment;
				break;
			}
		}

		$this->assertNotNull( $attachment_segment, 'Attachment segment should be present after sanitization' );
		$this->assertArrayHasKey( 'file_id', $attachment_segment, 'Attachment segment should have file_id after processing' );
	}

	/**
	 * Test that attachment segments without attachment_id are rejected.
	 */
	public function test_attachment_segment_without_id_rejected() {
		$message = array(
			'role'    => 'user',
			'content' => array(
				array(
					'type' => 'text',
					'text' => 'What is in this image?',
				),
				array(
					'type' => 'input_image',
					// Missing attachment_id.
				),
			),
		);

		if ( ! class_exists( 'WP_MCP_AI_REST_Validator' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-validator.php';
		}

		$validator = new WP_MCP_AI_REST_Validator();
		$result    = $validator->sanitize_messages( array( $message ) );

		// Should return WP_Error because attachment segment is invalid.
		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error for invalid attachment segment' );
	}
}
