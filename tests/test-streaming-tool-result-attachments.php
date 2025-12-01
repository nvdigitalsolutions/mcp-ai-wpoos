<?php
/**
 * Tests for streaming tool result attachment display.
 *
 * Validates that tool results with attachments (images, files) are properly
 * formatted in streaming SSE events for chat client display.
 *
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-validator.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-gemini-image.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-openai-image.php';

/**
 * Test streaming tool result attachment formatting.
 */
class WP_MCP_AI_Streaming_Tool_Result_Attachments_Test extends WP_UnitTestCase {

	/**
	 * Test that Gemini image tool result preserves content field for streaming display.
	 */
	public function test_gemini_image_result_preserves_content_for_streaming() {
		$validator = new WP_MCP_AI_REST_Validator();

		// Simulate a Gemini image generation tool result.
		$tool_result = array(
			'attachment_id' => 123,
			'url'           => 'https://example.com/gemini-image.png',
			'download_url'  => 'https://example.com/gemini-image.png',
			'file_name'     => 'gemini-image.png',
			'mime_type'     => 'image/png',
			'bytes'         => 1024,
			'title'         => 'Gemini Image: A test',
			'model'         => 'gemini-2.5-flash-image',
			'aspect_ratio'  => '1:1',
			'format'        => 'png',
			'prompt'        => 'A test',
			'provider'      => 'gemini',
			'text'          => 'Successfully generated image',
			'content'       => array(
				'encoding'  => 'base64',
				'data'      => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwsB9YwH0e0AAAAASUVORK5CYII=',
				'mime_type' => 'image/png',
				'data_url'  => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwsB9YwH0e0AAAAASUVORK5CYII=',
			),
		);

		// Sanitize for display (should preserve everything including content).
		$sanitized = $validator->sanitize_tool_result_for_display( $tool_result, 'generate_gemini_image' );

		// Verify content field is preserved for streaming event.
		$this->assertArrayHasKey( 'content', $sanitized, 'Content field must be preserved for display' );
		$this->assertArrayHasKey( 'data', $sanitized['content'], 'Content.data must be preserved' );
		$this->assertArrayHasKey( 'mime_type', $sanitized['content'], 'Content.mime_type must be preserved' );
		$this->assertArrayHasKey( 'data_url', $sanitized['content'], 'Content.data_url must be preserved' );

		// Verify other essential fields are preserved.
		$this->assertArrayHasKey( 'url', $sanitized );
		$this->assertArrayHasKey( 'download_url', $sanitized );
		$this->assertArrayHasKey( 'attachment_id', $sanitized );
		$this->assertArrayHasKey( 'text', $sanitized );
	}

	/**
	 * Test that OpenAI image tool result preserves content field for streaming display.
	 */
	public function test_openai_image_result_preserves_content_for_streaming() {
		$validator = new WP_MCP_AI_REST_Validator();

		// Simulate an OpenAI image generation tool result.
		$tool_result = array(
			'attachment_id'   => 456,
			'url'             => 'https://example.com/openai-image.png',
			'file_name'       => 'openai-image.png',
			'mime_type'       => 'image/png',
			'bytes'           => 2048,
			'format'          => 'png',
			'size'            => '1024x1024',
			'quality'         => 'hd',
			'model'           => 'dall-e-3',
			'response_format' => 'b64_json',
			'revised_prompt'  => 'A highly detailed test image',
			'text'            => 'Successfully generated image (ID: 456)',
			'content'         => array(
				'encoding'  => 'base64',
				'data'      => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwsB9YwH0e0AAAAASUVORK5CYII=',
				'mime_type' => 'image/png',
				'data_url'  => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwsB9YwH0e0AAAAASUVORK5CYII=',
			),
		);

		// Sanitize for display (should preserve everything including content).
		$sanitized = $validator->sanitize_tool_result_for_display( $tool_result, 'generate_openai_image' );

		// Verify content field is preserved for streaming event.
		$this->assertArrayHasKey( 'content', $sanitized, 'Content field must be preserved for display' );
		$this->assertArrayHasKey( 'data', $sanitized['content'], 'Content.data must be preserved' );
		$this->assertArrayHasKey( 'mime_type', $sanitized['content'], 'Content.mime_type must be preserved' );

		// Verify other essential fields are preserved.
		$this->assertArrayHasKey( 'url', $sanitized );
		$this->assertArrayHasKey( 'attachment_id', $sanitized );
		$this->assertArrayHasKey( 'text', $sanitized );
	}

