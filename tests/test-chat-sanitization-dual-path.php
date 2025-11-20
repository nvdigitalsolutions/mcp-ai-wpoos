<?php
/**
 * Tests for dual sanitization paths (LLM vs Chat-client).
 *
 * This test ensures that tool results are properly sanitized for both
 * LLM consumption (token efficiency) and chat-client consumption (schema compliance).
 *
 * @package WP_MCP_AI
 */

/**
 * Test dual sanitization paths for tool results.
 */
class WP_MCP_AI_Chat_Sanitization_Dual_Path_Test extends WP_UnitTestCase {

	/**
	 * Test that large base64 data is stripped for chat-client.
	 */
	public function test_chat_sanitization_strips_base64_data() {
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-validator.php';

		$validator = new WP_MCP_AI_REST_Validator();

		// Simulate a tool result with large base64 image data.
		$result = array(
			'attachment_id' => 123,
			'url'           => 'https://example.com/image.png',
			'data'          => base64_encode( str_repeat( 'A', 5000 ) ), // Large base64 data.
			'data_url'      => 'data:image/png;base64,' . base64_encode( str_repeat( 'B', 5000 ) ),
			'title'         => 'Generated Image',
		);

		$sanitized = $validator->sanitize_tool_result_for_chat( $result, 'generate_image', null );

		// Verify that large binary fields are stripped.
		$this->assertArrayNotHasKey( 'data', $sanitized, 'Base64 data field should be stripped' );
		$this->assertArrayNotHasKey( 'data_url', $sanitized, 'Data URL field should be stripped' );

		// Verify that essential fields are kept.
		$this->assertArrayHasKey( 'attachment_id', $sanitized, 'Attachment ID should be kept' );
		$this->assertArrayHasKey( 'url', $sanitized, 'URL should be kept' );
		$this->assertArrayHasKey( 'title', $sanitized, 'Title should be kept' );
	}

	/**
	 * Test that raw API responses are stripped for chat-client.
	 */
	public function test_chat_sanitization_strips_raw_responses() {
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-validator.php';

		$validator = new WP_MCP_AI_REST_Validator();

		$result = array(
			'status'       => 'success',
			'message'      => 'Image created successfully',
			'raw'          => array( 'huge' => 'api_response_data' ),
			'raw_response' => 'More raw data here',
		);

		$sanitized = $validator->sanitize_tool_result_for_chat( $result, 'test_tool', null );

		// Verify that raw fields are stripped.
		$this->assertArrayNotHasKey( 'raw', $sanitized, 'Raw field should be stripped' );
		$this->assertArrayNotHasKey( 'raw_response', $sanitized, 'Raw response field should be stripped' );

		// Verify that essential fields are kept.
		$this->assertArrayHasKey( 'status', $sanitized, 'Status should be kept' );
		$this->assertArrayHasKey( 'message', $sanitized, 'Message should be kept' );
	}

	/**
	 * Test that tools with LLM sanitizer interface use it for chat sanitization.
	 */
	public function test_chat_sanitization_uses_llm_sanitizer_fallback() {
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-validator.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-gemini-image.php';

		$validator = new WP_MCP_AI_REST_Validator();
		$tool      = new WP_MCP_AI_Tool_Generate_Gemini_Image();

		// Simulate result from generate_gemini_image tool.
		$result = array(
			'attachment_id' => 456,
			'url'           => 'https://example.com/gemini-image.png',
			'content'       => array(
				'data'     => base64_encode( str_repeat( 'C', 5000 ) ),
				'data_url' => 'data:image/png;base64,' . base64_encode( str_repeat( 'D', 5000 ) ),
			),
			'title'         => 'Gemini Generated Image',
		);

		// The tool implements WP_MCP_AI_Tool_LLM_Sanitizer_Interface.
		// Chat sanitization should fall back to LLM sanitization.
		$sanitized = $validator->sanitize_tool_result_for_chat( $result, 'generate_gemini_image', $tool );

		// Verify that the tool's sanitize_for_llm was applied.
		// The Gemini tool strips content.data and content.data_url in sanitize_for_llm.
		$this->assertArrayHasKey( 'attachment_id', $sanitized );
		$this->assertArrayHasKey( 'url', $sanitized );

		// Check if content exists and is sanitized.
		if ( isset( $sanitized['content'] ) ) {
			$this->assertArrayNotHasKey( 'data', $sanitized['content'], 'Content data should be stripped by tool sanitizer' );
			$this->assertArrayNotHasKey( 'data_url', $sanitized['content'], 'Content data_url should be stripped by tool sanitizer' );
		}
	}

