<?php
/**
 * Tests for OpenAI Models API integration.
 *
 * @package WP_MCP_AI
 */

/**
 * Test OpenAI Models API methods.
 */
class Test_OpenAI_Models_API extends WP_UnitTestCase {
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
	 * Test list_models requires API key.
	 */
	public function test_list_models_requires_api_key() {
		update_option( 'wp_mcp_ai_settings', array() );

		$result = $this->client->list_models();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_api_key', $result->get_error_code() );
	}

	/**
	 * Test list_models with mocked response.
	 */
	public function test_list_models_success() {
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
				if ( false !== strpos( $url, '/v1/models' ) && false === strpos( $url, '/v1/models/' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'object' => 'list',
								'data'   => array(
									array(
										'id'      => 'gpt-4o',
										'object'  => 'model',
										'created' => time(),
										'owned_by' => 'openai',
									),
									array(
										'id'      => 'gpt-4o-mini',
										'object'  => 'model',
										'created' => time(),
										'owned_by' => 'openai',
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

		$result = $this->client->list_models();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertCount( 2, $result['data'] );
		$this->assertSame( 'gpt-4o', $result['data'][0]['id'] );
		$this->assertSame( 'gpt-4o-mini', $result['data'][1]['id'] );
	}

	/**
	 * Test get_model requires model ID.
	 */
	public function test_get_model_requires_model_id() {
		update_option(
			'wp_mcp_ai_settings',
			array( 'openai_api_key' => 'test-key' )
		);

		$result = $this->client->get_model( '' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_model_id', $result->get_error_code() );
	}

	/**
	 * Test get_model with mocked response.
	 */
	public function test_get_model_success() {
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
				if ( false !== strpos( $url, '/v1/models/gpt-4o' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'id'       => 'gpt-4o',
								'object'   => 'model',
								'created'  => time(),
								'owned_by' => 'openai',
							)
						),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->client->get_model( 'gpt-4o' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertSame( 'gpt-4o', $result['id'] );
		$this->assertSame( 'openai', $result['owned_by'] );
	}
}
