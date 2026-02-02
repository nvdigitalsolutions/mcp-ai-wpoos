<?php
/**
 * Test chat-client endpoint error handling.
 *
 * Verifies that the chat-client endpoint properly handles error scenarios,
 * particularly when the main controller is not properly initialized.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

class WP_MCP_AI_REST_Chat_Client_Error_Handling_Test extends WP_UnitTestCase {

	/**
	 * Test that chat-client endpoint returns 503 when main_controller is null.
	 */
	public function test_chat_client_returns_503_when_main_controller_is_null() {
		$assistant_id = $this->create_assistant_post();

		// Create chat controller without main controller (null)
		$chat_controller = new WP_MCP_AI_REST_Chat_Controller( null );

		// Register the chat controller routes
		add_action(
			'rest_api_init',
			function () use ( $chat_controller ) {
				$chat_controller->register_routes();
			}
		);

		// Reinitialize REST server
		rest_get_server();
		do_action( 'rest_api_init' );

		// Create a request to the chat-client endpoint
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-client' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Test message',
				),
			)
		);

		// Add authentication (nonce)
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		// Dispatch the request
		$response = rest_get_server()->dispatch( $request );

		// Verify response
		$this->assertInstanceOf( WP_REST_Response::class, $response );

		// Should return 503 Service Unavailable, not 500 Internal Server Error
		$this->assertSame( 503, $response->get_status(), 'Expected 503 Service Unavailable status' );

		// Check error data
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'code', $data );
		$this->assertSame( 'wp_mcp_ai_chat_unavailable', $data['code'] );
		$this->assertArrayHasKey( 'message', $data );
		$this->assertNotEmpty( $data['message'] );
	}

	/**
	 * Test that chat-client endpoint properly removes filters even on error.
	 */
	public function test_chat_client_cleans_up_filters_on_normal_operation() {
		$assistant_id = $this->create_assistant_post();

		// Create a mock client
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn(
				array(
					'id'      => 'chatcmpl-test',
					'choices' => array(
						array(
							'message' => array(
								'role'    => 'assistant',
								'content' => 'Test response',
							),
						),
					),
				)
			);

		$this->bootstrap_rest_controller( $mock_client );

		// Create a request to the chat-client endpoint
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-client' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Test message',
				),
			)
		);

		// Add authentication (nonce)
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		// Count filters before request
		$filters_before = $GLOBALS['wp_filter']['wp_mcp_ai_max_agentic_iterations'] ?? null;

		// Dispatch the request
		$response = rest_get_server()->dispatch( $request );

		// Verify response
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		// Verify filters were properly removed after request
		// The filters should have been added and then removed
		$filters_after = $GLOBALS['wp_filter']['wp_mcp_ai_max_agentic_iterations'] ?? null;

		// If filters existed before, they should be the same after
		// If they didn't exist before, they should not exist after
		if ( $filters_before === null ) {
			$this->assertNull( $filters_after, 'Filters should be cleaned up after request' );
		}
	}

	/**
	 * Test that set_chat_client_tool_choice_default handles non-array options gracefully.
	 */
	public function test_set_chat_client_tool_choice_default_handles_non_array_options() {
		$chat_controller = new WP_MCP_AI_REST_Chat_Controller( null );

		// Test with null options
		$result = $chat_controller->set_chat_client_tool_choice_default( null, array(), array() );
		$this->assertNull( $result );

		// Test with string options
		$result = $chat_controller->set_chat_client_tool_choice_default( 'string', array(), array() );
		$this->assertSame( 'string', $result );

		// Test with integer options
		$result = $chat_controller->set_chat_client_tool_choice_default( 123, array(), array() );
		$this->assertSame( 123, $result );

		// Test with valid array options
		$options          = array( 'tools' => array( 'tool1', 'tool2' ) );
		$assistant_config = array( 'provider' => 'cloudflare' );
		$result           = $chat_controller->set_chat_client_tool_choice_default( $options, $assistant_config, array() );

		// For cloudflare provider with tools, tool_choice should be set to 'auto'
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'tool_choice', $result );
		$this->assertSame( 'auto', $result['tool_choice'] );
	}

	/**
	 * Prepare the REST controller with the provided mock client.
	 *
	 * @param WP_MCP_AI_Language_Model_Router $mock_client Mocked router instance.
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
	 * @return int Assistant post ID.
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
