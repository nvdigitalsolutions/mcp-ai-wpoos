<?php
/**
 * Tests for Gemini client header-based authentication.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for WP_MCP_AI_Gemini_Client header authentication.
 */
class WP_MCP_AI_Gemini_Header_Auth_Test extends WP_UnitTestCase {

	/**
	 * Test that API key is sent via x-goog-api-key header instead of query parameter.
	 */
	public function test_create_chat_completion_uses_header_auth() {
		$defaults                         = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key']       = 'test-api-key-12345';
		$defaults['default_gemini_model'] = 'gemini-2.5-flash';

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
						'candidates'    => array(
							array(
								'content'      => array(
									'parts' => array(
										array( 'text' => 'Test response' ),
									),
								),
								'finishReason' => 'STOP',
							),
						),
						'usageMetadata' => array(
							'promptTokenCount'     => 5,
							'candidatesTokenCount' => 10,
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

		// Verify request was captured.
		$this->assertNotNull( $captured_request, 'Request should have been captured' );

		// Verify header contains API key.
		$this->assertArrayHasKey( 'headers', $captured_request['args'], 'Request should have headers' );
		$this->assertArrayHasKey( 'x-goog-api-key', $captured_request['args']['headers'], 'Headers should contain x-goog-api-key' );
		$this->assertSame( 'test-api-key-12345', $captured_request['args']['headers']['x-goog-api-key'], 'API key should match' );

		// Verify URL does NOT contain API key as query parameter.
		$this->assertStringNotContainsString( '?key=', $captured_request['url'], 'URL should not contain ?key= query parameter' );
		$this->assertStringNotContainsString( '&key=', $captured_request['url'], 'URL should not contain &key= query parameter' );
		$this->assertStringNotContainsString( 'test-api-key-12345', $captured_request['url'], 'URL should not contain API key' );

		// Verify URL is clean endpoint.
		$this->assertStringContainsString( 'generativelanguage.googleapis.com/v1beta/models/', $captured_request['url'], 'URL should contain base endpoint' );
		$this->assertStringContainsString( ':generateContent', $captured_request['url'], 'URL should contain method' );
	}

	/**
	 * Test that stream endpoint uses header auth.
	 */
	public function test_stream_chat_completion_uses_header_auth() {
		$defaults                         = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key']       = 'test-stream-key';
		$defaults['default_gemini_model'] = 'gemini-2.5-flash';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Gemini_Client();
		$captured_request = null;

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			// Return SSE format response.
			$sse_response = 'data: ' . wp_json_encode(
				array(
					'candidates'    => array(
						array(
							'content' => array(
								'parts' => array(
									array( 'text' => 'Streaming response' ),
								),
							),
						),
					),
					'usageMetadata' => array(
						'promptTokenCount'     => 5,
						'candidatesTokenCount' => 10,
					),
				)
			) . "\n\n";

			return array(
				'headers'  => array(),
				'body'     => $sse_response,
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
				'content' => 'Test',
			),
		);

		$response = $client->stream_chat_completion( $messages, array() );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		// Verify header contains API key.
		$this->assertArrayHasKey( 'x-goog-api-key', $captured_request['args']['headers'], 'Stream request should use header auth' );
		$this->assertSame( 'test-stream-key', $captured_request['args']['headers']['x-goog-api-key'], 'Stream API key should match' );

		// Verify URL does NOT contain API key.
		$this->assertStringNotContainsString( 'test-stream-key', $captured_request['url'], 'Stream URL should not contain API key' );

		// Verify URL contains alt=sse parameter.
		$this->assertStringContainsString( 'alt=sse', $captured_request['url'], 'Stream URL should contain alt=sse parameter' );
	}

	/**
	 * Test that list_models uses header auth.
	 */
	public function test_list_models_uses_header_auth() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key'] = 'test-list-key';

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
							array( 'name' => 'gemini-2.5-flash' ),
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

		// Verify header contains API key.
		$this->assertArrayHasKey( 'x-goog-api-key', $captured_request['args']['headers'], 'List models should use header auth' );
		$this->assertSame( 'test-list-key', $captured_request['args']['headers']['x-goog-api-key'] );

