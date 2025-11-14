<?php
/**
 * Tests for retrieving a specific chat transcript by session key via query parameter.
 *
 * This tests the alternative endpoint: GET /chat-transcripts?session_key=xxx
 * as opposed to GET /chat-transcripts/{session_key}
 *
 * @package WP_MCP_AI
 */

/**
 * Test chat transcript retrieval via GET /chat-transcripts?session_key=xxx endpoint.
 */
class Test_Chat_Transcript_Get_By_Query_Param extends WP_UnitTestCase {

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
				'post_title'  => 'Test Assistant for Query Param',
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
	 * Test that GET route exists for /chat-transcripts with query params.
	 */
	public function test_get_route_exists() {
		$routes    = rest_get_server()->get_routes();
		$namespace = '/mcp-ai/v1';
		$route     = '/chat-transcripts';

		$this->assertArrayHasKey( $namespace . $route, $routes, 'Route should be registered' );

		$route_config = $routes[ $namespace . $route ];
		$methods      = array();

		foreach ( $route_config as $handler ) {
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
	 * Test that GET /chat-transcripts has args defined for query parameters.
	 */
	public function test_route_has_args_definition() {
		$routes    = rest_get_server()->get_routes();
		$namespace = '/mcp-ai/v1';
		$route     = '/chat-transcripts';

		$this->assertArrayHasKey( $namespace . $route, $routes, 'Route should be registered' );

		$route_config = $routes[ $namespace . $route ];
		$get_handler  = null;

		foreach ( $route_config as $handler ) {
			if ( isset( $handler['methods'] ) ) {
				$methods = is_array( $handler['methods'] ) ? array_keys( $handler['methods'] ) : array( $handler['methods'] );
				if ( in_array( 'GET', $methods, true ) ) {
					$get_handler = $handler;
					break;
				}
			}
		}

		$this->assertNotNull( $get_handler, 'GET handler should exist' );
		$this->assertArrayHasKey( 'args', $get_handler, 'GET handler should have args definition' );

		$args = $get_handler['args'];

		// Check that key query parameters are defined.
		$this->assertArrayHasKey( 'session_key', $args, 'session_key arg should be defined' );
		$this->assertArrayHasKey( 'user_id', $args, 'user_id arg should be defined' );
		$this->assertArrayHasKey( 'assistant_id', $args, 'assistant_id arg should be defined' );
		$this->assertArrayHasKey( 'page', $args, 'page arg should be defined' );
		$this->assertArrayHasKey( 'per_page', $args, 'per_page arg should be defined' );

		// Verify session_key has sanitize callback.
		$this->assertArrayHasKey( 'sanitize_callback', $args['session_key'], 'session_key should have sanitize_callback' );
	}

	/**
	 * Test that logged-in user can fetch transcript by session key in query param.
	 */
	public function test_logged_in_user_can_fetch_by_session_key_query_param() {
		wp_set_current_user( $this->user_id );

		$session_key = 'test-session-' . wp_generate_uuid4();

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'session_key', $session_key );
		$request->set_param( 'user_id', $this->user_id );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'Response should be a WP_REST_Response' );

		// The response might be 200 with null session (if JetEngine not available) or 404 (not found).
		// What's important is that it doesn't fail with 400 (bad request) or 500 (server error).
		$status = $response->get_status();
		$this->assertNotEquals( 400, $status, 'Should not return 400 for valid request' );
		$this->assertNotEquals( 500, $status, 'Should not return 500 for valid request' );
	}

	/**
	 * Test that session_key parameter is properly sanitized.
	 */
	public function test_session_key_is_sanitized() {
		wp_set_current_user( $this->user_id );

		// Session key with invalid characters.
		$session_key = 'test-session-' . wp_generate_uuid4() . '!@#$%^&*()';

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'session_key', $session_key );
		$request->set_param( 'user_id', $this->user_id );

		$response = rest_get_server()->dispatch( $request );

		// Should not fail with server error due to sanitization.
		$this->assertNotEquals( 500, $response->get_status(), 'Should not return 500 for sanitization' );

		// Response should be valid (either 200, 404, or 503 depending on database state).
		$this->assertContains(
			$response->get_status(),
			array( 200, 404, 503 ),
			'Should return valid status after sanitization'
		);
	}

	/**
	 * Test that user_id and assistant_id parameters are passed correctly.
	 */
	public function test_user_id_and_assistant_id_parameters() {
		wp_set_current_user( $this->user_id );

		$session_key = 'test-session-' . wp_generate_uuid4();

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'session_key', $session_key );
		$request->set_param( 'user_id', $this->user_id );
		$request->set_param( 'assistant_id', $this->assistant_id );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'Response should be a WP_REST_Response' );

		// Should not fail with bad request.
		$this->assertNotEquals( 400, $response->get_status(), 'Should not return 400 when all params provided' );
	}

	/**
	 * Test that when no session_key is provided, it lists all sessions.
	 */
	public function test_without_session_key_lists_all_sessions() {
		wp_set_current_user( $this->user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'user_id', $this->user_id );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'Response should be a WP_REST_Response' );

		// Should return 200 or 503 (if database not available).
		$this->assertContains(
			$response->get_status(),
			array( 200, 503 ),
			'Should return 200 or 503 for list request'
		);

		// If 200, should have sessions array.
		if ( 200 === $response->get_status() ) {
			$data = $response->get_data();
			$this->assertArrayHasKey( 'sessions', $data, 'Response should contain sessions array' );
			$this->assertIsArray( $data['sessions'], 'sessions should be an array' );
		}
	}

	/**
	 * Test that pagination parameters work correctly.
	 */
	public function test_pagination_parameters() {
		wp_set_current_user( $this->user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'user_id', $this->user_id );
		$request->set_param( 'page', 1 );
		$request->set_param( 'per_page', 10 );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'Response should be a WP_REST_Response' );

		// Should not fail with bad request.
		$this->assertNotEquals( 400, $response->get_status(), 'Should not return 400 with pagination params' );

		// If 200, should have pagination info.
		if ( 200 === $response->get_status() ) {
			$data = $response->get_data();
			$this->assertArrayHasKey( 'per_page', $data, 'Response should contain per_page' );
			$this->assertArrayHasKey( 'page', $data, 'Response should contain page' );
			$this->assertEquals( 10, $data['per_page'], 'per_page should be 10' );
			$this->assertEquals( 1, $data['page'], 'page should be 1' );
		}
	}

	/**
	 * Test that UUID format session keys are accepted.
	 */
	public function test_uuid_format_session_key() {
		wp_set_current_user( $this->user_id );

		// Standard UUID format with hyphens.
		$session_key = 'c79b0dc6-3088-48b6-82e2-a56c8fe6179f';

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'session_key', $session_key );
		$request->set_param( 'user_id', $this->user_id );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'Response should be a WP_REST_Response' );

		// Should not fail with bad request (sanitization should preserve hyphens).
		$this->assertNotEquals( 400, $response->get_status(), 'Should accept UUID format session key' );
		$this->assertNotEquals( 500, $response->get_status(), 'Should not cause server error' );
	}

	/**
	 * Test that unauthenticated user cannot access without guest token.
	 */
	public function test_unauthenticated_user_denied() {
		wp_set_current_user( 0 );

		$session_key = 'test-session-' . wp_generate_uuid4();

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'session_key', $session_key );

		$response = rest_get_server()->dispatch( $request );

		// Should fail permission check.
		$this->assertContains(
			$response->get_status(),
			array( 400, 401, 403 ),
			'Unauthenticated user should be denied'
		);
	}
}
