<?php
/**
 * Test helper class to expose protected methods.
 */
class WP_MCP_AI_OpenAI_Client_Test_Helper extends WP_MCP_AI_OpenAI_Client {

	/**
	 * Expose are_all_attachments_images for testing.
	 *
	 * @param array $attachments Array of attachment payloads.
	 * @return bool
	 */
	public function public_are_all_attachments_images( array $attachments ) {
		return $this->are_all_attachments_images( $attachments );
	}

	/**
	 * Expose should_use_responses_api for testing.
	 *
	 * @param array $messages Sanitized chat messages.
	 * @param array $options  Prepared request options.
	 * @return bool
	 */
	public function public_should_use_responses_api( array $messages, array $options ) {
		return $this->should_use_responses_api( $messages, $options );
	}

	/**
	 * Expose convert_image_files_to_image_url for testing.
	 *
	 * @param array $messages          Array of chat messages.
	 * @param array $attachment_lookup Indexed attachments by file_id.
	 * @return array
	 */
	public function public_convert_image_files_to_image_url( array $messages, array $attachment_lookup ) {
		return $this->convert_image_files_to_image_url( $messages, $attachment_lookup );
	}
}

/**
 * Tests for image attachment detection and API selection.
 */
class WP_MCP_AI_OpenAI_Image_Attachment_Detection_Test extends WP_UnitTestCase {

	/**
	 * Test that images are correctly identified.
	 */
	public function test_is_image_mime_type() {
		$this->assertTrue( WP_MCP_AI_Message_Attachments::is_image_mime_type( 'image/jpeg' ) );
		$this->assertTrue( WP_MCP_AI_Message_Attachments::is_image_mime_type( 'image/png' ) );
		$this->assertTrue( WP_MCP_AI_Message_Attachments::is_image_mime_type( 'image/gif' ) );
		$this->assertTrue( WP_MCP_AI_Message_Attachments::is_image_mime_type( 'image/webp' ) );
		$this->assertFalse( WP_MCP_AI_Message_Attachments::is_image_mime_type( 'application/pdf' ) );
		$this->assertFalse( WP_MCP_AI_Message_Attachments::is_image_mime_type( 'text/plain' ) );
		$this->assertFalse( WP_MCP_AI_Message_Attachments::is_image_mime_type( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ) );
	}

	/**
	 * Test that all images returns true.
	 */
	public function test_are_all_attachments_images_with_only_images() {
		$client = new WP_MCP_AI_OpenAI_Client_Test_Helper();

		$attachments = array(
			array(
				'id'        => 'file-123',
				'mime_type' => 'image/jpeg',
			),
			array(
				'id'        => 'file-456',
				'mime_type' => 'image/png',
			),
		);

		$this->assertTrue( $client->public_are_all_attachments_images( $attachments ) );
	}

	/**
	 * Test that mixed attachments returns false.
	 */
	public function test_are_all_attachments_images_with_mixed_types() {
		$client = new WP_MCP_AI_OpenAI_Client_Test_Helper();

		$attachments = array(
			array(
				'id'        => 'file-123',
				'mime_type' => 'image/jpeg',
			),
			array(
				'id'        => 'file-456',
				'mime_type' => 'application/pdf',
			),
		);

		$this->assertFalse( $client->public_are_all_attachments_images( $attachments ) );
	}

	/**
	 * Test that only PDFs returns false.
	 */
	public function test_are_all_attachments_images_with_only_pdfs() {
		$client = new WP_MCP_AI_OpenAI_Client_Test_Helper();

		$attachments = array(
			array(
				'id'        => 'file-123',
				'mime_type' => 'application/pdf',
			),
			array(
				'id'        => 'file-456',
				'mime_type' => 'text/plain',
			),
		);

		$this->assertFalse( $client->public_are_all_attachments_images( $attachments ) );
	}

	/**
	 * Test that empty attachments returns false.
	 */
	public function test_are_all_attachments_images_with_empty_array() {
		$client = new WP_MCP_AI_OpenAI_Client_Test_Helper();

		$this->assertFalse( $client->public_are_all_attachments_images( array() ) );
	}

