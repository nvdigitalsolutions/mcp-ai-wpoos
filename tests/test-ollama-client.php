<?php
/**
 * Tests for the Ollama client wrapper.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Ollama_Client_Test extends WP_UnitTestCase {

	/**
	 * Ensure an error is returned when the Ollama endpoint URL is missing.
	 */
	public function test_create_chat_completion_requires_endpoint() {
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );

		$client   = new WP_MCP_AI_Ollama_Client();
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		$response = $client->create_chat_completion( $messages, array() );

		$this->assertWPError( $response );
		$this->assertSame( 'wp_mcp_ai_missing_ollama_endpoint', $response->get_error_code() );

		$data = $response->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 400, $data['status'] );
	}

	/**
	 * Ensure an error is returned when the Ollama model is missing.
	 */
	public function test_create_chat_completion_requires_model() {
		$defaults                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['ollama_endpoint_url'] = 'http://localhost:11434';
		$defaults['ollama_model']        = '';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client   = new WP_MCP_AI_Ollama_Client();
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		$response = $client->create_chat_completion( $messages, array() );

		$this->assertWPError( $response );
		$this->assertSame( 'wp_mcp_ai_missing_ollama_model', $response->get_error_code() );

		$data = $response->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 400, $data['status'] );
	}

	/**
	 * Ensure the Ollama client normalizes the response correctly.
	 */
	public function test_create_chat_completion_normalizes_response() {
		$defaults                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['ollama_endpoint_url'] = 'http://localhost:11434';
		$defaults['ollama_model']        = 'llama2';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Ollama_Client();
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
						'model'             => 'llama2',
						'message'           => array(
							'role'    => 'assistant',
							'content' => 'Hello from Ollama',
						),
						'done'              => true,
						'prompt_eval_count' => 10,
						'eval_count'        => 20,
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
		$this->assertSame( 'ollama', $response['provider'] );
		$this->assertSame( 'llama2', $response['model'] );
		$this->assertNotEmpty( $response['choices'] );

		$choice = $response['choices'][0];
		$this->assertArrayHasKey( 'message', $choice );
		$this->assertArrayHasKey( 'content', $choice['message'] );
		$this->assertIsArray( $choice['message']['content'] );

		$this->assertArrayHasKey( 'usage', $response );
		$this->assertSame( 10, $response['usage']['prompt_tokens'] );
		$this->assertSame( 20, $response['usage']['completion_tokens'] );
	}

	/**
	 * Ensure the test connection method works correctly.
	 */
	public function test_connection_test_succeeds() {
		$defaults                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['ollama_endpoint_url'] = 'http://localhost:11434';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client = new WP_MCP_AI_Ollama_Client();

		$filter_callback = function ( $preempt, $args, $url ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'models' => array(),
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$result = $client->test_connection();

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'message', $result );
	}

	/**
	 * Ensure the test connection method uses configurable timeout.
	 */
	public function test_connection_uses_configurable_timeout() {
		$defaults                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['ollama_endpoint_url'] = 'http://localhost:11434';
		$defaults['request_timeout']     = 60; // Set custom timeout.

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client        = new WP_MCP_AI_Ollama_Client();
		$captured_args = null;

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_args ) {
			$captured_args = $args;
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'models' => array(),
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$client->test_connection();

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		// Verify timeout was passed correctly.
		$this->assertNotNull( $captured_args );
		$this->assertArrayHasKey( 'timeout', $captured_args );
		$this->assertEquals( 60, $captured_args['timeout'] );
	}

	/**
	 * Ensure the list models method uses configurable timeout.
	 */
	public function test_list_models_uses_configurable_timeout() {
		$defaults                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['ollama_endpoint_url'] = 'http://localhost:11434';
		$defaults['request_timeout']     = 45; // Set custom timeout.

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client        = new WP_MCP_AI_Ollama_Client();
		$captured_args = null;

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_args ) {
			$captured_args = $args;
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'models' => array(),
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$client->list_models();

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		// Verify timeout was passed correctly.
		$this->assertNotNull( $captured_args );
		$this->assertArrayHasKey( 'timeout', $captured_args );
		$this->assertEquals( 45, $captured_args['timeout'] );
	}

	/**
	 * Ensure the list models method returns models correctly.
	 */
	public function test_list_models_returns_models() {
		$defaults                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['ollama_endpoint_url'] = 'http://localhost:11434';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client = new WP_MCP_AI_Ollama_Client();

		$filter_callback = function ( $preempt, $args, $url ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'models' => array(
							array(
								'name'        => 'llama2',
								'size'        => 1234567890,
								'modified_at' => '2024-01-01T00:00:00Z',
								'digest'      => 'abc123',
								'details'     => array(
									'family'         => 'llama',
									'format'         => 'gguf',
									'parameter_size' => '7B',
								),
							),
							array(
								'name'        => 'codellama',
								'size'        => 987654321,
								'modified_at' => '2024-01-02T00:00:00Z',
								'digest'      => 'def456',
								'details'     => array(
									'family'         => 'llama',
									'format'         => 'gguf',
									'parameter_size' => '13B',
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

		$models = $client->list_models();

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $models );
		$this->assertCount( 2, $models );

		$this->assertSame( 'llama2', $models[0]['name'] );
		$this->assertSame( 1234567890, $models[0]['size'] );
		$this->assertSame( 'llama', $models[0]['family'] );

		$this->assertSame( 'codellama', $models[1]['name'] );
		$this->assertSame( 987654321, $models[1]['size'] );
		$this->assertSame( 'llama', $models[1]['family'] );
	}

	/**
	 * Ensure the client handles empty messages array gracefully.
	 */
	public function test_create_chat_completion_requires_messages() {
		$defaults                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['ollama_endpoint_url'] = 'http://localhost:11434';
		$defaults['ollama_model']        = 'llama2';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client   = new WP_MCP_AI_Ollama_Client();
		$response = $client->create_chat_completion( array(), array() );

		$this->assertWPError( $response );
		$this->assertSame( 'wp_mcp_ai_missing_messages', $response->get_error_code() );
	}

	/**
	 * Ensure the client uses the provided model override.
	 */
	public function test_create_chat_completion_uses_model_override() {
		$defaults                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['ollama_endpoint_url'] = 'http://localhost:11434';
		$defaults['ollama_model']        = 'llama2';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Ollama_Client();
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
						'model'   => 'codellama',
						'message' => array(
							'role'    => 'assistant',
							'content' => 'Hello',
						),
						'done'    => true,
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

		$response = $client->create_chat_completion( $messages, array( 'model' => 'codellama' ) );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'model', $response );
		$this->assertSame( 'codellama', $response['model'] );

		$this->assertNotNull( $captured_request );
		$this->assertArrayHasKey( 'args', $captured_request );
		$this->assertArrayHasKey( 'body', $captured_request['args'] );

		$payload = json_decode( $captured_request['args']['body'], true );
		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'model', $payload );
		$this->assertSame( 'codellama', $payload['model'] );
	}

	/**
	 * Ensure chat completions use a minimum timeout of 120 seconds.
	 */
	public function test_chat_completion_uses_minimum_120_second_timeout() {
		$defaults                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['ollama_endpoint_url'] = 'http://localhost:11434';
		$defaults['ollama_model']        = 'llama2';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client        = new WP_MCP_AI_Ollama_Client();
		$captured_args = null;

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_args ) {
			$captured_args = $args;
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'model'   => 'llama2',
						'message' => array(
							'role'    => 'assistant',
							'content' => 'Test response',
						),
						'done'    => true,
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

		$client->create_chat_completion( $messages, array() );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		// Verify timeout is at least 120 seconds for chat completions.
		$this->assertNotNull( $captured_args );
		$this->assertArrayHasKey( 'timeout', $captured_args );
		$this->assertGreaterThanOrEqual( 120, $captured_args['timeout'] );
	}

	/**
	 * Test that network interface setting is retrieved correctly.
	 */
	public function test_get_network_interface() {
		$defaults                             = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['ollama_network_interface'] = 'eth0';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client = new WP_MCP_AI_Ollama_Client();

		$this->assertSame( 'eth0', $client->get_network_interface() );
	}

	/**
	 * Test that network interface returns empty string when not configured.
	 */
	public function test_get_network_interface_empty() {
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );

		$client = new WP_MCP_AI_Ollama_Client();

		$this->assertSame( '', $client->get_network_interface() );
	}

	/**
	 * Test that connection timeout is properly configured for private network IPs.
	 *
	 * This test verifies the fix for the issue where connections to Ollama servers
	 * on private networks (e.g., 192.168.2.222:11434) timeout at 10 seconds even
	 * when the overall timeout is set to 120 seconds.
	 *
	 * The fix ensures CURLOPT_CONNECTTIMEOUT matches the overall timeout.
	 *
	 * @group connection-timeout
	 */
	public function test_connection_timeout_set_for_private_network() {
		$defaults                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['ollama_endpoint_url'] = 'http://192.168.2.222:11434';
		$defaults['ollama_model']        = 'llama2';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Ollama_Client();
		$curl_handle_info = null;

		// Capture cURL handle configuration.
		$curl_filter_callback = function ( $handle, $args, $url ) use ( &$curl_handle_info ) {
			// Only capture if this is our test URL.
			if ( strpos( $url, '192.168.2.222' ) !== false ) {
				$curl_handle_info = array(
					'url'  => $url,
					'args' => $args,
				);
			}
			return $handle;
		};

		add_filter( 'http_api_curl', $curl_filter_callback, 100, 3 );

		// Mock the HTTP response to avoid actual network call.
		$http_response_callback = function ( $preempt, $args, $url ) {
			if ( strpos( $url, '192.168.2.222' ) !== false ) {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'models' => array(),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			}
			return $preempt;
		};

		add_filter( 'pre_http_request', $http_response_callback, 10, 3 );

		// Trigger a connection attempt.
		$client->list_models();

		remove_filter( 'http_api_curl', $curl_filter_callback, 100 );
		remove_filter( 'pre_http_request', $http_response_callback, 10 );

		// Verify that our filter was called for the private IP.
		$this->assertNotNull( $curl_handle_info, 'cURL filter should have been called for private IP' );
		$this->assertStringContainsString( '192.168.2.222', $curl_handle_info['url'] );

		// Verify timeout is set to at least 30 seconds.
		$this->assertArrayHasKey( 'timeout', $curl_handle_info['args'] );
		$this->assertGreaterThanOrEqual( 30, $curl_handle_info['args']['timeout'] );
	}

	/**
	 * Test that finish_reason is 'stop' when done=true.
	 *
	 * @group finish-reason
	 */
	public function test_finish_reason_stop_when_done_true() {
		$defaults                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['ollama_endpoint_url'] = 'http://localhost:11434';
		$defaults['ollama_model']        = 'llama2';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client = new WP_MCP_AI_Ollama_Client();

		$filter_callback = function ( $preempt, $args, $url ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'model'   => 'llama2',
						'message' => array(
							'role'    => 'assistant',
							'content' => 'Test response',
						),
						'done'    => true,
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
		$this->assertNotEmpty( $response['choices'] );
		$this->assertArrayHasKey( 'finish_reason', $response['choices'][0] );
		$this->assertSame( 'stop', $response['choices'][0]['finish_reason'] );
	}

	/**
	 * Test that finish_reason is 'stop' when done is missing but content exists.
	 *
	 * This tests the fix for the "response ended prematurely" error.
	 *
	 * @group finish-reason
	 */
	public function test_finish_reason_stop_when_done_missing_with_content() {
		$defaults                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['ollama_endpoint_url'] = 'http://localhost:11434';
		$defaults['ollama_model']        = 'llama2';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client = new WP_MCP_AI_Ollama_Client();

		$filter_callback = function ( $preempt, $args, $url ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'model'   => 'llama2',
						'message' => array(
							'role'    => 'assistant',
							'content' => 'Valid response content',
						),
						// 'done' field is missing, but we have valid content.
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
		$this->assertNotEmpty( $response['choices'] );
		$this->assertArrayHasKey( 'finish_reason', $response['choices'][0] );
		// Should be 'stop' because we have content, even though 'done' is missing.
		$this->assertSame( 'stop', $response['choices'][0]['finish_reason'] );
	}

	/**
	 * Test that finish_reason is 'length' when done=false and no content.
	 *
	 * @group finish-reason
	 */
	public function test_finish_reason_length_when_no_content() {
		$defaults                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['ollama_endpoint_url'] = 'http://localhost:11434';
		$defaults['ollama_model']        = 'llama2';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client = new WP_MCP_AI_Ollama_Client();

		$filter_callback = function ( $preempt, $args, $url ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'model'   => 'llama2',
						'message' => array(
							'role'    => 'assistant',
							'content' => '', // Empty content.
						),
						'done'    => false,
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
		$this->assertNotEmpty( $response['choices'] );
		$this->assertArrayHasKey( 'finish_reason', $response['choices'][0] );
		// Should be 'length' because we have no content and done=false.
		$this->assertSame( 'length', $response['choices'][0]['finish_reason'] );
	}

	/**
	 * Test that finish_reason uses Ollama's done_reason field when available.
	 *
	 * @group finish-reason
	 */
	public function test_finish_reason_uses_done_reason_field() {
		$defaults                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['ollama_endpoint_url'] = 'http://localhost:11434';
		$defaults['ollama_model']        = 'llama2';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client = new WP_MCP_AI_Ollama_Client();

		$filter_callback = function ( $preempt, $args, $url ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'model'       => 'llama2',
						'message'     => array(
							'role'    => 'assistant',
							'content' => 'Test response',
						),
						'done'        => true,
						'done_reason' => 'stop', // Ollama's done_reason field.
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
		$this->assertNotEmpty( $response['choices'] );
		$this->assertArrayHasKey( 'finish_reason', $response['choices'][0] );
		// Should use Ollama's done_reason field value.
		$this->assertSame( 'stop', $response['choices'][0]['finish_reason'] );
	}

	/**
	 * Test that streaming responses accumulate content correctly.
	 *
	 * This tests the fix for the bug where streaming responses only returned
	 * the last chunk's content instead of accumulating all chunks.
	 *
	 * @group streaming
	 */
	public function test_streaming_response_accumulates_content() {
		$defaults                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['ollama_endpoint_url'] = 'http://localhost:11434';
		$defaults['ollama_model']        = 'llama2';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client = new WP_MCP_AI_Ollama_Client();

		// Simulate Ollama streaming response with multiple chunks.
		// Ollama sends content deltas in each chunk, not full accumulated content.
		// See: https://docs.ollama.com/api/streaming
		$filter_callback = function ( $preempt, $args, $url ) {
			// Simulate newline-delimited JSON stream with multiple chunks.
			$stream_chunks = array(
				array(
					'model'   => 'llama2',
					'message' => array(
						'role'    => 'assistant',
						'content' => 'Hello',
					),
					'done'    => false,
				),
				array(
					'model'   => 'llama2',
					'message' => array(
						'role'    => 'assistant',
						'content' => ' there',
					),
					'done'    => false,
				),
				array(
					'model'   => 'llama2',
					'message' => array(
						'role'    => 'assistant',
						'content' => '!',
					),
					'done'    => false,
				),
				array(
					'model'             => 'llama2',
					'message'           => array(
						'role'    => 'assistant',
						'content' => '', // Final chunk often has empty content.
					),
					'done'              => true,
					'done_reason'       => 'stop',
					'prompt_eval_count' => 10,
					'eval_count'        => 15,
				),
			);

			// Build newline-delimited JSON response.
			$body = '';
			foreach ( $stream_chunks as $chunk ) {
				$body .= wp_json_encode( $chunk ) . "\n";
			}

			return array(
				'headers'  => array(),
				'body'     => $body,
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
				'content' => 'Say hello',
			),
		);

		// Enable streaming in options.
		$response = $client->create_chat_completion( $messages, array( 'stream' => true ) );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		// Verify response structure.
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'choices', $response );
		$this->assertNotEmpty( $response['choices'] );

		$choice = $response['choices'][0];

		// Verify finish_reason is 'stop'.
		$this->assertArrayHasKey( 'finish_reason', $choice );
		$this->assertSame( 'stop', $choice['finish_reason'] );

		// Verify message content.
		$this->assertArrayHasKey( 'message', $choice );
		$this->assertArrayHasKey( 'content', $choice['message'] );
		$this->assertIsArray( $choice['message']['content'] );
		$this->assertNotEmpty( $choice['message']['content'] );

		// Content should be accumulated from all chunks: "Hello" + " there" + "!" = "Hello there!".
		$content_text = $choice['message']['content'][0]['text'];
		$this->assertSame( 'Hello there!', $content_text, 'Streaming content should accumulate all chunks, not just the last one' );

		// Verify usage metadata from final chunk.
		$this->assertArrayHasKey( 'usage', $response );
		$this->assertSame( 10, $response['usage']['prompt_tokens'] );
		$this->assertSame( 15, $response['usage']['completion_tokens'] );
	}

	/**
	 * Ensure the Ollama client handles token limit with empty content correctly.
	 * This tests the fix for the "response ended prematurely" error.
	 */
	public function test_token_limit_with_empty_content_provides_helpful_message() {
		$defaults                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['ollama_endpoint_url'] = 'http://localhost:11434';
		$defaults['ollama_model']        = 'llama2';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client = new WP_MCP_AI_Ollama_Client();

		// Simulate Ollama response when hitting token limit with no content generated.
		// This happens when the prompt consumes all available tokens (num_predict).
		$filter_callback = function ( $preempt, $args, $url ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'model'             => 'llama2',
						'message'           => array(
							'role'    => 'assistant',
							'content' => '', // Empty content - hit token limit before generating anything.
						),
						'done'              => true,
						'done_reason'       => 'length', // Ollama signals token limit hit.
						'prompt_eval_count' => 2000,
						'eval_count'        => 0, // No tokens generated in response.
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
				'content' => 'Very long prompt that consumes all tokens...',
			),
		);

		$response = $client->create_chat_completion( $messages, array() );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		// Verify response structure follows OpenAI API standard.
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'choices', $response );
		$this->assertNotEmpty( $response['choices'] );

		$choice = $response['choices'][0];

		// finish_reason should be 'length' (from done_reason).
		$this->assertArrayHasKey( 'finish_reason', $choice );
		$this->assertSame( 'length', $choice['finish_reason'] );

		// Message MUST have content field (OpenAI API standard).
		$this->assertArrayHasKey( 'message', $choice );
		$this->assertArrayHasKey( 'content', $choice['message'] );
		$this->assertIsArray( $choice['message']['content'] );
		$this->assertNotEmpty( $choice['message']['content'] );

		// Content should contain helpful error message, not be empty.
		$content_text = $choice['message']['content'][0]['text'];
		$this->assertNotEmpty( $content_text );
		$this->assertStringContainsString( 'token limit', $content_text );
		$this->assertStringContainsString( 'Orchestration', $content_text ); // Should mention where to adjust settings.

		// Verify usage is included.
		$this->assertArrayHasKey( 'usage', $response );
		$this->assertSame( 2000, $response['usage']['prompt_tokens'] );
		$this->assertSame( 0, $response['usage']['completion_tokens'] );
	}

	/**
	 * Test that build_payload extracts text from input_image segments into the message
	 * text when no base64 data is available (no attachment_id, no URL).
	 */
	public function test_build_payload_handles_input_image_without_data() {
		$reflection = new ReflectionMethod( WP_MCP_AI_Ollama_Client::class, 'build_payload' );
		$reflection->setAccessible( true );

		$client   = new WP_MCP_AI_Ollama_Client();
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'What do you see?',
					),
					array(
						'type' => 'input_image',
						// No attachment_id, no URL – no base64 can be produced.
					),
				),
			),
		);

		$result = $reflection->invoke( $client, $messages, array(), 'llava' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'messages', $result );
		// The message should be included (non-empty text from the text segment).
		$this->assertNotEmpty( $result['messages'] );
		// No images key should be set when no image data is available.
		$this->assertArrayNotHasKey( 'images', $result['messages'][0] );
	}

	/**
	 * Test that build_payload adds Ollama images array when image URL is present.
	 */
	public function test_build_payload_adds_images_for_input_image_with_url() {
		$reflection = new ReflectionMethod( WP_MCP_AI_Ollama_Client::class, 'build_payload' );
		$reflection->setAccessible( true );

		// Stub wp_remote_get to return fake image binary.
		$filter_callback = function ( $preempt, $args, $url ) {
			if ( false !== strpos( $url, 'test-image.jpg' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => 'FAKEIMAGEBYTES',
					'headers'  => array(),
				);
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$client   = new WP_MCP_AI_Ollama_Client();
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Describe the image.',
					),
					array(
						'type'      => 'input_image',
						'image_url' => array( 'url' => 'https://example.com/test-image.jpg' ),
						'mime_type' => 'image/jpeg',
					),
				),
			),
		);

		$result = $reflection->invoke( $client, $messages, array(), 'llava' );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'messages', $result );
		$this->assertNotEmpty( $result['messages'] );

		// The images array must be set with the base64-encoded image data.
		$this->assertArrayHasKey( 'images', $result['messages'][0], 'images key should be present for vision models' );
		$this->assertCount( 1, $result['messages'][0]['images'] );
		// Base64 of "FAKEIMAGEBYTES".
		$this->assertEquals( base64_encode( 'FAKEIMAGEBYTES' ), $result['messages'][0]['images'][0] );
	}

	/**
	 * Test that build_payload includes file references in text for input_file segments.
	 */
	public function test_build_payload_includes_file_reference_in_text() {
		$reflection = new ReflectionMethod( WP_MCP_AI_Ollama_Client::class, 'build_payload' );
		$reflection->setAccessible( true );

		$client   = new WP_MCP_AI_Ollama_Client();
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Summarise the document.',
					),
					array(
						'type'         => 'input_file',
						'display_name' => 'report.pdf',
						'url'          => 'https://example.com/report.pdf',
					),
				),
			),
		);

		$result = $reflection->invoke( $client, $messages, array(), 'llama3' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'messages', $result );
		$this->assertNotEmpty( $result['messages'] );

		$message_content = $result['messages'][0]['content'];
		$this->assertStringContainsString( 'report.pdf', $message_content );
		$this->assertStringContainsString( 'https://example.com/report.pdf', $message_content );
	}
}
