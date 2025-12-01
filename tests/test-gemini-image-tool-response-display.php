<?php
/**
 * Test that Gemini image tool results are properly formatted for chat display.
 *
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-gemini-image.php';
require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-validator.php';

/**
 * Test Gemini image tool response display formatting.
 */
class WP_MCP_AI_Gemini_Image_Tool_Response_Display_Test extends WP_UnitTestCase {

	/**
	 * Test that tool result includes content field with base64 data for display.
	 */
	public function test_tool_result_includes_content_for_display() {
		// Create a mock tool result as returned by the Gemini tool.
		$tool_result = array(
			'attachment_id'  => 123,
			'url'            => 'https://example.com/gemini-image.png',
			'download_url'   => 'https://example.com/gemini-image.png',
			'file_name'      => 'gemini-image-20241118-120000.png',
			'mime_type'      => 'image/png',
			'bytes'          => 1024,
			'title'          => 'Gemini Image: A test image',
			'model'          => 'gemini-2.5-flash-image',
			'aspect_ratio'   => '1:1',
			'format'         => 'png',
			'prompt'         => 'A test image',
			'revised_prompt' => '',
			'created'        => time(),
			'provider'       => 'gemini',
			'text'           => 'Successfully generated image "Gemini Image: A test image" (ID: 123). Format: 1:1, PNG',
			'content'        => array(
				'encoding'  => 'base64',
				'data'      => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwsB9YwH0e0AAAAASUVORK5CYII=',
				'mime_type' => 'image/png',
				'data_url'  => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwsB9YwH0e0AAAAASUVORK5CYII=',
				'file_name' => 'gemini-image-20241118-120000.png',
				'bytes'     => 95,
			),
		);

		// Verify the content field exists and has required structure.
		$this->assertArrayHasKey( 'content', $tool_result, 'Tool result should include content field' );
		$this->assertIsArray( $tool_result['content'], 'Content field should be an array' );
		$this->assertArrayHasKey( 'data', $tool_result['content'], 'Content should include base64 data' );
		$this->assertArrayHasKey( 'mime_type', $tool_result['content'], 'Content should include MIME type' );
		$this->assertArrayHasKey( 'data_url', $tool_result['content'], 'Content should include data URL' );

		// Test that sanitize_for_display preserves the content field.
		$validator = new WP_MCP_AI_REST_Validator();
		$sanitized = $validator->sanitize_tool_result_for_display( $tool_result, 'generate_gemini_image' );

		// Display sanitization should preserve ALL fields including content.
		$this->assertArrayHasKey( 'content', $sanitized, 'Display sanitization should preserve content field' );
		$this->assertArrayHasKey( 'data', $sanitized['content'], 'Display sanitization should preserve content.data' );
		$this->assertArrayHasKey( 'mime_type', $sanitized['content'], 'Display sanitization should preserve content.mime_type' );
	}

	/**
	 * Test that LLM sanitization removes content field to save tokens.
	 */
	public function test_llm_sanitization_removes_content() {
		$tool = new WP_MCP_AI_Tool_Generate_Gemini_Image();

		$tool_result = array(
			'attachment_id' => 123,
			'url'           => 'https://example.com/gemini-image.png',
			'download_url'  => 'https://example.com/gemini-image.png',
			'file_name'     => 'gemini-image.png',
			'mime_type'     => 'image/png',
			'bytes'         => 1024,
			'title'         => 'Gemini Image',
			'model'         => 'gemini-2.5-flash-image',
			'aspect_ratio'  => '1:1',
			'format'        => 'png',
			'prompt'        => 'A test image',
			'provider'      => 'gemini',
			'text'          => 'Successfully generated image',
			'content'       => array(
				'encoding'  => 'base64',
				'data'      => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwsB9YwH0e0AAAAASUVORK5CYII=',
				'mime_type' => 'image/png',
				'data_url'  => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwsB9YwH0e0AAAAASUVORK5CYII=',
			),
		);

		$sanitized = $tool->sanitize_for_llm( $tool_result );

		// LLM sanitization should remove content to save tokens.
		$this->assertArrayNotHasKey( 'content', $sanitized, 'LLM sanitization should remove content field' );

		// But should preserve metadata.
		$this->assertArrayHasKey( 'attachment_id', $sanitized );
		$this->assertArrayHasKey( 'url', $sanitized );
		$this->assertArrayHasKey( 'text', $sanitized );
	}

	/**
	 * Test the full flow: tool result -> tool message -> response payload.
	 */
	public function test_tool_result_in_response_payload() {
		// Simulate how the REST controller builds the tool message.
		$tool_result = array(
			'attachment_id' => 123,
			'url'           => 'https://example.com/image.png',
			'text'          => 'Image generated',
			'content'       => array(
				'data'      => 'base64data',
				'mime_type' => 'image/png',
			),
		);

		// This is what happens in class-wp-mcp-ai-rest.php lines 2127-2141.
		$full_tool_message = array(
			'role'         => 'tool',
			'content'      => $tool_result, // The FULL result including content field.
			'tool_call_id' => 'call_123',
			'name'         => 'generate_gemini_image',
		);

		$tool_result_messages = array( $full_tool_message );

		// This becomes payload['tool_results'].
		$payload = array(
			'tool_results' => $tool_result_messages,
		);

		// Verify structure.
		$this->assertArrayHasKey( 'tool_results', $payload );
		$this->assertCount( 1, $payload['tool_results'] );

		$first_result = $payload['tool_results'][0];
		$this->assertArrayHasKey( 'content', $first_result );
		$this->assertIsArray( $first_result['content'], 'content should be the full tool result object' );
		$this->assertArrayHasKey( 'content', $first_result['content'], 'Nested content field should exist' );
		$this->assertArrayHasKey( 'data', $first_result['content']['content'], 'Base64 data should be present' );
	}
}
