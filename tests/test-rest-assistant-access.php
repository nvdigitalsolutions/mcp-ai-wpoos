<?php
/**
 * Tests for assistant access restrictions over REST.
 */
class WP_MCP_AI_REST_Assistant_Access_Test extends WP_UnitTestCase {

	/**
	 * Ensure chat requests fail for unpublished assistants owned by other users.
	 */
	public function test_chat_request_rejected_for_unpublished_assistant() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'author' ) );
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Draft Assistant',
				'post_status' => 'draft',
				'post_author' => $owner_id,
			)
		);

		$requesting_user = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $requesting_user );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->disableOriginalConstructor()
			->getMock();

		$mock_client
			->expects( $this->never() )
			->method( 'create_chat_completion' );

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
		$this->assertSame( 403, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'wp_mcp_ai_assistant_forbidden', $data['code'] );
	}

	/**
	 * Ensure credential tokens can access unpublished assistants they are scoped to.
	 */
	public function test_chat_request_allows_local_token_for_unpublished_assistant() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Draft Assistant',
				'post_status' => 'draft',
			)
		);

		$issuer_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $issuer_id );

		$issued = WP_MCP_AI_Credentials::issue_credential( $assistant_id, $issuer_id );

		wp_set_current_user( 0 );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->disableOriginalConstructor()
			->getMock();

		$mock_client
			->expects( $this->once() )
			->method( 'create_chat_completion' )
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
		$request->set_header( 'Authorization', 'Bearer ' . $issued['token'] );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Ensure requests without explicit credentials return actionable guidance.
	 */
	public function test_request_without_credentials_returns_actionable_error() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Public Assistant',
				'post_status' => 'publish',
			)
		);

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->disableOriginalConstructor()
			->getMock();

		$mock_client
			->expects( $this->never() )
			->method( 'create_chat_completion' );

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

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 401, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'wp_mcp_ai_missing_credentials', $data['code'] );
		$this->assertArrayHasKey( 'actions', $data );
		$this->assertArrayHasKey( 'supply_bearer_token', $data['actions'] );
	}

	/**
	 * Ensure chat requests succeed for published assistants.
	 */
	public function test_chat_request_allows_published_assistant() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Public Assistant',
				'post_status' => 'publish',
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->disableOriginalConstructor()
			->getMock();

		$mock_client
			->expects( $this->once() )
			->method( 'create_chat_completion' )
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
	 * Ensure out-of-range temperatures fall back to the assistant default.
	 */
	public function test_chat_request_uses_assistant_temperature_for_out_of_range_request() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Public Assistant',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TEMPERATURE, 0.6 );

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->disableOriginalConstructor()
			->getMock();

		$mock_client
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->with(
				$this->anything(),
				$this->callback(
					function ( $options ) {
						$this->assertArrayHasKey( 'temperature', $options );
						$this->assertSame( 0.6, $options['temperature'] );

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
				'temperature' => 5,
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Ensure temperatures above the supported range are clamped when no assistant default exists.
	 */
	public function test_chat_request_clamps_temperature_above_range_without_assistant_default() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Public Assistant',
				'post_status' => 'publish',
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->disableOriginalConstructor()
			->getMock();

		$mock_client
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->with(
				$this->anything(),
				$this->callback(
					function ( $options ) {
						$this->assertArrayHasKey( 'temperature', $options );
						$this->assertSame( 2.0, $options['temperature'] );

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
				'temperature' => 4.5,
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Ensure temperatures below the supported range are clamped when no assistant default exists.
	 */
	public function test_chat_request_clamps_temperature_below_range_without_assistant_default() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Public Assistant',
				'post_status' => 'publish',
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->disableOriginalConstructor()
			->getMock();

		$mock_client
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->with(
				$this->anything(),
				$this->callback(
					function ( $options ) {
						$this->assertArrayHasKey( 'temperature', $options );
						$this->assertSame( 0.0, $options['temperature'] );

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
				'temperature' => -2,
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Ensure bearer token requests can be authorised via the validation filter.
	 */
	public function test_bearer_token_request_honours_validation_filter() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Public Assistant',
				'post_status' => 'publish',
			)
		);

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->disableOriginalConstructor()
			->getMock();

		$mock_client
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn(
				array(
					'id'      => 'chatcmpl-test',
					'choices' => array(),
				)
			);

		$this->bootstrap_rest_controller( $mock_client );

		add_filter(
			'wp_mcp_ai_pre_validate_bearer_token',
			function ( $pre, $token ) {
				if ( 'test-token' === $token ) {
					return true;
				}

				return $pre;
			},
			10,
			2
		);

		try {
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
			$request->set_header( 'Authorization', 'Bearer test-token' );

			$response = rest_get_server()->dispatch( $request );

			$this->assertInstanceOf( WP_REST_Response::class, $response );
			$this->assertSame( 200, $response->get_status() );
		} finally {
			remove_all_filters( 'wp_mcp_ai_pre_validate_bearer_token' );
		}
	}

	/**
	 * Ensure bearer token requests can be rejected via the validation filter.
	 */
	public function test_bearer_token_request_rejected_via_filter_error() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Public Assistant',
				'post_status' => 'publish',
			)
		);

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->disableOriginalConstructor()
			->getMock();

		$mock_client
			->expects( $this->never() )
			->method( 'create_chat_completion' );

		$this->bootstrap_rest_controller( $mock_client );

		add_filter(
			'wp_mcp_ai_pre_validate_bearer_token',
			function () {
				return new WP_Error( 'custom', 'Denied' );
			}
		);

		try {
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
			$request->set_header( 'Authorization', 'Bearer invalid' );

			$response = rest_get_server()->dispatch( $request );

			$this->assertInstanceOf( WP_REST_Response::class, $response );
			$this->assertSame( 500, $response->get_status() );
			$this->assertSame( 'custom', $response->get_data()['code'] );
		} finally {
			remove_all_filters( 'wp_mcp_ai_pre_validate_bearer_token' );
		}
	}

	/**
	 * Ensure tool requests fail for unpublished assistants owned by other users.
	 */
	public function test_tool_request_rejected_for_unpublished_assistant() {
		$owner_id     = self::factory()->user->create( array( 'role' => 'author' ) );
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Draft Assistant',
				'post_status' => 'draft',
				'post_author' => $owner_id,
			)
		);

		$requesting_user = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $requesting_user );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param( 'tool', 'dummy_tool' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 403, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'wp_mcp_ai_assistant_forbidden', $data['code'] );
	}

	/**
	 * Ensure tool requests succeed for published assistants.
	 */
	public function test_tool_request_allows_published_assistant() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Public Assistant',
				'post_status' => 'publish',
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( new WP_MCP_AI_Dummy_Tool() );

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, array( 'wp_mcp_ai_dummy_tool' ) );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param( 'tool', 'wp_mcp_ai_dummy_tool' );
		$request->set_param( 'arguments', array() );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'wp_mcp_ai_dummy_tool', $data['tool'] );
		$this->assertSame( array( 'success' => true ), $data['result'] );
	}

	/**
	 * Tool requests authenticated with local tokens should not require a WordPress user.
	 */
	public function test_tool_request_allows_local_token_without_user() {
		delete_option( WP_MCP_AI_Credentials::INDEX_OPTION );

		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Token Assistant',
				'post_status' => 'publish',
			)
		);

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( new WP_MCP_AI_Dummy_Tool() );

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, array( 'wp_mcp_ai_dummy_tool' ) );

		$issuer_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $issuer_id );
		$issued = WP_MCP_AI_Credentials::issue_credential( $assistant_id, $issuer_id );
		wp_set_current_user( 0 );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		$captured_context = null;
		$callback         = function ( $tool_slug, $arguments, $context ) use ( &$captured_context ) {
			$captured_context = $context;
		};

		add_action( 'wp_mcp_ai_before_tool_execution', $callback, 10, 3 );

		try {
			$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
			$request->set_param( 'assistant_id', $assistant_id );
			$request->set_param( 'tool', 'wp_mcp_ai_dummy_tool' );
			$request->set_param( 'arguments', array() );
			$request->set_header( 'Authorization', 'Bearer ' . $issued['token'] );

			$response = rest_get_server()->dispatch( $request );

			$this->assertInstanceOf( WP_REST_Response::class, $response );
			$this->assertSame( 200, $response->get_status() );

			$this->assertIsArray( $captured_context );
			$this->assertArrayHasKey( 'token_authenticated', $captured_context );
			$this->assertTrue( $captured_context['token_authenticated'] );
			$this->assertSame( 'local_token', $captured_context['token_type'] );
			$this->assertSame( 0, $captured_context['user_id'] );
			$this->assertArrayHasKey( 'token_context', $captured_context );
			$this->assertArrayHasKey( 'credential', $captured_context['token_context'] );
		} finally {
			remove_action( 'wp_mcp_ai_before_tool_execution', $callback, 10 );
		}
	}

	/**
	 * Tool requests authenticated with bearer tokens should be able to map to a WordPress user ID.
	 */
	public function test_tool_request_allows_bearer_token_without_user() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Bearer Assistant',
				'post_status' => 'publish',
			)
		);

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( new WP_MCP_AI_Dummy_Tool() );

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, array( 'wp_mcp_ai_dummy_tool' ) );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		$mapped_user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$pre_callback = function ( $pre, $token, $request ) {
			return true;
		};

		$map_callback = function ( $existing, $payload, $request ) use ( $mapped_user_id ) {
			return $mapped_user_id;
		};

		$captured_context = null;
		$context_callback = function ( $tool_slug, $arguments, $context ) use ( &$captured_context ) {
			$captured_context = $context;
		};

		add_filter( 'wp_mcp_ai_pre_validate_bearer_token', $pre_callback, 10, 3 );
		add_filter( 'wp_mcp_ai_map_bearer_to_user_id', $map_callback, 10, 3 );
		add_action( 'wp_mcp_ai_before_tool_execution', $context_callback, 10, 3 );

		try {
			$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
			$request->set_param( 'assistant_id', $assistant_id );
			$request->set_param( 'tool', 'wp_mcp_ai_dummy_tool' );
			$request->set_param( 'arguments', array() );
			$request->set_header( 'Authorization', 'Bearer example-token' );

			$response = rest_get_server()->dispatch( $request );

			$this->assertInstanceOf( WP_REST_Response::class, $response );
			$this->assertSame( 200, $response->get_status() );

			$this->assertIsArray( $captured_context );
			$this->assertArrayHasKey( 'token_authenticated', $captured_context );
			$this->assertTrue( $captured_context['token_authenticated'] );
			$this->assertSame( 'bearer', $captured_context['token_type'] );
			$this->assertSame( $mapped_user_id, $captured_context['user_id'] );
		} finally {
			remove_filter( 'wp_mcp_ai_pre_validate_bearer_token', $pre_callback, 10 );
			remove_filter( 'wp_mcp_ai_map_bearer_to_user_id', $map_callback, 10 );
			remove_action( 'wp_mcp_ai_before_tool_execution', $context_callback, 10 );
		}
	}

	/**
	 * Anonymous requests without any authentication method should continue to fail.
	 */
	public function test_tool_request_blocks_anonymous_without_credentials() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Locked Assistant',
				'post_status' => 'publish',
			)
		);

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( new WP_MCP_AI_Dummy_Tool() );

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, array( 'wp_mcp_ai_dummy_tool' ) );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param( 'tool', 'wp_mcp_ai_dummy_tool' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 401, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'wp_mcp_ai_missing_credentials', $data['code'] );
	}

	/**
	 * Bootstrap the REST controller with a mocked client.
	 *
	 * @param WP_MCP_AI_Language_Model_Router $client Client mock instance.
	 */
	protected function bootstrap_rest_controller( $client ) {
		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		$registry                             = WP_MCP_AI_Tool_Registry::get_instance();
		$GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $registry, $client );

		rest_get_server();
		do_action( 'rest_api_init' );
	}
}

/**
 * Simple tool implementation for testing tool execution.
 */
class WP_MCP_AI_Dummy_Tool implements WP_MCP_AI_Tool_Interface {
	public function get_slug() {
		return 'wp_mcp_ai_dummy_tool';
	}

	public function get_name() {
		return 'Dummy Tool';
	}

	public function get_description() {
		return 'Test tool.';
	}

	public function get_parameters_schema() {
		return array();
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		return array( 'success' => true );
	}
}
