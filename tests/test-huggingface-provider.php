<?php
/**
 * Tests for Hugging Face provider functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Hugging Face provider integration.
 */
class WP_MCP_AI_Huggingface_Provider_Test extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure the Hugging Face client class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Huggingface_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-huggingface-client.php';
		}

		// Ensure the diagnostic class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Provider_Diagnostics' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-provider-diagnostics.php';
		}

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that the Hugging Face client class exists and can be instantiated.
	 */
	public function test_huggingface_client_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Huggingface_Client' ), 'Hugging Face client class should exist' );

		$client = new WP_MCP_AI_Huggingface_Client();
		$this->assertInstanceOf( 'WP_MCP_AI_Huggingface_Client', $client );
	}

	/**
	 * Test that Hugging Face client requires API key for chat completion.
	 */
	public function test_chat_completion_requires_api_key() {
		// Ensure API key is not set.
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );

		$client   = new WP_MCP_AI_Huggingface_Client();
		$response = $client->create_chat_completion( array(), array() );

		$this->assertWPError( $response, 'Should return WP_Error when API key is missing' );
		$this->assertSame( 'wp_mcp_ai_missing_huggingface_api_key', $response->get_error_code() );

		$data = $response->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 400, $data['status'] );
		$this->assertArrayHasKey( 'actions', $data );
	}

	/**
	 * Test that Hugging Face client requires endpoint URL for chat completion.
	 */
	public function test_chat_completion_requires_endpoint_url() {
		$settings                             = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['huggingface_api_key']      = 'hf_test_key_12345';
		$settings['huggingface_endpoint_url'] = '';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client   = new WP_MCP_AI_Huggingface_Client();
		$response = $client->create_chat_completion( array(), array() );

		$this->assertWPError( $response, 'Should return WP_Error when endpoint URL is missing' );
		$this->assertSame( 'wp_mcp_ai_missing_huggingface_endpoint', $response->get_error_code() );
	}

	/**
	 * Test that Hugging Face client requires model for chat completion.
	 */
	public function test_chat_completion_requires_model() {
		$settings                             = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['huggingface_api_key']      = 'hf_test_key_12345';
		$settings['huggingface_endpoint_url'] = 'https://router.huggingface.co/v1';
		$settings['huggingface_model']        = '';
		$settings['default_model']            = '';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client   = new WP_MCP_AI_Huggingface_Client();
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);
		$response = $client->create_chat_completion( $messages, array() );

		$this->assertWPError( $response, 'Should return WP_Error when model is not configured' );
		$this->assertSame( 'wp_mcp_ai_missing_huggingface_model', $response->get_error_code() );
	}

	/**
	 * Test that Hugging Face client test_connection requires API key.
	 */
	public function test_connection_requires_api_key() {
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );

		$client = new WP_MCP_AI_Huggingface_Client();
		$result = $client->test_connection();

		$this->assertWPError( $result, 'Should return WP_Error when API key is missing' );
		$this->assertSame( 'wp_mcp_ai_missing_huggingface_api_key', $result->get_error_code() );
	}

	/**
	 * Test that Hugging Face client test_connection requires endpoint URL.
	 */
	public function test_connection_requires_endpoint_url() {
		$settings                             = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['huggingface_api_key']      = 'hf_test_key_12345';
		$settings['huggingface_endpoint_url'] = '';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client = new WP_MCP_AI_Huggingface_Client();
		$result = $client->test_connection();

		$this->assertWPError( $result, 'Should return WP_Error when endpoint URL is missing' );
		$this->assertSame( 'wp_mcp_ai_missing_huggingface_endpoint', $result->get_error_code() );
	}

	/**
	 * Test that Hugging Face client list_models requires API key.
	 */
	public function test_list_models_requires_api_key() {
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );

		$client = new WP_MCP_AI_Huggingface_Client();
		$result = $client->list_models();

		$this->assertWPError( $result, 'Should return WP_Error when API key is missing' );
		$this->assertSame( 'wp_mcp_ai_missing_huggingface_api_key', $result->get_error_code() );
	}

	/**
	 * Test that Hugging Face client list_models requires endpoint URL.
	 */
	public function test_list_models_requires_endpoint_url() {
		$settings                             = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['huggingface_api_key']      = 'hf_test_key_12345';
		$settings['huggingface_endpoint_url'] = '';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client = new WP_MCP_AI_Huggingface_Client();
		$result = $client->list_models();

		$this->assertWPError( $result, 'Should return WP_Error when endpoint URL is missing' );
		$this->assertSame( 'wp_mcp_ai_missing_huggingface_endpoint', $result->get_error_code() );
	}

	/**
	 * Test Hugging Face provider diagnostic AJAX test without API key.
	 */
	public function test_diagnostic_ajax_without_api_key() {
		// Ensure Hugging Face API key is not set.
		$settings = WP_MCP_AI_Admin_Settings::get_default_settings();
		unset( $settings['huggingface_api_key'] );
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Simulate AJAX request.
		$_POST['action']   = 'wp_mcp_ai_test_provider';
		$_POST['nonce']    = wp_create_nonce( 'wp-mcp-ai-provider-diagnostic' );
		$_POST['provider'] = 'huggingface';

		try {
			$this->_handleAjax( 'wp_mcp_ai_test_provider' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Expected exception.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'], 'Hugging Face test without API key should fail' );
		$this->assertStringContainsString( 'not configured', $response['data']['message'], 'Error message should mention configuration' );
	}

	/**
	 * Test Hugging Face provider diagnostic AJAX test without endpoint URL.
	 */
	public function test_diagnostic_ajax_without_endpoint_url() {
		// Ensure Hugging Face endpoint URL is not set.
		$settings                             = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['huggingface_api_key']      = 'hf_test_key_12345';
		$settings['huggingface_endpoint_url'] = '';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Simulate AJAX request.
		$_POST['action']   = 'wp_mcp_ai_test_provider';
		$_POST['nonce']    = wp_create_nonce( 'wp-mcp-ai-provider-diagnostic' );
		$_POST['provider'] = 'huggingface';

		try {
			$this->_handleAjax( 'wp_mcp_ai_test_provider' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Expected exception.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'], 'Hugging Face test without endpoint URL should fail' );
		$this->assertStringContainsString( 'not configured', $response['data']['message'], 'Error message should mention configuration' );
	}

	/**
	 * Test that Hugging Face is recognized as a valid provider in the diagnostic handler.
	 */
	public function test_huggingface_is_recognized_provider() {
		$settings                             = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['huggingface_api_key']      = 'hf_test_key_12345';
		$settings['huggingface_endpoint_url'] = 'https://router.huggingface.co/v1';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Simulate AJAX request.
		$_POST['action']   = 'wp_mcp_ai_test_provider';
		$_POST['nonce']    = wp_create_nonce( 'wp-mcp-ai-provider-diagnostic' );
		$_POST['provider'] = 'huggingface';

		// Mock the HTTP request to prevent actual API calls.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				// Return a mocked successful response for the models endpoint.
				if ( strpos( $url, '/models' ) !== false ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'data' => array(
									array( 'id' => 'meta-llama/Llama-3.3-70B-Instruct' ),
									array( 'id' => 'mistralai/Mistral-7B-Instruct-v0.3' ),
								),
							)
						),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		try {
			$this->_handleAjax( 'wp_mcp_ai_test_provider' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Expected exception.
		}

		$response = json_decode( $this->_last_response, true );

		// The test should succeed (not return "Unknown provider" error).
		$this->assertTrue( $response['success'], 'Hugging Face should be recognized as a valid provider' );
		$this->assertStringContainsString( 'successful', $response['data']['message'], 'Success message should indicate connection success' );
	}

	/**
	 * Test that Hugging Face client uses configured model.
	 */
	public function test_uses_configured_model() {
		$settings                             = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['huggingface_api_key']      = 'hf_test_key_12345';
		$settings['huggingface_endpoint_url'] = 'https://router.huggingface.co/v1';
		$settings['huggingface_model']        = 'meta-llama/Llama-3.3-70B-Instruct';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client           = new WP_MCP_AI_Huggingface_Client();
		$captured_request = null;

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			if ( strpos( $url, '/chat/completions' ) !== false ) {
				$captured_request = array(
					'args' => $args,
					'url'  => $url,
				);

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'choices' => array(
								array(
									'message' => array(
										'role'    => 'assistant',
										'content' => 'Hello from Hugging Face',
									),
								),
							),
							'usage'   => array(
								'prompt_tokens'     => 10,
								'completion_tokens' => 20,
							),
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

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		$response = $client->create_chat_completion( $messages, array() );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'choices', $response );
		$this->assertSame( 'huggingface', $response['provider'] );

		$this->assertNotNull( $captured_request, 'HTTP request should have been made' );
		$this->assertArrayHasKey( 'body', $captured_request['args'] );

		$payload = json_decode( $captured_request['args']['body'], true );
		$this->assertArrayHasKey( 'model', $payload );
		$this->assertSame( 'meta-llama/Llama-3.3-70B-Instruct', $payload['model'] );
	}

	/**
	 * Test that Hugging Face client falls back to default model when specific model not set.
	 */
	public function test_falls_back_to_default_model() {
		$settings                             = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['huggingface_api_key']      = 'hf_test_key_12345';
		$settings['huggingface_endpoint_url'] = 'https://router.huggingface.co/v1';
		$settings['huggingface_model']        = '';
		$settings['default_model']            = 'gpt-4o-mini'; // Fallback model.
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client           = new WP_MCP_AI_Huggingface_Client();
		$captured_request = null;

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			if ( strpos( $url, '/chat/completions' ) !== false ) {
				$captured_request = array(
					'args' => $args,
					'url'  => $url,
				);

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'choices' => array(
								array(
									'message' => array(
										'role'    => 'assistant',
										'content' => 'Hello',
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
			}
			return $preempt;
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
		$this->assertNotNull( $captured_request );

		$payload = json_decode( $captured_request['args']['body'], true );
		$this->assertArrayHasKey( 'model', $payload );
		$this->assertSame( 'gpt-4o-mini', $payload['model'], 'Should fall back to default_model when huggingface_model is not set' );
	}

	/**
	 * Test that the correct endpoint URL is used.
	 */
	public function test_uses_correct_endpoint_url() {
		$settings                             = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['huggingface_api_key']      = 'hf_test_key_12345';
		$settings['huggingface_endpoint_url'] = 'https://router.huggingface.co/v1';
		$settings['huggingface_model']        = 'test-model';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client           = new WP_MCP_AI_Huggingface_Client();
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
						'choices' => array(
							array(
								'message' => array(
									'role'    => 'assistant',
									'content' => 'Test',
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
				'content' => 'Test',
			),
		);

		$client->create_chat_completion( $messages, array() );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotNull( $captured_request );
		$this->assertStringContainsString( 'https://router.huggingface.co/v1/chat/completions', $captured_request['url'], 'Should use the correct base URL for chat completions' );
	}
}
