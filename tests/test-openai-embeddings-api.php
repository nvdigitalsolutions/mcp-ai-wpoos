<?php
/**
 * Tests for OpenAI Embeddings API integration.
 *
 * @package WP_MCP_AI
 */

/**
 * Test OpenAI Embeddings API methods.
 */
class Test_OpenAI_Embeddings_API extends WP_UnitTestCase {
	/**
	 * OpenAI client instance.
	 *
	 * @var WP_MCP_AI_OpenAI_Client
	 */
	protected $client;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->client = new WP_MCP_AI_OpenAI_Client();
	}

	/**
	 * Test create_embeddings requires API key.
	 */
	public function test_create_embeddings_requires_api_key() {
		update_option( 'wp_mcp_ai_settings', array() );

		$result = $this->client->create_embeddings( 'test input' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_api_key', $result->get_error_code() );
	}

	/**
	 * Test create_embeddings requires input.
	 */
	public function test_create_embeddings_requires_input() {
		update_option(
			'wp_mcp_ai_settings',
			array( 'openai_api_key' => 'test-key' )
		);

		$result = $this->client->create_embeddings( '' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_input', $result->get_error_code() );
	}

	/**
	 * Test create_embeddings with string input.
	 */
	public function test_create_embeddings_string_input() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'openai_api_key'  => 'test-key',
				'request_timeout' => 30,
			)
		);

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( false !== strpos( $url, '/v1/embeddings' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'object' => 'list',
								'data'   => array(
									array(
										'object'    => 'embedding',
										'embedding' => array_fill( 0, 1536, 0.1 ),
										'index'     => 0,
									),
								),
								'model'  => 'text-embedding-3-small',
								'usage'  => array(
									'prompt_tokens' => 8,
									'total_tokens'  => 8,
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

		$result = $this->client->create_embeddings( 'The quick brown fox' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertCount( 1, $result['data'] );
		$this->assertArrayHasKey( 'embedding', $result['data'][0] );
		$this->assertCount( 1536, $result['data'][0]['embedding'] );
	}

	/**
	 * Test create_embeddings with array input.
	 */
	public function test_create_embeddings_array_input() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'openai_api_key'  => 'test-key',
				'request_timeout' => 30,
			)
		);

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( false !== strpos( $url, '/v1/embeddings' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'object' => 'list',
								'data'   => array(
									array(
										'object'    => 'embedding',
										'embedding' => array_fill( 0, 1536, 0.1 ),
										'index'     => 0,
									),
									array(
										'object'    => 'embedding',
										'embedding' => array_fill( 0, 1536, 0.2 ),
										'index'     => 1,
									),
								),
								'model'  => 'text-embedding-3-small',
								'usage'  => array(
									'prompt_tokens' => 16,
									'total_tokens'  => 16,
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

		$result = $this->client->create_embeddings( array( 'First text', 'Second text' ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertCount( 2, $result['data'] );
		$this->assertSame( 0, $result['data'][0]['index'] );
		$this->assertSame( 1, $result['data'][1]['index'] );
	}

	/**
	 * Test create_embeddings with custom model.
	 */
	public function test_create_embeddings_custom_model() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'openai_api_key'  => 'test-key',
				'request_timeout' => 30,
			)
		);

		$captured_request = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_request ) {
				if ( false !== strpos( $url, '/v1/embeddings' ) ) {
					$captured_request = $args;
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'object' => 'list',
								'data'   => array(
									array(
										'object'    => 'embedding',
										'embedding' => array_fill( 0, 3072, 0.1 ),
										'index'     => 0,
									),
								),
								'model'  => 'text-embedding-3-large',
								'usage'  => array(
									'prompt_tokens' => 8,
									'total_tokens'  => 8,
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

		$result = $this->client->create_embeddings( 'test', array( 'model' => 'text-embedding-3-large' ) );

		$this->assertIsArray( $result );
		$this->assertNotNull( $captured_request );

		$body = json_decode( $captured_request['body'], true );
		$this->assertSame( 'text-embedding-3-large', $body['model'] );
	}
}