	/**
	 * Test should_use_responses_api with only images returns false (use Chat Completions).
	 */
	public function test_should_use_responses_api_with_only_images() {
		$client = new WP_MCP_AI_OpenAI_Client_Test_Helper();

		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Describe this image',
					),
				),
			),
		);

		$options = array(
			'attachments' => array(
				array(
					'id'        => 'file-123',
					'mime_type' => 'image/jpeg',
				),
			),
		);

		// Should use Chat Completions API (returns false) to enable tool calling
		$this->assertFalse( $client->public_should_use_responses_api( $messages, $options ) );
	}

	/**
	 * Test should_use_responses_api with multiple images returns false (use Chat Completions).
	 */
	public function test_should_use_responses_api_with_multiple_images() {
		$client = new WP_MCP_AI_OpenAI_Client_Test_Helper();

		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Compare these images',
					),
				),
			),
		);

		$options = array(
			'attachments' => array(
				array(
					'id'        => 'file-123',
					'mime_type' => 'image/jpeg',
				),
				array(
					'id'        => 'file-456',
					'mime_type' => 'image/png',
				),
				array(
					'id'        => 'file-789',
					'mime_type' => 'image/webp',
				),
			),
		);

		// Should use Chat Completions API (returns false) to enable tool calling with multiple images
		$this->assertFalse( $client->public_should_use_responses_api( $messages, $options ) );
	}

	/**
	 * Test should_use_responses_api with mixed attachments returns true (use Responses API).
	 */
	public function test_should_use_responses_api_with_mixed_attachments() {
		$client = new WP_MCP_AI_OpenAI_Client_Test_Helper();

		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Analyze this image and PDF',
					),
				),
			),
		);

		$options = array(
			'attachments' => array(
				array(
					'id'        => 'file-123',
					'mime_type' => 'image/jpeg',
				),
				array(
					'id'        => 'file-456',
					'mime_type' => 'application/pdf',
				),
			),
		);

		// Should use Responses API (returns true) when there are non-image documents
		$this->assertTrue( $client->public_should_use_responses_api( $messages, $options ) );
	}

	/**
	 * Test should_use_responses_api with only PDFs returns true (use Responses API).
	 */
	public function test_should_use_responses_api_with_only_pdfs() {
		$client = new WP_MCP_AI_OpenAI_Client_Test_Helper();

		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Summarize this PDF',
					),
				),
			),
		);

		$options = array(
			'attachments' => array(
				array(
					'id'        => 'file-123',
					'mime_type' => 'application/pdf',
				),
			),
		);

		// Should use Responses API (returns true) for PDFs
		$this->assertTrue( $client->public_should_use_responses_api( $messages, $options ) );
	}

	/**
	 * Test that image file segments are converted to image_url format.
	 */
	public function test_convert_image_files_to_image_url() {
		$client = new WP_MCP_AI_OpenAI_Client_Test_Helper();

		// Create a test attachment
		$attachment_id = $this->factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg'
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type'    => 'input_image',
						'file_id' => 'file-123',
						'detail'  => 'high',
					),
				),
			),
		);

		$attachment_lookup = array(
			'file-123' => array(
				'id'            => 'file-123',
				'attachment_id' => $attachment_id,
				'mime_type'     => 'image/jpeg',
			),
		);

		$converted = $client->public_convert_image_files_to_image_url( $messages, $attachment_lookup );

		$this->assertIsArray( $converted );
		$this->assertCount( 1, $converted );
		$this->assertSame( 'user', $converted[0]['role'] );
		$this->assertIsArray( $converted[0]['content'] );
		$this->assertCount( 1, $converted[0]['content'] );

		$segment = $converted[0]['content'][0];
		$this->assertSame( 'image_url', $segment['type'] );
		$this->assertArrayHasKey( 'image_url', $segment );
		$this->assertArrayHasKey( 'url', $segment['image_url'] );
		$this->assertStringContainsString( 'http', $segment['image_url']['url'] );
		$this->assertSame( 'high', $segment['image_url']['detail'] );
	}

	/**
	 * Test that non-image segments are left unchanged.
	 */
	public function test_convert_image_files_to_image_url_preserves_non_images() {
		$client = new WP_MCP_AI_OpenAI_Client_Test_Helper();

		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Hello',
					),
					array(
						'type'    => 'input_file',
						'file_id' => 'file-pdf-123',
					),
				),
			),
		);

		$attachment_lookup = array();

		$converted = $client->public_convert_image_files_to_image_url( $messages, $attachment_lookup );

		$this->assertIsArray( $converted );
		$this->assertCount( 1, $converted );
		$this->assertSame( 'user', $converted[0]['role'] );
		$this->assertIsArray( $converted[0]['content'] );
		$this->assertCount( 2, $converted[0]['content'] );

		// Text segment should be unchanged
		$this->assertSame( 'text', $converted[0]['content'][0]['type'] );
		$this->assertSame( 'Hello', $converted[0]['content'][0]['text'] );

		// Input file segment should be unchanged
		$this->assertSame( 'input_file', $converted[0]['content'][1]['type'] );
		$this->assertSame( 'file-pdf-123', $converted[0]['content'][1]['file_id'] );
	}
}