	/**
	 * Test that text-only tool results work correctly in streaming.
	 */
	public function test_text_only_tool_result_for_streaming() {
		$validator = new WP_MCP_AI_REST_Validator();

		// Simulate a text-only tool result (no attachments).
		$tool_result = array(
			'success' => true,
			'message' => 'Operation completed successfully',
			'data'    => array( 'count' => 5 ),
		);

		$sanitized = $validator->sanitize_tool_result_for_display( $tool_result, 'some_text_tool' );

		// Verify the result is preserved as-is.
		$this->assertArrayHasKey( 'success', $sanitized );
		$this->assertArrayHasKey( 'message', $sanitized );
		$this->assertArrayHasKey( 'data', $sanitized );
		$this->assertTrue( $sanitized['success'] );
		$this->assertEquals( 'Operation completed successfully', $sanitized['message'] );
	}

	/**
	 * Test that SSE tool_execution event includes the full result.
	 */
	public function test_sse_tool_execution_event_structure() {
		// This simulates the SSE event sent by the REST controller at line 2506-2514.
		$tool_result = array(
			'attachment_id' => 789,
			'url'           => 'https://example.com/test.png',
			'text'          => 'Image generated',
			'content'       => array(
				'data'      => 'base64encodeddata',
				'mime_type' => 'image/png',
			),
		);

		$validator = new WP_MCP_AI_REST_Validator();
		$sanitized = $validator->sanitize_tool_result_for_display( $tool_result, 'generate_gemini_image' );

		// Build the SSE event payload structure.
		$sse_event = array(
			'type'      => 'tool_result',
			'tool_name' => 'generate_gemini_image',
			'tool_id'   => 'call_123',
			'result'    => $sanitized,
		);

		// Verify the event structure.
		$this->assertEquals( 'tool_result', $sse_event['type'] );
		$this->assertEquals( 'generate_gemini_image', $sse_event['tool_name'] );
		$this->assertArrayHasKey( 'result', $sse_event );
		$this->assertArrayHasKey( 'content', $sse_event['result'], 'Result must include content for display' );
		$this->assertArrayHasKey( 'url', $sse_event['result'] );
	}

	/**
	 * Test comparison: LLM sanitization vs Display sanitization.
	 */
	public function test_llm_vs_display_sanitization_difference() {
		$tool      = new WP_MCP_AI_Tool_Generate_Gemini_Image();
		$validator = new WP_MCP_AI_REST_Validator();

		$tool_result = array(
			'attachment_id' => 999,
			'url'           => 'https://example.com/image.png',
			'text'          => 'Image generated',
			'content'       => array(
				'data'      => 'largebase64string',
				'mime_type' => 'image/png',
			),
		);

		// LLM sanitization strips content to save tokens.
		$llm_sanitized = $tool->sanitize_for_llm( $tool_result );
		$this->assertArrayNotHasKey( 'content', $llm_sanitized, 'LLM sanitization should remove content' );
		$this->assertArrayHasKey( 'url', $llm_sanitized, 'LLM sanitization should keep URL' );

		// Display sanitization preserves content for frontend rendering.
		$display_sanitized = $validator->sanitize_tool_result_for_display( $tool_result, 'generate_gemini_image' );
		$this->assertArrayHasKey( 'content', $display_sanitized, 'Display sanitization should preserve content' );
		$this->assertArrayHasKey( 'url', $display_sanitized, 'Display sanitization should keep URL' );
	}
}