	/**
	 * Test that simple string results pass through unchanged.
	 */
	public function test_chat_sanitization_preserves_simple_strings() {
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-validator.php';

		$validator = new WP_MCP_AI_REST_Validator();

		$result    = 'Simple text result from tool';
		$sanitized = $validator->sanitize_tool_result_for_chat( $result, 'test_tool', null );

		$this->assertSame( $result, $sanitized, 'Simple string results should pass through unchanged' );
	}

	/**
	 * Test that numeric and boolean results are preserved.
	 */
	public function test_chat_sanitization_preserves_scalar_values() {
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-validator.php';

		$validator = new WP_MCP_AI_REST_Validator();

		// Test integer.
		$result    = 42;
		$sanitized = $validator->sanitize_tool_result_for_chat( $result, 'test_tool', null );
		$this->assertSame( $result, $sanitized );

		// Test boolean.
		$result    = true;
		$sanitized = $validator->sanitize_tool_result_for_chat( $result, 'test_tool', null );
		$this->assertSame( $result, $sanitized );

		// Test null.
		$result    = null;
		$sanitized = $validator->sanitize_tool_result_for_chat( $result, 'test_tool', null );
		$this->assertNull( $sanitized );
	}

	/**
	 * Test that nested arrays are recursively sanitized.
	 */
	public function test_chat_sanitization_handles_nested_arrays() {
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-validator.php';

		$validator = new WP_MCP_AI_REST_Validator();

		$result = array(
			'items' => array(
				array(
					'id'   => 1,
					'data' => base64_encode( str_repeat( 'E', 2000 ) ), // Should be stripped.
					'name' => 'Item 1',
				),
				array(
					'id'   => 2,
					'raw'  => 'raw_data_here', // Should be stripped.
					'name' => 'Item 2',
				),
			),
		);

		$sanitized = $validator->sanitize_tool_result_for_chat( $result, 'test_tool', null );

		// Verify nested structure is preserved.
		$this->assertArrayHasKey( 'items', $sanitized );
		$this->assertCount( 2, $sanitized['items'] );

		// Verify first item.
		$this->assertArrayHasKey( 'id', $sanitized['items'][0] );
		$this->assertArrayHasKey( 'name', $sanitized['items'][0] );
		$this->assertArrayNotHasKey( 'data', $sanitized['items'][0], 'Nested data field should be stripped' );

		// Verify second item.
		$this->assertArrayHasKey( 'id', $sanitized['items'][1] );
		$this->assertArrayHasKey( 'name', $sanitized['items'][1] );
		$this->assertArrayNotHasKey( 'raw', $sanitized['items'][1], 'Nested raw field should be stripped' );
	}

	/**
	 * Test filters for chat sanitization.
	 */
	public function test_chat_sanitization_applies_filters() {
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-validator.php';

		$validator = new WP_MCP_AI_REST_Validator();

		// Add filter to modify result.
		add_filter(
			'wp_mcp_ai_sanitize_tool_result_chat',
			function ( $result, $tool_name ) {
				if ( is_array( $result ) ) {
					$result['filtered'] = true;
				}
				return $result;
			},
			10,
			2
		);

		$result = array( 'status' => 'success' );

		$sanitized = $validator->sanitize_tool_result_for_chat( $result, 'test_tool', null );

		$this->assertArrayHasKey( 'filtered', $sanitized, 'Filter should be applied' );
		$this->assertTrue( $sanitized['filtered'] );

		// Clean up filter.
		remove_all_filters( 'wp_mcp_ai_sanitize_tool_result_chat' );
	}
}
