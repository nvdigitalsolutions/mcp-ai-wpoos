<?php
/**
 * Tests for Gemini image tool fallback text when OpenAI is the chat provider.
 *
 * Verifies that the fallback text is conditionally added only when:
 * 1. The chat provider is OpenAI
 * 2. The text field is missing from the tool result
 *
 * @package WP_MCP_AI
 */

/**
 * Test Gemini image tools OpenAI fallback text.
 */
class WP_MCP_AI_Gemini_Image_OpenAI_Fallback_Test extends WP_UnitTestCase {

	/**
	 * Test that fallback text is added when provider is OpenAI and text is missing.
	 */
	public function test_fallback_text_added_for_openai_provider() {
		$content = array(
			'attachment_id'  => 123,
			'url'            => 'https://example.com/image.png',
			'download_url'   => 'https://example.com/image.png',
			'title'          => 'Test Image',
			'revised_prompt' => 'A detailed test image',
			'aspect_ratio'   => '1:1',
			'format'         => 'png',
		);

		$assistant_config = array(
			'provider' => 'openai',
		);

		$result = apply_filters( 'wp_mcp_ai_sanitize_tool_result_llm_generate_gemini_image', $content, $assistant_config );

		$this->assertArrayHasKey( 'text', $result, 'Text field should be added for OpenAI provider' );
		$this->assertNotEmpty( $result['text'], 'Fallback text should not be empty' );
		$this->assertStringContainsString( 'Test Image', $result['text'], 'Fallback text should include image title' );
		$this->assertStringContainsString( '123', $result['text'], 'Fallback text should include attachment ID' );
	}

	/**
	 * Test that fallback text is NOT added when provider is Gemini.
	 */
	public function test_no_fallback_text_for_gemini_provider() {
		$content = array(
			'attachment_id' => 123,
			'url'           => 'https://example.com/image.png',
			'title'         => 'Test Image',
		);

		$assistant_config = array(
			'provider' => 'gemini',
		);

		$result = apply_filters( 'wp_mcp_ai_sanitize_tool_result_llm_generate_gemini_image', $content, $assistant_config );

		$this->assertArrayNotHasKey( 'text', $result, 'Text field should NOT be added for Gemini provider' );
	}

	/**
	 * Test that fallback text is NOT added when provider is Ollama.
	 */
	public function test_no_fallback_text_for_ollama_provider() {
		$content = array(
			'attachment_id' => 123,
			'url'           => 'https://example.com/image.png',
			'title'         => 'Test Image',
		);

		$assistant_config = array(
			'provider' => 'ollama',
		);

		$result = apply_filters( 'wp_mcp_ai_sanitize_tool_result_llm_generate_gemini_image', $content, $assistant_config );

		$this->assertArrayNotHasKey( 'text', $result, 'Text field should NOT be added for Ollama provider' );
	}

	/**
	 * Test that existing text is preserved and not overwritten.
	 */
	public function test_existing_text_is_preserved() {
		$content = array(
			'attachment_id' => 123,
			'url'           => 'https://example.com/image.png',
			'title'         => 'Test Image',
			'text'          => 'Existing text message',
		);

		$assistant_config = array(
			'provider' => 'openai',
		);

		$result = apply_filters( 'wp_mcp_ai_sanitize_tool_result_llm_generate_gemini_image', $content, $assistant_config );

		$this->assertSame( 'Existing text message', $result['text'], 'Existing text should be preserved' );
	}

	/**
	 * Test that empty string text triggers fallback generation.
	 */
	public function test_empty_string_text_triggers_fallback() {
		$content = array(
			'attachment_id' => 123,
			'url'           => 'https://example.com/image.png',
			'title'         => 'Test Image',
			'text'          => '',
		);

		$assistant_config = array(
			'provider' => 'openai',
		);

		$result = apply_filters( 'wp_mcp_ai_sanitize_tool_result_llm_generate_gemini_image', $content, $assistant_config );

		$this->assertNotEmpty( $result['text'], 'Empty text should be replaced with fallback' );
		$this->assertStringContainsString( 'Test Image', $result['text'], 'Fallback text should include image title' );
	}

	/**
	 * Test edit_gemini_image tool also gets fallback text for OpenAI provider.
	 */
	public function test_edit_tool_fallback_text_for_openai() {
		$content = array(
			'attachment_id'     => 456,
			'url'               => 'https://example.com/edited.png',
			'title'             => 'Edited Image',
			'edit_instruction'  => 'Remove background',
			'source_attachment' => 123,
		);

		$assistant_config = array(
			'provider' => 'openai',
		);

		$result = apply_filters( 'wp_mcp_ai_sanitize_tool_result_llm_edit_gemini_image', $content, $assistant_config );

		$this->assertArrayHasKey( 'text', $result, 'Text field should be added for OpenAI provider on edit tool' );
		$this->assertNotEmpty( $result['text'], 'Fallback text should not be empty' );
		$this->assertStringContainsString( 'Edited Image', $result['text'], 'Fallback text should include image title' );
	}

