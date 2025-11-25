<?php
/**
 * Tests for Gemini image tool fallback text when using OpenAI provider.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Gemini image tool fallback text generation for OpenAI provider.
 */
class WP_MCP_AI_Gemini_Image_OpenAI_Fallback_Test extends WP_UnitTestCase {

	/**
	 * Test that fallback text is added when provider is OpenAI and text is missing.
	 */
	public function test_fallback_text_added_for_openai_provider() {
		$content = array(
			'attachment_id' => 123,
			'url'           => 'https://example.com/image.png',
			'title'         => 'Test Image',
			'model'         => 'gemini-2.5-flash-image',
		);

		$assistant_config = array(
			'provider' => 'openai',
			'model'    => 'gpt-4o',
		);

		// Apply the filter.
		$filtered = apply_filters( 'wp_mcp_ai_sanitize_tool_result_llm_generate_gemini_image', $content, $assistant_config );

		// Text should be added.
		$this->assertArrayHasKey( 'text', $filtered );
		$this->assertNotEmpty( $filtered['text'] );
		$this->assertStringContainsString( 'Test Image', $filtered['text'] );
		$this->assertStringContainsString( '123', $filtered['text'] );
	}

	/**
	 * Test that fallback text is NOT added when provider is Gemini.
	 */
	public function test_no_fallback_text_for_gemini_provider() {
		$content = array(
			'attachment_id' => 123,
			'url'           => 'https://example.com/image.png',
			'model'         => 'gemini-2.5-flash-image',
		);

		$assistant_config = array(
			'provider' => 'gemini',
			'model'    => 'gemini-2.0-flash',
		);

		// Apply the filter.
		$filtered = apply_filters( 'wp_mcp_ai_sanitize_tool_result_llm_generate_gemini_image', $content, $assistant_config );

		// Text should NOT be added for Gemini provider.
		$this->assertArrayNotHasKey( 'text', $filtered );
	}

	/**
	 * Test that fallback text is NOT added when provider is Ollama.
	 */
	public function test_no_fallback_text_for_ollama_provider() {
		$content = array(
			'attachment_id' => 123,
			'url'           => 'https://example.com/image.png',
			'model'         => 'gemini-2.5-flash-image',
		);

		$assistant_config = array(
			'provider' => 'ollama',
			'model'    => 'llama3.2',
		);

		// Apply the filter.
		$filtered = apply_filters( 'wp_mcp_ai_sanitize_tool_result_llm_generate_gemini_image', $content, $assistant_config );

		// Text should NOT be added for Ollama provider.
		$this->assertArrayNotHasKey( 'text', $filtered );
	}

	/**
	 * Test that existing text is preserved and not overwritten.
	 */
	public function test_existing_text_not_overwritten() {
		$content = array(
			'attachment_id' => 123,
			'url'           => 'https://example.com/image.png',
			'text'          => 'Original text message',
			'model'         => 'gemini-2.5-flash-image',
		);

		$assistant_config = array(
			'provider' => 'openai',
			'model'    => 'gpt-4o',
		);

		// Apply the filter.
		$filtered = apply_filters( 'wp_mcp_ai_sanitize_tool_result_llm_generate_gemini_image', $content, $assistant_config );

		// Original text should be preserved.
		$this->assertEquals( 'Original text message', $filtered['text'] );
	}

	/**
	 * Test fallback text for URL-only result.
	 */
	public function test_fallback_text_for_url_only() {
		$content = array(
			'url' => 'https://example.com/image.png',
		);

		$assistant_config = array(
			'provider' => 'openai',
		);

		// Apply the filter.
		$filtered = apply_filters( 'wp_mcp_ai_sanitize_tool_result_llm_generate_gemini_image', $content, $assistant_config );

		$this->assertArrayHasKey( 'text', $filtered );
		$this->assertStringContainsString( 'Image generated successfully', $filtered['text'] );
	}

	/**
	 * Test fallback text for error result.
	 */
	public function test_fallback_text_for_error_result() {
		$content = array(
			'error_message' => 'API quota exceeded',
		);

		$assistant_config = array(
			'provider' => 'openai',
		);

		// Apply the filter.
		$filtered = apply_filters( 'wp_mcp_ai_sanitize_tool_result_llm_generate_gemini_image', $content, $assistant_config );

		$this->assertArrayHasKey( 'text', $filtered );
		$this->assertStringContainsString( 'encountered an issue', $filtered['text'] );
		$this->assertStringContainsString( 'API quota exceeded', $filtered['text'] );
	}

	/**
	 * Test fallback text for incomplete result with only metadata.
	 */
	public function test_fallback_text_for_incomplete_result() {
		$content = array(
			'model'  => 'gemini-2.5-flash-image',
			'format' => 'png',
		);

		$assistant_config = array(
			'provider' => 'openai',
		);

		// Apply the filter.
		$filtered = apply_filters( 'wp_mcp_ai_sanitize_tool_result_llm_generate_gemini_image', $content, $assistant_config );

		$this->assertArrayHasKey( 'text', $filtered );
		$this->assertStringContainsString( 'result is incomplete', $filtered['text'] );
		$this->assertStringContainsString( 'gemini-2.5-flash-image', $filtered['text'] );
	}

	/**
	 * Test that edit_gemini_image also gets fallback text for OpenAI provider.
	 */
	public function test_edit_gemini_image_fallback_for_openai() {
		$content = array(
			'attachment_id' => 456,
			'url'           => 'https://example.com/edited-image.png',
			'title'         => 'Edited Image',
		);

		$assistant_config = array(
			'provider' => 'openai',
		);

		// Apply the filter for edit_gemini_image.
		$filtered = apply_filters( 'wp_mcp_ai_sanitize_tool_result_llm_edit_gemini_image', $content, $assistant_config );

		$this->assertArrayHasKey( 'text', $filtered );
		$this->assertStringContainsString( 'Edited Image', $filtered['text'] );
	}

	/**
	 * Test that edit_gemini_image does not get fallback for Gemini provider.
	 */
	public function test_edit_gemini_image_no_fallback_for_gemini() {
		$content = array(
			'attachment_id' => 456,
			'url'           => 'https://example.com/edited-image.png',
		);

		$assistant_config = array(
			'provider' => 'gemini',
		);

		// Apply the filter for edit_gemini_image.
		$filtered = apply_filters( 'wp_mcp_ai_sanitize_tool_result_llm_edit_gemini_image', $content, $assistant_config );

		// Text should NOT be added for Gemini provider.
		$this->assertArrayNotHasKey( 'text', $filtered );
	}

	/**
	 * Test that non-array content is returned unchanged.
	 */
	public function test_non_array_content_unchanged() {
		$content = 'Simple string content';

		$assistant_config = array(
			'provider' => 'openai',
		);

		// Apply the filter.
		$filtered = apply_filters( 'wp_mcp_ai_sanitize_tool_result_llm_generate_gemini_image', $content, $assistant_config );

		$this->assertEquals( 'Simple string content', $filtered );
	}

	/**
	 * Test with empty provider (defaults to no modification).
	 */
	public function test_empty_provider_no_modification() {
		$content = array(
			'attachment_id' => 123,
			'url'           => 'https://example.com/image.png',
		);

		$assistant_config = array();

		// Apply the filter.
		$filtered = apply_filters( 'wp_mcp_ai_sanitize_tool_result_llm_generate_gemini_image', $content, $assistant_config );

		// No text should be added when provider is not explicitly 'openai'.
		$this->assertArrayNotHasKey( 'text', $filtered );
	}
}
