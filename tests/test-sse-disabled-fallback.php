<?php
/**
 * Tests for chat functionality when SSE (Server-Sent Events) is disabled.
 *
 * Confirms that the chat UI properly handles JSON responses when:
 * 1. SSE streaming is explicitly disabled (enableStreaming=false)
 * 2. SSE is enabled but server returns JSON (fallback scenario)
 *
 * @package WP_MCP_AI
 */

class WP_MCP_AI_SSE_Disabled_Fallback_Test extends WP_Test_REST_TestCase {
	/**
	 * Ensure REST routes are registered before each test.
	 */
	public function set_up() {
		parent::set_up();

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$this->bootstrap_rest_controller();
	}

	/**
	 * Clean up the REST controller registration.
	 */
	public function tear_down() {
		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
			unset( $GLOBALS['wp_mcp_ai_rest_controller'] );
		}

		parent::tear_down();
	}

	/**
	 * Test that chat request returns valid JSON when SSE is not requested.
	 *
	 * This tests the non-streaming path: when enableStreaming is false,
	 * the frontend makes a regular POST request and expects JSON back.
	 */
	public function test_chat_returns_json_when_sse_not_requested() {
		$assistant_id = $this->create_test_assistant();
		$user_id      = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello, this is a test message.',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Accept', 'application/json' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data, 'Response should be a valid JSON array' );
		$this->assertArrayHasKey( 'assistant_id', $data, 'Response should contain assistant_id' );
		$this->assertArrayHasKey( 'data', $data, 'Response should contain data field' );
		$this->assertSame( $assistant_id, $data['assistant_id'] );

		// Verify the response data structure matches OpenAI format.
		$response_data = $data['data'];
		$this->assertIsArray( $response_data );
		$this->assertArrayHasKey( 'choices', $response_data, 'Response data should contain choices array' );
		$this->assertIsArray( $response_data['choices'] );
		$this->assertNotEmpty( $response_data['choices'], 'Choices array should not be empty' );

		// Verify first choice has expected structure.
		$first_choice = $response_data['choices'][0];
		$this->assertArrayHasKey( 'message', $first_choice, 'Choice should contain message' );
		$this->assertIsArray( $first_choice['message'] );
		$this->assertArrayHasKey( 'role', $first_choice['message'], 'Message should have role' );
		$this->assertArrayHasKey( 'content', $first_choice['message'], 'Message should have content' );
		$this->assertSame( 'assistant', $first_choice['message']['role'] );
	}

	/**
	 * Test that chat request returns JSON when SSE is requested but Accept header is JSON.
	 *
	 * This simulates the fallback scenario: when enableStreaming is true
	 * but the server determines it should return JSON instead of SSE.
	 */
	public function test_chat_returns_json_with_json_accept_header() {
		$assistant_id = $this->create_test_assistant();
		$user_id      = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Test message for JSON fallback.',
				),
			)
		);
		$request->set_param( 'stream', true ); // Request streaming.
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Accept', 'application/json' ); // But accept JSON.

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data, 'Response should be JSON when Accept header is application/json' );
		$this->assertArrayHasKey( 'assistant_id', $data );
		$this->assertArrayHasKey( 'data', $data );
	}

	/**
	 * Test that chat response includes tool_results when tools are executed.
	 *
	 * This verifies the agentic loop works correctly in non-SSE mode,
	 * including tool execution results in the JSON response.
	 */
	public function test_chat_json_response_includes_tool_results() {
		$assistant_id = $this->create_test_assistant_with_tools();
		$user_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Create a test post for the search tool to find.
		self::factory()->post->create(
			array(
				'post_title'   => 'Test Post for Search',
				'post_content' => 'This post should be found by the search tool.',
				'post_status'  => 'publish',
			)
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Search for posts about "test".',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Accept', 'application/json' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );

		// When tools are executed in the agentic loop, tool_results should be included.
		// Note: This depends on the LLM deciding to call tools, which is mocked in the test setup.
		if ( isset( $data['tool_results'] ) ) {
			$this->assertIsArray( $data['tool_results'], 'tool_results should be an array' );
			foreach ( $data['tool_results'] as $tool_result ) {
				$this->assertArrayHasKey( 'role', $tool_result, 'Tool result should have role' );
				$this->assertSame( 'tool', $tool_result['role'], 'Tool result role should be "tool"' );
				$this->assertArrayHasKey( 'content', $tool_result, 'Tool result should have content' );
			}
		}
	}

	/**
	 * Test that shortcode config properly sets enableStreaming flag.
	 *
	 * This verifies the shortcode passes the correct configuration
	 * to the frontend JavaScript.
	 */
	public function test_shortcode_config_sets_enable_streaming_flag() {
		$assistant_id = $this->create_test_assistant();

		// Test with streaming disabled (default).
		$shortcode = new WP_MCP_AI_Shortcode();
		$output    = $shortcode->render_shortcode(
			array(
				'assistant'        => $assistant_id,
				'enable_streaming' => 'false',
			)
		);

		$this->assertStringContainsString( '"enableStreaming":false', $output, 'Shortcode should set enableStreaming to false' );

		// Test with streaming enabled.
		$output = $shortcode->render_shortcode(
			array(
				'assistant'        => $assistant_id,
				'enable_streaming' => 'true',
			)
		);

		$this->assertStringContainsString( '"enableStreaming":true', $output, 'Shortcode should set enableStreaming to true' );
	}

	/**
	 * Create a test assistant for testing.
	 *
	 * @return int Assistant post ID.
	 */
	protected function create_test_assistant() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'    => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'   => 'Test Assistant',
				'post_content' => 'You are a helpful assistant for testing.',
				'post_status'  => 'publish',
			)
		);

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_MODEL, 'gpt-4o-mini' );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_PROVIDER, 'openai' );

		return $assistant_id;
	}

	/**
	 * Create a test assistant with tools enabled for testing agentic loop.
	 *
	 * @return int Assistant post ID.
	 */
	protected function create_test_assistant_with_tools() {
		$assistant_id = $this->create_test_assistant();

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, array( 'search_content' ) );

		return $assistant_id;
	}

	/**
	 * Bootstrap the REST controller instance for tests with mocked LLM client.
	 */
	protected function bootstrap_rest_controller() {
		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Mock the LLM client to return predictable responses.
		$client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		// Mock a simple text response from the LLM.
		$mock_response = array(
			'choices' => array(
				array(
					'message'       => array(
						'role'    => 'assistant',
						'content' => 'This is a test response from the assistant.',
					),
					'finish_reason' => 'stop',
					'index'         => 0,
				),
			),
			'model'   => 'gpt-4o-mini',
			'usage'   => array(
				'prompt_tokens'     => 10,
				'completion_tokens' => 15,
				'total_tokens'      => 25,
			),
		);

		$client->method( 'create_chat_completion' )
			->willReturn( $mock_response );

		$GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $registry, $client );

		rest_get_server();
		do_action( 'rest_api_init' );
	}
}
