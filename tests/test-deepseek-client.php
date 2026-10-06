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
		$this->assertEquals( 'deepseek-flash', WP_MCP_AI_DeepSeek_Client::DEFAULT_MODEL );
		$this->assertEmpty( WP_MCP_AI_DeepSeek_Client::MODELS_WITHOUT_TOOL_CALLING, 'All current DeepSeek V4 models support tool calling.' );
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
		update_option( 'wp_mcp_ai_settings', array( 'deepseek_model' => 'deepseek-flash' ) );

		$model = $this->client->get_model();

		$this->assertEquals( 'deepseek-flash', $model );
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
	 * Test build_payload passes tools through for V4 models.
	 */
	public function test_build_payload_passes_tools_for_v4_model() {
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

		$payload = $method->invoke( $this->client, $messages, $options, 'deepseek-flash' );

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

	/**
	 * Test normalize_response flattens array content blocks into a string.
	 */
	public function test_normalize_response_flattens_array_content() {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'normalize_response' );
		$method->setAccessible( true );

		$raw = array(
			'id'      => 'chatcmpl-blocks',
			'model'   => 'deepseek-chat',
			'choices' => array(
				array(
					'message'       => array(
						'role'    => 'assistant',
						'content' => array(
							array(
								'type' => 'text',
								'text' => 'First block.',
							),
							'Plain string block.',
							array( 'content' => 'Third block.' ),
							array(
								'type'  => 'image',
								'image' => 'not text',
							),
						),
					),
					'finish_reason' => 'stop',
				),
			),
			'usage'   => array(),
		);

		$normalized = $method->invoke( $this->client, $raw );

		$expected = "First block.\n\nPlain string block.\n\nThird block.";
		$this->assertIsString( $normalized['content'] );
		$this->assertSame( $expected, $normalized['content'] );
		$this->assertIsString( $normalized['choices'][0]['message']['content'] );
		$this->assertSame( $expected, $normalized['choices'][0]['message']['content'] );
	}

	/**
	 * Test normalize_response leaves string content untouched.
	 */
	public function test_normalize_response_keeps_string_content() {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'normalize_response' );
		$method->setAccessible( true );

		$raw = array(
			'id'      => 'chatcmpl-str',
			'model'   => 'deepseek-chat',
			'choices' => array(
				array(
					'message'       => array(
						'role'    => 'assistant',
						'content' => 'Plain answer.',
					),
					'finish_reason' => 'stop',
				),
			),
			'usage'   => array(),
		);

		$normalized = $method->invoke( $this->client, $raw );

		$this->assertSame( 'Plain answer.', $normalized['content'] );
		$this->assertSame( 'Plain answer.', $normalized['choices'][0]['message']['content'] );
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

	/**
	 * A transient API key overrides the persisted setting.
	 */
	public function test_set_api_key_overrides_persisted_key() {
		update_option( 'wp_mcp_ai_settings', array( 'deepseek_api_key' => 'sk-persisted' ) );
		$this->client->set_api_key( 'sk-override' );
		$this->assertEquals( 'sk-override', $this->client->get_api_key() );
	}

	// -------------------------------------------------------------------------
	// build_payload — clamping.
	// -------------------------------------------------------------------------

	/**
	 * Clamps out-of-range temperature values to 2.0 in build_payload.
	 */
	public function test_build_payload_clamps_temperature() {
		update_option( 'wp_mcp_ai_settings', array( 'deepseek_api_key' => 'sk-test' ) );
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hi',
			),
		);
		$payload  = $method->invoke( $this->client, $messages, array( 'temperature' => 5.0 ), 'deepseek-chat' );
		$this->assertEquals( 2.0, $payload['temperature'] );
	}

	/**
	 * Maps max_completion_tokens to DeepSeek's max_tokens payload key.
	 */
	public function test_build_payload_supports_max_completion_tokens() {
		update_option( 'wp_mcp_ai_settings', array( 'deepseek_api_key' => 'sk-test' ) );
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hi',
			),
		);
		$payload  = $method->invoke( $this->client, $messages, array( 'max_completion_tokens' => 200 ), 'deepseek-chat' );
		$this->assertEquals( 200, $payload['max_tokens'] );
	}

	/**
	 * DeepSeek V4 models advertise tool-calling support.
	 */
	public function test_model_supports_tools_public() {
		$this->assertTrue( $this->client->model_supports_tools( 'deepseek-flash' ) );
		$this->assertTrue( $this->client->model_supports_tools( 'deepseek-v4-pro' ) );
	}

	// -------------------------------------------------------------------------
	// build_payload — image segment conversion.
	// -------------------------------------------------------------------------

	/**
	 * Converts input_image segments into DeepSeek's OpenAI-compatible
	 * image_url content blocks.
	 */
	public function test_build_payload_converts_input_image_segments_to_image_url() {
		update_option( 'wp_mcp_ai_settings', array( 'deepseek_api_key' => 'sk-test' ) );

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Describe the image.',
					),
					array(
						'type'          => 'input_image',
						'attachment_id' => 0,
						'image_url'     => array(
							'url' => 'https://example.com/pack-shot.png',
						),
					),
				),
			),
		);

		$payload = $method->invoke( $this->client, $messages, array(), 'deepseek-flash' );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'messages', $payload );

		$content = $payload['messages'][0]['content'];
		$this->assertCount( 2, $content );
		$this->assertSame( 'text', $content[0]['type'] );
		$this->assertSame( 'image_url', $content[1]['type'] );
		$this->assertSame( 'https://example.com/pack-shot.png', $content[1]['image_url']['url'] );
	}

	/**
	 * Drops input_image segments without a resolvable URL so the request
	 * still goes out with the text content intact.
	 */
	public function test_build_payload_drops_unresolvable_input_image_segments() {
		update_option( 'wp_mcp_ai_settings', array( 'deepseek_api_key' => 'sk-test' ) );

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Describe the image.',
					),
					array(
						'type'          => 'input_image',
						'attachment_id' => 0,
					),
				),
			),
		);

		$payload = $method->invoke( $this->client, $messages, array(), 'deepseek-flash' );

		$this->assertIsArray( $payload );
		$content = $payload['messages'][0]['content'];
		$this->assertCount( 1, $content );
		$this->assertSame( 'text', $content[0]['type'] );
	}

	/**
	 * Advertises vision for deepseek-flash (and the retired vision-exp id
	 * that now routes to it) but not for the other DeepSeek models.
	 */
	public function test_supports_vision_capability_matrix() {
		$this->assertTrue( $this->client->supports_vision( 'deepseek-flash' ) );
		$this->assertTrue( $this->client->supports_vision( 'deepseek-v4-flash-vision-exp' ) );
		$this->assertFalse( $this->client->supports_vision( 'deepseek-v4-pro' ) );
		$this->assertFalse( $this->client->supports_vision( 'deepseek-chat' ) );
		$this->assertFalse( $this->client->supports_vision( '' ) );
	}

	/**
	 * The wp_mcp_ai_deepseek_supports_vision filter opts a text-only
	 * endpoint out of image payloads: input_image segments are stripped
	 * before the request while text content survives.
	 */
	public function test_supports_vision_filter_strips_image_segments() {
		update_option( 'wp_mcp_ai_settings', array( 'deepseek_api_key' => 'sk-test' ) );

		$opt_out = function ( $is_vision_model, $model ) {
			$this->assertSame( 'deepseek-flash', $model );
			return false;
		};
		add_filter( 'wp_mcp_ai_deepseek_supports_vision', $opt_out, 10, 2 );

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

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
						'image_url' => array(
							'url' => 'https://example.com/pack-shot.png',
						),
					),
				),
			),
		);

		$payload = $method->invoke( $this->client, $messages, array(), 'deepseek-flash' );

		remove_filter( 'wp_mcp_ai_deepseek_supports_vision', $opt_out, 10 );

		$this->assertIsArray( $payload );
		$content = $payload['messages'][0]['content'];
		$this->assertCount( 1, $content );
		$this->assertSame( 'text', $content[0]['type'] );
	}
}
