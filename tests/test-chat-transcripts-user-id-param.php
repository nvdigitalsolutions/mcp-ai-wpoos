<?php
/**
 * Tests for ensuring user_id parameter is properly accepted and processed
 * in the chat-transcripts endpoint.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test chat transcripts endpoint handles user_id parameter correctly.
 */
class Test_Chat_Transcripts_User_ID_Param extends WP_UnitTestCase {

	/**
	 * Assistant ID for testing.
	 *
	 * @var int
	 */
	private $assistant_id;

	/**
	 * Set up test environment before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create a test assistant.
		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant for User ID',
				'post_status' => 'publish',
			)
		);

		// Set required assistant metadata.
		update_post_meta( $this->assistant_id, 'mcp_ai_provider', 'openai' );
		update_post_meta( $this->assistant_id, 'mcp_ai_model', 'gpt-4' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		wp_delete_post( $this->assistant_id, true );
		parent::tearDown();
	}

	/**
	 * Test that logged-in user can fetch transcripts with their user_id.
	 */
	public function test_logged_in_user_can_fetch_with_user_id() {
		$user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);

		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'user_id', $user_id );
		$request->set_param( 'per_page', 20 );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'Response should be a WP_REST_Response' );
		$this->assertSame( 200, $response->get_status(), 'Response status should be 200 for authenticated user' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'sessions', $data, 'Response should include sessions array' );
		$this->assertArrayHasKey( 'total', $data, 'Response should include total count' );
	}

	/**
	 * Test that omitting user_id parameter defaults to current user.
	 */
	public function test_omitting_user_id_defaults_to_current_user() {
		$user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);

		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		// Intentionally NOT setting user_id parameter.
		$request->set_param( 'per_page', 20 );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'Response should be a WP_REST_Response' );
		$this->assertSame( 200, $response->get_status(), 'Response status should be 200 when user_id is omitted' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'sessions', $data, 'Response should include sessions array' );
	}

	/**
	 * Test that guest user with token can fetch transcripts with user_id=0.
	 */
	public function test_guest_user_can_fetch_with_user_id_zero() {
		$guest_token = WP_MCP_AI_Shortcode::generate_guest_token( $this->assistant_id );
		$this->assertNotEmpty( $guest_token, 'Guest token should be generated' );

		// Ensure no user is logged in.
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'user_id', 0 ); // Guest users should pass 0.
		$request->set_param( 'per_page', 20 );
		$request->set_header( 'X-WP-MCP-AI-Guest', $guest_token );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'Response should be a WP_REST_Response' );
		$this->assertSame( 200, $response->get_status(), 'Response status should be 200 for guest with token and user_id=0' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'sessions', $data, 'Response should include sessions array' );
	}

	/**
	 * Test that user_id parameter is respected when filtering transcripts.
	 */
	public function test_user_id_parameter_filters_transcripts_correctly() {
		// Create two users.
		$user1_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		$user2_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		// Create a transcript for user1 (if JetEngine is available).
		// For this test, we're just verifying the parameter is accepted.
		// The actual filtering behavior is tested in other test files.

		wp_set_current_user( $user1_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'user_id', $user1_id );
		$request->set_param( 'per_page', 20 );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), 'User should be able to query their own transcripts with user_id parameter' );

		// Now try querying as user2 with user1's ID - should fail permission check.
		// unless they're an admin.
		wp_set_current_user( $user2_id );

		$request2 = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request2->set_param( 'user_id', $user1_id ); // Try to access user1's transcripts.
		$request2->set_param( 'per_page', 20 );

		$response2 = rest_get_server()->dispatch( $request2 );

		// Non-admin users should only be able to query their own transcripts.
		// The permission check should prevent cross-user access.
		$this->assertNotEquals( 200, $response2->get_status(), 'Non-admin user should not be able to query other users transcripts' );
	}

	/**
	 * Test that admin can query any user's transcripts by user_id.
	 */
	public function test_admin_can_query_any_user_transcripts() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$user_id  = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $admin_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'user_id', $user_id );
		$request->set_param( 'per_page', 20 );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), 'Admin should be able to query any user transcripts with user_id parameter' );
	}

	/**
	 * Test that user_id parameter works with assistant_id filtering.
	 */
	public function test_user_id_works_with_assistant_id_filter() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'user_id', $user_id );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'per_page', 20 );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), 'Should be able to combine user_id and assistant_id filters' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'sessions', $data, 'Response should include sessions array' );
	}

	/**
	 * Test that invalid user_id is handled gracefully.
	 */
	public function test_invalid_user_id_is_handled() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'user_id', 'invalid' ); // Invalid user ID.
		$request->set_param( 'per_page', 20 );

		$response = rest_get_server()->dispatch( $request );

		// Should handle invalid user_id gracefully - it will be sanitized to 0.
		$this->assertInstanceOf( WP_REST_Response::class, $response );
	}
}
