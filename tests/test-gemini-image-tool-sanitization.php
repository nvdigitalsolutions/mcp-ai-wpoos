<?php
/**
 * Tests for Gemini image tool LLM sanitization.
 *
 *
 * @package WP_MCP_AI
 */

// Load required tool classes.
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-gemini-image.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php';

/**
 * Test Gemini image tools LLM sanitization.
 */
class WP_MCP_AI_Gemini_Image_Tool_Sanitization_Test extends WP_UnitTestCase {

	/**
	 * The generate tool should sanitize base64 content for LLM context.
	 */
	public function test_generate_tool_sanitize_for_llm_strips_base64_content() {
		$tool = new WP_MCP_AI_Tool_Generate_Gemini_Image();

		$result = array(
			'attachment_id'  => 123,
			'url'            => 'https://example.com/image.png',
			'download_url'   => 'https://example.com/image.png',
			'file_name'      => 'gemini-image.png',
			'mime_type'      => 'image/png',
			'bytes'          => 1024,
			'title'          => 'Gemini Image',
			'model'          => 'gemini-2.5-flash-image',
			'aspect_ratio'   => '1:1',
			'format'         => 'png',
			'prompt'         => 'A test image',
			'revised_prompt' => 'A detailed test image',
			'provider'       => 'gemini',
			'usage'          => array(
				'prompt_tokens'     => 10,
				'completion_tokens' => 5,
				'total_tokens'      => 15,
			),
			'content'        => array(
				'encoding'  => 'base64',
				'data'      => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=',
				'mime_type' => 'image/png',
				'data_url'  => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=',
				'file_name' => 'test.png',
				'bytes'     => 95,
			),
		);

		$sanitized = $tool->sanitize_for_llm( $result );

		// Should strip content.data and content.data_url.
		$this->assertArrayNotHasKey( 'content', $sanitized, 'content field should be removed after sanitization' );

		// Should keep essential metadata.
		$this->assertArrayHasKey( 'attachment_id', $sanitized );
		$this->assertArrayHasKey( 'url', $sanitized );
		$this->assertArrayHasKey( 'download_url', $sanitized );
		$this->assertArrayHasKey( 'file_name', $sanitized );
		$this->assertArrayHasKey( 'mime_type', $sanitized );
		$this->assertArrayHasKey( 'bytes', $sanitized );
		$this->assertArrayHasKey( 'title', $sanitized );
		$this->assertArrayHasKey( 'model', $sanitized );
		$this->assertArrayHasKey( 'aspect_ratio', $sanitized );
		$this->assertArrayHasKey( 'format', $sanitized );
		$this->assertArrayHasKey( 'prompt', $sanitized );
		$this->assertArrayHasKey( 'revised_prompt', $sanitized );
		$this->assertArrayHasKey( 'provider', $sanitized );
		$this->assertArrayHasKey( 'usage', $sanitized );

		// Verify values are preserved.
		$this->assertSame( 123, $sanitized['attachment_id'] );
		$this->assertSame( 'https://example.com/image.png', $sanitized['url'] );
		$this->assertSame( 'A test image', $sanitized['prompt'] );
	}

	/**
	 * The edit tool should sanitize base64 content for LLM context.
	 */
	public function test_edit_tool_sanitize_for_llm_strips_base64_content() {
		$tool = new WP_MCP_AI_Tool_Edit_Gemini_Image();

		$result = array(
			'attachment_id'     => 456,
			'url'               => 'https://example.com/edited.png',
			'download_url'      => 'https://example.com/edited.png',
			'file_name'         => 'edited-image.png',
			'mime_type'         => 'image/png',
			'bytes'             => 2048,
			'title'             => 'Edited Image',
			'model'             => 'gemini-2.5-flash-image',
			'aspect_ratio'      => '16:9',
			'format'            => 'png',
			'edit_instruction'  => 'Remove background',
			'source_attachment' => 123,
			'provider'          => 'gemini',
			'text'              => 'Successfully edited image "Edited Image" (ID: 456). Edit instruction: Remove background',
			'content'           => array(
				'encoding'  => 'base64',
				'data'      => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=',
				'mime_type' => 'image/png',
				'data_url'  => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=',
			),
		);

		$sanitized = $tool->sanitize_for_llm( $result );

		// Should strip content.data and content.data_url.
		$this->assertArrayNotHasKey( 'content', $sanitized, 'content field should be removed after sanitization' );

		// Should keep essential metadata.
		$this->assertArrayHasKey( 'attachment_id', $sanitized );
		$this->assertArrayHasKey( 'edit_instruction', $sanitized );
		$this->assertArrayHasKey( 'source_attachment', $sanitized );
		$this->assertArrayHasKey( 'model', $sanitized );
		$this->assertArrayHasKey( 'format', $sanitized );
		$this->assertArrayHasKey( 'text', $sanitized );

		// Verify values.
		$this->assertSame( 456, $sanitized['attachment_id'] );
		$this->assertSame( 'Remove background', $sanitized['edit_instruction'] );
		$this->assertSame( 123, $sanitized['source_attachment'] );
		$this->assertSame( 'png', $sanitized['format'] );
		$this->assertStringContainsString( 'Successfully edited', $sanitized['text'] );
	}

	/**
	 * Test that the generate tool implements the LLM Sanitizer Interface.
	 */
	public function test_generate_tool_implements_llm_sanitizer_interface() {
		$tool = new WP_MCP_AI_Tool_Generate_Gemini_Image();
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_LLM_Sanitizer_Interface', $tool );
	}

	/**
	 * Test that the edit tool implements the LLM Sanitizer Interface.
	 */
	public function test_edit_tool_implements_llm_sanitizer_interface() {
		$tool = new WP_MCP_AI_Tool_Edit_Gemini_Image();
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_LLM_Sanitizer_Interface', $tool );
	}
}
