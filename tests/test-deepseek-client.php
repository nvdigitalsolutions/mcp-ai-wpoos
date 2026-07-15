<?php
/**
 * Tests for WP_MCP_AI_DeepSeek_Client.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for DeepSeek Client.
 */
class Test_DeepSeek_Client extends WP_UnitTestCase {

	/**
	 * Client instance.
	 *
	 * @var WP_MCP_AI_DeepSeek_Client
	 */
	private $client;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-deepseek-client.php';

		$this->client = new WP_MCP_AI_DeepSeek_Client();

		// Clear any cached settings.
		wp_cache_flush();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		wp_cache_flush();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Constants & accessors.
	// -------------------------------------------------------------------------

	/**
	 * Test that class constants have expected values.
	 */
	public function test_constants() {
		$this->assertEquals( 'https://api.deepseek.com', WP_MCP_AI_DeepSeek_Client::DEFAULT_BASE_URL );
		$this->assertEquals( '/chat/completions', WP_MCP_AI_DeepSeek_Client::API_ENDPOINT );
		$this->assertEquals( '/models', WP_MCP_AI_DeepSeek_Client::API_MODELS );
		$this->assertEquals( 'deepseek-v4-flash', WP_MCP_AI_DeepSeek_Client::DEFAULT_MODEL );
		$this->assertContains( 'deepseek-reasoner', WP_MCP_AI_DeepSeek_Client::MODELS_WITHOUT_TOOL_CALLING );
	}

	/**
	 * Test get_api_key() returns empty string when not configured.
	 */
	public function test_get_api_key_returns_empty_when_unconfigured() {
		$key = $this->client->get_api_key();
		$this->assertIsString( $key );
		$this->assertEmpty( $key );
	}

	/**
	 * Test get_api_key() returns configured value.
	 */
	public function test_get_api_key_returns_configured_value() {
		update_option( 'wp_mcp_ai_settings', array( 'deepseek_api_key' => 'sk-test-key-123' ) );

		$key = $this->client->get_api_key();

		$this->assertEquals( 'sk-test-key-123', $key );
	}

	/**
	 * Test get_model() falls back to empty string when not configured.
	 */
	public function test_get_model_returns_empty_when_unconfigured() {
		$model = $this->client->get_model();
		$this->assertIsString( $model );
	}

	/**
	 * Test get_model() returns configured model.
	 */
	public function test_get_model_returns_configured_model() {
		update_option( 'wp_mcp_ai_settings', array( 'deepseek_model' => 'deepseek-reasoner' ) );

		$model = $this->client->get_model();

		$this->assertEquals( 'deepseek-reasoner', $model );
	}

	/**
	 * Test get_base_url() defaults to DEFAULT_BASE_URL.
	 */
	public function test_get_base_url_defaults_to_constant() {
		update_option( 'wp_mcp_ai_settings', array() );

		$url = $this->client->get_base_url();

		$this->assertEquals( WP_MCP_AI_DeepSeek_Client::DEFAULT_BASE_URL, $url );
	}

	/**
	 * Test get_base_url() honours custom deepseek_base_url setting.
	 */
	public function test_get_base_url_honours_custom_setting() {
		$custom_url = 'https://my-proxy.example.com';
		update_option( 'wp_mcp_ai_settings', array( 'deepseek_base_url' => $custom_url ) );

		$url = $this->client->get_base_url();

		$this->assertEquals( $custom_url, $url );
	}

	/**
	 * Test get_base_url() strips trailing slash from custom URL.
	 */
	public function test_get_base_url_strips_trailing_slash() {
		update_option( 'wp_mcp_ai_settings', array( 'deepseek_base_url' => 'https://proxy.example.com/' ) );

		$url = $this->client->get_base_url();

		$this->assertStringEndsNotWith( '/', $url );
	}

	// -------------------------------------------------------------------------
	// create_chat_completion — error paths (no HTTP call).
	// -------------------------------------------------------------------------

