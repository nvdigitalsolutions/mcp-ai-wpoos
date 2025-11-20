<?php
/**
 * Integration test for issue #1424 - OpenAI "Invalid parameter(s): messages" error.
 *
 * This test verifies that tool results with large binary data are properly
 * sanitized before being sent to the chat-client, preventing API errors when
 * the chat-client sends the conversation back in subsequent requests.
 *
 * @package WP_MCP_AI
 */

/**
 * Test the fix for issue #1424.
 */
class WP_MCP_AI_Issue_1424_Integration_Test extends WP_UnitTestCase {

	/**
	 * Test that image generation tools don't break chat-client.
	 *
	 * This simulates what happens when:
	 * 1. A tool returns an image with base64 data
	 * 2. The result is sent to chat-client
	 * 3. Chat-client adds it to the conversation
	 * 4. Next request sends conversation back to API
	 *
	 * Without the fix, step 4 would fail with "Invalid parameter(s): messages".
	 * With the fix, base64 data is stripped before sending to chat-client.
	 */
	public function test_image_tool_result_sanitized_for_chat_client() {
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-validator.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-gemini-image.php';

		$validator = new WP_MCP_AI_REST_Validator();

		// Simulate a realistic image generation tool result.
		$tool_result = array(
			'attachment_id' => 789,
			'url'           => 'https://example.com/generated-image.png',
			'download_url'  => 'https://example.com/download/generated-image.png',
			'file_name'     => 'generated-image.png',
			'mime_type'     => 'image/png',
			'bytes'         => 125000,
			'title'         => 'AI Generated Landscape',
			'model'         => 'gemini-2.5-flash-image',
			'aspect_ratio'  => '16:9',
			'prompt'        => 'A beautiful mountain landscape at sunset',
			'provider'      => 'gemini',
			'content'       => array(
				'type'     => 'image',
				// This is the problematic data that causes API errors!
				'data'     => base64_encode( str_repeat( 'IMAGE_DATA', 1000 ) ), // ~10KB
				'data_url' => 'data:image/png;base64,' . base64_encode( str_repeat( 'IMAGE_DATA', 1000 ) ),
			),
			'usage'         => array(
				'total_tokens' => 150,
			),
		);

		// Get the tool instance.
		$tool = new WP_MCP_AI_Tool_Generate_Gemini_Image();

		// Sanitize for chat-client (this is what the fix does).
		$sanitized = $validator->sanitize_tool_result_for_chat( $tool_result, 'generate_gemini_image', $tool );

		// CRITICAL: Verify base64 data is stripped.
		// The chat-client will add this to the conversation, which gets sent back to the API.
		// If base64 data is present, OpenAI will reject it with "Invalid parameter(s): messages".
		$this->assertIsArray( $sanitized, 'Sanitized result should be an array' );

		// Check that content.data and content.data_url are removed.
		if ( isset( $sanitized['content'] ) ) {
			$this->assertArrayNotHasKey( 'data', $sanitized['content'], 'Base64 data should be stripped from content' );
			$this->assertArrayNotHasKey( 'data_url', $sanitized['content'], 'Data URL should be stripped from content' );
		}

		// Verify essential display fields are kept.
		$this->assertArrayHasKey( 'attachment_id', $sanitized, 'Attachment ID needed for display' );
		$this->assertArrayHasKey( 'url', $sanitized, 'URL needed for display' );
		$this->assertArrayHasKey( 'title', $sanitized, 'Title needed for display' );
		$this->assertArrayHasKey( 'file_name', $sanitized, 'File name needed for display' );

		// Verify the sanitized result is safe to send back to the API.
		// Convert to JSON like the chat-client would.
		$json = wp_json_encode( $sanitized );
		$this->assertNotFalse( $json, 'Sanitized result should be JSON-encodable' );

		// Verify the JSON doesn't contain large base64 strings.
		$this->assertLessThan( 5000, strlen( $json ), 'Sanitized JSON should be small enough for API messages' );
	}

