<?php
/**
 * Tests for the OpenAI client wrapper.
 */
class WP_MCP_AI_OpenAI_Client_Test extends WP_UnitTestCase {

	/**
	 * Ensure the client falls back to the global default model when none is provided.
	 */
	public function test_create_chat_completion_uses_global_default_model() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['openai_api_key'] = 'sk-test';
		$defaults['default_model']  = 'gpt-unit-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_OpenAI_Client();
		$captured_request = null;
		$filter_callback  = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = $args;

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'id'      => 'chatcmpl-test',
						'choices' => array(),
					)
				),
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
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Hello',
					),
				),
			),
		);

		$response = $client->create_chat_completion( $messages, array() );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'choices', $response );
		$this->assertNotNull( $captured_request );
		$this->assertArrayHasKey( 'body', $captured_request );

		$payload = json_decode( $captured_request['body'], true );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'model', $payload );
		$this->assertSame( 'gpt-unit-test', $payload['model'] );
		$this->assertArrayHasKey( 'messages', $payload );
		$this->assertSame( 'Hello', $payload['messages'][0]['content'] );
		$this->assertSame( $defaults['request_timeout'], $captured_request['timeout'] );
	}

	/**
	 * Ensure multimodal messages retain their structured content segments.
	 */
	public function test_create_chat_completion_preserves_multimodal_segments() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_OpenAI_Client();
		$captured_request = null;

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = $args;

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'id'      => 'chatcmpl-test',
						'choices' => array(),
					)
				),
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
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Describe this image',
					),
					array(
						'type'      => 'input_image',
						'image_url' => array( 'url' => 'https://example.com/image.png' ),
					),
				),
			),
		);

		$response = $client->create_chat_completion( $messages, array() );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $response );
		$this->assertNotNull( $captured_request );

		$payload = json_decode( $captured_request['body'], true );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'messages', $payload );
		$this->assertIsArray( $payload['messages'][0]['content'] );
		$this->assertSame( 'text', $payload['messages'][0]['content'][0]['type'] );
		$this->assertSame( 'Describe this image', $payload['messages'][0]['content'][0]['text'] );
		$this->assertSame( 'input_image', $payload['messages'][0]['content'][1]['type'] );
	}

	/**
	 * Ensure requests containing attachments are routed through the Responses API.
	 */
	public function test_create_chat_completion_with_attachments_uses_responses_api() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_OpenAI_Client();
		$captured_request = array();

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'url'  => $url,
				'args' => $args,
			);

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'id'     => 'resp-test',
						'output' => array(
							array(
								'role'    => 'assistant',
								'content' => array(
									array(
										'type' => 'output_text',
										'text' => 'Hello from Responses API.',
									),
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

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type'    => 'input_file',
						'file_id' => 'file-123',
					),
				),
			),
		);

		$options = array(
			'attachments' => array(
				array(
					'id'        => 'file-123',
					'filename'  => 'notes.txt',
					'mime_type' => 'text/plain',
					'data'      => base64_encode( 'Example content' ),
					'bytes'     => strlen( 'Example content' ),
				),
			),
		);

		$response = $client->create_chat_completion( $messages, $options );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotEmpty( $captured_request );
		$this->assertSame( WP_MCP_AI_OpenAI_Client::RESPONSES_ENDPOINT, $captured_request['url'] );

		$payload = json_decode( $captured_request['args']['body'], true );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'input', $payload );
		$this->assertArrayNotHasKey( 'messages', $payload );
		$this->assertArrayHasKey( 'attachments', $payload );
		$this->assertSame( 'file-123', $payload['attachments'][0]['id'] );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'choices', $response );
		$this->assertSame( 'Hello from Responses API.', $response['choices'][0]['message']['content'] );
	}
}