		// Verify URL does NOT contain API key.
		$this->assertStringNotContainsString( 'test-list-key', $captured_request['url'] );
		$this->assertStringNotContainsString( '?key=', $captured_request['url'] );
	}

	/**
	 * Test that JSON schema options are properly added to generationConfig.
	 */
	public function test_response_json_schema_support() {
		$defaults                         = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key']       = 'test-key';
		$defaults['default_gemini_model'] = 'gemini-2.5-flash';

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
						'candidates'    => array(
							array(
								'content'      => array(
									'parts' => array(
										array( 'text' => '{"recipe_name":"Chocolate Chip Cookies"}' ),
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
				'content' => 'Extract the recipe',
			),
		);

		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'recipe_name' => array(
					'type'        => 'string',
					'description' => 'The name of the recipe.',
				),
				'ingredients' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'name'     => array( 'type' => 'string' ),
							'quantity' => array( 'type' => 'string' ),
						),
						'required'   => array( 'name', 'quantity' ),
					),
				),
			),
			'required'   => array( 'recipe_name', 'ingredients' ),
		);

		$options = array(
			'response_mime_type'   => 'application/json',
			'response_json_schema' => $schema,
		);

		$response = $client->create_chat_completion( $messages, $options );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		// Verify request was captured.
		$this->assertNotNull( $captured_request );

		// Decode the request body.
		$payload = json_decode( $captured_request['args']['body'], true );

		// Verify generationConfig contains responseMimeType.
		$this->assertArrayHasKey( 'generationConfig', $payload, 'Payload should have generationConfig' );
		$this->assertArrayHasKey( 'responseMimeType', $payload['generationConfig'], 'generationConfig should have responseMimeType' );
		$this->assertSame( 'application/json', $payload['generationConfig']['responseMimeType'] );

		// Verify generationConfig contains responseJsonSchema.
		$this->assertArrayHasKey( 'responseJsonSchema', $payload['generationConfig'], 'generationConfig should have responseJsonSchema' );
		$this->assertIsArray( $payload['generationConfig']['responseJsonSchema'] );
		$this->assertSame( 'object', $payload['generationConfig']['responseJsonSchema']['type'] );
		$this->assertArrayHasKey( 'recipe_name', $payload['generationConfig']['responseJsonSchema']['properties'] );
	}

	/**
	 * Test that response_schema option works.
	 */
	public function test_response_schema_support() {
		$defaults                         = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key']       = 'test-key';
		$defaults['default_gemini_model'] = 'gemini-2.5-flash';

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
						'candidates'    => array(
							array(
								'content'      => array(
									'parts' => array(
										array( 'text' => 'Response' ),
									),
								),
								'finishReason' => 'STOP',
							),
						),
						'usageMetadata' => array(),
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
				'content' => 'Test',
			),
		);

		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'name' => array( 'type' => 'string' ),
			),
		);

		$options = array(
			'response_schema' => $schema,
		);

		$response = $client->create_chat_completion( $messages, $options );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		// Decode the request body.
		$payload = json_decode( $captured_request['args']['body'], true );

		// Verify generationConfig contains responseSchema.
		$this->assertArrayHasKey( 'generationConfig', $payload );
		$this->assertArrayHasKey( 'responseSchema', $payload['generationConfig'], 'generationConfig should have responseSchema' );
		$this->assertSame( $schema, $payload['generationConfig']['responseSchema'] );
	}

	/**
	 * Test count_tokens uses header auth.
	 */
	public function test_count_tokens_uses_header_auth() {
		$defaults                         = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key']       = 'test-count-key';
		$defaults['default_gemini_model'] = 'gemini-2.5-flash';

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
						'totalTokens' => 15,
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
				'content' => 'Test',
			),
		);

		$response = $client->count_tokens( $messages, array() );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		// Verify header auth.
		$this->assertArrayHasKey( 'x-goog-api-key', $captured_request['args']['headers'] );
		$this->assertSame( 'test-count-key', $captured_request['args']['headers']['x-goog-api-key'] );
		$this->assertStringNotContainsString( 'test-count-key', $captured_request['url'] );
	}

	/**
	 * Test create_embedding uses header auth.
	 */
	public function test_create_embedding_uses_header_auth() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key'] = 'test-embed-key';

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
							'values' => array( 0.1, 0.2, 0.3 ),
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

		$response = $client->create_embedding( 'Test text', array() );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		// Verify header auth.
		$this->assertArrayHasKey( 'x-goog-api-key', $captured_request['args']['headers'] );
		$this->assertSame( 'test-embed-key', $captured_request['args']['headers']['x-goog-api-key'] );
		$this->assertStringNotContainsString( 'test-embed-key', $captured_request['url'] );
	}
}
