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
}
