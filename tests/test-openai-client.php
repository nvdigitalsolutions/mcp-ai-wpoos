<?php
/**
 * Helper client that forces the Chat Completions endpoint during detection.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Force_Chat_Client extends WP_MCP_AI_OpenAI_Client {

	/** @inheritDoc */
	protected function should_use_responses_api( array $messages, array $options ) {
		return false;
	}
}

/**
 * Helper client that forces the Responses API endpoint during detection.
 */
class WP_MCP_AI_Force_Responses_Client extends WP_MCP_AI_OpenAI_Client {

	/** @inheritDoc */
	protected function should_use_responses_api( array $messages, array $options ) {
		return true;
	}

	/**
	 * Make prepare_responses_input public for testing.
	 *
	 * @param array $original_messages   Original chat messages.
	 * @param array $normalised_messages Messages after normalisation.
	 * @param array $attachments         Attachment payloads.
	 * @return array
	 */
	public function public_prepare_responses_input( array $original_messages, array $normalised_messages, array $attachments = array() ) {
		return $this->prepare_responses_input( $original_messages, $normalised_messages, $attachments );
	}
}

/**
 * Tests for the OpenAI client wrapper.
 */
class WP_MCP_AI_OpenAI_Client_Test extends WP_UnitTestCase {

	/**
	 * Ensure missing API key errors include actionable guidance.
	 */
	public function test_create_chat_completion_requires_api_key() {
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );

		$client   = new WP_MCP_AI_OpenAI_Client();
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

		$this->assertWPError( $response );
		$this->assertSame( 'wp_mcp_ai_missing_api_key', $response->get_error_code() );

