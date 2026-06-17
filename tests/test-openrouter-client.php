<?php
/**
 * Tests for WP_MCP_AI_OpenRouter_Client.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for OpenRouter Client.
 */
class Test_OpenRouter_Client extends WP_UnitTestCase {

	/**
	 * Client instance.
	 *
	 * @var WP_MCP_AI_OpenRouter_Client
	 */
	private $client;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openrouter-client.php';

		$this->client = new WP_MCP_AI_OpenRouter_Client();

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
	 * Test class constants are set correctly.
	 */
	public function test_constants() {
		$this->assertEquals( 'https://openrouter.ai/api/v1', WP_MCP_AI_OpenRouter_Client::DEFAULT_BASE_URL );
		$this->assertEquals( '/chat/completions', WP_MCP_AI_OpenRouter_Client::API_ENDPOINT );
		$this->assertEquals( '/models', WP_MCP_AI_OpenRouter_Client::API_MODELS );
		$this->assertEquals( 'openrouter/auto', WP_MCP_AI_OpenRouter_Client::DEFAULT_MODEL );
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
		update_option( 'wp_mcp_ai_settings', array( 'openrouter_api_key' => 'sk-or-v1-test' ) );

		$this->assertEquals( 'sk-or-v1-test', $this->client->get_api_key() );
	}

	/**
	 * Test get_model() returns configured model.
	 */
	public function test_get_model_returns_configured_model() {
		update_option( 'wp_mcp_ai_settings', array( 'openrouter_model' => 'openai/gpt-4o-mini' ) );

		$this->assertEquals( 'openai/gpt-4o-mini', $this->client->get_model() );
	}

	/**
	 * Test get_base_url() defaults to DEFAULT_BASE_URL.
	 */
	public function test_get_base_url_defaults_to_constant() {
		update_option( 'wp_mcp_ai_settings', array() );

		$this->assertEquals( WP_MCP_AI_OpenRouter_Client::DEFAULT_BASE_URL, $this->client->get_base_url() );
	}

	/**
	 * Test get_base_url() honours custom openrouter_base_url setting.
	 */
	public function test_get_base_url_honours_custom_setting() {
		$custom_url = 'https://my-proxy.example.com/openrouter';
		update_option( 'wp_mcp_ai_settings', array( 'openrouter_base_url' => $custom_url ) );

		$this->assertEquals( $custom_url, $this->client->get_base_url() );
	}

	/**
	 * Test get_base_url() strips trailing slash.
	 */
	public function test_get_base_url_strips_trailing_slash() {
		update_option( 'wp_mcp_ai_settings', array( 'openrouter_base_url' => 'https://proxy.example.com/v1/' ) );

		$this->assertStringEndsNotWith( '/', $this->client->get_base_url() );
	}

	/**
	 * Test get_site_url() falls back to home_url() when no override is set.
	 */
	public function test_get_site_url_defaults_to_home_url() {
		delete_option( 'wp_mcp_ai_settings' );

		$site_url = $this->client->get_site_url();

		$this->assertNotEmpty( $site_url );
		$this->assertStringContainsString( wp_parse_url( home_url(), PHP_URL_HOST ), $site_url );
	}

	/**
	 * Test get_site_url() honours custom override.
	 */
	public function test_get_site_url_honours_custom_setting() {
		update_option( 'wp_mcp_ai_settings', array( 'openrouter_site_url' => 'https://example.com' ) );

		$this->assertEquals( 'https://example.com', $this->client->get_site_url() );
	}

	/**
	 * Test get_app_title() falls back to the WordPress blog name.
	 */
	public function test_get_app_title_defaults_to_blogname() {
		delete_option( 'wp_mcp_ai_settings' );

		$title = $this->client->get_app_title();

		$this->assertNotEmpty( $title );
	}

	/**
	 * Test get_app_title() honours custom override.
	 */
	public function test_get_app_title_honours_custom_setting() {
		update_option( 'wp_mcp_ai_settings', array( 'openrouter_app_title' => 'My App' ) );

		$this->assertEquals( 'My App', $this->client->get_app_title() );
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
		$this->assertEquals( 'wp_mcp_ai_missing_openrouter_api_key', $result->get_error_code() );
	}

