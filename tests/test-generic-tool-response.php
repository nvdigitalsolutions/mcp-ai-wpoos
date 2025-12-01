<?php
/**
 * Tests for the Generic Tool Response system.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for generic tool response functionality.
 */
class WP_MCP_AI_Generic_Tool_Response_Test extends WP_UnitTestCase {

	/**
	 * Test that OpenAI responses can be converted to generic format.
	 */
	public function test_from_openai_success() {
		$raw_response = array(
			'choices'  => array(
				array(
					'index'         => 0,
					'message'       => array(
						'role'    => 'assistant',
						'content' => 'Hello from OpenAI',
					),
					'finish_reason' => 'stop',
				),
			),
			'usage'    => array(
				'prompt_tokens'     => 10,
				'completion_tokens' => 20,
				'total_tokens'      => 30,
			),
			'model'    => 'gpt-4',
			'provider' => 'openai',
		);

		$generic_response = wp_mcp_ai_extract_generic_tool_response( $raw_response, 'openai' );

		$this->assertTrue( $generic_response->is_success() );
		$this->assertSame( 'Hello from OpenAI', $generic_response->get_content() );
		$this->assertSame( 'stop', $generic_response->get_finish_reason() );
		$this->assertSame( 'openai', $generic_response->get_provider() );
		$this->assertSame( 'gpt-4', $generic_response->get_model() );
		$this->assertNull( $generic_response->get_error() );

		$usage = $generic_response->get_usage();
		$this->assertIsArray( $usage );
		$this->assertSame( 10, $usage['prompt_tokens'] );
		$this->assertSame( 20, $usage['completion_tokens'] );
		$this->assertSame( 30, $usage['total_tokens'] );
	}

	/**
	 * Test that Gemini responses can be converted to generic format.
	 */
	public function test_from_gemini_success() {
		$raw_response = array(
			'choices'  => array(
				array(
					'index'         => 0,
					'message'       => array(
						'role'    => 'assistant',
						'content' => array(
							array(
								'type' => 'text',
								'text' => 'Hello from Gemini',
							),
						),
					),
					'finish_reason' => 'STOP',
				),
			),
			'usage'    => array(
				'prompt_tokens'     => 15,
				'completion_tokens' => 25,
				'total_tokens'      => 40,
			),
			'provider' => 'gemini',
		);

		$generic_response = wp_mcp_ai_extract_generic_tool_response( $raw_response, 'gemini' );

		$this->assertTrue( $generic_response->is_success() );
		$this->assertSame( 'Hello from Gemini', $generic_response->get_content() );
		$this->assertSame( 'STOP', $generic_response->get_finish_reason() );
		$this->assertSame( 'gemini', $generic_response->get_provider() );
		$this->assertNull( $generic_response->get_error() );
	}

	/**
	 * Test that Anthropic responses can be converted to generic format.
	 */
	public function test_from_anthropic_success() {
		$raw_response = array(
			'choices'  => array(
				array(
					'index'         => 0,
					'message'       => array(
						'role'    => 'assistant',
						'content' => array(
							array(
								'type' => 'text',
								'text' => 'Hello from Anthropic',
							),
						),
					),
					'finish_reason' => 'stop',
				),
			),
			'usage'    => array(
				'prompt_tokens'     => 12,
				'completion_tokens' => 18,
				'total_tokens'      => 30,
			),
			'provider' => 'anthropic',
		);

		$generic_response = wp_mcp_ai_extract_generic_tool_response( $raw_response, 'anthropic' );

		$this->assertTrue( $generic_response->is_success() );
		$this->assertSame( 'Hello from Anthropic', $generic_response->get_content() );
		$this->assertSame( 'stop', $generic_response->get_finish_reason() );
		$this->assertSame( 'anthropic', $generic_response->get_provider() );
		$this->assertNull( $generic_response->get_error() );
	}

	/**
	 * Test that Ollama responses can be converted to generic format.
	 */
	public function test_from_ollama_success() {
		$raw_response = array(
			'choices'  => array(
				array(
					'index'         => 0,
					'message'       => array(
						'role'    => 'assistant',
						'content' => 'Hello from Ollama',
					),
					'finish_reason' => 'stop',
				),
			),
			'usage'    => array(
				'prompt_tokens'     => 8,
				'completion_tokens' => 12,
				'total_tokens'      => 20,
			),
			'provider' => 'ollama',
		);

		$generic_response = wp_mcp_ai_extract_generic_tool_response( $raw_response, 'ollama' );

		$this->assertTrue( $generic_response->is_success() );
		$this->assertSame( 'Hello from Ollama', $generic_response->get_content() );
		$this->assertSame( 'stop', $generic_response->get_finish_reason() );
		$this->assertSame( 'ollama', $generic_response->get_provider() );
		$this->assertNull( $generic_response->get_error() );
	}

	/**
	 * Test that WP_Error responses are properly converted.
	 */
	public function test_from_wp_error() {
		$wp_error = new WP_Error(
			'wp_mcp_ai_api_error',
			'API request failed',
			array( 'status' => 500 )
		);

		$generic_response = wp_mcp_ai_extract_generic_tool_response( $wp_error, 'openai' );

		$this->assertFalse( $generic_response->is_success() );
		$this->assertNull( $generic_response->get_content() );

		$error = $generic_response->get_error();
		$this->assertIsArray( $error );
		$this->assertSame( 500, $error['code'] );
		$this->assertSame( 'API request failed', $error['message'] );
		$this->assertSame( 'openai', $generic_response->get_provider() );
	}

