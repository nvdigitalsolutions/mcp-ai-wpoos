<?php
/**
 * Tests that ensure model defaults are applied in chat REST requests.
 */
class WP_MCP_AI_REST_Model_Defaults_Test extends WP_UnitTestCase {

	/**
	 * Ensure an assistant's configured model is used when the request omits one.
	 */
	public function test_chat_request_uses_assistant_default_model() {
		$assistant_id = $this->create_assistant_post();
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_MODEL, 'gpt-4o' );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->with(
				$this->isType( 'array' ),
				$this->callback(
					function ( $options ) {
						$this->assertArrayHasKey( 'provider', $options );
						$this->assertSame( 'openai', $options['provider'] );
						$this->assertArrayHasKey( 'model', $options );
						$this->assertSame( 'gpt-4o', $options['model'] );
						return true;
					}
				)
			)
			->willReturn(
				array(
					'id'      => 'chatcmpl-test',
					'choices' => array(),
				)
			);

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Ensure request-provided models override the assistant default and are sanitized.
	 */
	public function test_chat_request_prefers_request_model_over_assistant_default() {
		$assistant_id = $this->create_assistant_post();
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_MODEL, 'gpt-4o' );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$provided_value = '  <b>gpt-4o-mini</b>  ';
		$expected_value = sanitize_text_field( $provided_value );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->with(
				$this->isType( 'array' ),
				$this->callback(
					function ( $options ) use ( $expected_value ) {
						$this->assertArrayHasKey( 'provider', $options );
						$this->assertSame( 'openai', $options['provider'] );
						$this->assertArrayHasKey( 'model', $options );
						$this->assertSame( $expected_value, $options['model'] );
						return true;
					}
				)
			)
			->willReturn(
				array(
					'id'      => 'chatcmpl-test',
					'choices' => array(),
				)
			);

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			)
		);
		$request->set_param(
			'options',
			array(
				'model' => $provided_value,
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Ensure the REST layer does not force a model, allowing the client to fall back to global defaults.
	 */
	public function test_chat_request_allows_client_default_model() {
		$assistant_id = $this->create_assistant_post();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->with(
				$this->isType( 'array' ),
				$this->callback(
					function ( $options ) {
						$this->assertArrayHasKey( 'provider', $options );
						$this->assertSame( 'openai', $options['provider'] );
						$this->assertArrayNotHasKey( 'model', $options );
						return true;
					}
				)
			)
			->willReturn(
				array(
					'id'      => 'chatcmpl-test',
					'choices' => array(),
				)
			);

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Prepare the REST controller instance for testing.
	 *
	 * @param WP_MCP_AI_Language_Model_Router $mock_client Mocked language model router.
	 */
	protected function bootstrap_rest_controller( WP_MCP_AI_Language_Model_Router $mock_client ) {
		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		$registry                             = WP_MCP_AI_Tool_Registry::get_instance();
		$GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $registry, $mock_client );

		rest_get_server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Create a published assistant post for testing.
	 *
	 * @return int
	 */
	protected function create_assistant_post() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		$this->assertNotWPError( $assistant_id );
		$this->assertNotEmpty( $assistant_id );

		return $assistant_id;
	}
}