	/**
	 * Test create_chat_completion() returns WP_Error when messages array is empty.
	 */
	public function test_create_chat_completion_returns_error_for_empty_messages() {
		update_option( 'wp_mcp_ai_settings', array( 'openrouter_api_key' => 'sk-or-test' ) );

		$result = $this->client->create_chat_completion( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_missing_messages', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// build_payload tests.
	// -------------------------------------------------------------------------

	/**
	 * Test build_payload passes tools through verbatim.
	 *
	 * Unlike DeepSeek, OpenRouter accepts tools for any model; unsupported
	 * models silently ignore them rather than rejecting the request.
	 */
	public function test_build_payload_passes_tools_through() {
		update_option( 'wp_mcp_ai_settings', array( 'openrouter_api_key' => 'sk-or-test' ) );

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$tool = array(
			'type'     => 'function',
			'function' => array( 'name' => 'do_thing' ),
		);

		$payload = $method->invoke(
			$this->client,
			array(
				array(
					'role'    => 'user',
					'content' => 'Hi',
				),
			),
			array(
				'tools'       => array( $tool ),
				'tool_choice' => 'auto',
			),
			'openai/gpt-4o-mini'
		);

		$this->assertIsArray( $payload );
		$this->assertSame( 'openai/gpt-4o-mini', $payload['model'] );
		$this->assertArrayHasKey( 'tools', $payload );
		$this->assertSame( 'auto', $payload['tool_choice'] );
	}

	/**
	 * Test build_payload prepends a system message when system_prompt is set.
	 */
	public function test_build_payload_prepends_system_prompt() {
		update_option( 'wp_mcp_ai_settings', array( 'openrouter_api_key' => 'sk-or-test' ) );

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke(
			$this->client,
			array(
				array(
					'role'    => 'user',
					'content' => 'Hi',
				),
			),
			array( 'system_prompt' => 'You are helpful.' ),
			'openrouter/auto'
		);

		$this->assertCount( 2, $payload['messages'] );
		$this->assertSame( 'system', $payload['messages'][0]['role'] );
		$this->assertSame( 'You are helpful.', $payload['messages'][0]['content'] );
	}

	/**
	 * Test build_payload passes through OpenRouter routing options (provider, models).
	 */
	public function test_build_payload_passes_router_options() {
		update_option( 'wp_mcp_ai_settings', array( 'openrouter_api_key' => 'sk-or-test' ) );

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke(
			$this->client,
			array(
				array(
					'role'    => 'user',
					'content' => 'Hi',
				),
			),
			array(
				'provider' => array( 'order' => array( 'OpenAI', 'Anthropic' ) ),
				'models'   => array( 'openai/gpt-4o-mini', 'anthropic/claude-3-haiku' ),
			),
			'openrouter/auto'
		);

		$this->assertArrayHasKey( 'provider', $payload );
		$this->assertSame( array( 'OpenAI', 'Anthropic' ), $payload['provider']['order'] );
		$this->assertSame(
			array( 'openai/gpt-4o-mini', 'anthropic/claude-3-haiku' ),
			$payload['models']
		);
	}

	// -------------------------------------------------------------------------
	// Request headers.
	// -------------------------------------------------------------------------

	/**
	 * Test build_request_headers includes the recommended HTTP-Referer and X-Title.
	 *
	 * These headers are an OpenRouter best-practice for app attribution on
	 * the public leaderboard / dashboard.
	 */
	public function test_build_request_headers_includes_attribution_headers() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'openrouter_api_key'   => 'sk-or-test',
				'openrouter_site_url'  => 'https://example.com',
				'openrouter_app_title' => 'My App',
			)
		);

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_request_headers' );
		$method->setAccessible( true );

		$headers = $method->invoke( $this->client, 'sk-or-test' );

		$this->assertSame( 'Bearer sk-or-test', $headers['Authorization'] );
		$this->assertSame( 'application/json', $headers['Content-Type'] );
		$this->assertSame( 'https://example.com', $headers['HTTP-Referer'] );
		$this->assertSame( 'My App', $headers['X-Title'] );
		$this->assertStringContainsString( 'WP-MCP-AI-OpenRouter-Client', $headers['User-Agent'] );
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
	 * Test count_tokens includes system_prompt in the estimate.
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
	 * Test normalize_response extracts content, finish_reason, model, usage.
	 */
	public function test_normalize_response() {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'normalize_response' );
		$method->setAccessible( true );

		$raw = array(
			'id'      => 'gen-abc',
			'model'   => 'openai/gpt-4o-mini',
			'choices' => array(
				array(
					'message'       => array(
						'role'    => 'assistant',
						'content' => 'Hello!',
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
		$this->assertEquals( 'openai/gpt-4o-mini', $normalized['model'] );
		$this->assertArrayHasKey( 'usage', $normalized );
		$this->assertArrayHasKey( 'raw', $normalized );
	}

	/**
	 * Test normalize_response surfaces tool_calls when present.
	 */
	public function test_normalize_response_passes_tool_calls() {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'normalize_response' );
		$method->setAccessible( true );

		$tool_call = array(
			'id'       => 'call_1',
			'type'     => 'function',
			'function' => array(
				'name'      => 'do_it',
				'arguments' => '{}',
			),
		);

		$raw = array(
			'choices' => array(
				array(
					'message'       => array(
						'role'       => 'assistant',
						'content'    => null,
						'tool_calls' => array( $tool_call ),
					),
					'finish_reason' => 'tool_calls',
				),
			),
		);

		$normalized = $method->invoke( $this->client, $raw );

		$this->assertArrayHasKey( 'tool_calls', $normalized );
		$this->assertCount( 1, $normalized['tool_calls'] );
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
		$this->assertEquals( 'wp_mcp_ai_openrouter_auth_error', $result->get_error_code() );
	}

	/**
	 * Test handle_api_error returns insufficient-credits error for 402.
	 */
	public function test_handle_api_error_402() {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'handle_api_error' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$this->client,
			402,
			array( 'error' => array( 'message' => 'Insufficient credits.' ) ),
			array()
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_openrouter_insufficient_credits', $result->get_error_code() );
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
		$this->assertEquals( 'wp_mcp_ai_missing_openrouter_api_key', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Provider adapter.
	// -------------------------------------------------------------------------

	/**
	 * Test the provider-client adapter returns the correct provider slug.
	 */
	public function test_provider_adapter_slug() {
		require_once WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-openrouter-provider-client.php';

		$adapter = new WP_MCP_AI_OpenRouter_Provider_Client( $this->client );

		$this->assertEquals( 'openrouter', $adapter->get_provider_slug() );
	}
}
