<?php
/**
 * Tests for retrieving a specific chat transcript by session key via RESTful route.
 *
 * @package WP_MCP_AI
 */

/**
 * Test chat transcript retrieval via /chat-transcripts/{session_key} endpoint.
 */
class Test_Chat_Transcript_Get_By_Session_Key extends WP_UnitTestCase {

	/**
	 * Assistant ID for testing.
	 *
	 * @var int
	 */
	private $assistant_id;

	/**
	 * User ID for testing.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Set up test environment before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create a test user.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);

		// Create a test assistant.
		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant for Session Key',
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
		wp_delete_user( $this->user_id );
		parent::tearDown();
	}

	/**
	 * Test that GET route exists for /chat-transcripts/{session_key}.
	 */
	public function test_get_route_exists() {
		$routes        = rest_get_server()->get_routes();
		$namespace     = '/mcp-ai/v1';
		$route_pattern = '/chat-transcripts/(?P<session_key>[^/]+)';

		$this->assertArrayHasKey( $namespace . $route_pattern, $routes, 'Route should be registered' );

		$route   = $routes[ $namespace . $route_pattern ];
		$methods = array();

		foreach ( $route as $handler ) {
			if ( isset( $handler['methods'] ) ) {
				if ( is_array( $handler['methods'] ) ) {
					$methods = array_merge( $methods, array_keys( $handler['methods'] ) );
				} else {
					$methods[] = $handler['methods'];
				}
			}
		}

		$this->assertContains( 'GET', $methods, 'GET method should be registered for the route' );
	}

	/**
	 * Test that logged-in user gets appropriate response when fetching non-existent transcript.
	 */
	public function test_logged_in_user_can_fetch_by_session_key_in_path() {
		wp_set_current_user( $this->user_id );

		$session_key = 'test-session-' . wp_generate_uuid4();

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts/' . $session_key );
		$request->set_param( 'user_id', $this->user_id );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'Response should be a WP_REST_Response' );

		$status = $response->get_status();

		// The response should be either:
		// - 404 if the transcript doesn't exist (when JetEngine is available)
		// - 200 with null session if transcript storage is unavailable (when JetEngine is not active)
		$this->assertContains(
			$status,
			array( 200, 404 ),
			'Should return 404 for missing transcript or 200 if storage unavailable'
		);

		// If 200, verify it has the expected structure for unavailable storage
		if ( 200 === $status ) {
			$data = $response->get_data();
			$this->assertIsArray( $data, 'Data should be an array' );
			$this->assertArrayHasKey( 'session', $data, 'Data should have session key' );
			$this->assertNull( $data['session'], 'Session should be null when storage unavailable' );
		}
	}

	/**
	 * Test that session_key parameter is required.
	 */
	public function test_empty_session_key_returns_error() {
		wp_set_current_user( $this->user_id );

		// Try with empty session key - WordPress will likely not match the route,
		// but let's test with a slash which should match but be empty.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts/ ' );
		$request->set_param( 'user_id', $this->user_id );

		$response = rest_get_server()->dispatch( $request );

		// This might be a 404 (route not found) or 400 (invalid parameter).
		// Either is acceptable for an empty session key.
		$this->assertContains(
			$response->get_status(),
			array( 400, 404 ),
			'Empty session key should return error status'
		);
	}

	/**
	 * Test that user must be authenticated to fetch transcripts.
	 */
	public function test_unauthenticated_user_cannot_fetch_without_guest_token() {
		wp_set_current_user( 0 );

		$session_key = 'test-session-' . wp_generate_uuid4();

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts/' . $session_key );
		// Not providing user_id and not logged in.

		$response = rest_get_server()->dispatch( $request );

		// Should fail permission check.
		$this->assertContains(
			$response->get_status(),
			array( 400, 401, 403 ),
			'Unauthenticated user without guest token should be denied'
		);
	}

	/**
	 * Test that user_id parameter is respected when fetching by session key.
	 */
	public function test_user_id_parameter_is_respected() {
		wp_set_current_user( $this->user_id );

		$session_key = 'test-session-' . wp_generate_uuid4();

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts/' . $session_key );
		$request->set_param( 'user_id', $this->user_id );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'Response should be a WP_REST_Response' );

		$status = $response->get_status();

		// Should return 404 for non-existent transcript or 200 if storage unavailable
		$this->assertContains(
			$status,
			array( 200, 404 ),
			'Should return 404 for missing transcript or 200 if storage unavailable'
		);
	}

	/**
	 * Test that assistant_id parameter is accepted.
	 */
	public function test_assistant_id_parameter_is_accepted() {
		wp_set_current_user( $this->user_id );

		$session_key = 'test-session-' . wp_generate_uuid4();

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts/' . $session_key );
		$request->set_param( 'user_id', $this->user_id );
		$request->set_param( 'assistant_id', $this->assistant_id );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'Response should be a WP_REST_Response' );

		$status = $response->get_status();

		// Should return 404 for non-existent transcript or 200 if storage unavailable
		$this->assertContains(
			$status,
			array( 200, 404 ),
			'Should return 404 for missing transcript or 200 if storage unavailable'
		);
	}

	/**
	 * Test that admin can fetch any user's transcript by session key.
	 */
	public function test_admin_can_fetch_any_user_transcript() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$session_key = 'test-session-' . wp_generate_uuid4();

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts/' . $session_key );
		$request->set_param( 'user_id', $this->user_id ); // Querying another user's transcript.

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'Response should be a WP_REST_Response' );

		// Admin should not get permission denied.
		$this->assertNotContains(
			$response->get_status(),
			array( 401, 403 ),
			'Admin should have permission to fetch any user transcript'
		);

		wp_delete_user( $admin_id );
	}

	/**
	 * Test that non-admin user cannot access other user's transcripts.
	 */
	public function test_non_admin_cannot_access_other_user_transcripts() {
		$user2_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user2_id );

		$session_key = 'test-session-' . wp_generate_uuid4();

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts/' . $session_key );
		$request->set_param( 'user_id', $this->user_id ); // Trying to access user1's transcript.

		$response = rest_get_server()->dispatch( $request );

		// Should fail permission check.
		$this->assertContains(
			$response->get_status(),
			array( 400, 401, 403 ),
			'Non-admin user should not be able to access other users transcripts'
		);

		wp_delete_user( $user2_id );
	}

	/**
	 * Test that guest token allows access to transcripts.
	 */
	public function test_guest_token_allows_transcript_access() {
		if ( ! class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Shortcode class not available' );
		}

		$guest_token = WP_MCP_AI_Shortcode::generate_guest_token( $this->assistant_id );
		$this->assertNotEmpty( $guest_token, 'Guest token should be generated' );

		wp_set_current_user( 0 ); // Guest user.

		$session_key = 'test-session-' . wp_generate_uuid4();

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts/' . $session_key );
		$request->set_param( 'user_id', 0 );
		$request->set_header( 'X-WP-MCP-AI-Guest', $guest_token );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'Response should be a WP_REST_Response' );

		// Guest with valid token should not get permission denied.
		$this->assertNotContains(
			$response->get_status(),
			array( 401, 403 ),
			'Guest user with valid token should have access'
		);
	}
}
