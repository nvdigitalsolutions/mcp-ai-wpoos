<?php
/**
 * Tests for the Ollama client wrapper.
 *
 * @package WP_MCP_AI
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
		$this->assertArrayHasKey( 'actions', $data );
		$this->assertArrayHasKey( 'configure_ollama_endpoint', $data['actions'] );
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
		$this->assertArrayHasKey( 'actions', $data );
		$this->assertArrayHasKey( 'configure_ollama_model', $data['actions'] );
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
}
