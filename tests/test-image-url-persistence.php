<?php
/**
 * Tests for image URL persistence in chat client.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Image_URL_Persistence_Test extends WP_UnitTestCase {

	/**
	 * Test that prepare_input_attachment_segment routes image_url segments correctly.
	 */
	public function test_prepare_input_attachment_segment_handles_image_url() {
		$attachments_helper = new WP_MCP_AI_Message_Attachments();

		// Test with image_url segment type.
		$segment = array(
			'type'      => 'image_url',
			'image_url' => array(
				'url'    => 'https://example.com/image.jpg',
				'detail' => 'high',
			),
			'caption'   => 'Test image',
		);

		$result = $attachments_helper->prepare_input_attachment_segment( $segment );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'type', $result );
		$this->assertEquals( 'input_image', $result['type'] );
		$this->assertArrayHasKey( 'image_url', $result );
		$this->assertArrayHasKey( 'url', $result['image_url'] );
		$this->assertEquals( 'https://example.com/image.jpg', $result['image_url']['url'] );
		$this->assertArrayHasKey( 'caption', $result );
		$this->assertEquals( 'Test image', $result['caption'] );
		$this->assertArrayHasKey( 'detail', $result );
		$this->assertEquals( 'high', $result['detail'] );
	}

	/**
	 * Test that prepare_input_attachment_segment handles image_url as string.
	 */
	public function test_prepare_input_attachment_segment_handles_image_url_string() {
		$attachments_helper = new WP_MCP_AI_Message_Attachments();

		// Test with image_url as a string.
		$segment = array(
			'type'      => 'image_url',
			'image_url' => 'https://example.com/image.jpg',
		);

		$result = $attachments_helper->prepare_input_attachment_segment( $segment );

		$this->assertIsArray( $result );
		$this->assertEquals( 'input_image', $result['type'] );
		$this->assertArrayHasKey( 'image_url', $result );
		$this->assertEquals( 'https://example.com/image.jpg', $result['image_url']['url'] );
	}

	/**
	 * Test that prepare_input_attachment_segment handles image_file segment type.
	 */
	public function test_prepare_input_attachment_segment_handles_image_file() {
		$attachments_helper = new WP_MCP_AI_Message_Attachments();

		// Test with image_file segment type (with URL).
		$segment = array(
			'type' => 'image_file',
			'url'  => 'https://example.com/image.jpg',
		);

		$result = $attachments_helper->prepare_input_attachment_segment( $segment );

		$this->assertIsArray( $result );
		$this->assertEquals( 'input_image', $result['type'] );
		$this->assertArrayHasKey( 'image_url', $result );
		$this->assertEquals( 'https://example.com/image.jpg', $result['image_url']['url'] );
	}

	/**
	 * Test that prepare_input_attachment_segment returns error for invalid segment.
	 */
	public function test_prepare_input_attachment_segment_returns_error_for_invalid_segment() {
		$attachments_helper = new WP_MCP_AI_Message_Attachments();

		// Test with invalid segment (no type, no text).
		$segment = array(
			'foo' => 'bar',
		);

		$result = $attachments_helper->prepare_input_attachment_segment( $segment );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_attachment_segment', $result->get_error_code() );
	}

	/**
	 * Test that prepare_input_image_segment handles image_url field correctly.
	 */
	public function test_prepare_input_image_segment_handles_image_url_object() {
		$attachments_helper = new WP_MCP_AI_Message_Attachments();

		// Test with image_url as object.
		$segment = array(
			'image_url' => array(
				'url'    => 'https://example.com/image.jpg',
				'detail' => 'low',
			),
		);

		$result = $attachments_helper->prepare_input_image_segment( $segment );

		$this->assertIsArray( $result );
		$this->assertEquals( 'input_image', $result['type'] );
		$this->assertArrayHasKey( 'image_url', $result );
		$this->assertEquals( 'https://example.com/image.jpg', $result['image_url']['url'] );
		// Detail should be extracted from image_url.detail
		$this->assertArrayHasKey( 'detail', $result );
		$this->assertEquals( 'low', $result['detail'] );
	}

	/**
	 * Test that prepare_input_image_segment sanitizes invalid URLs.
	 */
	public function test_prepare_input_image_segment_sanitizes_invalid_url() {
		$attachments_helper = new WP_MCP_AI_Message_Attachments();

		// Test with invalid URL.
		$segment = array(
			'image_url' => array(
				'url' => 'javascript:alert(1)',
			),
		);

		$result = $attachments_helper->prepare_input_image_segment( $segment );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_image_url', $result->get_error_code() );
	}

	/**
	 * Test that image_url in message content is preserved through sanitization.
	 */
	public function test_image_url_preserved_in_message_sanitization() {
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-validator.php';

		$validator          = new WP_MCP_AI_REST_Validator();
		$attachments_helper = new WP_MCP_AI_Message_Attachments();

		$content = array(
			array(
				'type' => 'text',
				'text' => 'Here is an image:',
			),
			array(
				'type'      => 'image_url',
				'image_url' => array(
					'url'    => 'https://example.com/test.jpg',
					'detail' => 'high',
				),
			),
		);

		$result = $validator->sanitize_message_content( $content, $attachments_helper );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );

		// First segment should be text.
		$this->assertEquals( 'text', $result[0]['type'] );
		$this->assertEquals( 'Here is an image:', $result[0]['text'] );

		// Second segment should be sanitized image.
		$this->assertEquals( 'input_image', $result[1]['type'] );
		$this->assertArrayHasKey( 'image_url', $result[1] );
		$this->assertEquals( 'https://example.com/test.jpg', $result[1]['image_url']['url'] );
		$this->assertEquals( 'high', $result[1]['detail'] );
	}
}