	/**
	 * Test that LLM messages still get sanitized separately.
	 *
	 * This ensures we didn't break the existing LLM sanitization.
	 */
	public function test_llm_sanitization_still_works() {
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-validator.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-gemini-image.php';

		$validator = new WP_MCP_AI_REST_Validator();

		$tool_result = array(
			'attachment_id' => 999,
			'url'           => 'https://example.com/test.png',
			'content'       => array(
				'data'     => base64_encode( str_repeat( 'X', 2000 ) ),
				'data_url' => 'data:image/png;base64,' . base64_encode( str_repeat( 'Y', 2000 ) ),
			),
			'raw'           => array( 'api' => 'response' ),
		);

		$tool = new WP_MCP_AI_Tool_Generate_Gemini_Image();

		// Sanitize for LLM.
		$sanitized_llm = $validator->sanitize_tool_result_for_llm( $tool_result, 'generate_gemini_image', array(), $tool );

		// Verify LLM sanitization strips base64 data.
		if ( isset( $sanitized_llm['content'] ) ) {
			$this->assertArrayNotHasKey( 'data', $sanitized_llm['content'], 'LLM sanitization should strip base64 data' );
		}

		// Sanitize for chat.
		$sanitized_chat = $validator->sanitize_tool_result_for_chat( $tool_result, 'generate_gemini_image', $tool );

		// Both should strip large data, but they serve different purposes.
		if ( isset( $sanitized_chat['content'] ) ) {
			$this->assertArrayNotHasKey( 'data', $sanitized_chat['content'], 'Chat sanitization should strip base64 data' );
		}

		// Both should keep essential fields.
		$this->assertArrayHasKey( 'attachment_id', $sanitized_llm, 'LLM needs attachment ID' );
		$this->assertArrayHasKey( 'attachment_id', $sanitized_chat, 'Chat needs attachment ID' );
	}

	/**
	 * Test that the fix prevents the actual error scenario.
	 *
	 * This simulates the full flow:
	 * 1. Tool returns result with base64 data
	 * 2. Result is sanitized for chat-client
	 * 3. Chat-client would add sanitized result to conversation
	 * 4. Next request would send conversation back to API
	 *
	 * The sanitized conversation should not trigger API errors.
	 */
	public function test_sanitized_conversation_safe_for_api() {
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-validator.php';

		$validator = new WP_MCP_AI_REST_Validator();

		// Simulate conversation with tool result.
		$conversation = array(
			array(
				'role'    => 'user',
				'content' => 'Generate an image of a sunset',
			),
			array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => array(
					array(
						'id'       => 'call_123',
						'type'     => 'function',
						'function' => array(
							'name'      => 'generate_gemini_image',
							'arguments' => wp_json_encode( array( 'prompt' => 'sunset over ocean' ) ),
						),
					),
				),
			),
		);

		// Simulate tool result (what would be sent to chat-client).
		$raw_tool_result = array(
			'url'     => 'https://example.com/sunset.png',
			'data'    => base64_encode( str_repeat( 'Z', 3000 ) ), // Large base64.
			'data_url' => 'data:image/png;base64,' . base64_encode( str_repeat( 'W', 3000 ) ),
		);

		// Apply chat sanitization (this is what the fix does).
		$sanitized_tool_result = $validator->sanitize_tool_result_for_chat( $raw_tool_result, 'generate_gemini_image', null );

		// Chat-client would add this to conversation.
		$conversation[] = array(
			'role'         => 'tool',
			'tool_call_id' => 'call_123',
			'name'         => 'generate_gemini_image',
			'content'      => $sanitized_tool_result,
		);

		// Verify the conversation is safe to serialize.
		$json = wp_json_encode( $conversation );
		$this->assertNotFalse( $json, 'Conversation should be JSON-encodable' );

		// Verify no large base64 data in the conversation.
		// The tool result content should be sanitized.
		$tool_message = end( $conversation );
		$this->assertIsArray( $tool_message['content'], 'Tool message content should be an array' );
		$this->assertArrayNotHasKey( 'data', $tool_message['content'], 'No base64 data in conversation' );
		$this->assertArrayNotHasKey( 'data_url', $tool_message['content'], 'No data URL in conversation' );

		// Verify essential data is preserved.
		$this->assertArrayHasKey( 'url', $tool_message['content'], 'URL should be in conversation for display' );
	}
}
