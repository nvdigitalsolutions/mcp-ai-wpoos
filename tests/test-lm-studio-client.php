<?php
/**
 * Tests for WP_MCP_AI_LM_Studio_Client class.
 *
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

		// Verify URL is correctly formed: http://localhost:1234/v1/models.
		// NOT: http://localhost:1234/v1/v1/models.
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
	 * Test that stream parameter is explicitly set to false in chat completion payloads.
	 *
	 * This ensures LM Studio doesn't default to streaming mode which could cause delays.
	 */
	public function test_chat_completion_has_stream_false_in_payload() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
				'lm_studio_model'        => 'test-model',
			)
		);

		$captured_args = null;

		// Intercept wp_remote_post to capture request args.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'id'      => 'test-id',
							'choices' => array(
								array(
									'message' => array(
										'role'    => 'assistant',
										'content' => 'Test response',
									),
								),
							),
						)
					),
				);
			},
			10,
			3
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Test message',
			),
		);

		$this->client->create_chat_completion( $messages, array() );

		// Verify the payload contains stream: false.
		$this->assertNotNull( $captured_args, 'Request args should be captured' );
		$this->assertArrayHasKey( 'body', $captured_args );
		$payload = json_decode( $captured_args['body'], true );
		$this->assertIsArray( $payload, 'Payload should be valid JSON' );
		$this->assertArrayHasKey( 'stream', $payload, 'Payload should contain stream parameter' );
		$this->assertFalse( $payload['stream'], 'Stream parameter should be false' );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test that stream parameter is explicitly set to false in text completion payloads.
	 */
	public function test_text_completion_has_stream_false_in_payload() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
				'lm_studio_model'        => 'test-model',
			)
		);

		$captured_args = null;

		// Intercept wp_remote_post to capture request args.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'id'      => 'test-id',
							'choices' => array(
								array(
									'text' => 'Test response',
								),
							),
						)
					),
				);
			},
			10,
			3
		);

		$this->client->create_completion( 'Test prompt', array() );

		// Verify the payload contains stream: false.
		$this->assertNotNull( $captured_args, 'Request args should be captured' );
		$this->assertArrayHasKey( 'body', $captured_args );
		$payload = json_decode( $captured_args['body'], true );
		$this->assertIsArray( $payload, 'Payload should be valid JSON' );
		$this->assertArrayHasKey( 'stream', $payload, 'Payload should contain stream parameter' );
		$this->assertFalse( $payload['stream'], 'Stream parameter should be false' );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test normalise_tools_for_payload method.
	 */
	public function test_normalise_tools_for_payload() {
		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'get_weather',
					'description' => 'Get weather information',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'location' => array(
								'type'        => 'string',
								'description' => 'The city name',
							),
						),
					),
				),
			),
			array(
				'slug' => 'send_email',
				'type' => 'function',
			),
		);

		// Use reflection to test protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'normalise_tools_for_payload' );
		$method->setAccessible( true );

		$normalised = $method->invoke( $this->client, $tools );

		$this->assertIsArray( $normalised );
		$this->assertCount( 2, $normalised );
		$this->assertEquals( 'get_weather', $normalised[0]['name'] );
		$this->assertEquals( 'send_email', $normalised[1]['name'] );
	}

	/**
	 * Test chat completion with tools includes them in payload.
	 */
	public function test_create_chat_completion_with_tools() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
				'lm_studio_model'        => 'qwen/qwen3-coder-30b',
			)
		);

		$captured_args = null;

		// Intercept wp_remote_post to capture request args.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'id'      => 'test-id',
							'choices' => array(
								array(
									'message' => array(
										'role'    => 'assistant',
										'content' => 'Test response',
									),
								),
							),
						)
					),
				);
			},
			10,
			3
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'What is the weather?',
			),
		);

		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'get_weather',
					'description' => 'Get weather information',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'location' => array(
								'type'        => 'string',
								'description' => 'The city name',
							),
						),
					),
				),
			),
		);

		$this->client->create_chat_completion( $messages, array( 'tools' => $tools ) );

		// Verify the payload contains tools.
		$this->assertNotNull( $captured_args, 'Request args should be captured' );
		$this->assertArrayHasKey( 'body', $captured_args );
		$payload = json_decode( $captured_args['body'], true );
		$this->assertIsArray( $payload, 'Payload should be valid JSON' );
		$this->assertArrayHasKey( 'tools', $payload, 'Payload should contain tools parameter' );
		$this->assertIsArray( $payload['tools'] );
		$this->assertCount( 1, $payload['tools'] );
		$this->assertEquals( 'get_weather', $payload['tools'][0]['name'] );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test that streaming is disabled when tools are present.
	 */
	public function test_streaming_disabled_with_tools() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
				'lm_studio_model'        => 'qwen/qwen3-coder-30b',
			)
		);

		$captured_args = null;

		// Intercept wp_remote_post to capture request args.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'id'      => 'test-id',
							'choices' => array(
								array(
									'message' => array(
										'role'    => 'assistant',
										'content' => 'Test response',
									),
								),
							),
						)
					),
				);
			},
			10,
			3
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'What is the weather?',
			),
		);

		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name' => 'get_weather',
				),
			),
		);

		$this->client->create_chat_completion( $messages, array( 'tools' => $tools ) );

		// Verify stream is false.
		$this->assertNotNull( $captured_args, 'Request args should be captured' );
		$this->assertArrayHasKey( 'body', $captured_args );
		$payload = json_decode( $captured_args['body'], true );
		$this->assertIsArray( $payload, 'Payload should be valid JSON' );
		$this->assertArrayHasKey( 'stream', $payload, 'Payload should contain stream parameter' );
		$this->assertFalse( $payload['stream'], 'Stream should be false when tools are present' );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test that tool role messages are preserved with tool_call_id when tools are present.
	 */
	public function test_tool_messages_preserved_with_tools() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
				'lm_studio_model'        => 'qwen/qwen3-coder-30b',
			)
		);

		$captured_args = null;

		// Intercept wp_remote_post to capture request args.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'id'      => 'test-id',
							'choices' => array(
								array(
									'message' => array(
										'role'    => 'assistant',
										'content' => 'The weather is sunny',
									),
								),
							),
						)
					),
				);
			},
			10,
			3
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'What is the weather?',
			),
			array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => array(
					array(
						'id'       => 'call_123',
						'type'     => 'function',
						'function' => array(
							'name'      => 'get_weather',
							'arguments' => '{"location":"San Francisco"}',
						),
					),
				),
			),
			array(
				'role'         => 'tool',
				'content'      => 'Sunny, 72F',
				'tool_call_id' => 'call_123',
				'name'         => 'get_weather',
			),
		);

		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name' => 'get_weather',
				),
			),
		);

		$this->client->create_chat_completion( $messages, array( 'tools' => $tools ) );

		// Verify the messages preserve tool structure.
		$this->assertNotNull( $captured_args, 'Request args should be captured' );
		$this->assertArrayHasKey( 'body', $captured_args );
		$payload = json_decode( $captured_args['body'], true );
		$this->assertIsArray( $payload, 'Payload should be valid JSON' );
		$this->assertArrayHasKey( 'messages', $payload );
		$this->assertCount( 3, $payload['messages'] );

		// Check assistant message has tool_calls.
		$this->assertEquals( 'assistant', $payload['messages'][1]['role'] );
		$this->assertArrayHasKey( 'tool_calls', $payload['messages'][1] );
		$this->assertEquals( 'call_123', $payload['messages'][1]['tool_calls'][0]['id'] );

		// Check tool message has tool_call_id.
		$this->assertEquals( 'tool', $payload['messages'][2]['role'] );
		$this->assertEquals( 'call_123', $payload['messages'][2]['tool_call_id'] );
		$this->assertEquals( 'get_weather', $payload['messages'][2]['name'] );
		$this->assertEquals( 'Sunny, 72F', $payload['messages'][2]['content'] );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test that tool messages are converted to user messages when tools are NOT provided.
	 * This ensures backward compatibility when replaying conversations with tool history
	 * but without providing the tools option.
	 */
	public function test_tool_messages_converted_to_user_without_tools() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
				'lm_studio_model'        => 'qwen/qwen3-coder-30b',
			)
		);

		$captured_args = null;

		// Intercept wp_remote_post to capture request args.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'id'      => 'test-id',
							'choices' => array(
								array(
									'message' => array(
										'role'    => 'assistant',
										'content' => 'The weather is sunny',
									),
								),
							),
						)
					),
				);
			},
			10,
			3
		);

		// Conversation history with tool messages but NO tools option provided.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'What is the weather?',
			),
			array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => array(
					array(
						'id'       => 'call_123',
						'type'     => 'function',
						'function' => array(
							'name'      => 'get_weather',
							'arguments' => '{"location":"San Francisco"}',
						),
					),
				),
			),
			array(
				'role'         => 'tool',
				'content'      => 'Sunny, 72F',
				'tool_call_id' => 'call_123',
				'name'         => 'get_weather',
			),
			array(
				'role'    => 'user',
				'content' => 'Thanks!',
			),
		);

		// Note: NO tools option provided - this simulates replaying saved conversation.
		$this->client->create_chat_completion( $messages, array() );

		// Verify the payload converts tool message to user message.
		$this->assertNotNull( $captured_args, 'Request args should be captured' );
		$this->assertArrayHasKey( 'body', $captured_args );
		$payload = json_decode( $captured_args['body'], true );
		$this->assertIsArray( $payload, 'Payload should be valid JSON' );
		$this->assertArrayHasKey( 'messages', $payload );

		// The tool message should be converted to user message.
		$tool_message_found = false;
		foreach ( $payload['messages'] as $msg ) {
			if ( isset( $msg['content'] ) && false !== strpos( $msg['content'], '[Tool get_weather]' ) ) {
				$this->assertEquals( 'user', $msg['role'], 'Tool message should be converted to user role' );
				$this->assertStringContainsString( 'Sunny, 72F', $msg['content'], 'Tool content should be preserved' );
				$this->assertArrayNotHasKey( 'tool_call_id', $msg, 'Should not have tool_call_id when converted to user' );
				$tool_message_found = true;
				break;
			}
		}

		$this->assertTrue( $tool_message_found, 'Tool message should be found and converted to user message' );

		// Verify no tools in payload.
		$this->assertArrayNotHasKey( 'tools', $payload, 'Payload should not contain tools when not provided' );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test that system_prompt is prepended to messages when provided in options.
	 *
	 * This ensures assistant knowledge and instructions are passed to LM Studio.
	 */
	public function test_system_prompt_prepended_to_messages() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
				'lm_studio_model'        => 'llama-3-8b',
			)
		);

		$captured_args = null;

		// Intercept wp_remote_post to capture request args.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'id'      => 'test-id',
							'choices' => array(
								array(
									'message' => array(
										'role'    => 'assistant',
										'content' => 'Test response',
									),
								),
							),
						)
					),
				);
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

		$system_prompt = 'You are a helpful AI assistant with expertise in WordPress development.';

		$this->client->create_chat_completion( $messages, array( 'system_prompt' => $system_prompt ) );

		// Verify the payload contains system message.
		$this->assertNotNull( $captured_args, 'Request args should be captured' );
		$this->assertArrayHasKey( 'body', $captured_args );
		$payload = json_decode( $captured_args['body'], true );
		$this->assertIsArray( $payload, 'Payload should be valid JSON' );
		$this->assertArrayHasKey( 'messages', $payload );
		$this->assertGreaterThanOrEqual( 2, count( $payload['messages'] ), 'Should have at least system and user message' );

		// First message should be the system message.
		$first_message = $payload['messages'][0];
		$this->assertEquals( 'system', $first_message['role'], 'First message should be system role' );
		$this->assertEquals( $system_prompt, $first_message['content'], 'System message content should match system_prompt option' );

		// Second message should be the user message.
		$second_message = $payload['messages'][1];
		$this->assertEquals( 'user', $second_message['role'], 'Second message should be user role' );
		$this->assertEquals( 'Hello', $second_message['content'], 'User message should be preserved' );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test that system_prompt works together with tools.
	 */
	public function test_system_prompt_with_tools() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
				'lm_studio_model'        => 'qwen/qwen3-coder-30b',
			)
		);

		$captured_args = null;

		// Intercept wp_remote_post to capture request args.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'id'      => 'test-id',
							'choices' => array(
								array(
									'message' => array(
										'role'       => 'assistant',
										'content'    => '',
										'tool_calls' => array(
											array(
												'id'       => 'call_123',
												'type'     => 'function',
												'function' => array(
													'name' => 'get_weather',
													'arguments' => '{"location":"San Francisco"}',
												),
											),
										),
									),
								),
							),
						)
					),
				);
			},
			10,
			3
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'What is the weather?',
			),
		);

		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'get_weather',
					'description' => 'Get current weather information',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'location' => array(
								'type'        => 'string',
								'description' => 'The city name',
							),
						),
					),
				),
			),
		);

		$system_prompt = 'You are a weather expert assistant. Always use the get_weather tool when asked about weather.';

		$this->client->create_chat_completion(
			$messages,
			array(
				'system_prompt' => $system_prompt,
				'tools'         => $tools,
			)
		);

		// Verify the payload contains both system message and tools.
		$this->assertNotNull( $captured_args, 'Request args should be captured' );
		$this->assertArrayHasKey( 'body', $captured_args );
		$payload = json_decode( $captured_args['body'], true );
		$this->assertIsArray( $payload, 'Payload should be valid JSON' );

		// Check system message is first.
		$this->assertArrayHasKey( 'messages', $payload );
		$this->assertGreaterThanOrEqual( 2, count( $payload['messages'] ) );
		$this->assertEquals( 'system', $payload['messages'][0]['role'], 'First message should be system' );
		$this->assertEquals( $system_prompt, $payload['messages'][0]['content'], 'System prompt should match' );

		// Check tools are included.
		$this->assertArrayHasKey( 'tools', $payload, 'Payload should contain tools' );
		$this->assertIsArray( $payload['tools'] );
		$this->assertCount( 1, $payload['tools'] );
		$this->assertEquals( 'get_weather', $payload['tools'][0]['name'] );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test that empty system_prompt is not added to messages.
	 */
	public function test_empty_system_prompt_not_added() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
				'lm_studio_model'        => 'llama-3-8b',
			)
		);

		$captured_args = null;

		// Intercept wp_remote_post to capture request args.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'id'      => 'test-id',
							'choices' => array(
								array(
									'message' => array(
										'role'    => 'assistant',
										'content' => 'Test response',
									),
								),
							),
						)
					),
				);
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

		// Test with empty string system_prompt.
		$this->client->create_chat_completion( $messages, array( 'system_prompt' => '' ) );

		$this->assertNotNull( $captured_args, 'Request args should be captured' );
		$this->assertArrayHasKey( 'body', $captured_args );
		$payload = json_decode( $captured_args['body'], true );
		$this->assertIsArray( $payload, 'Payload should be valid JSON' );
		$this->assertArrayHasKey( 'messages', $payload );

		// Should only have the user message, no system message.
		$this->assertCount( 1, $payload['messages'], 'Should only have user message when system_prompt is empty' );
		$this->assertEquals( 'user', $payload['messages'][0]['role'], 'Only message should be user role' );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test that memory_documents are added as system messages.
	 */
	public function test_memory_documents_added_as_system_messages() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
				'lm_studio_model'        => 'llama-3-8b',
			)
		);

		$captured_args = null;

		// Intercept wp_remote_post to capture request args.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'id'      => 'test-id',
							'choices' => array(
								array(
									'message' => array(
										'role'    => 'assistant',
										'content' => 'Test response',
									),
								),
							),
						)
					),
				);
			},
			10,
			3
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'What is WordPress?',
			),
		);

		$memory_documents = array(
			array(
				'title'  => 'WordPress Documentation',
				'chunks' => array(
					'WordPress is a free and open-source content management system.',
					'It is written in PHP and paired with a MySQL or MariaDB database.',
				),
			),
			array(
				'title'  => 'Plugin Guide',
				'chunks' => array(
					'Plugins extend and expand the functionality of WordPress.',
				),
			),
		);

		$this->client->create_chat_completion(
			$messages,
			array( 'memory_documents' => $memory_documents )
		);

		// Verify the payload contains memory documents as system messages.
		$this->assertNotNull( $captured_args, 'Request args should be captured' );
		$this->assertArrayHasKey( 'body', $captured_args );
		$payload = json_decode( $captured_args['body'], true );
		$this->assertIsArray( $payload, 'Payload should be valid JSON' );
		$this->assertArrayHasKey( 'messages', $payload );

		// Should have 3 system messages (memory docs) + 1 user message = 4 total.
		$this->assertCount( 4, $payload['messages'], 'Should have memory docs as system messages plus user message' );

		// First three should be system messages with memory content.
		$this->assertEquals( 'system', $payload['messages'][0]['role'], 'First message should be system (memory)' );
		$this->assertStringContainsString( 'WordPress Documentation (Part 1)', $payload['messages'][0]['content'] );
		$this->assertStringContainsString( 'free and open-source', $payload['messages'][0]['content'] );

		$this->assertEquals( 'system', $payload['messages'][1]['role'], 'Second message should be system (memory)' );
		$this->assertStringContainsString( 'WordPress Documentation (Part 2)', $payload['messages'][1]['content'] );
		$this->assertStringContainsString( 'PHP', $payload['messages'][1]['content'] );

		$this->assertEquals( 'system', $payload['messages'][2]['role'], 'Third message should be system (memory)' );
		$this->assertStringContainsString( 'Plugin Guide', $payload['messages'][2]['content'] );
		$this->assertStringContainsString( 'Plugins extend', $payload['messages'][2]['content'] );

		// Last should be user message.
		$this->assertEquals( 'user', $payload['messages'][3]['role'], 'Last message should be user' );
		$this->assertEquals( 'What is WordPress?', $payload['messages'][3]['content'] );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test that system_prompt and memory_documents work together.
	 */
	public function test_system_prompt_and_memory_documents_together() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'lm_studio_endpoint_url' => 'http://localhost:1234',
				'lm_studio_model'        => 'llama-3-8b',
			)
		);

		$captured_args = null;

		// Intercept wp_remote_post to capture request args.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'id'      => 'test-id',
							'choices' => array(
								array(
									'message' => array(
										'role'    => 'assistant',
										'content' => 'Test response',
									),
								),
							),
						)
					),
				);
			},
			10,
			3
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Help me with WordPress',
			),
		);

		$system_prompt = 'You are a WordPress expert assistant.';

		$memory_documents = array(
			array(
				'title'  => 'WordPress Basics',
				'chunks' => array(
					'WordPress powers over 40% of all websites.',
				),
			),
		);

		$this->client->create_chat_completion(
			$messages,
			array(
				'system_prompt'    => $system_prompt,
				'memory_documents' => $memory_documents,
			)
		);

		// Verify both system_prompt and memory_documents are included.
		$this->assertNotNull( $captured_args, 'Request args should be captured' );
		$this->assertArrayHasKey( 'body', $captured_args );
		$payload = json_decode( $captured_args['body'], true );
		$this->assertIsArray( $payload, 'Payload should be valid JSON' );
		$this->assertArrayHasKey( 'messages', $payload );

		// Should have: system_prompt + memory doc + user message = 3 messages.
		$this->assertCount( 3, $payload['messages'], 'Should have system prompt, memory doc, and user message' );

		// First should be system_prompt.
		$this->assertEquals( 'system', $payload['messages'][0]['role'], 'First should be system prompt' );
		$this->assertEquals( $system_prompt, $payload['messages'][0]['content'], 'Should contain system prompt' );

		// Second should be memory document.
		$this->assertEquals( 'system', $payload['messages'][1]['role'], 'Second should be memory doc' );
		$this->assertStringContainsString( 'WordPress Basics', $payload['messages'][1]['content'] );
		$this->assertStringContainsString( '40% of all websites', $payload['messages'][1]['content'] );

		// Third should be user message.
		$this->assertEquals( 'user', $payload['messages'][2]['role'], 'Third should be user' );

		remove_all_filters( 'pre_http_request' );
	}
}