	/**
	 * Test that tool calls are properly extracted.
	 */
	public function test_get_tool_calls() {
		$raw_response = array(
			'choices'  => array(
				array(
					'index'   => 0,
					'message' => array(
						'role'       => 'assistant',
						'tool_calls' => array(
							array(
								'id'       => 'call_123',
								'type'     => 'function',
								'function' => array(
									'name'      => 'get_weather',
									'arguments' => '{"location":"London"}',
								),
							),
						),
					),
				),
			),
			'provider' => 'openai',
		);

		$generic_response = wp_mcp_ai_extract_generic_tool_response( $raw_response, 'openai' );

		$tool_calls = $generic_response->get_tool_calls();
		$this->assertIsArray( $tool_calls );
		$this->assertCount( 1, $tool_calls );
		$this->assertSame( 'call_123', $tool_calls[0]['id'] );
		$this->assertSame( 'get_weather', $tool_calls[0]['function']['name'] );
	}

	/**
	 * Test that unsupported providers return error response.
	 */
	public function test_unsupported_provider() {
		$raw_response = array( 'test' => 'data' );

		$generic_response = wp_mcp_ai_extract_generic_tool_response( $raw_response, 'unsupported_provider' );

		$this->assertFalse( $generic_response->is_success() );

		$error = $generic_response->get_error();
		$this->assertIsArray( $error );
		$this->assertStringContainsString( 'Unsupported AI provider', $error['message'] );
	}

	/**
	 * Test provider support check helper function.
	 */
	public function test_is_provider_supported() {
		$this->assertTrue( wp_mcp_ai_is_provider_supported( 'openai' ) );
		$this->assertTrue( wp_mcp_ai_is_provider_supported( 'gemini' ) );
		$this->assertTrue( wp_mcp_ai_is_provider_supported( 'anthropic' ) );
		$this->assertTrue( wp_mcp_ai_is_provider_supported( 'ollama' ) );
		$this->assertTrue( wp_mcp_ai_is_provider_supported( 'lm-studio' ) );
		$this->assertTrue( wp_mcp_ai_is_provider_supported( 'lm_studio' ) );
		$this->assertFalse( wp_mcp_ai_is_provider_supported( 'unknown' ) );
	}

	/**
	 * Test that original response is preserved.
	 */
	public function test_get_original_response() {
		$raw_response = array(
			'choices'  => array(
				array(
					'index'         => 0,
					'message'       => array(
						'role'    => 'assistant',
						'content' => 'Test',
					),
					'finish_reason' => 'stop',
				),
			),
			'provider' => 'openai',
			'custom'   => 'data',
		);

		$generic_response = wp_mcp_ai_extract_generic_tool_response( $raw_response, 'openai' );

		$original = $generic_response->get_original_response();
		$this->assertIsArray( $original );
		$this->assertSame( 'data', $original['custom'] );
	}

	/**
	 * Test that content is null when not present.
	 */
	public function test_get_content_when_not_present() {
		$raw_response = array(
			'choices'  => array(
				array(
					'index'   => 0,
					'message' => array(
						'role' => 'assistant',
					),
				),
			),
			'provider' => 'openai',
		);

		$generic_response = wp_mcp_ai_extract_generic_tool_response( $raw_response, 'openai' );

		$this->assertNull( $generic_response->get_content() );
	}

	/**
	 * Test that usage is null when not present.
	 */
	public function test_get_usage_when_not_present() {
		$raw_response = array(
			'choices'  => array(
				array(
					'index'   => 0,
					'message' => array(
						'role'    => 'assistant',
						'content' => 'Test',
					),
				),
			),
			'provider' => 'openai',
		);

		$generic_response = wp_mcp_ai_extract_generic_tool_response( $raw_response, 'openai' );

		$this->assertNull( $generic_response->get_usage() );
	}

	/**
	 * Test that finish reason is null when not present.
	 */
	public function test_get_finish_reason_when_not_present() {
		$raw_response = array(
			'choices'  => array(
				array(
					'index'   => 0,
					'message' => array(
						'role'    => 'assistant',
						'content' => 'Test',
					),
				),
			),
			'provider' => 'openai',
		);

		$generic_response = wp_mcp_ai_extract_generic_tool_response( $raw_response, 'openai' );

		$this->assertNull( $generic_response->get_finish_reason() );
	}

	/**
	 * Test that tool calls are null when not present.
	 */
	public function test_get_tool_calls_when_not_present() {
		$raw_response = array(
			'choices'  => array(
				array(
					'index'   => 0,
					'message' => array(
						'role'    => 'assistant',
						'content' => 'Test',
					),
				),
			),
			'provider' => 'openai',
		);

		$generic_response = wp_mcp_ai_extract_generic_tool_response( $raw_response, 'openai' );

		$this->assertNull( $generic_response->get_tool_calls() );
	}

	/**
	 * Test that model defaults to null when not present.
	 */
	public function test_get_model_when_not_present() {
		$raw_response = array(
			'choices'  => array(
				array(
					'index'   => 0,
					'message' => array(
						'role'    => 'assistant',
						'content' => 'Test',
					),
				),
			),
			'provider' => 'openai',
		);

		$generic_response = wp_mcp_ai_extract_generic_tool_response( $raw_response, 'openai' );

		$this->assertNull( $generic_response->get_model() );
	}
}
