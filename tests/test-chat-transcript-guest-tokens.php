<?php
/**
 * Tests for guest token authentication with chat transcript endpoints.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Chat_Transcript_Guest_Tokens_Test extends WP_UnitTestCase {
	/**
	 * Assistant post ID used in requests.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Mock transcript handler that captures stored records.
	 *
	 * @var object
	 */
	protected $transcript_handler;

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
				'post_title'  => 'Guest Token Transcript Test Assistant',
			)
		);

		// Set up assistant configuration.
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_model', 'gpt-4' );
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_provider', 'openai' );

		rest_get_server();
		do_action( 'init' );
	}

	public function tearDown(): void {
		remove_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );
		wp_set_current_user( 0 );
		$this->transcript_handler = null;
		parent::tearDown();
	}

	/**
	 * Provide a mock handler that captures transcript records without requiring JetEngine.
	 *
	 * @return object Mock handler instance.
	 */
	public function provide_transcript_handler() {
		if ( ! $this->transcript_handler ) {
			$this->transcript_handler = new class() {
				public $records = array();

				public function update_item( $record ) {
					$this->records[] = $record;
					return true;
				}
			};
		}

		return $this->transcript_handler;
	}

	/**
	 * Test that guest users can save transcripts with a valid guest token.
	 */
	public function test_guest_user_can_save_transcript_with_guest_token() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ) );

		$guest_token = WP_MCP_AI_Shortcode::generate_guest_token( $this->assistant_id );
		$this->assertNotEmpty( $guest_token, 'Guest token should be generated' );

		$session_key = 'test-session-' . wp_generate_uuid4();
		$messages    = array(
			array(
				'role'    => 'user',
				'content' => 'Hello from guest',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Hello! How can I help you?',
			),
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'session_key', $session_key );
		$request->set_param( 'messages', $messages );
		$request->set_header( 'X-WP-MCP-AI-Guest', $guest_token );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'Response should be a WP_REST_Response' );
		$this->assertSame( 200, $response->get_status(), 'Response status should be 200 for guest users with valid token' );

		$data = $response->get_data();
		$this->assertTrue( $data['success'], 'Save should succeed' );
		$this->assertSame( $session_key, $data['session_key'], 'Session key should match' );
	}

	/**
	 * Test that guest users cannot save transcripts without a guest token.
	 */
	public function test_guest_user_cannot_save_transcript_without_token() {
		$session_key = 'test-session-' . wp_generate_uuid4();
		$messages    = array(
			array(
				'role'    => 'user',
				'content' => 'Hello from guest',
			),
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'session_key', $session_key );
		$request->set_param( 'messages', $messages );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'Response should be a WP_REST_Response' );
		$this->assertSame( 403, $response->get_status(), 'Response status should be 403 for unauthenticated guests' );
	}

	/**
	 * Test that guest users can retrieve transcript list with a valid guest token.
	 */
	public function test_guest_user_can_get_transcript_list_with_guest_token() {
		$guest_token = WP_MCP_AI_Shortcode::generate_guest_token( $this->assistant_id );
		$this->assertNotEmpty( $guest_token, 'Guest token should be generated' );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'per_page', 20 );
		$request->set_param( 'user_id', 0 ); // Guest users have user_id = 0.
		$request->set_header( 'X-WP-MCP-AI-Guest', $guest_token );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'Response should be a WP_REST_Response' );
		$this->assertSame( 200, $response->get_status(), 'Response status should be 200 for guest users with valid token' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'sessions', $data, 'Response should include sessions array' );
		$this->assertArrayHasKey( 'total', $data, 'Response should include total count' );
	}

	/**
	 * Test that guest users can retrieve a specific transcript with a valid guest token.
	 */
	public function test_guest_user_can_get_specific_transcript_with_guest_token() {
		$guest_token = WP_MCP_AI_Shortcode::generate_guest_token( $this->assistant_id );
		$this->assertNotEmpty( $guest_token, 'Guest token should be generated' );

		$session_key = 'test-session-' . wp_generate_uuid4();

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'session_key', $session_key );
		$request->set_param( 'user_id', 0 ); // Guest users have user_id = 0.
		$request->set_header( 'X-WP-MCP-AI-Guest', $guest_token );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'Response should be a WP_REST_Response' );
		// Should get 200 even if transcript doesn't exist (returns empty result).
		$this->assertSame( 200, $response->get_status(), 'Response status should be 200 for guest users with valid token' );
	}

	/**
	 * Test that guest users cannot retrieve transcripts without a guest token.
	 */
	public function test_guest_user_cannot_get_transcripts_without_token() {
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'per_page', 20 );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'Response should be a WP_REST_Response' );
		$this->assertSame( 403, $response->get_status(), 'Response status should be 403 for unauthenticated guests' );
	}

	/**
	 * Test that guest users cannot delete transcripts even with a guest token.
	 */
	public function test_guest_user_cannot_delete_transcript_with_guest_token() {
		$guest_token = WP_MCP_AI_Shortcode::generate_guest_token( $this->assistant_id );
		$this->assertNotEmpty( $guest_token, 'Guest token should be generated' );

		$session_key = 'test-session-' . wp_generate_uuid4();

		$request = new WP_REST_Request( 'DELETE', '/mcp-ai/v1/chat-transcripts/' . $session_key );
		$request->set_header( 'X-WP-MCP-AI-Guest', $guest_token );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'Response should be a WP_REST_Response' );
		// Guest users should not be able to delete transcripts.
		$this->assertNotSame( 200, $response->get_status(), 'Guest users should not be able to delete transcripts' );
	}
}
