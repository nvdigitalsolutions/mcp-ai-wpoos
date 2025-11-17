<?php
/**
 * Tests for WP_MCP_AI_LM_Studio_Client class.
 *
 * @package WP_MCP_AI
 */

/**
 * @group lm-studio-client
 */
class WP_MCP_AI_LM_Studio_Client_Tests extends WP_UnitTestCase {

	/**
	 * LM Studio client instance.
	 *
	 * @var WP_MCP_AI_LM_Studio_Client
	 */
	protected $client;

	public function setUp(): void {
		parent::setUp();

		$this->client = new WP_MCP_AI_LM_Studio_Client();

		// Clear settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
	}

	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Test get_endpoint_url with no configuration.
	 */
	public function test_get_endpoint_url_with_no_config() {
		$url = $this->client->get_endpoint_url();
		$this->assertEmpty( $url );
	}

	/**
	 * Test get_endpoint_url with configuration.
	 */
	public function test_get_endpoint_url_with_config() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
			)
		);

		$url = $this->client->get_endpoint_url();
		$this->assertEquals( 'http://localhost:1234', $url );
	}

	/**
	 * Test get_model with no configuration.
	 */
	public function test_get_model_with_no_config() {
		$model = $this->client->get_model();
		$this->assertEmpty( $model );
	}

	/**
	 * Test get_model with configuration.
	 */
	public function test_get_model_with_config() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_model' => 'llama-3-8b',
			)
		);

		$model = $this->client->get_model();
		$this->assertEquals( 'llama-3-8b', $model );
	}

	/**
	 * Test resolve_model falls back to default_model when lm_studio_model is empty.
	 *
	 * Since LM Studio implements OpenAI-compatible API, it should support
	 * the same model fallback behavior as OpenAI.
	 */
	public function test_resolve_model_falls_back_to_default_model() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'default_model' => 'gpt-4o',
			)
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'resolve_model' );
		$method->setAccessible( true );

		$model = $method->invoke( $this->client, array() );
		$this->assertEquals( 'gpt-4o', $model, 'Should fall back to default_model when lm_studio_model is not set' );
	}

	/**
	 * Test resolve_model prioritizes lm_studio_model over default_model.
	 */
	public function test_resolve_model_prioritizes_lm_studio_model() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_model' => 'llama-3-8b',
				'default_model'   => 'gpt-4o',
			)
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'resolve_model' );
		$method->setAccessible( true );

		$model = $method->invoke( $this->client, array() );
		$this->assertEquals( 'llama-3-8b', $model, 'Should prioritize lm_studio_model over default_model' );
	}

	/**
	 * Test resolve_model prioritizes options model over all settings.
	 */
	public function test_resolve_model_prioritizes_options_model() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_model' => 'llama-3-8b',
				'default_model'   => 'gpt-4o',
			)
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'resolve_model' );
		$method->setAccessible( true );

		$model = $method->invoke( $this->client, array( 'model' => 'custom-model' ) );
		$this->assertEquals( 'custom-model', $model, 'Should prioritize options[model] over all settings' );
	}

	/**
	 * Test wp_mcp_ai_lm_studio_fallback_model filter.
	 */
	public function test_lm_studio_fallback_model_filter() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'default_model' => 'gpt-4o',
			)
		);

		// Add filter to customize fallback model.
		add_filter(
			'wp_mcp_ai_lm_studio_fallback_model',
			function ( $fallback_model, $options ) {
				return 'custom-fallback-model';
			},
			10,
			2
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'resolve_model' );
		$method->setAccessible( true );

		$model = $method->invoke( $this->client, array() );
		$this->assertEquals( 'custom-fallback-model', $model, 'Filter should allow customizing fallback model' );

		// Clean up filter.
		remove_all_filters( 'wp_mcp_ai_lm_studio_fallback_model' );
	}

	/**
	 * Test wp_mcp_ai_lm_studio_fallback_model filter can disable fallback.
	 */
	public function test_lm_studio_fallback_model_filter_can_disable() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'default_model' => 'gpt-4o',
			)
		);

		// Add filter to disable fallback by returning empty string.
		add_filter(
			'wp_mcp_ai_lm_studio_fallback_model',
			function ( $fallback_model, $options ) {
				return '';
			},
			10,
			2
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'resolve_model' );
		$method->setAccessible( true );

		$model = $method->invoke( $this->client, array() );
		$this->assertEmpty( $model, 'Filter should allow disabling fallback by returning empty string' );

		// Clean up filter.
		remove_all_filters( 'wp_mcp_ai_lm_studio_fallback_model' );
	}

	/**
	 * Test test_connection with no endpoint configured.
	 */
	public function test_connection_with_no_endpoint() {
		$result = $this->client->test_connection();

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_lm_studio_endpoint', $result->get_error_code() );
	}

	/**
	 * Test list_models with no endpoint configured.
	 */
	public function test_list_models_with_no_endpoint() {
		$result = $this->client->list_models();

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_lm_studio_endpoint', $result->get_error_code() );
	}

	/**
	 * Test test_connection with HTTP error.
	 */
	public function test_connection_with_http_error() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
			)
		);

		// Mock HTTP error response.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'localhost:1234' ) !== false ) {
					return new WP_Error( 'http_request_failed', 'Connection refused' );
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->client->test_connection();

		$this->assertWPError( $result );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test test_connection with error status code.
	 */
	public function test_connection_with_error_status() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
			)
		);

		// Mock error response.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'localhost:1234' ) !== false ) {
					return array(
						'response' => array(
							'code'    => 500,
							'message' => 'Internal Server Error',
						),
						'body'     => '',
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->client->test_connection();

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_api_error', $result->get_error_code() );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test test_connection with success.
	 */
	public function test_connection_success() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
			)
		);

		// Mock successful response.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'localhost:1234' ) !== false ) {
					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode(
							array(
								'data' => array(
									array(
										'id'     => 'llama-3-8b',
										'object' => 'model',
									),
								),
							)
						),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->client->test_connection();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'message', $result );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test list_models with malformed JSON response.
	 */
	public function test_list_models_with_malformed_json() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
			)
		);

		// Mock response with invalid JSON.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'localhost:1234' ) !== false ) {
					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => 'not valid json',
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->client->list_models();

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_response', $result->get_error_code() );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test client can be instantiated.
	 */
	public function test_client_instantiation() {
		$client = new WP_MCP_AI_LM_Studio_Client();
		$this->assertInstanceOf( 'WP_MCP_AI_LM_Studio_Client', $client );
	}

	/**
	 * Test endpoint URL is properly formatted in requests.
	 */
	public function test_endpoint_url_formatting() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234/',
			)
		);

		// Verify URL building removes trailing slash.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				// URL should have /v1/models without double slashes.
				if ( strpos( $url, '//v1/models' ) !== false ) {
					$this->fail( 'URL contains double slashes' );
				}

				if ( strpos( $url, '/v1/models' ) !== false ) {
					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode( array( 'data' => array() ) ),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$this->client->test_connection();

		remove_all_filters( 'pre_http_request' );

		$this->assertTrue( true );
	}

	/**
	 * Test endpoint URL does NOT include /v1 in the base (to avoid /v1/v1/ paths).
	 *
	 * This test validates the fix for the bug where default endpoint was
	 * http://localhost:1234/v1 causing URLs like http://localhost:1234/v1/v1/models.
	 */
	public function test_endpoint_url_no_v1_suffix() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
			)
		);

		$captured_url = null;

		// Capture the actual URL being requested.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_url ) {
				if ( strpos( $url, 'localhost:1234' ) !== false ) {
					$captured_url = $url;
					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode( array( 'data' => array() ) ),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$this->client->test_connection();

		// Verify URL is correctly formed: http://localhost:1234/v1/models
		// NOT: http://localhost:1234/v1/v1/models
		$this->assertNotNull( $captured_url, 'URL should be captured' );
		$this->assertStringEndsWith( '/v1/models', $captured_url, 'URL should end with /v1/models' );
		$this->assertStringNotContainsString( '/v1/v1/', $captured_url, 'URL should NOT contain /v1/v1/ (double v1)' );
		$this->assertEquals( 'http://localhost:1234/v1/models', $captured_url, 'URL should be correctly constructed' );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test that chat completions use correct URL format.
	 */
	public function test_chat_completion_url_format() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
				'lm_studio_model'        => 'test-model',
			)
		);

		$captured_url = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_url ) {
				if ( strpos( $url, 'localhost:1234' ) !== false && strpos( $url, '/chat/completions' ) !== false ) {
					$captured_url = $url;
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'id'      => 'chatcmpl-123',
								'object'  => 'chat.completion',
								'created' => time(),
								'model'   => 'test-model',
								'choices' => array(
									array(
										'index'         => 0,
										'message'       => array(
											'role'    => 'assistant',
											'content' => 'Test response',
										),
										'finish_reason' => 'stop',
									),
								),
							)
						),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		$this->client->create_chat_completion( $messages, array() );

		// Verify correct URL format for chat completions.
		$this->assertNotNull( $captured_url, 'URL should be captured' );
		$this->assertStringEndsWith( '/v1/chat/completions', $captured_url, 'URL should end with /v1/chat/completions' );
		$this->assertStringNotContainsString( '/v1/v1/', $captured_url, 'URL should NOT contain /v1/v1/ (double v1)' );
		$this->assertEquals( 'http://localhost:1234/v1/chat/completions', $captured_url, 'URL should be correctly constructed' );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test client methods return proper types.
	 */
	public function test_method_return_types() {
		$endpoint = $this->client->get_endpoint_url();
		$model    = $this->client->get_model();

		$this->assertIsString( $endpoint );
		$this->assertIsString( $model );
	}

	/**
	 * Test settings are read correctly.
	 */
	public function test_settings_reading() {
		$test_endpoint = 'http://test-endpoint:8080';
		$test_model    = 'test-model';

		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => $test_endpoint,
				'lm_studio_model'        => $test_model,
				'other_setting'          => 'other_value',
			)
		);

		$this->assertEquals( $test_endpoint, $this->client->get_endpoint_url() );
		$this->assertEquals( $test_model, $this->client->get_model() );
	}

	/**
	 * Test create_completion with no endpoint configured.
	 */
	public function test_create_completion_with_no_endpoint() {
		$result = $this->client->create_completion( 'test prompt' );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_lm_studio_endpoint', $result->get_error_code() );
	}

	/**
	 * Test create_completion with no model configured.
	 */
	public function test_create_completion_with_no_model() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
			)
		);

		$result = $this->client->create_completion( 'test prompt' );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_lm_studio_model', $result->get_error_code() );
	}

	/**
	 * Test create_completion with empty prompt.
	 */
	public function test_create_completion_with_empty_prompt() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
				'lm_studio_model'        => 'test-model',
			)
		);

		$result = $this->client->create_completion( '' );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_prompt', $result->get_error_code() );
	}

	/**
	 * Test create_completion with successful response.
	 */
	public function test_create_completion_success() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
				'lm_studio_model'        => 'test-model',
			)
		);

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'localhost:1234' ) !== false && strpos( $url, '/v1/completions' ) !== false ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'id'      => 'cmpl-123',
								'object'  => 'text_completion',
								'created' => time(),
								'model'   => 'test-model',
								'choices' => array(
									array(
										'text'          => ' Paris',
										'index'         => 0,
										'logprobs'      => null,
										'finish_reason' => 'stop',
									),
								),
								'usage'   => array(
									'prompt_tokens'     => 5,
									'completion_tokens' => 2,
									'total_tokens'      => 7,
								),
							)
						),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->client->create_completion( 'The capital of France is' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertArrayHasKey( 'choices', $result );
		$this->assertEquals( 'test-model', $result['model'] );
		$this->assertEquals( ' Paris', $result['choices'][0]['text'] );
	}

	/**
	 * Test that test_connection uses configurable timeout instead of hardcoded value.
	 */
	public function test_connection_respects_timeout_settings() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
				'request_timeout'        => 60,
			)
		);

		// Track the actual timeout used in the request.
		$actual_timeout = null;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$actual_timeout ) {
				if ( strpos( $url, 'localhost:1234' ) !== false ) {
					$actual_timeout = isset( $args['timeout'] ) ? $args['timeout'] : null;
					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode( array( 'data' => array() ) ),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$this->client->test_connection();

		// Verify that the timeout was set to the configured value (or resource manager value).
		$this->assertNotNull( $actual_timeout, 'Timeout should be set in request args' );
		$this->assertGreaterThanOrEqual( 30, $actual_timeout, 'Timeout should be at least 30 seconds' );
		$this->assertNotEquals( 10, $actual_timeout, 'Timeout should not be the old hardcoded 10 seconds' );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test that list_models uses configurable timeout instead of hardcoded value.
	 */
	public function test_list_models_respects_timeout_settings() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
				'request_timeout'        => 60,
			)
		);

		// Track the actual timeout used in the request.
		$actual_timeout = null;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$actual_timeout ) {
				if ( strpos( $url, 'localhost:1234' ) !== false ) {
					$actual_timeout = isset( $args['timeout'] ) ? $args['timeout'] : null;
					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode( array( 'data' => array() ) ),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$this->client->list_models();

		// Verify that the timeout was set to the configured value (or resource manager value).
		$this->assertNotNull( $actual_timeout, 'Timeout should be set in request args' );
		$this->assertGreaterThanOrEqual( 30, $actual_timeout, 'Timeout should be at least 30 seconds' );
		$this->assertNotEquals( 10, $actual_timeout, 'Timeout should not be the old hardcoded 10 seconds' );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test that chat completions use a minimum timeout of 120 seconds.
	 */
	public function test_chat_completion_uses_minimum_120_second_timeout() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
				'lm_studio_model'        => 'test-model',
			)
		);

		$captured_args = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_args ) {
				if ( strpos( $url, 'localhost:1234' ) !== false && strpos( $url, '/v1/chat/completions' ) !== false ) {
					$captured_args = $args;
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'id'      => 'chatcmpl-123',
								'object'  => 'chat.completion',
								'created' => time(),
								'model'   => 'test-model',
								'choices' => array(
									array(
										'index'         => 0,
										'message'       => array(
											'role'    => 'assistant',
											'content' => 'Test response',
										),
										'finish_reason' => 'stop',
									),
								),
							)
						),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		$this->client->create_chat_completion( $messages, array() );

		// Verify timeout is at least 120 seconds for chat completions.
		$this->assertNotNull( $captured_args, 'Request args should be captured' );
		$this->assertArrayHasKey( 'timeout', $captured_args );
		$this->assertGreaterThanOrEqual( 120, $captured_args['timeout'] );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test that tools are properly passed to LM Studio API.
	 */
	public function test_chat_completion_with_tools() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
				'lm_studio_model'        => 'test-model',
			)
		);

		$captured_body = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_body ) {
				if ( strpos( $url, 'localhost:1234' ) !== false && strpos( $url, '/v1/chat/completions' ) !== false ) {
					// Capture the request body to verify tools are included.
					$captured_body = json_decode( $args['body'], true );

					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'id'      => 'chatcmpl-123',
								'object'  => 'chat.completion',
								'created' => time(),
								'model'   => 'test-model',
								'choices' => array(
									array(
										'index'         => 0,
										'message'       => array(
											'role'       => 'assistant',
											'content'    => null,
											'tool_calls' => array(
												array(
													'id'       => 'call_abc123',
													'type'     => 'function',
													'function' => array(
														'name'      => 'get_weather',
														'arguments' => '{"location":"San Francisco"}',
													),
												),
											),
										),
										'finish_reason' => 'tool_calls',
									),
								),
							)
						),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'What is the weather in San Francisco?',
			),
		);

		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'get_weather',
					'description' => 'Get the current weather in a location',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'location' => array(
								'type'        => 'string',
								'description' => 'The city and state, e.g. San Francisco, CA',
							),
						),
						'required'   => array( 'location' ),
					),
				),
			),
		);

		$result = $this->client->create_chat_completion( $messages, array( 'tools' => $tools ) );

		// Verify tools were included in the request.
		$this->assertNotNull( $captured_body, 'Request body should be captured' );
		$this->assertArrayHasKey( 'tools', $captured_body, 'Tools should be included in request' );
		$this->assertIsArray( $captured_body['tools'] );
		$this->assertCount( 1, $captured_body['tools'] );
		$this->assertEquals( 'get_weather', $captured_body['tools'][0]['function']['name'] );

		// Verify the response is properly normalized.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'choices', $result );
		$this->assertArrayHasKey( 'provider', $result );
		$this->assertEquals( 'lm_studio', $result['provider'] );

		// Verify tool_calls are preserved in the response.
		$this->assertArrayHasKey( 'tool_calls', $result['choices'][0]['message'] );
		$this->assertIsArray( $result['choices'][0]['message']['tool_calls'] );
		$this->assertCount( 1, $result['choices'][0]['message']['tool_calls'] );
		$this->assertEquals( 'get_weather', $result['choices'][0]['message']['tool_calls'][0]['function']['name'] );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test that tools with different formats are properly normalized.
	 */
	public function test_tools_normalization() {
		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'normalise_tools_for_payload' );
		$method->setAccessible( true );

		// Test standard OpenAI format.
		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'test_tool',
					'description' => 'A test tool',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(),
					),
				),
			),
		);

		$normalized = $method->invoke( $this->client, $tools );
		$this->assertIsArray( $normalized );
		$this->assertCount( 1, $normalized );
		$this->assertEquals( 'test_tool', $normalized[0]['name'] );

		// Test tool with slug instead of name.
		$tools = array(
			array(
				'type'     => 'function',
				'slug'     => 'slug_tool',
				'function' => array(
					'description' => 'A tool with slug',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(),
					),
				),
			),
		);

		$normalized = $method->invoke( $this->client, $tools );
		$this->assertIsArray( $normalized );
		$this->assertCount( 1, $normalized );
		$this->assertEquals( 'slug_tool', $normalized[0]['name'] );

		// Test empty tools array.
		$normalized = $method->invoke( $this->client, array() );
		$this->assertIsArray( $normalized );
		$this->assertCount( 0, $normalized );

		// Test invalid tool (no name or identifier).
		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'description' => 'A tool without name',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(),
					),
				),
			),
		);

		$normalized = $method->invoke( $this->client, $tools );
		$this->assertIsArray( $normalized );
		$this->assertCount( 0, $normalized, 'Tools without name should be filtered out' );
	}

	/**
	 * Test that tools parameter is optional.
	 */
	public function test_chat_completion_without_tools() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
				'lm_studio_model'        => 'test-model',
			)
		);

		$captured_body = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_body ) {
				if ( strpos( $url, 'localhost:1234' ) !== false && strpos( $url, '/v1/chat/completions' ) !== false ) {
					$captured_body = json_decode( $args['body'], true );

					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'id'      => 'chatcmpl-123',
								'object'  => 'chat.completion',
								'created' => time(),
								'model'   => 'test-model',
								'choices' => array(
									array(
										'index'         => 0,
										'message'       => array(
											'role'    => 'assistant',
											'content' => 'Hello!',
										),
										'finish_reason' => 'stop',
									),
								),
							)
						),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		$result = $this->client->create_chat_completion( $messages, array() );

		// Verify tools are not included when not provided.
		$this->assertNotNull( $captured_body, 'Request body should be captured' );
		$this->assertArrayNotHasKey( 'tools', $captured_body, 'Tools should not be in request when not provided' );

		// Verify response is still properly handled.
		$this->assertIsArray( $result );
		$this->assertEquals( 'lm_studio', $result['provider'] );

		remove_all_filters( 'pre_http_request' );
	}
}
