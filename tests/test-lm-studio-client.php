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
}
