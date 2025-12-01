<?php
/**
 * Auth Split-Brain Security Tests for WP oOS
 *
 * Tests to verify that the /mcp endpoint enforces bearer-only authentication
 * for remote MCP access, while nonce-only authentication should fail.
 *
 * @package WP_MCP_AI
 */

/**
 * Test auth split-brain security requirements.
 *
 * @group security
 * @group rest
 * @group mcp
 */
class WP_MCP_AI_Auth_Split_Brain_Test extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure REST server is initialized.
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tearDown();
	}

	/**
	 * Test that /mcp endpoint rejects nonce-only authentication.
	 *
	 * Goal: nonce-only access fails for remote MCP.
	 */
	public function test_mcp_endpoint_rejects_nonce_only_auth() {
		// Create admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Create a valid nonce.
		$nonce = wp_create_nonce( 'wp_rest' );

		// Attempt to access /mcp endpoint with only nonce authentication.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'X-WP-Nonce', $nonce );
		$request->set_param( 'jsonrpc', '2.0' );
		$request->set_param( 'id', 1 );
		$request->set_param( 'method', 'initialize' );
		$request->set_param( 'params', array() );

		$response = rest_do_request( $request );

		// Should return 401 Unauthorized.
		$this->assertEquals(
			401,
			$response->get_status(),
			'MCP endpoint should reject nonce-only authentication and return 401'
		);

		// Verify error message indicates bearer token is required.
		$data = $response->get_data();
		$this->assertArrayHasKey( 'code', $data );
		$this->assertStringContainsString(
			'bearer',
			strtolower( $data['message'] ?? '' ),
			'Error message should mention bearer token requirement'
		);
	}

	/**
	 * Test that /mcp endpoint accepts bearer token authentication.
	 *
	 * Goal: bearer succeeds for remote MCP.
	 */
	public function test_mcp_endpoint_accepts_bearer_token_auth() {
		// Create admin user and assistant.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$assistant_id = $this->create_assistant_post();

		// Issue a credential token.
		$issued = WP_MCP_AI_Credentials::issue_credential( $assistant_id, $user_id );
		$this->assertArrayHasKey( 'token', $issued );

		// Clear current user to simulate remote access.
		wp_set_current_user( 0 );

		// Attempt to access /mcp endpoint with bearer token.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Authorization', 'Bearer ' . $issued['token'] );
		$request->set_param( 'jsonrpc', '2.0' );
		$request->set_param( 'id', 1 );
		$request->set_param( 'method', 'initialize' );
		$request->set_param( 'params', array() );

		$response = rest_do_request( $request );

		// Should return 200 OK.
		$this->assertEquals(
			200,
			$response->get_status(),
			'MCP endpoint should accept bearer token authentication and return 200'
		);

		// Verify response contains expected MCP initialization data.
		$data = $response->get_data();
		$this->assertArrayHasKey( 'jsonrpc', $data );
		$this->assertEquals( '2.0', $data['jsonrpc'] );
	}

	/**
	 * Test that /mcp endpoint rejects both nonce and bearer when bearer is invalid.
	 */
	public function test_mcp_endpoint_rejects_invalid_bearer_token() {
		// Create admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$nonce = wp_create_nonce( 'wp_rest' );

		// Attempt with invalid bearer token and valid nonce.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Authorization', 'Bearer invalid_token_12345' );
		$request->set_header( 'X-WP-Nonce', $nonce );
		$request->set_param( 'jsonrpc', '2.0' );
		$request->set_param( 'id', 1 );
		$request->set_param( 'method', 'initialize' );
		$request->set_param( 'params', array() );

		$response = rest_do_request( $request );

		// Should return 401 or 403.
		$this->assertContains(
			$response->get_status(),
			array( 401, 403 ),
			'MCP endpoint should reject invalid bearer token'
		);
	}

	/**
	 * Test that other endpoints still accept nonce authentication.
	 *
	 * Ensures we haven't broken nonce auth for non-MCP endpoints.
	 */
	public function test_other_endpoints_still_accept_nonce_auth() {
		// Create admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$nonce = wp_create_nonce( 'wp_rest' );

		// Test /assistants endpoint with nonce.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', $nonce );

		$response = rest_do_request( $request );

		// Should return 200 OK for /assistants with nonce.
		$this->assertEquals(
			200,
			$response->get_status(),
			'Assistants endpoint should still accept nonce authentication'
		);
	}

	/**
	 * Test mesh API key authentication for /mcp endpoint.
	 */
	public function test_mcp_endpoint_accepts_mesh_api_key() {
		// Enable mesh networking and set an inbound key.
		$settings                         = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_mesh']          = true;
		$settings['mesh_inbound_api_key'] = 'test-mesh-key-123456';
		update_option( 'wp_mcp_ai_settings', $settings );

		// Attempt to access /mcp with mesh API key.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'X-WP-MCP-AI-Mesh-Key', 'test-mesh-key-123456' );
		$request->set_param( 'jsonrpc', '2.0' );
		$request->set_param( 'id', 1 );
		$request->set_param( 'method', 'initialize' );
		$request->set_param( 'params', array() );

		$response = rest_do_request( $request );

		// Should return 200 OK.
		$this->assertEquals(
			200,
			$response->get_status(),
			'MCP endpoint should accept mesh API key authentication'
		);

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
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
				'post_title'  => 'Test MCP Assistant',
				'post_status' => 'publish',
			)
		);

		$this->assertNotWPError( $assistant_id );
		$this->assertNotEmpty( $assistant_id );

		return $assistant_id;
	}
}
