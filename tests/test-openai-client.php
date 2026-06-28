<?php
/**
 * Helper client that forces the Chat Completions endpoint during detection.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
	 * All image models should return false for response_format support by default
	 * since the OpenAI API currently rejects the response_format parameter.
	 */
	public function test_image_model_supports_response_format_for_gpt_image() {
		$this->assertFalse( WP_MCP_AI_OpenAI_Client::image_model_supports_response_format( 'gpt-image-2' ) );
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
		$this->assertSame( 'image_url', $payload['messages'][0]['content'][1]['type'] );
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
	 * Ensure non-image file attachments with tool calls in conversation use Chat Completions
	 * and convert input_file segments to the 'file' content type (GPT-4.1+).
	 *
	 * When a conversation history contains tool_calls/tool messages, the Responses API
	 * cannot be used (it does not support the tool_calls/tool_call_id mechanism).
	 * The client must fall back to Chat Completions and translate 'input_file' →
	 * {"type":"file","file":{"file_id":"file-xxx"}}.
	 */
	public function test_chat_completions_converts_input_file_to_file_type_when_tool_calls_present() {
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
						'id'      => 'chatcmpl-test',
						'choices' => array(
							array(
								'index'         => 0,
								'message'       => array(
									'role'    => 'assistant',
									'content' => 'Summary.',
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

		// With tool calls in conversation history, Chat Completions API must be used.
		$this->assertSame( WP_MCP_AI_OpenAI_Client::CHAT_COMPLETIONS_ENDPOINT, $captured_request['url'] );

		$payload = json_decode( $captured_request['args']['body'], true );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'messages', $payload );

		// Find the user message (index 2 after tool messages are preserved).
		$user_message = null;
		foreach ( $payload['messages'] as $msg ) {
			if ( isset( $msg['role'] ) && 'user' === $msg['role'] ) {
				$user_message = $msg;
				break;
			}
		}

		$this->assertNotNull( $user_message, 'User message must be present in Chat Completions payload' );
		$this->assertIsArray( $user_message['content'] );

		// The text segment should be preserved.
		$this->assertSame( 'text', $user_message['content'][0]['type'] );
		$this->assertSame( 'Summarise the findings.', $user_message['content'][0]['text'] );

		// The input_file segment must be converted to the Chat Completions 'file' content type.
		$this->assertSame( 'file', $user_message['content'][1]['type'], 'input_file must be converted to file type for Chat Completions' );
		$this->assertArrayHasKey( 'file', $user_message['content'][1] );
		$this->assertSame( 'file-789', $user_message['content'][1]['file']['file_id'] );
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
		$this->assertSame( 'gpt-image-1.5', $response['model'] );
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

	/**
	 * Test that agentic workflow metadata fields are removed from input_image segments for Responses API.
	 */
	public function test_responses_api_removes_agentic_workflow_metadata_from_image_segments() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client = new WP_MCP_AI_Force_Responses_Client();

		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type'          => 'input_image',
						'file_id'       => 'file-img-123',
						'url'           => 'https://example.com/image.jpg',
						'attachment_id' => 456,
						'file_name'     => 'test-image.jpg',
						'mime_type'     => 'image/jpeg',
						'bytes'         => 12345,
						'detail'        => 'high',
					),
				),
			),
		);

		$attachments = array(
			array(
				'id'      => 'file-img-123',
				'file_id' => 'file-img-123',
			),
		);

		// Prepare the input using the public wrapper method.
		$prepared_input = $client->public_prepare_responses_input( $messages, $messages, $attachments );

		// Verify the input was prepared.
		$this->assertIsArray( $prepared_input );
		$this->assertCount( 1, $prepared_input );

		// Get the content segment.
		$content = $prepared_input[0]['content'];
		$this->assertIsArray( $content );
		$this->assertCount( 1, $content );

		$segment = $content[0];

		// Verify type is preserved.
		$this->assertSame( 'input_image', $segment['type'] );

		// Verify file_id is preserved.
		$this->assertSame( 'file-img-123', $segment['file_id'] );

		// Verify detail is preserved.
		$this->assertSame( 'high', $segment['detail'] );

		// Verify agentic workflow metadata fields are removed.
		$this->assertArrayNotHasKey( 'url', $segment, 'url should be removed from Responses API payload' );
		$this->assertArrayNotHasKey( 'attachment_id', $segment, 'attachment_id should be removed from Responses API payload' );
		$this->assertArrayNotHasKey( 'file_name', $segment, 'file_name should be removed from Responses API payload' );
		$this->assertArrayNotHasKey( 'mime_type', $segment, 'mime_type should be removed from Responses API payload' );
		$this->assertArrayNotHasKey( 'bytes', $segment, 'bytes should be removed from Responses API payload' );
	}

	/**
	 * Test that agentic workflow metadata fields are removed from input_file segments for Responses API.
	 */
	public function test_responses_api_removes_agentic_workflow_metadata_from_file_segments() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client = new WP_MCP_AI_Force_Responses_Client();

		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type'          => 'input_file',
						'file_id'       => 'file-doc-789',
						'url'           => 'https://example.com/document.pdf',
						'attachment_id' => 789,
						'name'          => 'test-document.pdf',
						'mime_type'     => 'application/pdf',
						'bytes'         => 54321,
						'display_name'  => 'Test Document',
					),
				),
			),
		);

		$attachments = array(
			array(
				'id'      => 'file-doc-789',
				'file_id' => 'file-doc-789',
			),
		);

		// Prepare the input using the public wrapper method.
		$prepared_input = $client->public_prepare_responses_input( $messages, $messages, $attachments );

		// Verify the input was prepared.
		$this->assertIsArray( $prepared_input );
		$this->assertCount( 1, $prepared_input );

		// Get the content segment.
		$content = $prepared_input[0]['content'];
		$this->assertIsArray( $content );
		$this->assertCount( 1, $content );

		$segment = $content[0];

		// Verify type is preserved.
		$this->assertSame( 'input_file', $segment['type'] );

		// Verify file_id is preserved.
		$this->assertSame( 'file-doc-789', $segment['file_id'] );

		// Verify agentic workflow metadata fields are removed.
		$this->assertArrayNotHasKey( 'url', $segment, 'url should be removed from Responses API payload' );
		$this->assertArrayNotHasKey( 'attachment_id', $segment, 'attachment_id should be removed from Responses API payload' );
		$this->assertArrayNotHasKey( 'name', $segment, 'name should be removed from Responses API payload' );
		$this->assertArrayNotHasKey( 'mime_type', $segment, 'mime_type should be removed from Responses API payload' );
		$this->assertArrayNotHasKey( 'bytes', $segment, 'bytes should be removed from Responses API payload' );
		$this->assertArrayNotHasKey( 'display_name', $segment, 'display_name should be removed from Responses API payload' );
	}

	/**
	 * Test is_codex_model correctly identifies Codex models.
	 */
	public function test_is_codex_model_returns_true_for_codex_models() {
		$this->assertTrue( WP_MCP_AI_OpenAI_Client::is_codex_model( 'gpt-5.3-codex' ) );
		$this->assertTrue( WP_MCP_AI_OpenAI_Client::is_codex_model( 'gpt-5.3-codex-spark' ) );
		$this->assertTrue( WP_MCP_AI_OpenAI_Client::is_codex_model( 'gpt-5.2-codex' ) );
		$this->assertTrue( WP_MCP_AI_OpenAI_Client::is_codex_model( 'gpt-5.1-codex' ) );
		$this->assertTrue( WP_MCP_AI_OpenAI_Client::is_codex_model( 'gpt-5.1-codex-max' ) );
		$this->assertTrue( WP_MCP_AI_OpenAI_Client::is_codex_model( 'gpt-5.1-codex-mini' ) );
		$this->assertTrue( WP_MCP_AI_OpenAI_Client::is_codex_model( 'gpt-5-codex' ) );
		$this->assertTrue( WP_MCP_AI_OpenAI_Client::is_codex_model( 'gpt-5-codex-mini' ) );
	}

	/**
	 * Test is_codex_model returns false for non-Codex models.
	 */
	public function test_is_codex_model_returns_false_for_non_codex_models() {
		$this->assertFalse( WP_MCP_AI_OpenAI_Client::is_codex_model( 'gpt-5.2' ) );
		$this->assertFalse( WP_MCP_AI_OpenAI_Client::is_codex_model( 'gpt-5.1' ) );
		$this->assertFalse( WP_MCP_AI_OpenAI_Client::is_codex_model( 'gpt-4o' ) );
		$this->assertFalse( WP_MCP_AI_OpenAI_Client::is_codex_model( 'gpt-4o-mini' ) );
		$this->assertFalse( WP_MCP_AI_OpenAI_Client::is_codex_model( 'o3' ) );
		$this->assertFalse( WP_MCP_AI_OpenAI_Client::is_codex_model( 'claude-sonnet-4-6' ) );
		$this->assertFalse( WP_MCP_AI_OpenAI_Client::is_codex_model( 'gemini-3-pro-preview' ) );
	}
}