	/**
	 * Test edit_gemini_image tool does NOT get fallback text for Gemini provider.
	 */
	public function test_edit_tool_no_fallback_for_gemini() {
		$content = array(
			'attachment_id'     => 456,
			'url'               => 'https://example.com/edited.png',
			'title'             => 'Edited Image',
			'edit_instruction'  => 'Remove background',
			'source_attachment' => 123,
		);

		$assistant_config = array(
			'provider' => 'gemini',
		);

		$result = apply_filters( 'wp_mcp_ai_sanitize_tool_result_llm_edit_gemini_image', $content, $assistant_config );

		$this->assertArrayNotHasKey( 'text', $result, 'Text field should NOT be added for Gemini provider on edit tool' );
	}

	/**
	 * Test fallback text generation for URL-only results.
	 */
	public function test_fallback_text_for_url_only_result() {
		$content = array(
			'url' => 'https://example.com/image.png',
		);

		$assistant_config = array(
			'provider' => 'openai',
		);

		$result = apply_filters( 'wp_mcp_ai_sanitize_tool_result_llm_generate_gemini_image', $content, $assistant_config );

		$this->assertArrayHasKey( 'text', $result, 'Text field should be added for URL-only result' );
		$this->assertStringContainsString( 'https://example.com/image.png', $result['text'], 'Fallback text should include URL' );
	}

	/**
	 * Test fallback text generation for error results.
	 */
	public function test_fallback_text_for_error_result() {
		$content = array(
			'error'   => true,
			'message' => 'Rate limit exceeded',
		);

		$assistant_config = array(
			'provider' => 'openai',
		);

		$result = apply_filters( 'wp_mcp_ai_sanitize_tool_result_llm_generate_gemini_image', $content, $assistant_config );

		$this->assertArrayHasKey( 'text', $result, 'Text field should be added for error result' );
		$this->assertSame( 'Rate limit exceeded', $result['text'], 'Fallback text should include error message' );
	}

	/**
	 * Test fallback text includes format information when available.
	 */
	public function test_fallback_text_includes_format_info() {
		$content = array(
			'attachment_id' => 789,
			'url'           => 'https://example.com/image.png',
			'title'         => 'Formatted Image',
			'aspect_ratio'  => '16:9',
			'format'        => 'webp',
		);

		$assistant_config = array(
			'provider' => 'openai',
		);

		$result = apply_filters( 'wp_mcp_ai_sanitize_tool_result_llm_generate_gemini_image', $content, $assistant_config );

		$this->assertArrayHasKey( 'text', $result );
		$this->assertStringContainsString( '16:9', $result['text'], 'Fallback text should include aspect ratio' );
		$this->assertStringContainsString( 'WEBP', $result['text'], 'Fallback text should include format in uppercase' );
	}

	/**
	 * Test helper function directly with various content types.
	 */
	public function test_helper_function_generates_appropriate_text() {
		// Test with complete result.
		$complete = array(
			'attachment_id'  => 100,
			'title'          => 'Complete Image',
			'revised_prompt' => 'A beautiful sunset',
			'aspect_ratio'   => '4:3',
			'format'         => 'jpeg',
		);
		$text     = wp_mcp_ai_generate_gemini_image_fallback_text( $complete );
		$this->assertStringContainsString( 'Complete Image', $text );
		$this->assertStringContainsString( 'A beautiful sunset', $text );
		$this->assertStringContainsString( '4:3', $text );

		// Test with minimal result.
		$minimal = array();
		$text    = wp_mcp_ai_generate_gemini_image_fallback_text( $minimal );
		$this->assertNotEmpty( $text );
		$this->assertStringContainsString( 'completed', strtolower( $text ) );

		// Test with error.
		$error = array(
			'error'   => true,
			'message' => 'API error',
		);
		$text  = wp_mcp_ai_generate_gemini_image_fallback_text( $error );
		$this->assertSame( 'API error', $text );

		// Test with download_url only.
		$download_only = array(
			'download_url' => 'https://cdn.example.com/img.png',
		);
		$text          = wp_mcp_ai_generate_gemini_image_fallback_text( $download_only );
		$this->assertStringContainsString( 'https://cdn.example.com/img.png', $text );
	}

	/**
	 * Test that non-array content is returned unchanged.
	 */
	public function test_non_array_content_returned_unchanged() {
		$content = 'string content';

		$assistant_config = array(
			'provider' => 'openai',
		);

		$result = apply_filters( 'wp_mcp_ai_sanitize_tool_result_llm_generate_gemini_image', $content, $assistant_config );

		$this->assertSame( 'string content', $result, 'Non-array content should be returned unchanged' );
	}

	/**
	 * Test that empty provider config does not add fallback text.
	 */
	public function test_empty_provider_no_fallback() {
		$content = array(
			'attachment_id' => 123,
			'url'           => 'https://example.com/image.png',
			'title'         => 'Test Image',
		);

		$assistant_config = array();

		$result = apply_filters( 'wp_mcp_ai_sanitize_tool_result_llm_generate_gemini_image', $content, $assistant_config );

		$this->assertArrayNotHasKey( 'text', $result, 'Text field should NOT be added when provider is empty' );
	}
}
