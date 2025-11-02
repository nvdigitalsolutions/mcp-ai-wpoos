<?php
/**
 * Tests for OpenAI multi-tool call handling.
 *
 * @package WP_MCP_AI
 */

/**
 * Test multi-tool call scenarios with the OpenAI client.
 */
class WP_MCP_AI_OpenAI_Multi_Tool_Calls_Test extends WP_UnitTestCase {

	/**
	 * Ensure messages with multiple tool calls are properly structured for subsequent API requests.
	 *
	 * When OpenAI returns an assistant message with multiple tool_calls, the next request should:
	 * 1. Include the assistant message with its tool_calls array
	 * 2. Include a separate role: "tool" message for each tool call with matching tool_call_id
	 */
	public function test_multiple_tool_calls_message_structure() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test-multiple-tools';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client = new WP_MCP_AI_OpenAI_Client();

		// Simulate a conversation where:
		// 1. User asks a question
		// 2. Assistant responds with multiple tool calls
		// 3. Each tool is executed
		// 4. Results are sent back to OpenAI

		$messages = array(
			// User's initial question
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Get the weather for London and Paris',
					),
				),
			),
			// Assistant's response with multiple tool calls
			array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => array(
					array(
						'id'       => 'call_123',
						'type'     => 'function',
						'function' => array(
							'name'      => 'get_weather',
							'arguments' => '{"city":"London"}',
						),
					),
					array(
						'id'       => 'call_456',
						'type'     => 'function',
						'function' => array(
							'name'      => 'get_weather',
							'arguments' => '{"city":"Paris"}',
						),
					),
				),
			),
			// Tool response for London
			array(
				'role'         => 'tool',
				'tool_call_id' => 'call_123',
				'content'      => 'Weather in London: Cloudy, 15°C',
			),
			// Tool response for Paris
			array(
				'role'         => 'tool',
				'tool_call_id' => 'call_456',
				'content'      => 'Weather in Paris: Sunny, 22°C',
			),
		);

		$response_payload = array(
			'id'      => 'chatcmpl-test',
			'object'  => 'chat.completion',
			'model'   => 'gpt-4',
			'choices' => array(
				array(
					'index'         => 0,
					'message'       => array(
						'role'    => 'assistant',
						'content' => 'Based on the weather data: London is cloudy at 15°C and Paris is sunny at 22°C.',
					),
					'finish_reason' => 'stop',
				),
			),
		);

		// Mock the HTTP request to OpenAI
		$filter = function ( $preempt, $args, $url ) use ( $messages, $response_payload ) {
			// Decode the request body to verify message structure
			$body = json_decode( $args['body'], true );

			// Verify the messages array structure
			$this->assertIsArray( $body['messages'], 'Messages should be an array' );
			$this->assertCount( 4, $body['messages'], 'Should have 4 messages: user, assistant with tool_calls, and 2 tool responses' );

			// Check user message
			$this->assertSame( 'user', $body['messages'][0]['role'] );

			// Check assistant message with tool_calls
			$this->assertSame( 'assistant', $body['messages'][1]['role'] );
			$this->assertArrayHasKey( 'tool_calls', $body['messages'][1], 'Assistant message should have tool_calls' );
			$this->assertCount( 2, $body['messages'][1]['tool_calls'], 'Should have 2 tool calls' );
			$this->assertSame( 'call_123', $body['messages'][1]['tool_calls'][0]['id'] );
			$this->assertSame( 'call_456', $body['messages'][1]['tool_calls'][1]['id'] );

			// Check first tool response
			$this->assertSame( 'tool', $body['messages'][2]['role'] );
			$this->assertSame( 'call_123', $body['messages'][2]['tool_call_id'] );
			$this->assertArrayNotHasKey( 'tool_calls', $body['messages'][2], 'Tool message should NOT have tool_calls array' );

			// Check second tool response
			$this->assertSame( 'tool', $body['messages'][3]['role'] );
			$this->assertSame( 'call_456', $body['messages'][3]['tool_call_id'] );
			$this->assertArrayNotHasKey( 'tool_calls', $body['messages'][3], 'Tool message should NOT have tool_calls array' );

			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( $response_payload ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );

		$response = $client->create_chat_completion( $messages, array( 'model' => 'gpt-4' ) );

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'choices', $response );
	}

	/**
	 * Test that tool messages without tool_call_id are rejected.
	 */
	public function test_tool_message_requires_tool_call_id() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client = new WP_MCP_AI_OpenAI_Client();

		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Hello',
					),
				),
			),
			array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => array(
					array(
						'id'       => 'call_123',
						'type'     => 'function',
						'function' => array(
							'name'      => 'get_weather',
							'arguments' => '{"city":"London"}',
						),
					),
				),
			),
			// Tool message without tool_call_id should be filtered out
			array(
				'role'    => 'tool',
				'content' => 'Result',
			),
		);

		$filter = function ( $preempt, $args, $url ) {
			$body = json_decode( $args['body'], true );

			// The tool message without tool_call_id should have been filtered out
			// So we should only have user and assistant messages
			$this->assertCount( 2, $body['messages'], 'Tool message without tool_call_id should be filtered' );

			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode(
					array(
						'id'      => 'chatcmpl-test',
						'choices' => array(
							array(
								'index'   => 0,
								'message' => array(
									'role'    => 'assistant',
									'content' => 'Response',
								),
							),
						),
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );

		$response = $client->create_chat_completion( $messages, array( 'model' => 'gpt-4' ) );

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertIsArray( $response );
	}
}
