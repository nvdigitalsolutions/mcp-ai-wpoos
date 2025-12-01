<?php
/**
 * Tests for the fix of missing chat transcript 404 error.
 *
 * This test verifies that when a transcript doesn't exist in the database,
 * the API returns a graceful response instead of a 404 error.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test chat transcript retrieval for non-existent sessions.
 */
class Test_Chat_Transcript_Missing_Session_Fix extends WP_UnitTestCase {

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
				'post_title'  => 'Test Assistant for Missing Session',
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
	 * Test that GET request with non-existent session_key as query parameter
	 * returns a graceful response instead of 404.
	 *
	 * This is the approach used by the JavaScript frontend.
	 */
	public function test_missing_session_via_query_param_returns_graceful_response() {
		wp_set_current_user( $this->user_id );

		// Generate a session key that doesn't exist in the database.
		$session_key = 'non-existent-session-' . wp_generate_uuid4();

		// Make request using query parameter approach (same as JavaScript frontend).
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'session_key', $session_key );
		$request->set_param( 'user_id', $this->user_id );
		$request->set_param( 'assistant_id', $this->assistant_id );

		$response = rest_get_server()->dispatch( $request );

		// Should return a successful response, not 404.
		$this->assertInstanceOf( WP_REST_Response::class, $response, 'Response should be a WP_REST_Response' );
		$status = $response->get_status();
		$this->assertNotEquals( 404, $status, 'Should not return 404 for non-existent session' );

		// Response should be 200 OK.
		$this->assertEquals( 200, $status, 'Should return 200 for graceful handling' );

		// Response should contain null session.
		$data = $response->get_data();
		$this->assertIsArray( $data, 'Response data should be an array' );
		$this->assertArrayHasKey( 'session', $data, 'Response should have session key' );
		$this->assertNull( $data['session'], 'Session should be null for non-existent transcript' );

		// Response should contain a message.
		$this->assertArrayHasKey( 'message', $data, 'Response should have message key' );
		$this->assertIsString( $data['message'], 'Message should be a string' );
		$this->assertNotEmpty( $data['message'], 'Message should not be empty' );
	}

	/**
	 * Test that GET request with non-existent session_key as path parameter
	 * also returns a graceful response instead of 404.
	 */
	public function test_missing_session_via_path_param_returns_graceful_response() {
		wp_set_current_user( $this->user_id );

		// Generate a session key that doesn't exist in the database.
		$session_key = 'non-existent-session-' . wp_generate_uuid4();

		// Make request using path parameter approach.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts/' . $session_key );
		$request->set_param( 'user_id', $this->user_id );
		$request->set_param( 'assistant_id', $this->assistant_id );

		$response = rest_get_server()->dispatch( $request );

		// Should return a successful response, not 404.
		$this->assertInstanceOf( WP_REST_Response::class, $response, 'Response should be a WP_REST_Response' );
		$status = $response->get_status();
		$this->assertNotEquals( 404, $status, 'Should not return 404 for non-existent session' );

		// Response should be 200 OK.
		$this->assertEquals( 200, $status, 'Should return 200 for graceful handling' );

		// Response should contain null session.
		$data = $response->get_data();
		$this->assertIsArray( $data, 'Response data should be an array' );
		$this->assertArrayHasKey( 'session', $data, 'Response should have session key' );
		$this->assertNull( $data['session'], 'Session should be null for non-existent transcript' );

		// Response should contain a message.
		$this->assertArrayHasKey( 'message', $data, 'Response should have message key' );
		$this->assertIsString( $data['message'], 'Message should be a string' );
		$this->assertNotEmpty( $data['message'], 'Message should not be empty' );
	}

	/**
	 * Test that the error code 'wp_mcp_ai_transcript_missing' is handled gracefully.
	 *
	 * This tests the specific error code that was causing the 404 before the fix.
	 */
	public function test_transcript_missing_error_code_is_handled_gracefully() {
		wp_set_current_user( $this->user_id );

		// Use a session key that will definitely not exist.
		$session_key = 'definitely-missing-' . time() . '-' . wp_generate_uuid4();

		// Test via query parameter.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'session_key', $session_key );
		$request->set_param( 'user_id', $this->user_id );

		$response = rest_get_server()->dispatch( $request );

		// Should handle wp_mcp_ai_transcript_missing error gracefully.
		$this->assertEquals( 200, $response->get_status(), 'Should return 200 for missing transcript error' );

		$data = $response->get_data();
		$this->assertNull( $data['session'], 'Session should be null' );
		$this->assertNotEmpty( $data['message'], 'Should have an error message' );
	}
}
