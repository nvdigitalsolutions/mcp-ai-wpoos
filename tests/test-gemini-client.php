<?php
/**
 * Tests for the Gemini client wrapper.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for WP_MCP_AI_Gemini_Client.
 */
class WP_MCP_AI_Gemini_Client_Test extends WP_UnitTestCase {

	/**
	 * Ensure an error is returned when the Gemini API key is missing.
	 */
	public function test_create_chat_completion_requires_api_key() {
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );

		$client   = new WP_MCP_AI_Gemini_Client();
		$response = $client->create_chat_completion( array(), array() );

		$this->assertWPError( $response );
		$this->assertSame( 'wp_mcp_ai_missing_gemini_api_key', $response->get_error_code() );

		$data = $response->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 400, $data['status'] );
		$this->assertArrayHasKey( 'actions', $data );
		$this->assertArrayHasKey( 'configure_gemini_api_key', $data['actions'] );
	}

	/**
	 * Ensure the Gemini client uses the configured default model when none is provided.
	 */
	public function test_create_chat_completion_uses_default_model() {
		$defaults                         = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key']       = 'gsk-test';
		$defaults['default_gemini_model'] = 'gemini-test-model';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Gemini_Client();
		$captured_request = null;

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request, $defaults ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'candidates'    => array(
							array(
								'content'      => array(
									'parts' => array(
										array( 'text' => 'Hello from Gemini' ),
									),
								),
								'finishReason' => 'STOP',
							),
						),
						'usageMetadata' => array(
							'promptTokenCount'     => 10,
							'candidatesTokenCount' => 20,
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
				'content' => 'Hello',
			),
		);

		$response = $client->create_chat_completion( $messages, array() );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'choices', $response );
		$this->assertSame( 'gemini', $response['provider'] );
		$this->assertNotEmpty( $response['choices'] );

		$this->assertNotNull( $captured_request );
		$this->assertArrayHasKey( 'args', $captured_request );
		$this->assertArrayHasKey( 'body', $captured_request['args'] );

		$payload = json_decode( $captured_request['args']['body'], true );
		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'contents', $payload );
		$this->assertSame( $defaults['default_gemini_model'], $this->extract_model_from_url( $captured_request['url'] ) );
	}

	/**
	 * Ensure an error is returned when attempting to generate an image without an API key.
	 */
	public function test_generate_image_requires_api_key() {
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );

		$client   = new WP_MCP_AI_Gemini_Client();
		$response = $client->generate_image( 'A playful otter', array() );

		$this->assertWPError( $response );
		$this->assertSame( 'wp_mcp_ai_missing_gemini_api_key', $response->get_error_code() );
	}

	/**
	 * Ensure image generation sends the expected payload and returns decoded binary data.
	 */
	public function test_generate_image_sends_expected_payload() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key'] = 'gsk-test';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Gemini_Client();
		$captured_request = null;
		$binary_image     = random_bytes( 16 );
		$png_base64       = base64_encode( $binary_image );

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request, $png_base64 ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			$payload = array(
				'candidates' => array(
					array(
						'content' => array(
							'parts' => array(
								array(
									'text' => 'Suggested prompt: A brighter banana on a teal background',
								),
								array(
									'inlineData' => array(
										'data'     => $png_base64,
										'mimeType' => 'image/png',
									),
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

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$response = $client->generate_image(
			'A banana for scale',
			array(
				'model'        => 'gemini-2.5-flash-image',
				'aspect_ratio' => '16:9',
				'mime_type'    => 'image/png',
			)
		);

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotNull( $captured_request );
		$this->assertArrayHasKey( 'args', $captured_request );

		$payload = json_decode( $captured_request['args']['body'], true );
		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'generationConfig', $payload );
		$this->assertArrayNotHasKey( 'imageGenerationConfig', $payload );
		$this->assertArrayHasKey( 'responseModalities', $payload['generationConfig'] );
		$this->assertContains( 'IMAGE', $payload['generationConfig']['responseModalities'] );
		$this->assertArrayHasKey( 'imageConfig', $payload['generationConfig'] );
		$this->assertSame( '16:9', $payload['generationConfig']['imageConfig']['aspectRatio'] );
		$this->assertArrayNotHasKey( 'temperature', $payload['generationConfig'] );
		$this->assertSame( 'gemini-2.5-flash-image', $this->extract_model_from_url( $captured_request['url'] ) );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'image', $response );
		$this->assertSame( $binary_image, $response['image'] );
		$this->assertSame( 'image/png', $response['mime_type'] );
		$this->assertSame( 'png', $response['format'] );
		$this->assertSame( 'gemini-2.5-flash-image', $response['model'] );
		$this->assertSame( '16:9', $response['aspect_ratio'] );
		$this->assertArrayHasKey( 'created', $response );
		$this->assertIsInt( $response['created'] );
		$this->assertSame( 'Suggested prompt: A brighter banana on a teal background', $response['revised_prompt'] );
	}

	/**
	 * Ensure chat payloads include tool call and response history when provided.
	 */
	public function test_create_chat_completion_preserves_tool_history() {
		$defaults                         = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key']       = 'gsk-test';
		$defaults['default_gemini_model'] = 'gemini-test-model';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Gemini_Client();
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
						'candidates' => array(),
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
				'content' => 'What is the weather in Paris?',
			),
			array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => array(
					array(
						'id'       => 'call_abc',
						'type'     => 'function',
						'function' => array(
							'name'      => 'get_weather',
							'arguments' => '{"location":"Paris"}',
						),
					),
				),
			),
			array(
				'role'         => 'tool',
				'tool_call_id' => 'call_abc',
				'name'         => 'get_weather',
				'content'      => '{"result":"sunny"}',
			),
			array(
				'role'    => 'user',
				'content' => 'What should I wear today?',
			),
		);

		$response = $client->create_chat_completion( $messages, array() );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'choices', $response );
		$this->assertNotNull( $captured_request );

		$payload = json_decode( $captured_request['args']['body'], true );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'contents', $payload );
		$this->assertCount( 4, $payload['contents'] );

		$this->assertSame( 'user', $payload['contents'][0]['role'] );
		$this->assertSame( 'What is the weather in Paris?', $payload['contents'][0]['parts'][0]['text'] );

		$this->assertSame( 'model', $payload['contents'][1]['role'] );
		$this->assertArrayHasKey( 'functionCall', $payload['contents'][1]['parts'][0] );
		$this->assertSame( 'get_weather', $payload['contents'][1]['parts'][0]['functionCall']['name'] );
		$this->assertSame(
			array( 'location' => 'Paris' ),
			$payload['contents'][1]['parts'][0]['functionCall']['args']
		);

		$this->assertSame( 'user', $payload['contents'][2]['role'] );
		$this->assertArrayHasKey( 'functionResponse', $payload['contents'][2]['parts'][0] );
		$this->assertSame(
			'get_weather',
			$payload['contents'][2]['parts'][0]['functionResponse']['name']
		);
		$this->assertSame(
			array(
				'result'       => 'sunny',
				'tool_call_id' => 'call_abc',
			),
			$payload['contents'][2]['parts'][0]['functionResponse']['response']
		);

		$this->assertSame( 'user', $payload['contents'][3]['role'] );
		$this->assertSame( 'What should I wear today?', $payload['contents'][3]['parts'][0]['text'] );
	}

	/**
	 * Ensure multiple tool responses following a single assistant tool call are retained in order.
	 */
	public function test_create_chat_completion_allows_multiple_tool_responses_after_single_call() {
		$defaults                         = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key']       = 'gsk-test';
		$defaults['default_gemini_model'] = 'gemini-test-model';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Gemini_Client();
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
						'candidates' => array(),
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
				'content' => 'Please gather weather and time information.',
			),
			array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => array(
					array(
						'id'       => 'call_weather',
						'type'     => 'function',
						'function' => array(
							'name'      => 'get_weather',
							'arguments' => '{"location":"London"}',
						),
					),
					array(
						'id'       => 'call_time',
						'type'     => 'function',
						'function' => array(
							'name'      => 'get_time',
							'arguments' => '{"location":"London"}',
						),
					),
				),
			),
			array(
				'role'         => 'tool',
				'tool_call_id' => 'call_weather',
				'name'         => 'get_weather',
				'content'      => '{"result":"sunny"}',
			),
			array(
				'role'         => 'tool',
				'tool_call_id' => 'call_time',
				'name'         => 'get_time',
				'content'      => '{"result":"10:00"}',
			),
			array(
				'role'    => 'user',
				'content' => 'Thank you!',
			),
		);

		$response = $client->create_chat_completion( $messages, array() );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'choices', $response );
		$this->assertNotNull( $captured_request );

		$payload = json_decode( $captured_request['args']['body'], true );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'contents', $payload );
		$this->assertCount( 5, $payload['contents'] );

		$this->assertSame( 'model', $payload['contents'][1]['role'] );
		$this->assertArrayHasKey( 'functionCall', $payload['contents'][1]['parts'][0] );
		$this->assertSame( 'get_weather', $payload['contents'][1]['parts'][0]['functionCall']['name'] );

		$this->assertSame( 'user', $payload['contents'][2]['role'] );
		$this->assertArrayHasKey( 'functionResponse', $payload['contents'][2]['parts'][0] );
		$this->assertSame( 'get_weather', $payload['contents'][2]['parts'][0]['functionResponse']['name'] );
		$this->assertSame(
			array(
				'result'       => 'sunny',
				'tool_call_id' => 'call_weather',
			),
			$payload['contents'][2]['parts'][0]['functionResponse']['response']
		);

		$this->assertSame( 'user', $payload['contents'][3]['role'] );
		$this->assertArrayHasKey( 'functionResponse', $payload['contents'][3]['parts'][0] );
		$this->assertSame( 'get_time', $payload['contents'][3]['parts'][0]['functionResponse']['name'] );
		$this->assertSame(
			array(
				'result'       => '10:00',
				'tool_call_id' => 'call_time',
			),
			$payload['contents'][3]['parts'][0]['functionResponse']['response']
		);

		$this->assertSame( 'user', $payload['contents'][4]['role'] );
		$this->assertSame( 'Thank you!', $payload['contents'][4]['parts'][0]['text'] );
	}

	/**
	 * Ensure tool messages without a matching call are skipped to avoid Gemini errors.
	 */
	public function test_create_chat_completion_skips_unpaired_tool_messages() {
		$defaults                         = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key']       = 'gsk-test';
		$defaults['default_gemini_model'] = 'gemini-test-model';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Gemini_Client();
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
						'candidates' => array(),
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
				'content' => 'Please run the generate_gemini_image tool.',
			),
			array(
				'role'    => 'tool',
				'name'    => 'generate_gemini_image',
				'content' => '{"result":"done"}',
			),
			array(
				'role'    => 'user',
				'content' => 'Thanks!',
			),
		);

		$response = $client->create_chat_completion( $messages, array() );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'choices', $response );
		$this->assertNotNull( $captured_request );

		$payload = json_decode( $captured_request['args']['body'], true );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'contents', $payload );
		$this->assertCount( 2, $payload['contents'] );

		foreach ( $payload['contents'] as $entry ) {
			foreach ( $entry['parts'] as $part ) {
				$this->assertArrayNotHasKey( 'functionResponse', $part );
			}
		}
	}

	/**
	 * Ensure tool messages that do not immediately follow the originating tool call are skipped.
	 */
	public function test_create_chat_completion_skips_tool_messages_not_immediately_after_call() {
		$defaults                         = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key']       = 'gsk-test';
		$defaults['default_gemini_model'] = 'gemini-test-model';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Gemini_Client();
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
						'candidates' => array(),
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
				'content' => 'Can you generate an image of a red ball?',
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
							'arguments' => '{"prompt":"red ball"}',
						),
					),
				),
			),
			array(
				'role'    => 'user',
				'content' => 'I need it to be glossy.',
			),
			array(
				'role'         => 'tool',
				'tool_call_id' => 'call_123',
				'name'         => 'generate_gemini_image',
				'content'      => '{"result":"done"}',
			),
			array(
				'role'    => 'user',
				'content' => 'Thanks!',
			),
		);

		$response = $client->create_chat_completion( $messages, array() );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'choices', $response );
		$this->assertNotNull( $captured_request );

		$payload = json_decode( $captured_request['args']['body'], true );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'contents', $payload );
		$this->assertCount( 4, $payload['contents'] );

		foreach ( $payload['contents'] as $entry ) {
			foreach ( $entry['parts'] as $part ) {
				$this->assertArrayNotHasKey( 'functionResponse', $part );
			}
		}
	}

	/**
	 * Extract the model slug from the generated Gemini endpoint URL.
	 *
	 * @param string $url Request URL.
	 * @return string|null
	 */
	protected function extract_model_from_url( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( ! is_string( $path ) ) {
			return null;
		}

		$pattern = '#/models/([^:]+):generateContent$#';
		if ( preg_match( $pattern, $path, $matches ) ) {
			return rawurldecode( $matches[1] );
		}

		return null;
	}

	/**
	 * Test list_models() requires API key.
	 */
	public function test_list_models_requires_api_key() {
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );

		$client   = new WP_MCP_AI_Gemini_Client();
		$response = $client->list_models( array() );

		$this->assertWPError( $response );
		$this->assertSame( 'wp_mcp_ai_missing_gemini_api_key', $response->get_error_code() );
	}

	/**
	 * Test list_models() retrieves available models successfully.
	 */
	public function test_list_models_retrieves_models() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key'] = 'gsk-test';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Gemini_Client();
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
						'models' => array(
							array(
								'name'        => 'models/gemini-1.5-flash',
								'displayName' => 'Gemini 1.5 Flash',
							),
							array(
								'name'        => 'models/gemini-1.5-pro',
								'displayName' => 'Gemini 1.5 Pro',
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

		$response = $client->list_models( array() );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'models', $response );
		$this->assertCount( 2, $response['models'] );
		$this->assertNotNull( $captured_request );
		$this->assertStringContainsString( 'models', $captured_request['url'] );
	}

	/**
	 * Test count_tokens() requires API key.
	 */
	public function test_count_tokens_requires_api_key() {
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );

		$client   = new WP_MCP_AI_Gemini_Client();
		$response = $client->count_tokens( array(), array() );

		$this->assertWPError( $response );
		$this->assertSame( 'wp_mcp_ai_missing_gemini_api_key', $response->get_error_code() );
	}

	/**
	 * Test count_tokens() returns token count for messages.
	 */
	public function test_count_tokens_returns_token_count() {
		$defaults                         = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key']       = 'gsk-test';
		$defaults['default_gemini_model'] = 'gemini-1.5-flash';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Gemini_Client();
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
						'totalTokens' => 42,
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
				'content' => 'Hello, how are you?',
			),
		);

		$response = $client->count_tokens( $messages, array() );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'totalTokens', $response );
		$this->assertSame( 42, $response['totalTokens'] );
		$this->assertNotNull( $captured_request );
		$this->assertStringContainsString( 'countTokens', $captured_request['url'] );
	}

	/**
	 * Test create_embedding() requires API key.
	 */
	public function test_create_embedding_requires_api_key() {
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );

		$client   = new WP_MCP_AI_Gemini_Client();
		$response = $client->create_embedding( 'Test text', array() );

		$this->assertWPError( $response );
		$this->assertSame( 'wp_mcp_ai_missing_gemini_api_key', $response->get_error_code() );
	}

	/**
	 * Test create_embedding() requires text content.
	 */
	public function test_create_embedding_requires_text() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key'] = 'gsk-test';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client   = new WP_MCP_AI_Gemini_Client();
		$response = $client->create_embedding( '', array() );

		$this->assertWPError( $response );
		$this->assertSame( 'wp_mcp_ai_missing_text', $response->get_error_code() );
	}

	/**
	 * Test create_embedding() returns embedding data.
	 */
	public function test_create_embedding_returns_embedding() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key'] = 'gsk-test';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Gemini_Client();
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
						'embedding' => array(
							'values' => array( 0.1, 0.2, 0.3, 0.4, 0.5 ),
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

		$response = $client->create_embedding(
			'The quick brown fox jumps over the lazy dog',
			array(
				'model'     => 'text-embedding-004',
				'task_type' => 'RETRIEVAL_DOCUMENT',
			)
		);

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'embedding', $response );
		$this->assertArrayHasKey( 'values', $response['embedding'] );
		$this->assertCount( 5, $response['embedding']['values'] );
		$this->assertNotNull( $captured_request );
		$this->assertStringContainsString( 'embedContent', $captured_request['url'] );

		$payload = json_decode( $captured_request['args']['body'], true );
		$this->assertArrayHasKey( 'content', $payload );
		$this->assertArrayHasKey( 'taskType', $payload );
		$this->assertSame( 'RETRIEVAL_DOCUMENT', $payload['taskType'] );
	}

	/**
	 * Test stream_chat_completion() requires API key.
	 */
	public function test_stream_chat_completion_requires_api_key() {
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );

		$client   = new WP_MCP_AI_Gemini_Client();
		$response = $client->stream_chat_completion( array(), array() );

		$this->assertWPError( $response );
		$this->assertSame( 'wp_mcp_ai_missing_gemini_api_key', $response->get_error_code() );
	}

	/**
	 * Test stream_chat_completion() processes streaming response.
	 */
	public function test_stream_chat_completion_processes_stream() {
		$defaults                         = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key']       = 'gsk-test';
		$defaults['default_gemini_model'] = 'gemini-1.5-flash';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Gemini_Client();
		$captured_request = null;
		$callback_calls   = array();

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			// Simulate SSE stream response.
			$sse_body  = 'data: ' . wp_json_encode(
				array(
					'candidates' => array(
						array(
							'content' => array(
								'parts' => array(
									array( 'text' => 'Hello, ' ),
								),
							),
						),
					),
				)
			) . "\n";
			$sse_body .= 'data: ' . wp_json_encode(
				array(
					'candidates'    => array(
						array(
							'content' => array(
								'parts' => array(
									array( 'text' => 'how can I help you?' ),
								),
							),
						),
					),
					'usageMetadata' => array(
						'promptTokenCount'     => 10,
						'candidatesTokenCount' => 20,
					),
				)
			) . "\n";
			$sse_body .= "data: [DONE]\n";

			return array(
				'headers'  => array(),
				'body'     => $sse_body,
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

		$callback = function ( $chunk, $type ) use ( &$callback_calls ) {
			$callback_calls[] = array(
				'chunk' => $chunk,
				'type'  => $type,
			);
		};

		$response = $client->stream_chat_completion( $messages, array(), $callback );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'choices', $response );
		$this->assertArrayHasKey( 'provider', $response );
		$this->assertSame( 'gemini', $response['provider'] );
		$this->assertNotEmpty( $callback_calls );
		$this->assertSame( 'text', $callback_calls[0]['type'] );
		$this->assertSame( 'Hello, ', $callback_calls[0]['chunk'] );
		$this->assertArrayHasKey( 'usage', $response );
		$this->assertSame( 10, $response['usage']['prompt_tokens'] );
		$this->assertSame( 20, $response['usage']['completion_tokens'] );
		$this->assertNotNull( $captured_request );
		$this->assertStringContainsString( 'streamGenerateContent', $captured_request['url'] );
		$this->assertStringContainsString( 'alt=sse', $captured_request['url'] );
	}
}
