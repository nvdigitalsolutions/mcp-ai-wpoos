<?php
/**
 * Tests for WP_MCP_AI_Huggingface_Client class.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for huggingface client tests.
 *
 * @group huggingface-client
 */
class WP_MCP_AI_Huggingface_Client_Tests extends WP_UnitTestCase {

	/**
	 * Hugging Face client instance.
	 *
	 * @var WP_MCP_AI_Huggingface_Client
	 */
	protected $client;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->client = new WP_MCP_AI_Huggingface_Client();

		// Clear settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Test get_api_key with no configuration.
	 */
	public function test_get_api_key_with_no_config() {
		$api_key = $this->client->get_api_key();
		$this->assertEmpty( $api_key );
	}

	/**
	 * Test get_api_key with configuration.
	 */
	public function test_get_api_key_with_config() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'huggingface_api_key' => 'hf_test123',
			)
		);

		$api_key = $this->client->get_api_key();
		$this->assertEquals( 'hf_test123', $api_key );
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
				'huggingface_endpoint_url' => 'https://api-inference.huggingface.co/v1',
			)
		);

		$url = $this->client->get_endpoint_url();
		$this->assertEquals( 'https://api-inference.huggingface.co/v1', $url );
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
				'huggingface_model' => 'meta-llama/Llama-3.3-70B-Instruct',
			)
		);

		$model = $this->client->get_model();
		$this->assertEquals( 'meta-llama/Llama-3.3-70B-Instruct', $model );
	}

	/**
	 * Test resolve_model falls back to default_model when huggingface_model is empty.
	 *
	 * Since Hugging Face implements OpenAI-compatible API, it should support
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
		$this->assertEquals( 'gpt-4o', $model, 'Should fall back to default_model when huggingface_model is not set' );
	}

	/**
	 * Test resolve_model uses options model when provided.
	 */
	public function test_resolve_model_uses_options_model() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'huggingface_model' => 'meta-llama/Llama-3.3-70B-Instruct',
				'default_model'     => 'gpt-4o',
			)
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'resolve_model' );
		$method->setAccessible( true );

		$model = $method->invoke( $this->client, array( 'model' => 'mistralai/Mistral-7B-Instruct-v0.3' ) );
		$this->assertEquals( 'mistralai/Mistral-7B-Instruct-v0.3', $model, 'Should use model from options when provided' );
	}

	/**
	 * Test resolve_model uses huggingface_model setting.
	 */
	public function test_resolve_model_uses_huggingface_model_setting() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'huggingface_model' => 'meta-llama/Llama-3.3-70B-Instruct',
				'default_model'     => 'gpt-4o',
			)
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'resolve_model' );
		$method->setAccessible( true );

		$model = $method->invoke( $this->client, array() );
		$this->assertEquals( 'meta-llama/Llama-3.3-70B-Instruct', $model, 'Should use huggingface_model setting when available' );
	}

	/**
	 * Test create_chat_completion returns error when API key is missing.
	 */
	public function test_create_chat_completion_returns_error_without_api_key() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		$result = $this->client->create_chat_completion( $messages );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_huggingface_api_key', $result->get_error_code() );
	}

	/**
	 * Test create_chat_completion returns error when endpoint URL is missing.
	 */
	public function test_create_chat_completion_returns_error_without_endpoint_url() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'huggingface_api_key' => 'hf_test123',
			)
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		$result = $this->client->create_chat_completion( $messages );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_huggingface_endpoint', $result->get_error_code() );
	}

	/**
	 * Test create_chat_completion returns error when model is missing.
	 */
	public function test_create_chat_completion_returns_error_without_model() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'huggingface_api_key'      => 'hf_test123',
				'huggingface_endpoint_url' => 'https://api-inference.huggingface.co/v1',
			)
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		$result = $this->client->create_chat_completion( $messages );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_huggingface_model', $result->get_error_code() );
	}

	/**
	 * Test test_connection returns error when API key is missing.
	 */
	public function test_test_connection_returns_error_without_api_key() {
		$result = $this->client->test_connection();

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_huggingface_api_key', $result->get_error_code() );
	}

	/**
	 * Test test_connection returns error when endpoint URL is missing.
	 */
	public function test_test_connection_returns_error_without_endpoint_url() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'huggingface_api_key' => 'hf_test123',
			)
		);

		$result = $this->client->test_connection();

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_huggingface_endpoint', $result->get_error_code() );
	}

	/**
	 * Test list_models returns error when API key is missing.
	 */
	public function test_list_models_returns_error_without_api_key() {
		$result = $this->client->list_models();

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_huggingface_api_key', $result->get_error_code() );
	}

	/**
	 * Test list_models returns error when endpoint URL is missing.
	 */
	public function test_list_models_returns_error_without_endpoint_url() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'huggingface_api_key' => 'hf_test123',
			)
		);

		$result = $this->client->list_models();

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_huggingface_endpoint', $result->get_error_code() );
	}

	/**
	 * Test build_payload correctly formats system messages.
	 */
	public function test_build_payload_formats_system_messages() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'huggingface_model' => 'meta-llama/Llama-3.3-70B-Instruct',
			)
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		$options = array(
			'system_prompt' => 'You are a helpful assistant.',
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, $options, 'meta-llama/Llama-3.3-70B-Instruct' );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'messages', $payload );
		$this->assertCount( 2, $payload['messages'] );
		$this->assertEquals( 'system', $payload['messages'][0]['role'] );
		$this->assertEquals( 'You are a helpful assistant.', $payload['messages'][0]['content'] );
		$this->assertEquals( 'user', $payload['messages'][1]['role'] );
		$this->assertEquals( 'Hello', $payload['messages'][1]['content'] );
	}

	/**
	 * Test build_payload handles tools correctly.
	 */
	public function test_build_payload_handles_tools() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'huggingface_model' => 'meta-llama/Llama-3.3-70B-Instruct',
			)
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'What is the weather?',
			),
		);

		$options = array(
			'tools' => array(
				array(
					'type'     => 'function',
					'function' => array(
						'name'        => 'get_weather',
						'description' => 'Get the current weather',
					),
				),
			),
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, $options, 'meta-llama/Llama-3.3-70B-Instruct' );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'tools', $payload );
		$this->assertCount( 1, $payload['tools'] );
		$this->assertEquals( 'get_weather', $payload['tools'][0]['name'] );
	}

	/**
	 * Test normalize_response adds provider field.
	 */
	public function test_normalize_response_adds_provider_field() {
		$response = array(
			'choices' => array(
				array(
					'message' => array(
						'role'    => 'assistant',
						'content' => 'Hello!',
					),
				),
			),
			'model'   => 'meta-llama/Llama-3.3-70B-Instruct',
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'normalize_response' );
		$method->setAccessible( true );

		$normalized = $method->invoke( $this->client, $response, 'meta-llama/Llama-3.3-70B-Instruct' );

		$this->assertArrayHasKey( 'provider', $normalized );
		$this->assertEquals( 'huggingface', $normalized['provider'] );
	}

	/**
	 * Test normalize_response converts string content to array format.
	 */
	public function test_normalize_response_converts_string_content_to_array() {
		$response = array(
			'choices' => array(
				array(
					'message' => array(
						'role'    => 'assistant',
						'content' => 'Hello!',
					),
				),
			),
			'model'   => 'meta-llama/Llama-3.3-70B-Instruct',
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'normalize_response' );
		$method->setAccessible( true );

		$normalized = $method->invoke( $this->client, $response, 'meta-llama/Llama-3.3-70B-Instruct' );

		$this->assertIsArray( $normalized['choices'][0]['message']['content'] );
		$this->assertEquals( 'text', $normalized['choices'][0]['message']['content'][0]['type'] );
		$this->assertEquals( 'Hello!', $normalized['choices'][0]['message']['content'][0]['text'] );
	}
}
