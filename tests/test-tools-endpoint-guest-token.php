<?php
/**
 * Tests for guest token authentication with tools endpoint.
 *
 * Verifies that the /wp-json/mcp-ai/v1/tools endpoint properly handles
 * guest tokens, allowing tools to be executed from public chat widgets.
 *
 * @package WP_MCP_AI
 */

class WP_MCP_AI_Tools_Endpoint_Guest_Token_Test extends WP_UnitTestCase {
	/**
	 * Assistant post ID used in requests.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( function_exists( 'wp_mcp_ai_bootstrap' ) ) {
			wp_mcp_ai_bootstrap();
		}

		// Create assistant as unauthenticated user.
		wp_set_current_user( 0 );

		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Guest Token Tools Test Assistant',
			)
		);

		// Set up assistant configuration with a simple tool.
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_model', 'gpt-4' );
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_provider', 'openai' );
		update_post_meta(
			$this->assistant_id,
			'wp_mcp_ai_allowed_tools',
			array( 'get_current_datetime' ) // Simple tool that doesn't require external API
		);

		rest_get_server();
		do_action( 'init' );
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that guest tokens set the is_guest flag in auth context.
	 */
	public function test_guest_token_sets_is_guest_flag() {
		// Generate a guest token.
		$guest_token = WP_MCP_AI_Shortcode::generate_guest_token( $this->assistant_id );
		$this->assertNotEmpty( $guest_token, 'Guest token should be generated' );

		// Create authenticator instance.
		$authenticator = new WP_MCP_AI_REST_Authenticator();

		// Create a mock request with guest token.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-MCP-AI-Guest', $guest_token );

		// Authenticate the request.
		$auth_context = $authenticator->authenticate( $request );

		// Verify auth context includes is_guest flag.
		$this->assertIsArray( $auth_context, 'Auth context should be an array' );
		$this->assertArrayHasKey( 'is_guest', $auth_context, 'Auth context should have is_guest key' );
		$this->assertTrue( $auth_context['is_guest'], 'is_guest should be true for guest tokens' );
		$this->assertArrayHasKey( 'guest_token', $auth_context, 'Auth context should have guest_token key' );
		$this->assertEquals( $guest_token, $auth_context['guest_token'], 'Guest token should match' );
	}

	/**
	 * Test that tools endpoint accepts guest tokens without 403 error.
	 */
	public function test_tools_endpoint_accepts_guest_token() {
		// Generate a guest token.
		$guest_token = WP_MCP_AI_Shortcode::generate_guest_token( $this->assistant_id );
		$this->assertNotEmpty( $guest_token, 'Guest token should be generated' );

		// Create request to execute a tool.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'tool', 'get_current_datetime' );
		$request->set_param( 'arguments', array() );
		$request->set_header( 'X-WP-MCP-AI-Guest', $guest_token );

		// Dispatch the request.
		$response = rest_get_server()->dispatch( $request );

		// Verify the response is not a 403 error.
		$this->assertNotEquals( 403, $response->get_status(), 'Should not return 403 Forbidden with valid guest token' );

		// Should return either 200 (success) or an error other than authentication.
		// Note: The actual tool execution might fail due to missing configuration,
		// but authentication should pass.
		$status = $response->get_status();
		$this->assertTrue(
			$status === 200 || $status === 400 || $status === 500 || $status === 503,
			"Expected success or non-auth error, got status: {$status}"
		);
	}

	/**
	 * Test that tools endpoint rejects invalid guest tokens.
	 */
	public function test_tools_endpoint_rejects_invalid_guest_token() {
		// Create request with invalid guest token.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'tool', 'get_current_datetime' );
		$request->set_param( 'arguments', array() );
		$request->set_header( 'X-WP-MCP-AI-Guest', 'invalid-token-12345' );

		// Dispatch the request.
		$response = rest_get_server()->dispatch( $request );

		// Should return error (likely 401 or 403).
		$status = $response->get_status();
		$this->assertTrue(
			$status === 401 || $status === 403,
			"Expected 401 or 403 for invalid token, got: {$status}"
		);
	}

	/**
	 * Test that tools endpoint works without any authentication (should fail).
	 */
	public function test_tools_endpoint_requires_authentication() {
		// Create request without any authentication.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'tool', 'get_current_datetime' );
		$request->set_param( 'arguments', array() );

		// Dispatch the request.
		$response = rest_get_server()->dispatch( $request );

		// Should return 401 Unauthorized.
		$status = $response->get_status();
		$this->assertEquals(
			401,
			$status,
			"Expected 401 Unauthorized without authentication, got: {$status}"
		);
	}

	/**
	 * Test multiple widgets scenario with different assistant IDs.
	 */
	public function test_multiple_widgets_with_different_guest_tokens() {
		// Create a second assistant.
		$assistant_id_2 = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Second Guest Token Tools Test Assistant',
			)
		);

		update_post_meta( $assistant_id_2, 'wp_mcp_ai_model', 'gpt-4' );
		update_post_meta( $assistant_id_2, 'wp_mcp_ai_provider', 'openai' );
		update_post_meta(
			$assistant_id_2,
			'wp_mcp_ai_allowed_tools',
			array( 'get_current_datetime' )
		);

		// Generate guest tokens for both assistants.
		$guest_token_1 = WP_MCP_AI_Shortcode::generate_guest_token( $this->assistant_id );
		$guest_token_2 = WP_MCP_AI_Shortcode::generate_guest_token( $assistant_id_2 );

		$this->assertNotEmpty( $guest_token_1, 'Guest token 1 should be generated' );
		$this->assertNotEmpty( $guest_token_2, 'Guest token 2 should be generated' );
		$this->assertNotEquals( $guest_token_1, $guest_token_2, 'Guest tokens should be different' );

		// Request 1: Execute tool with first assistant's token.
		$request1 = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request1->set_param( 'assistant_id', $this->assistant_id );
		$request1->set_param( 'tool', 'get_current_datetime' );
		$request1->set_param( 'arguments', array() );
		$request1->set_header( 'X-WP-MCP-AI-Guest', $guest_token_1 );

		$response1 = rest_get_server()->dispatch( $request1 );
		$this->assertNotEquals( 403, $response1->get_status(), 'First assistant should not return 403' );

		// Request 2: Execute tool with second assistant's token.
		$request2 = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request2->set_param( 'assistant_id', $assistant_id_2 );
		$request2->set_param( 'tool', 'get_current_datetime' );
		$request2->set_param( 'arguments', array() );
		$request2->set_header( 'X-WP-MCP-AI-Guest', $guest_token_2 );

		$response2 = rest_get_server()->dispatch( $request2 );
		$this->assertNotEquals( 403, $response2->get_status(), 'Second assistant should not return 403' );

		// Request 3: Try to use first token with second assistant (should fail).
		$request3 = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request3->set_param( 'assistant_id', $assistant_id_2 );
		$request3->set_param( 'tool', 'get_current_datetime' );
		$request3->set_param( 'arguments', array() );
		$request3->set_header( 'X-WP-MCP-AI-Guest', $guest_token_1 );

		$response3 = rest_get_server()->dispatch( $request3 );
		$status3   = $response3->get_status();
		$this->assertTrue(
			$status3 === 401 || $status3 === 403,
			"Using token for wrong assistant should fail with auth error, got: {$status3}"
		);
	}
}
