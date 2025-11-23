<?php
/**
 * SSE Tool Result Text Extraction Tests
 *
 * Tests that tool result text is properly extracted when LLM returns empty content.
 * This addresses the issue where Gemini image generation with OpenAI provider
 * would result in empty SSE stream content.
 *
 * @package WP_MCP_AI
 */

/**
 * Test SSE tool result text extraction fallback.
 */
class WP_MCP_AI_Test_SSE_Tool_Result_Text_Extraction extends WP_UnitTestCase {

	/**
	 * Test that extract_text_from_tool_results extracts text from single tool result.
	 */
	public function test_extract_text_from_single_tool_result() {
		$rest = new WP_MCP_AI_REST();
		$method = new ReflectionMethod( $rest, 'extract_text_from_tool_results' );
		$method->setAccessible( true );

		$tool_result_messages = array(
			array(
				'role'         => 'tool',
				'content'      => wp_json_encode(
					array(
						'text'          => 'Successfully generated image "sunset.png" (ID: 123). Description: Beautiful sunset over ocean.',
						'attachment_id' => 123,
						'url'           => 'https://example.com/sunset.png',
					)
				),
				'tool_call_id' => 'call_123',
				'name'         => 'generate_gemini_image',
			),
		);

		$result = $method->invoke( $rest, $tool_result_messages );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'Successfully generated image', $result );
		$this->assertStringContainsString( 'sunset.png', $result );
		$this->assertStringContainsString( 'ID: 123', $result );
	}

	/**
	 * Test that extract_text_from_tool_results handles multiple tool results.
	 */
	public function test_extract_text_from_multiple_tool_results() {
		$rest = new WP_MCP_AI_REST();
		$method = new ReflectionMethod( $rest, 'extract_text_from_tool_results' );
		$method->setAccessible( true );

		$tool_result_messages = array(
			array(
				'role'         => 'tool',
				'content'      => wp_json_encode(
					array(
						'text' => 'First image created successfully.',
						'url'  => 'https://example.com/image1.png',
					)
				),
				'tool_call_id' => 'call_1',
				'name'         => 'generate_gemini_image',
			),
			array(
				'role'         => 'tool',
				'content'      => wp_json_encode(
					array(
						'text' => 'Second image created successfully.',
						'url'  => 'https://example.com/image2.png',
					)
				),
				'tool_call_id' => 'call_2',
				'name'         => 'generate_gemini_image',
			),
		);

		$result = $method->invoke( $rest, $tool_result_messages );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'First image created successfully', $result );
		$this->assertStringContainsString( 'Second image created successfully', $result );
		// Verify results are joined with double newlines
		$this->assertStringContainsString( "\n\n", $result );
	}

	/**
	 * Test that extract_text_from_tool_results returns empty string when no text field exists.
	 */
	public function test_extract_text_returns_empty_when_no_text_field() {
		$rest = new WP_MCP_AI_REST();
		$method = new ReflectionMethod( $rest, 'extract_text_from_tool_results' );
		$method->setAccessible( true );

		$tool_result_messages = array(
			array(
				'role'         => 'tool',
				'content'      => wp_json_encode(
					array(
						'status' => 'success',
						'url'    => 'https://example.com/image.png',
						// No 'text' field
					)
				),
				'tool_call_id' => 'call_123',
				'name'         => 'some_tool',
			),
		);

		$result = $method->invoke( $rest, $tool_result_messages );

		$this->assertSame( '', $result );
	}

	/**
	 * Test that extract_text_from_tool_results handles plain string content.
	 */
	public function test_extract_text_handles_plain_string_content() {
		$rest = new WP_MCP_AI_REST();
		$method = new ReflectionMethod( $rest, 'extract_text_from_tool_results' );
		$method->setAccessible( true );

		$tool_result_messages = array(
			array(
				'role'         => 'tool',
				'content'      => 'Simple text result',
				'tool_call_id' => 'call_123',
				'name'         => 'some_tool',
			),
		);

		$result = $method->invoke( $rest, $tool_result_messages );

		$this->assertSame( 'Simple text result', $result );
	}

	/**
	 * Test that extract_text_from_tool_results handles empty array.
	 */
	public function test_extract_text_handles_empty_array() {
		$rest = new WP_MCP_AI_REST();
		$method = new ReflectionMethod( $rest, 'extract_text_from_tool_results' );
		$method->setAccessible( true );

		$result = $method->invoke( $rest, array() );

		$this->assertSame( '', $result );
	}

	/**
	 * Test that extract_text_from_tool_results handles malformed JSON.
	 */
	public function test_extract_text_handles_malformed_json() {
		$rest = new WP_MCP_AI_REST();
		$method = new ReflectionMethod( $rest, 'extract_text_from_tool_results' );
		$method->setAccessible( true );

		$tool_result_messages = array(
			array(
				'role'         => 'tool',
				'content'      => '{invalid json',
				'tool_call_id' => 'call_123',
				'name'         => 'some_tool',
			),
		);

		$result = $method->invoke( $rest, $tool_result_messages );

		// Should treat it as plain string and return it
		$this->assertSame( '{invalid json', $result );
	}

	/**
	 * Test that extract_text_from_tool_results handles tool result with array content.
	 */
	public function test_extract_text_handles_array_content() {
		$rest = new WP_MCP_AI_REST();
		$method = new ReflectionMethod( $rest, 'extract_text_from_tool_results' );
		$method->setAccessible( true );

		$tool_result_messages = array(
			array(
				'role'         => 'tool',
				'content'      => array(
					'text' => 'Tool executed successfully.',
					'data' => 'some data',
				),
				'tool_call_id' => 'call_123',
				'name'         => 'some_tool',
			),
		);

		$result = $method->invoke( $rest, $tool_result_messages );

		$this->assertSame( 'Tool executed successfully.', $result );
	}

	/**
	 * Test that extract_text_from_tool_results skips empty content.
	 */
	public function test_extract_text_skips_empty_content() {
		$rest = new WP_MCP_AI_REST();
		$method = new ReflectionMethod( $rest, 'extract_text_from_tool_results' );
		$method->setAccessible( true );

		$tool_result_messages = array(
			array(
				'role'         => 'tool',
				'content'      => '',
				'tool_call_id' => 'call_1',
				'name'         => 'empty_tool',
			),
			array(
				'role'         => 'tool',
				'content'      => wp_json_encode( array( 'text' => 'Valid text' ) ),
				'tool_call_id' => 'call_2',
				'name'         => 'valid_tool',
			),
		);

		$result = $method->invoke( $rest, $tool_result_messages );

		$this->assertSame( 'Valid text', $result );
	}

	/**
	 * Test that extract_text_from_tool_results trims whitespace.
	 */
	public function test_extract_text_trims_whitespace() {
		$rest = new WP_MCP_AI_REST();
		$method = new ReflectionMethod( $rest, 'extract_text_from_tool_results' );
		$method->setAccessible( true );

		$tool_result_messages = array(
			array(
				'role'         => 'tool',
				'content'      => wp_json_encode( array( 'text' => '  Text with spaces  ' ) ),
				'tool_call_id' => 'call_123',
				'name'         => 'some_tool',
			),
		);

		$result = $method->invoke( $rest, $tool_result_messages );

		$this->assertSame( 'Text with spaces', $result );
	}
}
