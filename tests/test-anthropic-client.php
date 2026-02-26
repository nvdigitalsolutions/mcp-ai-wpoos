<?php
/**
 * Tests for the Anthropic client wrapper.
 */
class WP_MCP_AI_Anthropic_Client_Test extends WP_UnitTestCase {

	/**
	 * Ensure an error is returned when the Anthropic API key is missing.
	 */
	public function test_create_chat_completion_requires_api_key() {
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );

		$client   = new WP_MCP_AI_Anthropic_Client();
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		$response = $client->create_chat_completion( $messages, array() );

		$this->assertWPError( $response );
		$this->assertSame( 'wp_mcp_ai_missing_anthropic_api_key', $response->get_error_code() );

		$data = $response->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 400, $data['status'] );
		$this->assertArrayHasKey( 'actions', $data );
		$this->assertArrayHasKey( 'configure_anthropic_api_key', $data['actions'] );
	}

	/**
	 * Ensure an error is returned when the Anthropic model is missing.
	 */
	public function test_create_chat_completion_requires_model() {
		$defaults                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['anthropic_api_key'] = 'sk-ant-test-key';
		$defaults['anthropic_model']   = '';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client   = new WP_MCP_AI_Anthropic_Client();
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		$response = $client->create_chat_completion( $messages, array() );

		// Should use default model, so this should not error.
		// Instead, it will error on HTTP request (no real API key).
		$this->assertWPError( $response );
	}

	/**
	 * Ensure the Anthropic client normalizes the response correctly.
	 */
	public function test_create_chat_completion_normalizes_response() {
		$defaults                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['anthropic_api_key'] = 'sk-ant-test-key';
		$defaults['anthropic_model']   = 'claude-3-5-sonnet-20241022';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Anthropic_Client();
		$captured_request = null;

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			// Mock Anthropic API response.
			$mock_response = array(
				'id'            => 'msg_test123',
				'type'          => 'message',
				'role'          => 'assistant',
				'model'         => 'claude-3-5-sonnet-20241022',
				'content'       => array(
					array(
						'type' => 'text',
						'text' => 'Hello! How can I help you today?',
					),
				),
				'stop_reason'   => 'end_turn',
				'stop_sequence' => null,
				'usage'         => array(
					'input_tokens'  => 10,
					'output_tokens' => 15,
				),
			);

			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( $mock_response ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		$response = $client->create_chat_completion( $messages, array() );

		remove_filter( 'pre_http_request', $filter_callback );

		$this->assertNotWPError( $response );
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'choices', $response );
		$this->assertArrayHasKey( 'provider', $response );
		$this->assertSame( 'anthropic', $response['provider'] );

		$this->assertIsArray( $response['choices'] );
		$this->assertCount( 1, $response['choices'] );
		$this->assertArrayHasKey( 'message', $response['choices'][0] );

		$message = $response['choices'][0]['message'];
		$this->assertArrayHasKey( 'role', $message );
		$this->assertSame( 'assistant', $message['role'] );
		$this->assertArrayHasKey( 'content', $message );
		$this->assertIsArray( $message['content'] );
		$this->assertCount( 1, $message['content'] );
		$this->assertSame( 'text', $message['content'][0]['type'] );
		$this->assertSame( 'Hello! How can I help you today?', $message['content'][0]['text'] );

		// Check usage data.
		$this->assertArrayHasKey( 'usage', $response );
		$this->assertSame( 10, $response['usage']['prompt_tokens'] );
		$this->assertSame( 15, $response['usage']['completion_tokens'] );
		$this->assertSame( 25, $response['usage']['total_tokens'] );

		// Verify request format.
		$this->assertNotNull( $captured_request );
		$this->assertSame( 'https://api.anthropic.com/v1/messages', $captured_request['url'] );
		$this->assertArrayHasKey( 'headers', $captured_request['args'] );
		$this->assertArrayHasKey( 'x-api-key', $captured_request['args']['headers'] );
		$this->assertSame( 'sk-ant-test-key', $captured_request['args']['headers']['x-api-key'] );
		$this->assertArrayHasKey( 'anthropic-version', $captured_request['args']['headers'] );
	}

	/**
	 * Test that system messages are handled correctly.
	 */
	public function test_system_message_handling() {
		$defaults                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['anthropic_api_key'] = 'sk-ant-test-key';
		$defaults['anthropic_model']   = 'claude-3-haiku-20240307';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Anthropic_Client();
		$captured_payload = null;

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_payload ) {
			$captured_payload = json_decode( $args['body'], true );

			$mock_response = array(
				'id'          => 'msg_test',
				'type'        => 'message',
				'role'        => 'assistant',
				'model'       => 'claude-3-haiku-20240307',
				'content'     => array(
					array(
						'type' => 'text',
						'text' => 'Understood.',
					),
				),
				'stop_reason' => 'end_turn',
				'usage'       => array(
					'input_tokens'  => 20,
					'output_tokens' => 5,
				),
			);

			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( $mock_response ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are a helpful assistant.',
			),
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		$response = $client->create_chat_completion( $messages, array() );

		remove_filter( 'pre_http_request', $filter_callback );

		$this->assertNotWPError( $response );

		// Verify that system message is in separate field.
		$this->assertNotNull( $captured_payload );
		$this->assertArrayHasKey( 'system', $captured_payload );
		$this->assertSame( 'You are a helpful assistant.', $captured_payload['system'] );

		// Verify that messages array only has user message.
		$this->assertArrayHasKey( 'messages', $captured_payload );
		$this->assertCount( 1, $captured_payload['messages'] );
		$this->assertSame( 'user', $captured_payload['messages'][0]['role'] );
	}

	/**
	 * Test that max_tokens is required and set correctly.
	 */
	public function test_max_tokens_required() {
		$defaults                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['anthropic_api_key'] = 'sk-ant-test-key';
		$defaults['anthropic_model']   = 'claude-3-sonnet-20240229';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Anthropic_Client();
		$captured_payload = null;

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_payload ) {
			$captured_payload = json_decode( $args['body'], true );

			$mock_response = array(
				'id'          => 'msg_test',
				'type'        => 'message',
				'role'        => 'assistant',
				'model'       => 'claude-3-sonnet-20240229',
				'content'     => array(
					array(
						'type' => 'text',
						'text' => 'Hello',
					),
				),
				'stop_reason' => 'end_turn',
				'usage'       => array(
					'input_tokens'  => 5,
					'output_tokens' => 3,
				),
			);

			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( $mock_response ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hi',
			),
		);

		// Test with default max_tokens (should be set from resource manager).
		$response = $client->create_chat_completion( $messages, array() );
		remove_filter( 'pre_http_request', $filter_callback );

		$this->assertNotWPError( $response );
		$this->assertNotNull( $captured_payload );
		$this->assertArrayHasKey( 'max_tokens', $captured_payload );
		$this->assertGreaterThan( 0, $captured_payload['max_tokens'] );

		// Test with explicit max_tokens.
		$captured_payload = null;
		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$response = $client->create_chat_completion( $messages, array( 'max_tokens' => 500 ) );
		remove_filter( 'pre_http_request', $filter_callback );

		$this->assertNotWPError( $response );
		$this->assertNotNull( $captured_payload );
		$this->assertArrayHasKey( 'max_tokens', $captured_payload );
		$this->assertSame( 500, $captured_payload['max_tokens'] );
	}

	/**
	 * Helper: build a mock HTTP filter that returns a successful Anthropic response.
	 *
	 * @param array|null $captured_payload Reference to capture the decoded request body.
	 * @param string     $model            Model name to echo back.
	 * @return callable
	 */
	private function make_mock_filter( &$captured_payload, $model = 'claude-3-5-sonnet-20241022' ) {
		return function ( $preempt, $args, $url ) use ( &$captured_payload, $model ) {
			$captured_payload = json_decode( $args['body'], true );

			$mock_response = array(
				'id'          => 'msg_test',
				'type'        => 'message',
				'role'        => 'assistant',
				'model'       => $model,
				'content'     => array(
					array(
						'type' => 'text',
						'text' => 'OK',
					),
				),
				'stop_reason' => 'end_turn',
				'usage'       => array(
					'input_tokens'  => 5,
					'output_tokens' => 2,
				),
			);

			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( $mock_response ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};
	}

	/**
	 * Test that tool-role messages are converted to Anthropic tool_result format.
	 */
	public function test_tool_messages_converted_to_tool_result() {
		$defaults                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['anthropic_api_key'] = 'sk-ant-test-key';
		$defaults['anthropic_model']   = 'claude-3-5-sonnet-20241022';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Anthropic_Client();
		$captured_payload = null;
		$filter           = $this->make_mock_filter( $captured_payload );

		add_filter( 'pre_http_request', $filter, 10, 3 );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'What is 2+2?',
			),
			array(
				'role'       => 'assistant',
				'content'    => array(
					array(
						'type' => 'text',
						'text' => 'Let me calculate.',
					),
				),
				'tool_calls' => array(
					array(
						'id'       => 'toolu_abc123',
						'type'     => 'function',
						'function' => array(
							'name'      => 'calculate',
							'arguments' => '{"expression":"2+2"}',
						),
					),
				),
			),
			array(
				'role'         => 'tool',
				'tool_call_id' => 'toolu_abc123',
				'name'         => 'calculate',
				'content'      => '4',
			),
		);

		$response = $client->create_chat_completion( $messages, array() );
		remove_filter( 'pre_http_request', $filter );

		$this->assertNotWPError( $response );
		$this->assertNotNull( $captured_payload );
		$this->assertArrayHasKey( 'messages', $captured_payload );

		$sent_messages = $captured_payload['messages'];

		// Expect: user, assistant (with tool_use), user (with tool_result).
		$this->assertCount( 3, $sent_messages );

		// First: original user message.
		$this->assertSame( 'user', $sent_messages[0]['role'] );

		// Second: assistant message must contain a tool_use block.
		$this->assertSame( 'assistant', $sent_messages[1]['role'] );
		$assistant_content = $sent_messages[1]['content'];
		$this->assertIsArray( $assistant_content );
		$tool_use_block = null;
		foreach ( $assistant_content as $block ) {
			if ( isset( $block['type'] ) && 'tool_use' === $block['type'] ) {
				$tool_use_block = $block;
				break;
			}
		}
		$this->assertNotNull( $tool_use_block, 'Assistant message should contain a tool_use block.' );
		$this->assertSame( 'toolu_abc123', $tool_use_block['id'] );
		$this->assertSame( 'calculate', $tool_use_block['name'] );
		$this->assertSame( array( 'expression' => '2+2' ), $tool_use_block['input'] );

		// Third: user message with tool_result block.
		$this->assertSame( 'user', $sent_messages[2]['role'] );
		$tool_result_content = $sent_messages[2]['content'];
		$this->assertIsArray( $tool_result_content );
		$this->assertCount( 1, $tool_result_content );
		$this->assertSame( 'tool_result', $tool_result_content[0]['type'] );
		$this->assertSame( 'toolu_abc123', $tool_result_content[0]['tool_use_id'] );
		$this->assertSame( '4', $tool_result_content[0]['content'] );
	}

	/**
	 * Test that multiple consecutive tool messages are grouped into one user message.
	 */
	public function test_consecutive_tool_messages_are_grouped() {
		$defaults                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['anthropic_api_key'] = 'sk-ant-test-key';
		$defaults['anthropic_model']   = 'claude-3-5-sonnet-20241022';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Anthropic_Client();
		$captured_payload = null;
		$filter           = $this->make_mock_filter( $captured_payload );

		add_filter( 'pre_http_request', $filter, 10, 3 );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Do two things.',
			),
			array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => array(
					array(
						'id'       => 'toolu_111',
						'type'     => 'function',
						'function' => array(
							'name'      => 'tool_a',
							'arguments' => '{}',
						),
					),
					array(
						'id'       => 'toolu_222',
						'type'     => 'function',
						'function' => array(
							'name'      => 'tool_b',
							'arguments' => '{}',
						),
					),
				),
			),
			array(
				'role'         => 'tool',
				'tool_call_id' => 'toolu_111',
				'name'         => 'tool_a',
				'content'      => 'result_a',
			),
			array(
				'role'         => 'tool',
				'tool_call_id' => 'toolu_222',
				'name'         => 'tool_b',
				'content'      => 'result_b',
			),
		);

		$response = $client->create_chat_completion( $messages, array() );
		remove_filter( 'pre_http_request', $filter );

		$this->assertNotWPError( $response );
		$this->assertNotNull( $captured_payload );

		$sent_messages = $captured_payload['messages'];

		// user + assistant + 1 user (two tool_results merged).
		$this->assertCount( 3, $sent_messages );
		$this->assertSame( 'user', $sent_messages[2]['role'] );
		$this->assertCount( 2, $sent_messages[2]['content'] );
		$this->assertSame( 'tool_result', $sent_messages[2]['content'][0]['type'] );
		$this->assertSame( 'toolu_111', $sent_messages[2]['content'][0]['tool_use_id'] );
		$this->assertSame( 'tool_result', $sent_messages[2]['content'][1]['type'] );
		$this->assertSame( 'toolu_222', $sent_messages[2]['content'][1]['tool_use_id'] );
	}

	/**
	 * Test that a trailing assistant message is silently removed.
	 */
	public function test_trailing_assistant_message_is_removed() {
		$defaults                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['anthropic_api_key'] = 'sk-ant-test-key';
		$defaults['anthropic_model']   = 'claude-3-5-sonnet-20241022';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Anthropic_Client();
		$captured_payload = null;
		$filter           = $this->make_mock_filter( $captured_payload );

		add_filter( 'pre_http_request', $filter, 10, 3 );

		// Pass a conversation that incorrectly ends with an assistant message.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Hi there!',
			),
		);

		$response = $client->create_chat_completion( $messages, array() );
		remove_filter( 'pre_http_request', $filter );

		// The request should succeed (trailing assistant message removed).
		$this->assertNotWPError( $response );
		$this->assertNotNull( $captured_payload );

		// Only the user message should have been sent.
		$sent_messages = $captured_payload['messages'];
		$this->assertCount( 1, $sent_messages );
		$this->assertSame( 'user', $sent_messages[0]['role'] );
	}

	/**
	 * Test that consecutive user messages are merged into one.
	 */
	public function test_consecutive_user_messages_are_merged() {
		$defaults                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['anthropic_api_key'] = 'sk-ant-test-key';
		$defaults['anthropic_model']   = 'claude-3-5-sonnet-20241022';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Anthropic_Client();
		$captured_payload = null;
		$filter           = $this->make_mock_filter( $captured_payload );

		add_filter( 'pre_http_request', $filter, 10, 3 );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'First part.',
			),
			array(
				'role'    => 'user',
				'content' => 'Second part.',
			),
		);

		$response = $client->create_chat_completion( $messages, array() );
		remove_filter( 'pre_http_request', $filter );

		$this->assertNotWPError( $response );
		$this->assertNotNull( $captured_payload );

		// The two consecutive user messages should be merged into one.
		$sent_messages = $captured_payload['messages'];
		$this->assertCount( 1, $sent_messages );
		$this->assertSame( 'user', $sent_messages[0]['role'] );
		$this->assertStringContainsString( 'First part.', $sent_messages[0]['content'] );
		$this->assertStringContainsString( 'Second part.', $sent_messages[0]['content'] );
	}
}
