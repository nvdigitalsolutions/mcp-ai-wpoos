<?php
/**
 * Tests for WP_MCP_AI_DigitalOcean_Client.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for DigitalOcean Serverless Inference client.
 */
class Test_DigitalOcean_Client extends WP_UnitTestCase {

	/**
	 * Client instance.
	 *
	 * @var WP_MCP_AI_DigitalOcean_Client
	 */
	private $client;

	/**
	 * Captured HTTP request args for assertions.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $captured_requests = array();

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-digitalocean-client.php';

		$this->client            = new WP_MCP_AI_DigitalOcean_Client();
		$this->captured_requests = array();

		wp_cache_flush();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		remove_all_filters( 'pre_http_request' );
		wp_cache_flush();
		parent::tearDown();
	}

	/**
	 * Mock a HTTP response via pre_http_request filter.
	 *
	 * @param array $response Response to return.
	 */
	private function mock_http( array $response ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $response ) {
				$this->captured_requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return $response;
			},
			10,
			3
		);
	}

	// -------------------------------------------------------------------------
	// Constants & accessors.
	// -------------------------------------------------------------------------

	public function test_constants() {
		$this->assertEquals( 'https://inference.do-ai.run/v1', WP_MCP_AI_DigitalOcean_Client::DEFAULT_BASE_URL );
		$this->assertEquals( '/chat/completions', WP_MCP_AI_DigitalOcean_Client::API_ENDPOINT );
		$this->assertEquals( '/models', WP_MCP_AI_DigitalOcean_Client::API_MODELS );
		$this->assertEquals( '/embeddings', WP_MCP_AI_DigitalOcean_Client::API_EMBEDDINGS );
		$this->assertEquals( 'llama3.3-70b-instruct', WP_MCP_AI_DigitalOcean_Client::DEFAULT_MODEL );
	}

	public function test_get_api_key_returns_empty_when_unconfigured() {
		$this->assertSame( '', $this->client->get_api_key() );
	}

	public function test_get_api_key_returns_configured_value() {
		update_option( 'wp_mcp_ai_settings', array( 'digitalocean_api_key' => 'do-test-key' ) );
		$this->assertEquals( 'do-test-key', $this->client->get_api_key() );
	}

	public function test_get_base_url_falls_back_to_default() {
		$this->assertEquals( WP_MCP_AI_DigitalOcean_Client::DEFAULT_BASE_URL, $this->client->get_base_url() );
	}

	public function test_get_base_url_respects_override() {
		update_option(
			'wp_mcp_ai_settings',
			array( 'digitalocean_base_url' => 'https://proxy.example.com/v1/' )
		);
		$this->assertEquals( 'https://proxy.example.com/v1', $this->client->get_base_url() );
	}

	// -------------------------------------------------------------------------
	// Chat completion.
	// -------------------------------------------------------------------------

	public function test_chat_completion_returns_error_without_key() {
		$result = $this->client->create_chat_completion( array( array( 'role' => 'user', 'content' => 'Hi' ) ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_digitalocean_api_key', $result->get_error_code() );
	}

	public function test_chat_completion_success() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'digitalocean_api_key' => 'do-key',
				'digitalocean_model'   => 'llama3.3-70b-instruct',
			)
		);

		$this->mock_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'choices' => array(
							array(
								'message'       => array(
									'role'    => 'assistant',
									'content' => 'Hello, world.',
								),
								'finish_reason' => 'stop',
							),
						),
						'model'   => 'llama3.3-70b-instruct',
						'usage'   => array(
							'prompt_tokens'     => 5,
							'completion_tokens' => 3,
							'total_tokens'      => 8,
						),
					)
				),
			)
		);

		$result = $this->client->create_chat_completion( array( array( 'role' => 'user', 'content' => 'Hi' ) ) );

		$this->assertIsArray( $result );
		$this->assertEquals( 'Hello, world.', $result['content'] );
		$this->assertEquals( 'stop', $result['finish_reason'] );
		$this->assertEquals( 'llama3.3-70b-instruct', $result['model'] );
		$this->assertEquals( 8, $result['usage']['total_tokens'] );

		// Verify request shape.
		$this->assertCount( 1, $this->captured_requests );
		$request = $this->captured_requests[0];
		$this->assertSame( 'https://inference.do-ai.run/v1/chat/completions', $request['url'] );
		$this->assertEquals( 'Bearer do-key', $request['args']['headers']['Authorization'] );
		$body = json_decode( $request['args']['body'], true );
		$this->assertEquals( 'llama3.3-70b-instruct', $body['model'] );
	}

	public function test_chat_completion_includes_tools() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'digitalocean_api_key' => 'do-key',
				'digitalocean_model'   => 'llama3.3-70b-instruct',
			)
		);

		$this->mock_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'choices' => array(
							array(
								'message'       => array(
									'role'       => 'assistant',
									'content'    => '',
									'tool_calls' => array(
										array(
											'id'       => 'call_1',
											'type'     => 'function',
											'function' => array(
												'name'      => 'lookup',
												'arguments' => '{"q":"hi"}',
											),
										),
									),
								),
								'finish_reason' => 'tool_calls',
							),
						),
					)
				),
			)
		);

		$tools  = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'lookup',
					'description' => 'Look something up',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array( 'q' => array( 'type' => 'string' ) ),
					),
				),
			),
		);
		$result = $this->client->create_chat_completion(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array( 'tools' => $tools )
		);

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['tool_calls'] );
		$this->assertEquals( 'lookup', $result['tool_calls'][0]['function']['name'] );

		$body = json_decode( $this->captured_requests[0]['args']['body'], true );
		$this->assertNotEmpty( $body['tools'] );
		$this->assertEquals( 'lookup', $body['tools'][0]['function']['name'] );
	}

	public function test_chat_completion_propagates_401_as_auth_error() {
		update_option( 'wp_mcp_ai_settings', array( 'digitalocean_api_key' => 'bad-key' ) );

		$this->mock_http(
			array(
				'response' => array( 'code' => 401 ),
				'body'     => wp_json_encode( array( 'error' => array( 'message' => 'Invalid key' ) ) ),
			)
		);

		$result = $this->client->create_chat_completion( array( array( 'role' => 'user', 'content' => 'Hi' ) ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_digitalocean_auth_error', $result->get_error_code() );
	}

	public function test_chat_completion_propagates_429_with_retry_after() {
		update_option( 'wp_mcp_ai_settings', array( 'digitalocean_api_key' => 'do-key' ) );

		$this->mock_http(
			array(
				'response' => array( 'code' => 429 ),
				'headers'  => array( 'retry-after' => '12' ),
				'body'     => wp_json_encode( array( 'error' => array( 'message' => 'Rate limited' ) ) ),
			)
		);

		$result = $this->client->create_chat_completion( array( array( 'role' => 'user', 'content' => 'Hi' ) ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_rate_limit_exceeded', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertEquals( 12, $data['retry_after'] );
	}

	public function test_chat_completion_handles_invalid_json() {
		update_option( 'wp_mcp_ai_settings', array( 'digitalocean_api_key' => 'do-key' ) );

		$this->mock_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => 'not json',
			)
		);

		$result = $this->client->create_chat_completion( array( array( 'role' => 'user', 'content' => 'Hi' ) ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_digitalocean_invalid_response', $result->get_error_code() );
	}

	public function test_chat_completion_passes_through_reasoning_content() {
		update_option( 'wp_mcp_ai_settings', array( 'digitalocean_api_key' => 'do-key' ) );

		$this->mock_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'choices' => array(
							array(
								'message'       => array(
									'role'              => 'assistant',
									'content'           => 'final',
									'reasoning_content' => 'thinking...',
								),
								'finish_reason' => 'stop',
							),
						),
					)
				),
			)
		);

		$result = $this->client->create_chat_completion( array( array( 'role' => 'user', 'content' => 'Hi' ) ) );
		$this->assertEquals( 'thinking...', $result['reasoning_content'] );
	}

	// -------------------------------------------------------------------------
	// list_models().
	// -------------------------------------------------------------------------

	public function test_list_models_normalises_response() {
		update_option( 'wp_mcp_ai_settings', array( 'digitalocean_api_key' => 'do-key' ) );

		$this->mock_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'data' => array(
							array(
								'id'             => 'llama3.3-70b-instruct',
								'name'           => 'Llama 3.3 70B Instruct',
								'context_length' => 131072,
							),
							array( 'id' => 'gte-large-en-v1.5' ),
							array( 'name' => 'ignored-because-no-id' ),
						),
					)
				),
			)
		);

		$models = $this->client->list_models();
		$this->assertIsArray( $models );
		$this->assertCount( 2, $models );
		$this->assertEquals( 'llama3.3-70b-instruct', $models[0]['id'] );
		$this->assertEquals( 131072, $models[0]['context_length'] );
	}

	public function test_list_models_requires_key() {
		$result = $this->client->list_models();
		$this->assertWPError( $result );
	}

	// -------------------------------------------------------------------------
	// Embeddings.
	// -------------------------------------------------------------------------

	public function test_create_embedding_returns_vector() {
		update_option( 'wp_mcp_ai_settings', array( 'digitalocean_api_key' => 'do-key' ) );

		$this->mock_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'data' => array( array( 'embedding' => array( 0.1, 0.2, 0.3 ) ) ),
					)
				),
			)
		);

		$result = $this->client->create_embedding( array( 'input' => 'hello' ) );
		$this->assertIsArray( $result );
		$this->assertCount( 3, $result['data'][0]['embedding'] );

		$body = json_decode( $this->captured_requests[0]['args']['body'], true );
		$this->assertEquals( 'gte-large-en-v1.5', $body['model'] );
		$this->assertEquals( 'hello', $body['input'] );
		$this->assertSame( 'https://inference.do-ai.run/v1/embeddings', $this->captured_requests[0]['url'] );
	}

	public function test_create_embedding_requires_input() {
		update_option( 'wp_mcp_ai_settings', array( 'digitalocean_api_key' => 'do-key' ) );
		$result = $this->client->create_embedding( array() );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_input', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Misc.
	// -------------------------------------------------------------------------

	public function test_custom_base_url_used_in_request() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'digitalocean_api_key'  => 'do-key',
				'digitalocean_base_url' => 'https://proxy.example.com/v1',
			)
		);

		$this->mock_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'choices' => array( array( 'message' => array( 'content' => 'ok' ), 'finish_reason' => 'stop' ) ) ) ),
			)
		);

		$this->client->create_chat_completion( array( array( 'role' => 'user', 'content' => 'Hi' ) ) );
		$this->assertSame( 'https://proxy.example.com/v1/chat/completions', $this->captured_requests[0]['url'] );
	}

	public function test_count_tokens_heuristic() {
		$count = $this->client->count_tokens(
			array(
				array( 'role' => 'user', 'content' => 'hello world!' ),
			)
		);
		$this->assertGreaterThan( 0, $count );
	}
}