	/**
	 * Test create_chat_completion() returns WP_Error when no API key is set.
	 */
	public function test_create_chat_completion_returns_error_without_api_key() {
		delete_option( 'wp_mcp_ai_settings' );

		$result = $this->client->create_chat_completion(
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_missing_deepseek_api_key', $result->get_error_code() );
	}

	/**
	 * Test create_chat_completion() returns WP_Error when messages array is empty.
	 */
	public function test_create_chat_completion_returns_error_for_empty_messages() {
		update_option( 'wp_mcp_ai_settings', array( 'deepseek_api_key' => 'sk-test' ) );

		$result = $this->client->create_chat_completion( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_missing_messages', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Tool-calling / model_lacks_tool_calling logic.
	// -------------------------------------------------------------------------

	/**
	 * Test that deepseek-reasoner is identified as lacking tool calling support.
	 */
	public function test_model_lacks_tool_calling_for_reasoner() {
		// Use reflection to call protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'model_lacks_tool_calling' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $this->client, 'deepseek-reasoner' ) );
	}

	/**
	 * Test that deepseek-chat is identified as supporting tool calling.
	 */
	public function test_model_supports_tool_calling_for_chat() {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'model_lacks_tool_calling' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( $this->client, 'deepseek-chat' ) );
	}

	/**
	 * Test build_payload strips tools for deepseek-reasoner.
	 */
	public function test_build_payload_strips_tools_for_reasoner() {
		update_option( 'wp_mcp_ai_settings', array( 'deepseek_api_key' => 'sk-test' ) );

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);
		$options  = array(
			'tools' => array(
				array(
					'type'     => 'function',
					'function' => array( 'name' => 'test_fn' ),
				),
			),
		);

		$payload = $method->invoke( $this->client, $messages, $options, 'deepseek-reasoner' );

		$this->assertIsArray( $payload );
		$this->assertArrayNotHasKey( 'tools', $payload, 'Tools should be stripped for deepseek-reasoner' );
	}

	/**
	 * Test build_payload passes tools through for deepseek-chat.
	 */
	public function test_build_payload_passes_tools_for_chat_model() {
		update_option( 'wp_mcp_ai_settings', array( 'deepseek_api_key' => 'sk-test' ) );

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$tool     = array(
			'type'     => 'function',
			'function' => array( 'name' => 'my_tool' ),
		);
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);
		$options  = array( 'tools' => array( $tool ) );

		$payload = $method->invoke( $this->client, $messages, $options, 'deepseek-chat' );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'tools', $payload );
		$this->assertCount( 1, $payload['tools'] );
	}

	// -------------------------------------------------------------------------
	// count_tokens heuristic.
	// -------------------------------------------------------------------------

	/**
	 * Test count_tokens returns a positive integer for a non-empty message set.
	 */
	public function test_count_tokens_returns_positive_int() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello, how are you?',
			),
		);

		$count = $this->client->count_tokens( $messages );

		$this->assertIsInt( $count );
		$this->assertGreaterThan( 0, $count );
	}

	/**
	 * Test count_tokens includes system_prompt in estimate.
	 */
	public function test_count_tokens_includes_system_prompt() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hi',
			),
		);
		$without  = $this->client->count_tokens( $messages );
		$with     = $this->client->count_tokens( $messages, array( 'system_prompt' => str_repeat( 'x', 400 ) ) );

		$this->assertGreaterThan( $without, $with );
	}

	// -------------------------------------------------------------------------
	// normalize_response.
	// -------------------------------------------------------------------------

	/**
	 * Test normalize_response extracts content and tool_calls.
	 */
	public function test_normalize_response() {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'normalize_response' );
		$method->setAccessible( true );

		$raw = array(
			'id'      => 'chatcmpl-abc',
			'model'   => 'deepseek-chat',
			'choices' => array(
				array(
					'message'       => array(
						'role'       => 'assistant',
						'content'    => 'Hello!',
						'tool_calls' => array(),
					),
					'finish_reason' => 'stop',
				),
			),
			'usage'   => array(
				'prompt_tokens'     => 10,
				'completion_tokens' => 5,
				'total_tokens'      => 15,
			),
		);

		$normalized = $method->invoke( $this->client, $raw );

		$this->assertEquals( 'Hello!', $normalized['content'] );
		$this->assertEquals( 'stop', $normalized['finish_reason'] );
		$this->assertEquals( 'deepseek-chat', $normalized['model'] );
		$this->assertArrayHasKey( 'usage', $normalized );
		$this->assertArrayHasKey( 'raw', $normalized );
	}

	/**
	 * Test normalize_response includes reasoning_content when present.
	 */
	public function test_normalize_response_includes_reasoning_content() {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'normalize_response' );
		$method->setAccessible( true );

		$raw = array(
			'id'      => 'chatcmpl-r1',
			'model'   => 'deepseek-reasoner',
			'choices' => array(
				array(
					'message'       => array(
						'role'              => 'assistant',
						'content'           => 'The answer is 42.',
						'reasoning_content' => 'Let me think step by step...',
					),
					'finish_reason' => 'stop',
				),
			),
			'usage'   => array(),
		);

		$normalized = $method->invoke( $this->client, $raw );

		$this->assertArrayHasKey( 'reasoning_content', $normalized );
		$this->assertEquals( 'Let me think step by step...', $normalized['reasoning_content'] );
	}

	// -------------------------------------------------------------------------
	// handle_api_error.
	// -------------------------------------------------------------------------

	/**
	 * Test handle_api_error returns auth error for 401.
	 */
	public function test_handle_api_error_401() {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'handle_api_error' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$this->client,
			401,
			array( 'error' => array( 'message' => 'Invalid API key.' ) ),
			array()
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_deepseek_auth_error', $result->get_error_code() );
	}

	/**
	 * Test handle_api_error returns rate-limit error for 429.
	 */
	public function test_handle_api_error_429() {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'handle_api_error' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$this->client,
			429,
			array( 'error' => array( 'message' => 'Rate limit exceeded.' ) ),
			array()
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_rate_limit_exceeded', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// list_models — error paths.
	// -------------------------------------------------------------------------

	/**
	 * Test list_models() returns WP_Error when no API key is configured.
	 */
	public function test_list_models_returns_error_without_api_key() {
		delete_option( 'wp_mcp_ai_settings' );

		$result = $this->client->list_models();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_missing_deepseek_api_key', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// get_provider_slug.
	// -------------------------------------------------------------------------

	/**
	 * Test that the client correctly returns its own provider slug.
	 * Uses the 'deepseek' constant from the router.
	 */
	public function test_provider_slug_constant_is_deepseek() {
		// The slug is referenced directly in the router switch case — verify
		// the class identity convention by checking the class name.
		$this->assertStringContainsString( 'DeepSeek', get_class( $this->client ) );
	}

	// -------------------------------------------------------------------------
	// set_api_key / api_key_override.
	// -------------------------------------------------------------------------

	public function test_set_api_key_overrides_persisted_key() {
		update_option( 'wp_mcp_ai_settings', array( 'deepseek_api_key' => 'sk-persisted' ) );
		$this->client->set_api_key( 'sk-override' );
		$this->assertEquals( 'sk-override', $this->client->get_api_key() );
	}

	// -------------------------------------------------------------------------
	// build_payload — clamping.
	// -------------------------------------------------------------------------

	public function test_build_payload_clamps_temperature() {
		update_option( 'wp_mcp_ai_settings', array( 'deepseek_api_key' => 'sk-test' ) );
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );
		$messages = array( array( 'role' => 'user', 'content' => 'Hi' ) );
		$payload  = $method->invoke( $this->client, $messages, array( 'temperature' => 5.0 ), 'deepseek-chat' );
		$this->assertEquals( 2.0, $payload['temperature'] );
	}

	public function test_build_payload_supports_max_completion_tokens() {
		update_option( 'wp_mcp_ai_settings', array( 'deepseek_api_key' => 'sk-test' ) );
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );
		$messages = array( array( 'role' => 'user', 'content' => 'Hi' ) );
		$payload  = $method->invoke( $this->client, $messages, array( 'max_completion_tokens' => 200 ), 'deepseek-chat' );
		$this->assertEquals( 200, $payload['max_tokens'] );
	}

	public function test_model_supports_tools_public() {
		$this->assertTrue( $this->client->model_supports_tools( 'deepseek-chat' ) );
		$this->assertFalse( $this->client->model_supports_tools( 'deepseek-reasoner' ) );
	}
}