/**
 * Exposes the protected filter_tool_messages_for_payload() method for unit testing.
 */
class WP_MCP_AI_Testable_OpenAI_Client extends WP_MCP_AI_OpenAI_Client {

	/**
	 * Proxy that makes filter_tool_messages_for_payload() publicly callable.
	 *
	 * @param array $messages Input messages.
	 * @return array Filtered messages.
	 */
	public function public_filter_tool_messages( array $messages ) {
		return $this->filter_tool_messages_for_payload( $messages );
	}
}

/**
 * Tests for WP_MCP_AI_OpenAI_Client::filter_tool_messages_for_payload().
 *
 * These tests cover the bug where an assistant message with tool_calls that is
 * never followed by the corresponding tool-response messages (because the agentic
 * loop hit max_iterations before executing them) was included in filtered output.
 * When that orphaned assistant message was later sent back to OpenAI in the next
 * turn's conversation history, OpenAI returned:
 *   "An assistant message with 'tool_calls' must be followed by tool messages
 *    responding to each 'tool_call_id'."
 */
class WP_MCP_AI_Filter_Tool_Messages_Test extends WP_UnitTestCase {

	/** @var WP_MCP_AI_Testable_OpenAI_Client */
	private $client;

	public function setUp(): void {
		parent::setUp();
		$this->client = new WP_MCP_AI_Testable_OpenAI_Client();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function user_msg( $text = 'Hello' ) {
		return array(
			'role'    => 'user',
			'content' => $text,
		);
	}

	private function system_msg( $text = 'You are an assistant.' ) {
		return array(
			'role'    => 'system',
			'content' => $text,
		);
	}

	private function assistant_msg( $text = 'Sure.' ) {
		return array(
			'role'    => 'assistant',
			'content' => $text,
		);
	}

	private function assistant_with_tool_calls( array $tool_ids, $text = null ) {
		$tool_calls = array();
		foreach ( $tool_ids as $id => $name ) {
			$tool_calls[] = array(
				'id'       => $id,
				'type'     => 'function',
				'function' => array(
					'name'      => $name,
					'arguments' => '{}',
				),
			);
		}
		$msg = array(
			'role'       => 'assistant',
			'tool_calls' => $tool_calls,
		);
		if ( null !== $text ) {
			$msg['content'] = $text;
		}
		return $msg;
	}

	private function tool_msg( $call_id, $name = 'some_tool', $content = '{"result":"ok"}' ) {
		return array(
			'role'         => 'tool',
			'tool_call_id' => $call_id,
			'name'         => $name,
			'content'      => $content,
		);
	}

	// -------------------------------------------------------------------------
	// Tests – valid sequences that should be preserved
	// -------------------------------------------------------------------------

	/**
	 * A fully-resolved single tool call group is kept unchanged.
	 */
	public function test_complete_single_tool_call_group_is_kept() {
		$messages = array(
			$this->user_msg( 'Analyse this image.' ),
			$this->assistant_with_tool_calls( array( 'call_AAA' => 'vision_object_localization' ) ),
			$this->tool_msg( 'call_AAA', 'vision_object_localization' ),
			$this->assistant_msg( 'The image contains a cat.' ),
		);

		$filtered = $this->client->public_filter_tool_messages( $messages );

		$this->assertCount( 4, $filtered );
		$this->assertSame( 'user', $filtered[0]['role'] );
		$this->assertSame( 'assistant', $filtered[1]['role'] );
		$this->assertArrayHasKey( 'tool_calls', $filtered[1] );
		$this->assertSame( 'tool', $filtered[2]['role'] );
		$this->assertSame( 'assistant', $filtered[3]['role'] );
	}

	/**
	 * Multiple consecutive fully-resolved groups across turns are kept.
	 */
	public function test_multiple_complete_groups_across_turns_are_kept() {
		$messages = array(
			$this->user_msg( 'Turn 1' ),
			$this->assistant_with_tool_calls( array( 'call_T1' => 'get_time' ) ),
			$this->tool_msg( 'call_T1', 'get_time' ),
			$this->assistant_msg( 'It is noon.' ),
			$this->user_msg( 'Turn 2' ),
			$this->assistant_with_tool_calls( array( 'call_T2' => 'get_weather' ) ),
			$this->tool_msg( 'call_T2', 'get_weather' ),
			$this->assistant_msg( 'It is sunny.' ),
			$this->user_msg( 'Turn 3' ),
		);

		$filtered = $this->client->public_filter_tool_messages( $messages );

		$this->assertCount( 9, $filtered );
	}

	/**
	 * A group with parallel tool calls (all answered) is kept.
	 */
	public function test_parallel_tool_calls_all_answered_is_kept() {
		$messages = array(
			$this->user_msg(),
			$this->assistant_with_tool_calls(
				array(
					'call_P1' => 'tool_alpha',
					'call_P2' => 'tool_beta',
				)
			),
			$this->tool_msg( 'call_P1', 'tool_alpha' ),
			$this->tool_msg( 'call_P2', 'tool_beta' ),
			$this->assistant_msg( 'Done.' ),
		);

		$filtered = $this->client->public_filter_tool_messages( $messages );

		$this->assertCount( 5, $filtered );
	}

	// -------------------------------------------------------------------------
	// Tests – the fix: orphaned tool_calls groups must be dropped
	// -------------------------------------------------------------------------

	/**
	 * An assistant message whose tool_calls were never answered (loop hit
	 * max_iterations) is removed when the next user message arrives.
	 *
	 * This is the root scenario reported in the vision_object_localization bug:
	 *   1. Turn 1: agentic loop executed the tool, but the *final* LLM response
	 *      still had tool_calls (max_iterations reached without the model stopping).
	 *   2. The JS client stored that orphaned assistant-with-tool_calls message.
	 *   3. Turn 2: the conversation including the orphaned message is sent back
	 *      to OpenAI → "tool_call_ids did not have response messages" error.
	 */
	public function test_orphaned_tool_calls_before_user_message_are_dropped() {
		$messages = array(
			$this->user_msg( 'Previous turn user message.' ),
			// Properly answered pair from the agentic loop iteration.
			$this->assistant_with_tool_calls( array( 'call_LOOP' => 'vision_object_localization' ) ),
			$this->tool_msg( 'call_LOOP', 'vision_object_localization', '{"objects":[]}' ),
			// Orphaned final assistant message: the last LLM response still wanted
			// to call a tool but max_iterations was reached without executing it.
			$this->assistant_with_tool_calls( array( 'call_ORPHAN' => 'vision_object_localization' ) ),
			// Next turn.
			$this->user_msg( 'New user message.' ),
		);

		$filtered = $this->client->public_filter_tool_messages( $messages );

		// The orphaned assistant message must be absent.
		$roles = array_column( $filtered, 'role' );
		$this->assertNotContains(
			'call_ORPHAN',
			array_map(
				function ( $m ) {
					if ( isset( $m['tool_calls'][0]['id'] ) ) {
						return $m['tool_calls'][0]['id'];
					}
					return '';
				},
				$filtered
			),
			'Orphaned tool_call_id call_ORPHAN must not be present in filtered output.'
		);

		// The valid pair (call_LOOP) and surrounding messages must be kept.
		$this->assertSame( 'user', $filtered[0]['role'], 'Previous turn user message preserved.' );
		$this->assertSame( 'assistant', $filtered[1]['role'], 'Answered assistant message preserved.' );
		$this->assertSame( 'tool', $filtered[2]['role'], 'Tool result for call_LOOP preserved.' );
		$this->assertSame( 'user', $filtered[3]['role'], 'New user message preserved.' );
		$this->assertCount( 4, $filtered );
	}

	/**
	 * When two consecutive assistant-with-tool_calls messages both lack tool
	 * responses, both are dropped before the eventual user message.
	 */
	public function test_two_consecutive_orphaned_assistant_messages_are_both_dropped() {
		$messages = array(
			$this->user_msg( 'First user message.' ),
			$this->assistant_with_tool_calls( array( 'call_A' => 'tool_a' ) ),
			// call_A never answered → second assistant comes along.
			$this->assistant_with_tool_calls( array( 'call_B' => 'tool_b' ) ),
			// call_B never answered either.
			$this->user_msg( 'Second user message.' ),
		);

		$filtered = $this->client->public_filter_tool_messages( $messages );

		// Neither orphaned assistant message should appear.
		foreach ( $filtered as $msg ) {
			$this->assertArrayNotHasKey( 'tool_calls', $msg, 'No orphaned tool_calls should remain.' );
		}
		// Both user messages should survive.
		$roles = array_column( $filtered, 'role' );
		$this->assertSame( array( 'user', 'user' ), $roles );
	}

	/**
	 * Valid conversation history before an orphaned group is preserved while
	 * only the orphaned group is removed.
	 */
	public function test_valid_history_before_orphaned_group_is_preserved() {
		$messages = array(
			$this->system_msg(),
			$this->user_msg( 'Turn 1' ),
			$this->assistant_with_tool_calls( array( 'call_OK' => 'some_tool' ) ),
			$this->tool_msg( 'call_OK', 'some_tool' ),
			$this->assistant_msg( 'Done with turn 1.' ),
			$this->user_msg( 'Turn 2' ),
			// Orphaned group: loop hit max_iterations, tool never executed.
			$this->assistant_with_tool_calls( array( 'call_ORPHAN2' => 'vision_object_localization' ) ),
			$this->user_msg( 'Turn 3 (new message).' ),
		);

		$filtered = $this->client->public_filter_tool_messages( $messages );

		// The orphaned assistant message must be gone.
		foreach ( $filtered as $msg ) {
			if ( isset( $msg['tool_calls'] ) ) {
				$ids = array_column( $msg['tool_calls'], 'id' );
				$this->assertNotContains( 'call_ORPHAN2', $ids, 'Orphaned tool_call must be dropped.' );
			}
		}

		// Turn 1 complete pair plus system and all user messages survive.
		$this->assertSame( 'system', $filtered[0]['role'] );
		$this->assertSame( 'user', $filtered[1]['role'] );   // Turn 1 user.
		$this->assertSame( 'assistant', $filtered[2]['role'] ); // Answered assistant.
		$this->assertSame( 'tool', $filtered[3]['role'] );   // Tool result.
		$this->assertSame( 'assistant', $filtered[4]['role'] ); // Final assistant turn 1.
		$this->assertSame( 'user', $filtered[5]['role'] );   // Turn 2 user.
		$this->assertSame( 'user', $filtered[6]['role'] );   // Turn 3 user.
		$this->assertCount( 7, $filtered );
	}

	/**
	 * Orphaned tool messages (no matching pending assistant tool_call) are
	 * still dropped, which is the existing behaviour that must not regress.
	 */
	public function test_orphaned_standalone_tool_message_is_dropped() {
		$messages = array(
			$this->user_msg(),
			$this->assistant_msg( 'Hi.' ),
			// A stray tool message with no preceding assistant tool_calls.
			$this->tool_msg( 'call_STRAY', 'orphan_tool' ),
			$this->user_msg( 'Follow up.' ),
		);

		$filtered = $this->client->public_filter_tool_messages( $messages );

		$roles = array_column( $filtered, 'role' );
		$this->assertNotContains( 'tool', $roles, 'Orphaned tool message must be dropped.' );
		$this->assertCount( 3, $filtered );
	}

	/**
	 * A partially-answered group (some tool_call_ids responded to, some not)
	 * must be dropped entirely to avoid sending an invalid sequence to OpenAI.
	 */
	public function test_partially_answered_group_is_dropped_entirely() {
		$messages = array(
			$this->user_msg(),
			// Parallel calls: A and B.
			$this->assistant_with_tool_calls(
				array(
					'call_PA' => 'tool_alpha',
					'call_PB' => 'tool_beta',
				)
			),
			// Only A answered; B never was.
			$this->tool_msg( 'call_PA', 'tool_alpha' ),
			$this->user_msg( 'Next turn.' ),
		);

		$filtered = $this->client->public_filter_tool_messages( $messages );

		// The assistant message AND the partial tool response must be gone.
		foreach ( $filtered as $msg ) {
			$this->assertNotSame( 'tool', $msg['role'], 'Partial tool response must be dropped.' );
			$this->assertArrayNotHasKey( 'tool_calls', $msg, 'Partially-answered assistant must be dropped.' );
		}
	}

	/**
	 * Inside the agentic loop (no user message at the end) a complete group is
	 * kept so the second LLM call receives valid messages.
	 */
	public function test_complete_group_at_end_of_array_is_kept_for_agentic_loop() {
		// This mirrors what filter_tool_messages_for_payload sees when called
		// for the second OpenAI call inside the agentic loop.
		$messages = array(
			$this->system_msg(),
			$this->user_msg( 'Use vision.' ),
			$this->assistant_with_tool_calls( array( 'call_VIS' => 'vision_object_localization' ) ),
			$this->tool_msg( 'call_VIS', 'vision_object_localization', '{"objects":["cat"]}' ),
		);

		$filtered = $this->client->public_filter_tool_messages( $messages );

		$this->assertCount( 4, $filtered );
			$this->assertSame( 'tool', $filtered[3]['role'] );
			$this->assertSame( 'call_VIS', $filtered[3]['tool_call_id'] );
	}

		// ---------------------------------------------------------------------
		// gpt-image Responses API routing tests.
		// ---------------------------------------------------------------------

		/**
		 * Confirm that is_gpt_image_model() returns true for known gpt-image identifiers.
		 */
	public function test_is_gpt_image_model_recognises_gpt_image_family() {
		$this->assertTrue( WP_MCP_AI_OpenAI_Client::is_gpt_image_model( 'gpt-image-1' ) );
		$this->assertTrue( WP_MCP_AI_OpenAI_Client::is_gpt_image_model( 'gpt-image-1-mini' ) );
		$this->assertTrue( WP_MCP_AI_OpenAI_Client::is_gpt_image_model( 'gpt-image-1.5' ) );
		$this->assertTrue( WP_MCP_AI_OpenAI_Client::is_gpt_image_model( 'gpt-image-2' ) );
		$this->assertTrue( WP_MCP_AI_OpenAI_Client::is_gpt_image_model( 'GPT-IMAGE-2' ) );
	}

		/**
		 * Confirm that is_gpt_image_model() returns false for non-gpt-image models.
		 */
	public function test_is_gpt_image_model_rejects_dalle_and_unknown_models() {
		$this->assertFalse( WP_MCP_AI_OpenAI_Client::is_gpt_image_model( 'dall-e-2' ) );
		$this->assertFalse( WP_MCP_AI_OpenAI_Client::is_gpt_image_model( 'dall-e-3' ) );
		$this->assertFalse( WP_MCP_AI_OpenAI_Client::is_gpt_image_model( 'gpt-image-test' ) );
		$this->assertFalse( WP_MCP_AI_OpenAI_Client::is_gpt_image_model( '' ) );
	}

		/**
		 * Ensure generate_image with a gpt-image model sends to the Responses API endpoint.
		 */
	public function test_generate_image_routes_gpt_image_to_responses_endpoint() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key']  = 'sk-test';
		$settings['request_timeout'] = 30;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client           = new WP_MCP_AI_OpenAI_Client();
		$captured_request = null;
		$png_base64       = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=';

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request, $png_base64 ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			$payload = array(
				'model'  => 'gpt-image-1',
				'output' => array(
					array(
						'type'    => 'image_generation',
						'content' => array(
							array(
								'type'  => 'output_image',
								'image' => array(
									'b64_json' => $png_base64,
								),
							),
						),
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
				'A mountain at dawn',
				array(
					'model'   => 'gpt-image-1',
							'size'    => '2048x2048',
							'quality' => 'high',
						)
					);

				remove_filter( 'pre_http_request', $http_stub, 10 );

				$this->assertNotNull( $captured_request );
				$this->assertSame(
					$this->resolve_responses_endpoint(),
					$captured_request['url']
				);

				$payload = json_decode( $captured_request['args']['body'], true );
				$this->assertIsArray( $payload );
				$this->assertSame( 'gpt-image-1', $payload['model'] );
					$this->assertSame( 'A mountain at dawn', $payload['input'] );
					$this->assertArrayNotHasKey( 'prompt', $payload );
					$this->assertArrayNotHasKey( 'n', $payload );
					$this->assertArrayNotHasKey( 'response_format', $payload );

					$this->assertArrayHasKey( 'tools', $payload );
					$this->assertCount( 1, $payload['tools'] );
					$this->assertSame( 'image_generation', $payload['tools'][0]['type'] );
					$this->assertSame( '2048x2048', $payload['tools'][0]['size'] );
					$this->assertSame( 'high', $payload['tools'][0]['quality'] );

					// Verify parsed response.
					$this->assertIsArray( $response );
					$this->assertArrayHasKey( 'image', $response );
					$this->assertSame( 'gpt-image-1', $response['model'] );
	}

		/**
		 * Ensure generate_image with gpt-image model parses Responses API output correctly.
		 */
	public function test_generate_image_parses_responses_api_output() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key']  = 'sk-test';
		$settings['request_timeout'] = 30;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client     = new WP_MCP_AI_OpenAI_Client();
		$png_base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=';
		$png_binary = base64_decode( $png_base64 );

		$http_stub = function () use ( $png_base64 ) {
			$payload = array(
				'model'  => 'gpt-image-1',
				'output' => array(
					array(
						'type'    => 'image_generation',
						'content' => array(
							array(
								'type'  => 'output_image',
								'image' => array(
									'b64_json' => $png_base64,
								),
							),
						),
					),
				),
			);

			return array(
				'body'     => wp_json_encode( $payload ),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

			add_filter( 'pre_http_request', $http_stub );

			$response = $client->generate_image(
				'Abstract art',
				array( 'model' => 'gpt-image-1' )
			);

		remove_filter( 'pre_http_request', $http_stub );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'image', $response );
		$this->assertSame( $png_binary, $response['image'] );
		$this->assertSame( 'png', $response['format'] );
		$this->assertSame( 'image/png', $response['mime_type'] );
		$this->assertSame( 'gpt-image-1', $response['model'] );
		$this->assertSame( 0, $response['created'] );
	}

		/**
		 * Ensure generate_image with gpt-image model returns error on empty output.
		 */
	public function test_generate_image_errors_on_empty_responses_output() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key']  = 'sk-test';
		$settings['request_timeout'] = 30;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client = new WP_MCP_AI_OpenAI_Client();

		$http_stub = function () {
			return array(
				'body'     => wp_json_encode(
					array(
						'model'  => 'gpt-image-1',
						'output' => array(),
					)
				),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

			add_filter( 'pre_http_request', $http_stub );

			$response = $client->generate_image(
				'Failing prompt',
				array( 'model' => 'gpt-image-1' )
			);

		remove_filter( 'pre_http_request', $http_stub );

		$this->assertWPError( $response );
		$this->assertSame( 'wp_mcp_ai_image_empty', $response->get_error_code() );
	}

		/**
		 * Ensure generate_image with DALL-E model still uses the classic Images endpoint (regression).
		 */
	public function test_generate_image_routes_dalle_to_images_endpoint() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key']  = 'sk-test';
		$settings['request_timeout'] = 30;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client           = new WP_MCP_AI_OpenAI_Client();
		$captured_request = null;
		$png_base64       = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=';

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request, $png_base64 ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			$payload = array(
				'created' => 99,
				'data'    => array(
					array(
						'b64_json'       => $png_base64,
						'revised_prompt' => 'Revised',
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
				'Vintage photograph',
				array(
					'model'   => 'dall-e-3',
					'size'    => '1024x1024',
					'quality' => 'hd',
					'style'   => 'vivid',
				)
			);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request );
		$this->assertSame( WP_MCP_AI_OpenAI_Client::IMAGES_ENDPOINT, $captured_request['url'] );

		$payload = json_decode( $captured_request['args']['body'], true );
		$this->assertIsArray( $payload );
		$this->assertSame( 'dall-e-3', $payload['model'] );
		$this->assertSame( 'Vintage photograph', $payload['prompt'] );
		$this->assertArrayHasKey( 'n', $payload );
		$this->assertSame( 'hd', $payload['quality'] );
		$this->assertSame( 'vivid', $payload['style'] );
		$this->assertArrayNotHasKey( 'tools', $payload );

		// Verify parsed response.
		$this->assertIsArray( $response );
		$this->assertSame( 99, $response['created'] );
		$this->assertSame( 'Revised', $response['revised_prompt'] );
	}

		/**
		 * Ensure edit_image with a gpt-image model delegates to the Responses API.
		 */
	public function test_edit_image_routes_gpt_image_to_responses_api() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key']  = 'sk-test';
		$settings['request_timeout'] = 30;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client           = new WP_MCP_AI_OpenAI_Client();
		$captured_request = null;
		$png_base64       = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=';

		// Create a temporary PNG file for the test.
		$temp_file = tempnam( sys_get_temp_dir(), 'wp-mcp-ai-test-' ) . '.png';
		file_put_contents( $temp_file, base64_decode( $png_base64 ) );

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request, $png_base64 ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			$payload = array(
				'model'  => 'gpt-image-2',
				'output' => array(
					array(
						'type'    => 'image_generation',
						'content' => array(
							array(
								'type'  => 'output_image',
								'image' => array(
									'b64_json' => $png_base64,
								),
							),
						),
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

			$response = $client->edit_image(
				$temp_file,
				'Add a rainbow',
				array(
					'model' => 'gpt-image-2',
					'size'  => '1024x1024',
				)
			);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		// Clean up temp file.
		if ( file_exists( $temp_file ) ) {
			unlink( $temp_file );
		}

		$this->assertNotNull( $captured_request );
		$this->assertSame(
			$this->resolve_responses_endpoint(),
			$captured_request['url']
		);

		$payload = json_decode( $captured_request['args']['body'], true );
		$this->assertIsArray( $payload );
		$this->assertSame( 'gpt-image-2', $payload['model'] );
		$this->assertArrayHasKey( 'input', $payload );
		$this->assertIsArray( $payload['input'] );

		// Input should contain a text part and an image part.
		$has_text  = false;
		$has_image = false;
		foreach ( $payload['input'] as $part ) {
			if ( 'input_text' === $part['type'] ) {
				$has_text = true;
				$this->assertSame( 'Add a rainbow', $part['text'] );
			}
			if ( 'input_image' === $part['type'] ) {
				$has_image = true;
				$this->assertArrayHasKey( 'image_url', $part );
				$this->assertStringStartsWith( 'data:image/', $part['image_url']['url'] );
			}
		}
		$this->assertTrue( $has_text, 'Input should include input_text.' );
		$this->assertTrue( $has_image, 'Input should include input_image.' );

		$this->assertArrayHasKey( 'tools', $payload );
		$this->assertCount( 1, $payload['tools'] );
		$this->assertSame( 'image_generation', $payload['tools'][0]['type'] );

		// Verify response envelope.
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
		$this->assertTrue( $response['success'] );
		$this->assertArrayHasKey( 'data', $response );
		$this->assertCount( 1, $response['data'] );
		$this->assertSame( $png_base64, $response['data'][0]['b64_json'] );
	}

		/**
		 * Resolve the configured Responses API endpoint for assertion purposes.
		 *
		 * @return string
		 */
	private function resolve_responses_endpoint() {
		$client = new WP_MCP_AI_OpenAI_Client();

		return $client->resolve_endpoint( WP_MCP_AI_OpenAI_Client::RESPONSES_ENDPOINT );
	}
}
