<?php
/**
 * Tests to verify bearer token authentication doesn't inadvertently use session user privileges.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Bearer_Token_Privilege_Escalation_Test extends WP_UnitTestCase {

	/**
	 * Ensure bearer tokens without user mapping cannot access unpublished assistants
	 * even when an admin session is active.
	 */
	public function test_bearer_token_without_user_mapping_respects_unpublished_assistant() {
		// Create an unpublished assistant owned by an author.
		$owner_id     = self::factory()->user->create( array( 'role' => 'author' ) );
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Draft Assistant',
				'post_status' => 'draft',
				'post_author' => $owner_id,
			)
		);

		// Simulate an active admin session (e.g., admin browsing dashboard in another tab).
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		// The language model should NOT be called.
		$mock_client
			->expects( $this->never() )
			->method( 'create_chat_completion' );

		$this->bootstrap_rest_controller( $mock_client );

		// Add a filter that validates the bearer token but does NOT map it to a user.
		$pre_callback = function ( $pre, $token ) {
			if ( 'unmapped-bearer-token' === $token ) {
				return true;
			}
			return $pre;
		};

		add_filter( 'wp_mcp_ai_pre_validate_bearer_token', $pre_callback, 10, 2 );

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
			$request->set_header( 'Authorization', 'Bearer unmapped-bearer-token' );

			$response = rest_get_server()->dispatch( $request );

			// The request should be REJECTED because:
			// 1. The assistant is unpublished.
			// 2. The bearer token is not mapped to any user.
			// 3. Even though an admin is logged in, we should NOT use the admin's privileges.
			$this->assertInstanceOf( WP_REST_Response::class, $response );
			$this->assertSame( 403, $response->get_status(), 'Bearer token without user mapping should not access unpublished assistant even with active admin session' );

			$data = $response->get_data();
			$this->assertIsArray( $data );
			$this->assertSame( 'wp_mcp_ai_assistant_forbidden', $data['code'] );
		} finally {
			remove_filter( 'wp_mcp_ai_pre_validate_bearer_token', $pre_callback, 10 );
			wp_set_current_user( 0 );
		}
	}

	/**
	 * Ensure bearer tokens mapped to a subscriber cannot access unpublished assistants
	 * even when an admin session is active.
	 */
	public function test_bearer_token_mapped_to_subscriber_cannot_access_unpublished_assistant() {
		// Create an unpublished assistant owned by an author.
		$owner_id     = self::factory()->user->create( array( 'role' => 'author' ) );
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Private Assistant',
				'post_status' => 'draft',
				'post_author' => $owner_id,
			)
		);

		// Simulate an active admin session.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create a subscriber user that will be mapped to the bearer token.
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		// The language model should NOT be called.
		$mock_client
			->expects( $this->never() )
			->method( 'create_chat_completion' );

		$this->bootstrap_rest_controller( $mock_client );

		// Add filters to validate and map the bearer token to the subscriber.
		$pre_callback = function ( $pre, $token ) {
			if ( 'subscriber-bearer-token' === $token ) {
				return true;
			}
			return $pre;
		};

		$map_callback = function ( $existing, $payload, $request ) use ( $subscriber_id ) {
			return $subscriber_id;
		};

		add_filter( 'wp_mcp_ai_pre_validate_bearer_token', $pre_callback, 10, 2 );
		add_filter( 'wp_mcp_ai_map_bearer_to_user_id', $map_callback, 10, 3 );

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
			$request->set_header( 'Authorization', 'Bearer subscriber-bearer-token' );

			$response = rest_get_server()->dispatch( $request );

			// The request should be REJECTED because:
			// 1. The bearer token is mapped to a subscriber.
			// 2. The subscriber does not have permission to read the draft assistant.
			// 3. Even though an admin is logged in, we should use the subscriber's privileges.
			$this->assertInstanceOf( WP_REST_Response::class, $response );
			$this->assertSame( 403, $response->get_status(), 'Bearer token mapped to subscriber should not inherit admin session privileges' );

			$data = $response->get_data();
			$this->assertIsArray( $data );
			$this->assertSame( 'wp_mcp_ai_assistant_forbidden', $data['code'] );
		} finally {
			remove_filter( 'wp_mcp_ai_pre_validate_bearer_token', $pre_callback, 10 );
			remove_filter( 'wp_mcp_ai_map_bearer_to_user_id', $map_callback, 10 );
			wp_set_current_user( 0 );
		}
	}

	/**
	 * Ensure bearer tokens mapped to the assistant owner CAN access unpublished assistants.
	 */
	public function test_bearer_token_mapped_to_owner_can_access_unpublished_assistant() {
		// Create an unpublished assistant.
		$owner_id     = self::factory()->user->create( array( 'role' => 'author' ) );
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Owner Assistant',
				'post_status' => 'draft',
				'post_author' => $owner_id,
			)
		);

		// No session user is set (anonymous request).
		wp_set_current_user( 0 );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		// The language model SHOULD be called.
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

		// Add filters to validate and map the bearer token to the owner.
		$pre_callback = function ( $pre, $token ) {
			if ( 'owner-bearer-token' === $token ) {
				return true;
			}
			return $pre;
		};

		$map_callback = function ( $existing, $payload, $request ) use ( $owner_id ) {
			return $owner_id;
		};

		add_filter( 'wp_mcp_ai_pre_validate_bearer_token', $pre_callback, 10, 2 );
		add_filter( 'wp_mcp_ai_map_bearer_to_user_id', $map_callback, 10, 3 );

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
			$request->set_header( 'Authorization', 'Bearer owner-bearer-token' );

			$response = rest_get_server()->dispatch( $request );

			// The request should SUCCEED because the bearer token is mapped to the owner.
			$this->assertInstanceOf( WP_REST_Response::class, $response );
			$this->assertSame( 200, $response->get_status(), 'Bearer token mapped to owner should access their unpublished assistant' );
		} finally {
			remove_filter( 'wp_mcp_ai_pre_validate_bearer_token', $pre_callback, 10 );
			remove_filter( 'wp_mcp_ai_map_bearer_to_user_id', $map_callback, 10 );
		}
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