		$data = $response->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 400, $data['status'] );
		$this->assertArrayHasKey( 'actions', $data );
		$this->assertArrayHasKey( 'configure_openai_api_key', $data['actions'] );
	}

	/**
	 * Ensure WordPress transport timeouts surface actionable guidance.
	 */
	public function test_create_chat_completion_exposes_wordpress_timeout_guidance() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-timeout';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client   = new WP_MCP_AI_OpenAI_Client();
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

		$filter = function ( $preempt, $args, $url ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 1000 milliseconds with 0 bytes received' );
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );

		$response = $client->create_chat_completion( $messages, array() );

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertWPError( $response );
		$this->assertSame( 'wp_mcp_ai_wordpress_timeout', $response->get_error_code() );
		$this->assertSame( 'WordPress timed out waiting for a response from OpenAI.', $response->get_error_message() );

		$data = $response->get_error_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'status', $data );
		$this->assertSame( 504, $data['status'] );
		$this->assertArrayHasKey( 'actions', $data );
		$this->assertArrayHasKey( 'configure_request_timeout', $data['actions'] );
	}

	/**
	 * GPT-Image-1 should allow callers to control the response_format flag.
	 */
	public function test_image_model_supports_response_format_for_gpt_image() {
		$this->assertTrue( WP_MCP_AI_OpenAI_Client::image_model_supports_response_format( 'gpt-image-1' ) );
	}

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

		$this->assertArrayHasKey( 'headers', $captured_request );
		$this->assertArrayNotHasKey( 'OpenAI-Beta', $captured_request['headers'] );

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
	 * Ensure delete requests require a configured API key.
	 */
	public function test_delete_file_requires_api_key() {
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );

		$client   = new WP_MCP_AI_OpenAI_Client();
		$response = $client->delete_file( 'file-123' );

		$this->assertWPError( $response );
		$this->assertSame( 'wp_mcp_ai_missing_api_key', $response->get_error_code() );
	}

	/**
	 * Ensure delete requests target the expected endpoint and use the DELETE method.
	 */
	public function test_delete_file_sends_delete_request() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_OpenAI_Client();
		$captured_request = null;
		$expected_file_id = 'file-delete-123';

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request, $expected_file_id ) {
			if ( WP_MCP_AI_OpenAI_Client::FILES_ENDPOINT . '/' . $expected_file_id !== $url ) {
				return false;
			}

			$captured_request = $args;

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'id'      => $expected_file_id,
						'deleted' => true,
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$response = $client->delete_file( $expected_file_id );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'deleted', $response );
		$this->assertTrue( $response['deleted'] );

		$this->assertNotNull( $captured_request );
		$this->assertSame( 'DELETE', $captured_request['method'] );
		$this->assertArrayHasKey( 'headers', $captured_request );
		$this->assertArrayHasKey( 'Authorization', $captured_request['headers'] );
	}

	/**
	 * Ensure download_file requests the file content endpoint and normalises the response headers.
	 */
	public function test_download_file_requests_content_endpoint() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_OpenAI_Client();
		$captured_request = null;

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			if ( WP_MCP_AI_OpenAI_Client::FILES_ENDPOINT . '/file-download-123/content' !== $url ) {
				return null;
			}

			$captured_request = array(
				'url'  => $url,
				'args' => $args,
			);

			return array(
				'headers'  => array(
					'Content-Type'        => 'text/plain',
					'Content-Disposition' => 'attachment; filename="notes.txt"',
				),
				'body'     => 'Example content',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$result = $client->download_file( 'file-download-123' );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotNull( $captured_request );
		$this->assertSame( WP_MCP_AI_OpenAI_Client::FILES_ENDPOINT . '/file-download-123/content', $captured_request['url'] );
		$this->assertSame( 'GET', strtoupper( $captured_request['args']['method'] ) );
		$this->assertSame( 'Bearer sk-test', $captured_request['args']['headers']['Authorization'] );

		$this->assertIsArray( $result );
		$this->assertSame( 'Example content', $result['body'] );
		$this->assertSame( 'text/plain', $result['content_type'] );
		$this->assertSame( 'notes.txt', $result['filename'] );
		$this->assertSame( 200, $result['status_code'] );
		$this->assertArrayHasKey( 'headers', $result );
		$this->assertSame( 'text/plain', $result['headers']['content-type'] );
		$this->assertSame( 'attachment; filename="notes.txt"', $result['headers']['content-disposition'] );
	}

	/**
	 * Ensure download_file propagates error responses from the Files API.
	 */
	public function test_download_file_handles_error_status() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client = new WP_MCP_AI_OpenAI_Client();

		$filter_callback = function () {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'error' => array( 'message' => 'Not found' ) ) ),
				'response' => array(
					'code'    => 404,
					'message' => 'Not Found',
				),
			);
		};

		add_filter( 'pre_http_request', $filter_callback );

		$result = $client->download_file( 'file-missing' );

		remove_filter( 'pre_http_request', $filter_callback );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_file_download_failed', $result->get_error_code() );
	}

	/**
	 * Ensure download_file rejects empty responses even when the status code succeeds.
	 */
	public function test_download_file_rejects_empty_body() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client = new WP_MCP_AI_OpenAI_Client();

		$filter_callback = function () {
			return array(
				'headers'  => array( 'Content-Type' => 'application/octet-stream' ),
				'body'     => '',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $filter_callback );

		$result = $client->download_file( 'file-empty' );

		remove_filter( 'pre_http_request', $filter_callback );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_file_download_empty', $result->get_error_code() );
	}

	/**
	 * Ensure chat completion payloads include the tool name alongside the function definition.
	 */
	public function test_chat_completion_payload_includes_tool_name() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Force_Chat_Client();
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
						'text' => 'Hello',
					),
				),
			),
		);

		$tool_definition = array(
			'type'     => 'function',
			'function' => array(
				'name'        => 'fetch_latest_posts',
				'description' => 'Fetches the latest posts.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(),
				),
			),
		);

		$response = $client->create_chat_completion(
			$messages,
			array(
				'tools' => array( $tool_definition ),
			)
		);

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'choices', $response );
		$this->assertNotNull( $captured_request );

		$payload = json_decode( $captured_request['body'], true );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'tools', $payload );
		$this->assertIsArray( $payload['tools'] );
		$this->assertArrayHasKey( 0, $payload['tools'] );
		$this->assertArrayHasKey( 'name', $payload['tools'][0] );
		$this->assertSame( 'fetch_latest_posts', $payload['tools'][0]['name'] );
		$this->assertArrayHasKey( 'function', $payload['tools'][0] );
		$this->assertSame( 'fetch_latest_posts', $payload['tools'][0]['function']['name'] );
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
					'file_id'   => 'file-123',
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

		$this->assertArrayHasKey( 'headers', $captured_request['args'] );
		$this->assertArrayHasKey( 'OpenAI-Beta', $captured_request['args']['headers'] );
		$this->assertSame( 'responses=v1', $captured_request['args']['headers']['OpenAI-Beta'] );

		$payload = json_decode( $captured_request['args']['body'], true );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'input', $payload );
		$this->assertArrayNotHasKey( 'messages', $payload );
		$this->assertArrayNotHasKey( 'attachments', $payload );

		$this->assertArrayHasKey( 0, $payload['input'] );
		$content = $payload['input'][0]['content'];

		$this->assertIsArray( $content );
		$this->assertArrayHasKey( 0, $content );
		$file_segment = $content[0];

		$this->assertSame( 'input_file', $file_segment['type'] );
		$this->assertArrayHasKey( 'file_id', $file_segment );
		$this->assertSame( 'file-123', $file_segment['file_id'] );
		$this->assertArrayNotHasKey( 'file', $file_segment );
		$this->assertArrayNotHasKey( 'file_data', $file_segment );
		$this->assertArrayNotHasKey( 'filename', $file_segment );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'choices', $response );
		$this->assertSame( 'Hello from Responses API.', $response['choices'][0]['message']['content'] );
	}

	/**
	 * Ensure image segments expose the `file_id` key when building Responses payloads.
	 */
	public function test_responses_payload_uses_file_id_key() {
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
						'id'     => 'resp-image',
						'output' => array(),
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
						'type'    => 'input_image',
						'caption' => 'Reference still',
						'detail'  => 'high',
						'file_id' => 'file-img-123',
					),
				),
			),
		);

		$options = array(
			'attachments' => array(
				array(
					'id'        => 'file-img-123',
					'file_id'   => 'file-img-123',
					'filename'  => 'reference.png',
					'mime_type' => 'image/png',
					'data'      => base64_encode( 'image-data' ),
					'bytes'     => strlen( 'image-data' ),
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
		$this->assertArrayHasKey( 0, $payload['input'] );

		$input_message = $payload['input'][0];
		$this->assertArrayHasKey( 'content', $input_message );
		$this->assertIsArray( $input_message['content'] );
		$this->assertArrayHasKey( 0, $input_message['content'] );

		$caption_segment = $input_message['content'][0];
		$this->assertSame( 'input_text', $caption_segment['type'] );
		$this->assertSame( 'Reference still', $caption_segment['text'] );

		$this->assertArrayHasKey( 1, $input_message['content'] );

		$image_segment = $input_message['content'][1];
		$this->assertSame( 'input_image', $image_segment['type'] );
		$this->assertArrayHasKey( 'file_id', $image_segment );
		$this->assertSame( 'file-img-123', $image_segment['file_id'] );
		$this->assertArrayNotHasKey( 'image', $image_segment );
		$this->assertArrayNotHasKey( 'image_url', $image_segment );
		$this->assertArrayNotHasKey( 'caption', $image_segment );
		$this->assertSame( 'high', $image_segment['detail'] );

		$this->assertIsArray( $response );
	}

	/**
	 * Ensure Responses API choices without message payloads are normalised for the chat UI.
	 */
	public function test_responses_choices_are_transformed_into_chat_completion_shape() {
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
						'id'      => 'resp-choices',
						'choices' => array(
							array(
								'id'            => 'choice-1',
								'type'          => 'message',
								'role'          => 'assistant',
								'content'       => array(
									array(
										'type' => 'output_text',
										'text' => array(
											'value'       => 'Processed PDF summary.',
											'annotations' => array(),
										),
									),
								),
								'finish_reason' => 'stop',
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
						'file_id' => 'file-789',
					),
				),
			),
		);

		$options = array(
			'attachments' => array(
				array(
					'id'        => 'file-789',
					'filename'  => 'report.pdf',
					'mime_type' => 'application/pdf',
					'data'      => base64_encode( 'PDF data' ),
					'bytes'     => strlen( 'PDF data' ),
				),
			),
		);

		$response = $client->create_chat_completion( $messages, $options );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotEmpty( $captured_request );
		$this->assertSame( WP_MCP_AI_OpenAI_Client::RESPONSES_ENDPOINT, $captured_request['url'] );
		$this->assertArrayHasKey( 'headers', $captured_request['args'] );
		$this->assertArrayHasKey( 'OpenAI-Beta', $captured_request['args']['headers'] );
		$this->assertSame( 'responses=v1', $captured_request['args']['headers']['OpenAI-Beta'] );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'choices', $response );
		$this->assertArrayHasKey( 0, $response['choices'] );
		$this->assertArrayHasKey( 'message', $response['choices'][0] );
		$this->assertSame( 'Processed PDF summary.', $response['choices'][0]['message']['content'] );
		$this->assertSame( 'assistant', $response['choices'][0]['message']['role'] );
	}

	/**
	 * Ensure Responses API output items using the text field are normalised for chat rendering.
	 */
	public function test_responses_output_text_items_are_normalised() {
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
						'id'     => 'resp-output-text',
						'output' => array(
							array(
								'id'            => 'output-1',
								'type'          => 'output_text',
								'text'          => array(
									'value'       => 'Summary generated from attachment.',
									'annotations' => array(),
								),
								'finish_reason' => 'stop',
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
						'file_id' => 'file-456',
					),
				),
			),
		);

		$options = array(
			'attachments' => array(
				array(
					'id'        => 'file-456',
					'filename'  => 'handout.pdf',
					'mime_type' => 'application/pdf',
					'data'      => base64_encode( 'Attachment data' ),
					'bytes'     => strlen( 'Attachment data' ),
				),
			),
		);

		$response = $client->create_chat_completion( $messages, $options );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotEmpty( $captured_request );
		$this->assertSame( WP_MCP_AI_OpenAI_Client::RESPONSES_ENDPOINT, $captured_request['url'] );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'choices', $response );
		$this->assertArrayHasKey( 0, $response['choices'] );
		$this->assertArrayHasKey( 'message', $response['choices'][0] );
		$this->assertSame( 'Summary generated from attachment.', $response['choices'][0]['message']['content'] );
		$this->assertSame( 'assistant', $response['choices'][0]['message']['role'] );
	}

	/**
	 * Ensure Responses API payloads without textual content preserve their original segments.
	 */
	public function test_responses_choices_preserve_non_text_segments() {
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
						'id'      => 'resp-non-text',
						'choices' => array(
							array(
								'id'            => 'choice-1',
								'type'          => 'message',
								'role'          => 'assistant',
								'content'       => array(
									array(
										'type'  => 'output_image',
										'image' => array(
											'id' => 'file-img-123',
										),
									),
								),
								'finish_reason' => 'stop',
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

		$response = $client->create_chat_completion(
			array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'    => 'input_file',
							'file_id' => 'file-123',
						),
					),
				),
			)
		);

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'choices', $response );
		$this->assertCount( 1, $response['choices'] );

		$choice = $response['choices'][0];

		$this->assertArrayHasKey( 'message', $choice );
		$message = $choice['message'];

		$this->assertSame( 'assistant', $message['role'] );
		$this->assertIsArray( $message['content'] );
		$this->assertSame( 'output_image', $message['content'][0]['type'] );
		$this->assertSame( 'file-img-123', $message['content'][0]['image']['id'] );
	}

	/**
	 * Ensure Responses API payloads nested under the `response` key are flattened for chat rendering.
	 */
	public function test_responses_nested_payload_is_normalised() {
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
						'id'       => 'resp-nested',
						'response' => array(
							'status' => 'completed',
							'output' => array(
								array(
									'id'            => 'msg-1',
									'type'          => 'message',
									'role'          => 'assistant',
									'content'       => array(
										array(
											'type' => 'output_text',
											'text' => array(
												'value' => 'Nested payload text.',
												'annotations' => array(),
											),
										),
									),
									'finish_reason' => 'stop',
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
						'file_id' => 'file-789',
					),
				),
			),
		);

		$options = array(
			'attachments' => array(
				array(
					'id'        => 'file-789',
					'filename'  => 'report.pdf',
					'mime_type' => 'application/pdf',
					'data'      => base64_encode( 'PDF data' ),
					'bytes'     => strlen( 'PDF data' ),
				),
			),
		);

		$response = $client->create_chat_completion( $messages, $options );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotEmpty( $captured_request );
		$this->assertSame( WP_MCP_AI_OpenAI_Client::RESPONSES_ENDPOINT, $captured_request['url'] );
		$this->assertSame( 'Nested payload text.', $response['choices'][0]['message']['content'] );
		$this->assertArrayHasKey( 'response', $response );
		$this->assertArrayHasKey( 'choices', $response['response'] );
		$this->assertSame( 'Nested payload text.', $response['response']['choices'][0]['message']['content'] );
	}

	public function test_responses_output_text_segments_are_collapsed() {
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
						'id'       => 'resp-segmented',
						'response' => array(
							'status' => 'completed',
							'output' => array(
								array(
									'id'            => 'msg-1',
									'type'          => 'message',
									'role'          => 'assistant',
									'content'       => array(
										array(
											'type' => 'output_text',
											'text' => array(
												array(
													'type' => 'output_text',
													'text' => 'First paragraph.',
													'annotations' => array(),
												),
												array(
													'type' => 'output_text',
													'text' => 'Second paragraph.',
													'annotations' => array(),
												),
											),
										),
									),
									'finish_reason' => 'stop',
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
					'filename'  => 'outline.pdf',
					'mime_type' => 'application/pdf',
					'data'      => base64_encode( 'PDF data' ),
					'bytes'     => strlen( 'PDF data' ),
				),
			),
		);

		$response = $client->create_chat_completion( $messages, $options );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotEmpty( $captured_request );
		$this->assertSame( WP_MCP_AI_OpenAI_Client::RESPONSES_ENDPOINT, $captured_request['url'] );
		$this->assertSame( "First paragraph.\n\nSecond paragraph.", $response['choices'][0]['message']['content'] );
		$this->assertArrayHasKey( 'response', $response );
		$this->assertArrayHasKey( 'choices', $response['response'] );
		$this->assertSame( "First paragraph.\n\nSecond paragraph.", $response['response']['choices'][0]['message']['content'] );
	}

	/**
	 * Ensure Responses API output_text arrays are collapsed into a single assistant message.
	 */
	public function test_responses_output_text_arrays_are_joined() {
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
						'id'          => 'resp-output-array',
						'output_text' => array(
							'First paragraph.',
							'Second paragraph.',
							'',
							42,
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
					'filename'  => 'doc.pdf',
					'mime_type' => 'application/pdf',
					'data'      => base64_encode( 'PDF data' ),
					'bytes'     => strlen( 'PDF data' ),
				),
			),
		);

		$response = $client->create_chat_completion( $messages, $options );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotEmpty( $captured_request );
		$this->assertSame( WP_MCP_AI_OpenAI_Client::RESPONSES_ENDPOINT, $captured_request['url'] );
		$this->assertArrayHasKey( 'choices', $response );
		$this->assertSame( "First paragraph.\n\nSecond paragraph.\n\n42", $response['choices'][0]['message']['content'] );
	}

	/**
	 * Ensure Responses API payloads include the tool name alongside the function definition.
	 */
	public function test_responses_payload_includes_tool_name() {
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
						'output' => array(),
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

		$tool_definition = array(
			'type'     => 'function',
			'function' => array(
				'name'        => 'fetch_latest_posts',
				'description' => 'Fetches the latest posts.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(),
				),
			),
		);

		$options = array(
			'attachments' => array(
				array(
					'id'        => 'file-123',
					'file_id'   => 'file-123',
					'filename'  => 'notes.txt',
					'mime_type' => 'text/plain',
					'data'      => base64_encode( 'Example content' ),
					'bytes'     => strlen( 'Example content' ),
				),
			),
			'tools'       => array( $tool_definition ),
		);

		$response = $client->create_chat_completion( $messages, $options );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'output', $response );
		$this->assertNotEmpty( $captured_request );
		$this->assertArrayHasKey( 'args', $captured_request );

		$payload = json_decode( $captured_request['args']['body'], true );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'tools', $payload );
		$this->assertIsArray( $payload['tools'] );
		$this->assertArrayHasKey( 0, $payload['tools'] );
		$this->assertArrayHasKey( 'name', $payload['tools'][0] );
		$this->assertSame( 'fetch_latest_posts', $payload['tools'][0]['name'] );
		$this->assertArrayHasKey( 'function', $payload['tools'][0] );
		$this->assertSame( 'fetch_latest_posts', $payload['tools'][0]['function']['name'] );
	}

	/**
	 * Ensure attachments still route through the Responses API if detection is bypassed.
	 */
	public function test_attachments_force_responses_api_when_detection_is_bypassed() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Force_Chat_Client();
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
						'output' => array(),
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

		$this->assertArrayHasKey( 'headers', $captured_request['args'] );
		$this->assertArrayHasKey( 'OpenAI-Beta', $captured_request['args']['headers'] );
		$this->assertSame( 'responses=v1', $captured_request['args']['headers']['OpenAI-Beta'] );

		$payload = json_decode( $captured_request['args']['body'], true );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'input', $payload );
		$this->assertArrayNotHasKey( 'attachments', $payload );

		$this->assertArrayHasKey( 0, $payload['input'] );
		$content = $payload['input'][0]['content'];

		$this->assertIsArray( $content );
		$this->assertArrayHasKey( 0, $content );
		$file_segment = $content[0];

		$this->assertSame( 'input_file', $file_segment['type'] );
		$this->assertArrayHasKey( 'file_id', $file_segment );
		$this->assertSame( 'file-123', $file_segment['file_id'] );
		$this->assertArrayNotHasKey( 'file', $file_segment );
		$this->assertArrayNotHasKey( 'file_data', $file_segment );
		$this->assertArrayNotHasKey( 'filename', $file_segment );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'output', $response );
	}

	/**
	 * Ensure text segments are converted to input_text when using the Responses API.
	 */
	public function test_responses_payload_normalises_text_segments() {
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
						'output' => array(),
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
						'text' => 'Please review the attached notes.',
					),
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
					'file_id'   => 'file-123',
					'filename'  => 'notes.txt',
					'mime_type' => 'text/plain',
					'data'      => base64_encode( 'Notes content' ),
					'bytes'     => strlen( 'Notes content' ),
				),
			),
		);

		$client->create_chat_completion( $messages, $options );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotEmpty( $captured_request );
		$this->assertSame( WP_MCP_AI_OpenAI_Client::RESPONSES_ENDPOINT, $captured_request['url'] );

		$payload = json_decode( $captured_request['args']['body'], true );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'input', $payload );
		$this->assertArrayHasKey( 0, $payload['input'] );
		$this->assertArrayHasKey( 'content', $payload['input'][0] );
		$this->assertSame( 'input_text', $payload['input'][0]['content'][0]['type'] );
		$this->assertSame( 'Please review the attached notes.', $payload['input'][0]['content'][0]['text'] );

		$file_segment = $payload['input'][0]['content'][1];
		$this->assertSame( 'input_file', $file_segment['type'] );
		$this->assertArrayHasKey( 'file_id', $file_segment );
		$this->assertSame( 'file-123', $file_segment['file_id'] );
		$this->assertArrayNotHasKey( 'file', $file_segment );
		$this->assertArrayNotHasKey( 'file_data', $file_segment );
		$this->assertArrayNotHasKey( 'filename', $file_segment );
	}

	/**
	 * Ensure prior assistant messages use the correct mode when calling the Responses API.
	 */
	public function test_responses_payload_uses_output_text_for_assistant_segments() {
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
						'output' => array(),
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
				'role'    => 'assistant',
				'content' => 'Earlier summary.',
			),
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Please summarise the PDF.',
					),
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
					'filename'  => 'notes.pdf',
					'mime_type' => 'application/pdf',
					'data'      => base64_encode( 'PDF contents' ),
					'bytes'     => strlen( 'PDF contents' ),
				),
			),
		);

		$client->create_chat_completion( $messages, $options );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotEmpty( $captured_request );
		$this->assertSame( WP_MCP_AI_OpenAI_Client::RESPONSES_ENDPOINT, $captured_request['url'] );

		$payload = json_decode( $captured_request['args']['body'], true );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'input', $payload );
		$this->assertSame( 'output_text', $payload['input'][0]['content'][0]['type'] );
		$this->assertSame( 'Earlier summary.', $payload['input'][0]['content'][0]['text'] );
		$this->assertSame( 'input_text', $payload['input'][1]['content'][0]['type'] );
		$this->assertSame( 'Please summarise the PDF.', $payload['input'][1]['content'][0]['text'] );
	}

	/**
	 * Ensure tool role messages are emitted as output_text segments for Responses API requests.
	 */
	public function test_responses_payload_uses_output_text_for_tool_segments() {
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
						'output' => array(),
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
				'role'       => 'assistant',
				'content'    => 'Inspecting your PDF…',
				'tool_calls' => array(
					array(
						'id'       => 'call-123',
						'type'     => 'function',
						'function' => array(
							'name'      => 'process_document',
							'arguments' => '{"file":"document.pdf"}',
						),
					),
				),
			),
			array(
				'role'         => 'tool',
				'tool_call_id' => 'call-123',
				'content'      => 'Processed attachment contents.',
			),
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Summarise the findings.',
					),
					array(
						'type'    => 'input_file',
						'file_id' => 'file-789',
					),
				),
			),
		);

		$options = array(
			'attachments' => array(
				array(
					'id'        => 'file-789',
					'filename'  => 'document.pdf',
					'mime_type' => 'application/pdf',
					'data'      => base64_encode( 'PDF content' ),
					'bytes'     => strlen( 'PDF content' ),
				),
			),
		);

		$client->create_chat_completion( $messages, $options );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotEmpty( $captured_request );
		$this->assertSame( WP_MCP_AI_OpenAI_Client::RESPONSES_ENDPOINT, $captured_request['url'] );

		$payload = json_decode( $captured_request['args']['body'], true );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'input', $payload );

		$this->assertSame( 'output_text', $payload['input'][0]['content'][0]['type'] );
		$this->assertSame( 'Inspecting your PDF…', $payload['input'][0]['content'][0]['text'] );

		$this->assertSame( 'output_text', $payload['input'][1]['content'][0]['type'] );
		$this->assertSame( 'Processed attachment contents.', $payload['input'][1]['content'][0]['text'] );

		$this->assertSame( 'input_text', $payload['input'][2]['content'][0]['type'] );
		$this->assertSame( 'Summarise the findings.', $payload['input'][2]['content'][0]['text'] );
	}

	/**
	 * Ensure tool responses that no longer follow the originating tool call are omitted.
	 */
	public function test_chat_completion_skips_tool_messages_after_intervening_prompt() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Force_Chat_Client();
		$captured_request = null;

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

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
						'text' => 'generate_gemini_image create a red ball',
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
							'name'      => 'generate_gemini_image',
							'arguments' => '{"prompt":"create a red ball"}',
						),
					),
				),
			),
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Thanks!',
					),
				),
			),
			array(
				'role'         => 'tool',
				'tool_call_id' => 'call_123',
				'name'         => 'generate_gemini_image',
				'content'      => '{"result":"done"}',
			),
		);

		$response = $client->create_chat_completion( $messages, array() );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'choices', $response );
		$this->assertNotNull( $captured_request );

		$payload = json_decode( $captured_request['args']['body'], true );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'messages', $payload );
		$this->assertCount( 3, $payload['messages'] );
		$this->assertSame( 'user', $payload['messages'][0]['role'] );
		$this->assertSame( 'assistant', $payload['messages'][1]['role'] );
		$this->assertSame( 'user', $payload['messages'][2]['role'] );
	}

	/**
	 * Ensure tool responses immediately following the matching call are preserved.
	 */
	public function test_chat_completion_preserves_tool_messages_after_matching_call() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Force_Chat_Client();
		$captured_request = null;

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

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
						'text' => 'generate_gemini_image create a blue cube',
					),
				),
			),
			array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => array(
					array(
						'id'       => 'call_456',
						'type'     => 'function',
						'function' => array(
							'name'      => 'generate_gemini_image',
							'arguments' => '{"prompt":"create a blue cube"}',
						),
					),
				),
			),
			array(
				'role'         => 'tool',
				'tool_call_id' => 'call_456',
				'name'         => 'generate_gemini_image',
				'content'      => '{"result":"done"}',
			),
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'What next?',
					),
				),
			),
		);

		$response = $client->create_chat_completion( $messages, array() );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'choices', $response );
		$this->assertNotNull( $captured_request );

		$payload = json_decode( $captured_request['args']['body'], true );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'messages', $payload );
		$this->assertCount( 4, $payload['messages'] );
		$this->assertSame( 'tool', $payload['messages'][2]['role'] );
		$this->assertSame( 'call_456', $payload['messages'][2]['tool_call_id'] );
	}

	/**
	 * Ensure legacy mode flags are aligned with the expected segment type when using the Responses API.
	 */
	public function test_responses_payload_updates_legacy_segment_modes() {
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
						'output' => array(),
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
				'role'    => 'assistant',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Previous summary.',
					),
				),
			),
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Please summarise the document again.',
					),
					array(
						'type'    => 'input_file',
						'file_id' => 'file-321',
					),
				),
			),
		);

		$options = array(
			'attachments' => array(
				array(
					'id'        => 'file-321',
					'file_id'   => 'file-321',
					'filename'  => 'document.pdf',
					'mime_type' => 'application/pdf',
					'data'      => base64_encode( 'PDF content' ),
					'bytes'     => strlen( 'PDF content' ),
				),
			),
		);

		$client->create_chat_completion( $messages, $options );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotEmpty( $captured_request );
		$this->assertSame( WP_MCP_AI_OpenAI_Client::RESPONSES_ENDPOINT, $captured_request['url'] );

		$payload = json_decode( $captured_request['args']['body'], true );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'input', $payload );

		$assistant_segment = $payload['input'][0]['content'][0];
		$this->assertSame( 'output_text', $assistant_segment['type'] );
		$this->assertSame( 'Previous summary.', $assistant_segment['text'] );

		$user_text_segment = $payload['input'][1]['content'][0];
		$this->assertSame( 'input_text', $user_text_segment['type'] );
		$this->assertSame( 'Please summarise the document again.', $user_text_segment['text'] );

		$file_segment = $payload['input'][1]['content'][1];
		$this->assertSame( 'input_file', $file_segment['type'] );
		$this->assertArrayHasKey( 'file_id', $file_segment );
		$this->assertSame( 'file-321', $file_segment['file_id'] );
		$this->assertArrayNotHasKey( 'file', $file_segment );
		$this->assertArrayNotHasKey( 'filename', $file_segment );
		$this->assertArrayNotHasKey( 'mode', $assistant_segment );
		$this->assertArrayNotHasKey( 'mode', $user_text_segment );
		$this->assertArrayNotHasKey( 'mode', $file_segment );
		$this->assertArrayNotHasKey( 'file_data', $file_segment );
	}

	/**
	 * Ensure the image helper surfaces an actionable error when no API key is configured.
	 */
	public function test_generate_image_requires_api_key() {
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );

		$client   = new WP_MCP_AI_OpenAI_Client();
		$response = $client->generate_image( 'A scenic landscape', array() );

		$this->assertWPError( $response );
		$this->assertSame( 'wp_mcp_ai_missing_api_key', $response->get_error_code() );
	}

	/**
	 * Ensure generate_image issues the correct HTTP request payload.
	 */
	public function test_generate_image_sends_expected_payload() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key']  = 'sk-test';
		$settings['request_timeout'] = 42;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client           = new WP_MCP_AI_OpenAI_Client();
		$captured_request = null;
		$png_base64       = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=';
		$png_binary       = base64_decode( $png_base64 );

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request, $png_base64 ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			$payload = array(
				'created' => 123,
				'data'    => array(
					array(
						'b64_json'       => $png_base64,
						'revised_prompt' => 'A revised scenic landscape',
					),
				),
			);

			return array(
				'body'     => wp_json_encode( $payload ),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$response = $client->generate_image(
			'A scenic landscape at sunrise',
			array(
				'model'   => 'gpt-image-test',
				'size'    => '1024x1536',
				'quality' => 'hd',
			)
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'image', $response );
		$this->assertSame( $png_binary, $response['image'] );
		$this->assertSame( 'png', $response['format'] );
		$this->assertSame( 'image/png', $response['mime_type'] );
		$this->assertSame( 'gpt-image-test', $response['model'] );
		$this->assertSame( '1024x1536', $response['size'] );
		$this->assertSame( 'hd', $response['quality'] );
		$this->assertSame( 123, $response['created'] );
		$this->assertSame( 'A revised scenic landscape', $response['revised_prompt'] );

		$this->assertNotNull( $captured_request );
		$this->assertSame( WP_MCP_AI_OpenAI_Client::IMAGES_ENDPOINT, $captured_request['url'] );
		$this->assertSame( 42, $captured_request['args']['timeout'] );
		$this->assertArrayHasKey( 'headers', $captured_request['args'] );
		$this->assertSame( 'application/json', $captured_request['args']['headers']['Content-Type'] );

		$payload = json_decode( $captured_request['args']['body'], true );
		$this->assertIsArray( $payload );
		$this->assertSame( 'gpt-image-test', $payload['model'] );
		$this->assertSame( 'A scenic landscape at sunrise', $payload['prompt'] );
		$this->assertSame( '1024x1536', $payload['size'] );
		$this->assertSame( 'hd', $payload['quality'] );
		$this->assertArrayNotHasKey( 'format', $payload );
		$this->assertArrayHasKey( 'response_format', $payload );
		$this->assertSame( 'b64_json', $payload['response_format'] );
		$this->assertSame( 1, $payload['n'] );
	}

	/**
	 * Ensure generate_image includes the response_format when explicitly supplied.
	 */
	public function test_generate_image_honors_response_format_option() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key']  = 'sk-test';
		$settings['request_timeout'] = 20;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client           = new WP_MCP_AI_OpenAI_Client();
		$captured_request = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			$payload = array(
				'created' => 123,
				'data'    => array(
					array(
						'b64_json' => base64_encode( 'stub-image' ),
					),
				),
			);

			return array(
				'body'     => wp_json_encode( $payload ),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$client->generate_image(
			'Prompt with explicit response format',
			array(
				'model'           => 'gpt-image-test',
				'response_format' => 'url',
			)
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request );
		$payload = json_decode( $captured_request['args']['body'], true );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'response_format', $payload );
		$this->assertSame( 'url', $payload['response_format'] );
	}

	/**
	 * Ensure generate_image omits response_format for models that do not support it.
	 */
	public function test_generate_image_omits_response_format_when_model_lacks_support() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key']  = 'sk-test';
		$settings['request_timeout'] = 20;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client           = new WP_MCP_AI_OpenAI_Client();
		$captured_request = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			$payload = array(
				'created' => 123,
				'data'    => array(
					array(
						'b64_json' => base64_encode( 'stub-image' ),
					),
				),
			);

			return array(
				'body'     => wp_json_encode( $payload ),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$override = function ( $supported, $model ) {
			if ( 'legacy-model' === $model ) {
				return false;
			}

			return $supported;
		};

		add_filter( 'wp_mcp_ai_image_model_supports_response_format', $override, 10, 2 );

		$client->generate_image(
			'Prompt with unsupported response format',
			array(
				'model'           => 'legacy-model',
				'response_format' => 'url',
			)
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );
		remove_filter( 'wp_mcp_ai_image_model_supports_response_format', $override, 10 );

		$this->assertNotNull( $captured_request );
		$payload = json_decode( $captured_request['args']['body'], true );

		$this->assertIsArray( $payload );
		$this->assertArrayNotHasKey( 'response_format', $payload );
	}

	/**
	 * Ensure generate_image downloads images when a URL is returned.
	 */
	public function test_generate_image_downloads_image_url_payload() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key']  = 'sk-test';
		$settings['request_timeout'] = 25;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client            = new WP_MCP_AI_OpenAI_Client();
		$captured_requests = array();
		$png_base64        = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=';
		$png_binary        = base64_decode( $png_base64 );
		$image_url         = 'https://example.com/generated-image.png';

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_requests, $png_binary, $image_url ) {
			if ( WP_MCP_AI_OpenAI_Client::IMAGES_ENDPOINT === $url ) {
				$captured_requests[] = array(
					'type' => 'generation',
					'args' => $args,
					'url'  => $url,
				);

				$payload = array(
					'created' => 456,
					'data'    => array(
						array(
							'url'            => $image_url,
							'revised_prompt' => 'Reimagined prompt',
						),
					),
				);

				return array(
					'body'     => wp_json_encode( $payload ),
					'response' => array( 'code' => 200 ),
					'headers'  => array( 'content-type' => 'application/json' ),
				);
			}

			if ( $image_url === $url ) {
				$captured_requests[] = array(
					'type' => 'download',
					'args' => $args,
					'url'  => $url,
				);

				return array(
					'body'     => $png_binary,
					'response' => array( 'code' => 200 ),
					'headers'  => array( 'content-type' => 'image/png' ),
				);
			}

			return $preempt;
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$response = $client->generate_image( 'Prompt returning URL', array() );

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertIsArray( $response );
		$this->assertSame( $png_binary, $response['image'] );
		$this->assertSame( 'png', $response['format'] );
		$this->assertSame( 'image/png', $response['mime_type'] );
		$this->assertSame( 456, $response['created'] );
		$this->assertSame( 'Reimagined prompt', $response['revised_prompt'] );
		$this->assertCount( 2, $captured_requests );
		$this->assertSame( 'generation', $captured_requests[0]['type'] );
		$this->assertSame( 'download', $captured_requests[1]['type'] );
		$this->assertSame( $image_url, $captured_requests[1]['url'] );
	}

	/**
	 * Ensure generate_image can process binary image responses.
	 */
	public function test_generate_image_accepts_binary_responses() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key']  = 'sk-test';
		$settings['request_timeout'] = 30;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client     = new WP_MCP_AI_OpenAI_Client();
		$png_binary = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=' );

		$http_stub = function () use ( $png_binary ) {
			return array(
				'body'     => $png_binary,
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'image/png' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub );

		$response = $client->generate_image( 'Binary payload', array( 'format' => 'png' ) );

		remove_filter( 'pre_http_request', $http_stub );

		$this->assertIsArray( $response );
		$this->assertSame( $png_binary, $response['image'] );
		$this->assertSame( 'png', $response['format'] );
		$this->assertSame( 'image/png', $response['mime_type'] );
		$this->assertSame( 'gpt-image-1', $response['model'] );
		$this->assertSame( 'Binary payload', $response['prompt'] );
		$this->assertSame( 0, $response['created'] );
		$this->assertSame( '', $response['revised_prompt'] );
	}

	/**
	 * Ensure generate_image surfaces useful errors when the response is not JSON.
	 */
	public function test_generate_image_handles_non_json_errors() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key']  = 'sk-test';
		$settings['request_timeout'] = 15;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client = new WP_MCP_AI_OpenAI_Client();

		$http_stub = function () {
			return array(
				'body'     => '<html>Internal Error</html>',
				'response' => array( 'code' => 500 ),
				'headers'  => array( 'content-type' => 'text/html' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub );

		$response = $client->generate_image( 'Server error scenario', array() );

		remove_filter( 'pre_http_request', $http_stub );

		$this->assertWPError( $response );
		$this->assertSame( 'wp_mcp_ai_image_error', $response->get_error_code() );
		$this->assertStringContainsString( 'status 500', $response->get_error_message() );
	}

	/**
	 * Ensure the speech helper surfaces an actionable error when no API key is configured.
	 */
	public function test_generate_speech_requires_api_key() {
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );

		$client   = new WP_MCP_AI_OpenAI_Client();
		$response = $client->generate_speech( 'Hello world', array() );

		$this->assertWPError( $response );
		$this->assertSame( 'wp_mcp_ai_missing_api_key', $response->get_error_code() );
	}

	/**
	 * Ensure generate_speech issues the correct HTTP request payload.
	 */
	public function test_generate_speech_sends_expected_payload() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key']  = 'sk-test';
		$settings['request_timeout'] = 42;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client           = new WP_MCP_AI_OpenAI_Client();
		$captured_request = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			return array(
				'body'     => 'FAKEAUDIO',
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'audio/mpeg' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$response = $client->generate_speech(
			'Read me a story',
			array(
				'model'  => 'gpt-test-tts',
				'voice'  => 'verse',
				'format' => 'wav',
				'speed'  => 1.5,
			)
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'audio', $response );
		$this->assertSame( 'FAKEAUDIO', $response['audio'] );
		$this->assertSame( 'wav', $response['format'] );
		$this->assertSame( 'verse', $response['voice'] );
		$this->assertSame( 'gpt-test-tts', $response['model'] );
		$this->assertSame( 1.5, $response['speed'] );

		$this->assertNotNull( $captured_request );
		$this->assertSame( WP_MCP_AI_OpenAI_Client::AUDIO_SPEECH_ENDPOINT, $captured_request['url'] );
		$this->assertSame( 42, $captured_request['args']['timeout'] );
		$this->assertArrayHasKey( 'headers', $captured_request['args'] );
		$this->assertSame( 'application/json', $captured_request['args']['headers']['Content-Type'] );

		$payload = json_decode( $captured_request['args']['body'], true );
		$this->assertIsArray( $payload );
		$this->assertSame( 'Read me a story', $payload['input'] );
		$this->assertSame( 'gpt-test-tts', $payload['model'] );
		$this->assertSame( 'verse', $payload['voice'] );
		$this->assertSame( 'wav', $payload['format'] );
		$this->assertSame( 1.5, $payload['speed'] );
	}

	/**
	 * Ensure the audio transcription helper requires an API key.
	 */
	public function test_transcribe_audio_requires_api_key() {
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );

		$client   = new WP_MCP_AI_OpenAI_Client();
		$response = $client->transcribe_audio( '/tmp/non-existent-file.wav', array() );

		$this->assertWPError( $response );
		$this->assertSame( 'wp_mcp_ai_missing_api_key', $response->get_error_code() );
	}

	/**
	 * Ensure the audio transcription helper validates the file path when an API key exists.
	 */
	public function test_transcribe_audio_requires_existing_file() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key']  = 'sk-test';
		$settings['request_timeout'] = 15;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client   = new WP_MCP_AI_OpenAI_Client();
		$response = $client->transcribe_audio( '/tmp/non-existent-file.wav', array() );

		$this->assertWPError( $response );
		$this->assertSame( 'wp_mcp_ai_transcription_missing_file', $response->get_error_code() );
	}

	/**
	 * Ensure audio transcription requests are issued to the correct endpoint with the expected payload.
	 */
	public function test_transcribe_audio_sends_expected_payload() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key']  = 'sk-test';
		$settings['request_timeout'] = 99;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client           = new WP_MCP_AI_OpenAI_Client();
		$captured_request = null;

		$tmp_file = wp_tempnam( 'transcription-test.mp3' );
		file_put_contents( $tmp_file, 'FAKEAUDIO' );

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			$payload = array(
				'text'     => 'Hello translated world',
				'language' => 'en',
				'duration' => 2.5,
			);

			return array(
				'body'     => wp_json_encode( $payload ),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$response = $client->transcribe_audio(
			$tmp_file,
			array(
				'translate'       => true,
				'model'           => 'gpt-test-transcribe',
				'prompt'          => 'Helpful hint',
				'temperature'     => 0.4,
				'response_format' => 'json',
				'timeout'         => 123,
			)
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		unlink( $tmp_file );

		$this->assertNotNull( $captured_request );
		$this->assertSame( WP_MCP_AI_OpenAI_Client::AUDIO_TRANSLATIONS_ENDPOINT, $captured_request['url'] );
		$this->assertArrayHasKey( 'headers', $captured_request['args'] );
		$this->assertArrayHasKey( 'body', $captured_request['args'] );
		$this->assertSame( 123, $captured_request['args']['timeout'] );

		$content_type = $captured_request['args']['headers']['Content-Type'];
		$this->assertStringContainsString( 'multipart/form-data', $content_type );
		$this->assertStringContainsString( 'boundary=', $content_type );

		$body = $captured_request['args']['body'];
		$this->assertStringContainsString( 'name="model"', $body );
		$this->assertStringContainsString( 'gpt-test-transcribe', $body );
		$this->assertStringContainsString( 'name="prompt"', $body );
		$this->assertStringContainsString( 'Helpful hint', $body );
		$this->assertStringContainsString( 'name="temperature"', $body );
		$this->assertStringContainsString( '0.4', $body );
		$this->assertStringContainsString( 'name="response_format"', $body );
		$this->assertStringContainsString( 'json', $body );

		$this->assertIsArray( $response );
		$this->assertSame( 'Hello translated world', $response['text'] );
		$this->assertSame( 'gpt-test-transcribe', $response['model'] );
		$this->assertTrue( $response['translated'] );
		$this->assertSame( 'json', $response['format'] );
		$this->assertSame( 'en', $response['language'] );
		$this->assertSame( 2.5, $response['duration'] );
	}

	/**
	 * Ensure transcription requests that are not translations use the transcription endpoint.
	 */
	public function test_transcribe_audio_transcription_endpoint_when_not_translating() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key']  = 'sk-test';
		$settings['request_timeout'] = 15;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client           = new WP_MCP_AI_OpenAI_Client();
		$captured_request = null;

		$tmp_file = wp_tempnam( 'transcription-test.wav' );
		file_put_contents( $tmp_file, 'FAKEAUDIO' );

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			return array(
				'body'     => wp_json_encode(
					array(
						'text'     => 'Hello world',
						'language' => 'en',
					)
				),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$response = $client->transcribe_audio(
			$tmp_file,
			array(
				'translate'       => false,
				'model'           => 'gpt-test-transcribe',
				'response_format' => 'verbose_json',
			)
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );
		unlink( $tmp_file );

		$this->assertNotNull( $captured_request );
		$this->assertSame( WP_MCP_AI_OpenAI_Client::AUDIO_TRANSCRIPTIONS_ENDPOINT, $captured_request['url'] );

		$this->assertIsArray( $response );
		$this->assertFalse( $response['translated'] );
		$this->assertSame( 'verbose_json', $response['format'] );
	}

	/**
	 * Verify that tool_calls and tool_call_id are stripped from Responses API input.
	 *
	 * The Responses API doesn't support the tool_calls/tool_call_id mechanism used by
	 * Chat Completions. This test ensures that if messages somehow contain these fields,
	 * they are removed before being sent to the Responses API.
	 */
	public function test_responses_api_strips_tool_calls_from_input() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client = new WP_MCP_AI_Force_Responses_Client();

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
				'content'    => array(
					array(
						'type' => 'text',
						'text' => 'I will call a tool.',
					),
				),
				'tool_calls' => array(
					array(
						'id'       => 'call_123',
						'type'     => 'function',
						'function' => array(
							'name'      => 'test_tool',
							'arguments' => '{}',
						),
					),
				),
			),
			array(
				'role'         => 'tool',
				'tool_call_id' => 'call_123',
				'name'         => 'test_tool',
				'content'      => 'Tool result',
			),
		);

		// Prepare the input using the public wrapper method.
		$prepared_input = $client->public_prepare_responses_input( $messages, $messages, array() );

		// Verify the input was prepared.
		$this->assertIsArray( $prepared_input );
		$this->assertCount( 3, $prepared_input );

		// Verify tool_calls is not present in the assistant message.
		$this->assertArrayNotHasKey( 'tool_calls', $prepared_input[1], 'tool_calls should be stripped from assistant messages in Responses API input' );

		// Verify tool_call_id is not present in the tool message.
		$this->assertArrayNotHasKey( 'tool_call_id', $prepared_input[2], 'tool_call_id should be stripped from tool messages in Responses API input' );

		// Verify content is still present.
		$this->assertArrayHasKey( 'content', $prepared_input[0] );
		$this->assertArrayHasKey( 'content', $prepared_input[1] );
		$this->assertArrayHasKey( 'content', $prepared_input[2] );

		// Verify roles are still present.
		$this->assertSame( 'user', $prepared_input[0]['role'] );
		$this->assertSame( 'assistant', $prepared_input[1]['role'] );
		$this->assertSame( 'tool', $prepared_input[2]['role'] );
	}
}
