<?php
/**
 * Tests for chat transcript handling with multiple widgets on the same page.
 *
 * This test validates that when a session from one assistant is loaded into
 * a different chat widget, the transcript saves use the target widget's
 * assistant_id, not the loaded session's assistant_id.
 *
 * Note: This primarily tests the server-side API. The client-side fix is in
 * assets/js/chat.js where originalAssistantId is preserved and used for
 * transcript saves.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Chat_Transcript_Multiple_Widgets_Test extends WP_UnitTestCase {
	/**
	 * Administrator user ID for authenticated requests.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * First assistant post ID (Widget A).
	 *
	 * @var int
	 */
	protected $assistant_id_1;

	/**
	 * Second assistant post ID (Widget B).
	 *
	 * @var int
	 */
	protected $assistant_id_2;

	public function setUp(): void {
		parent::setUp();

		if ( function_exists( 'wp_mcp_ai_bootstrap' ) ) {
			wp_mcp_ai_bootstrap();
		}

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Create first assistant (Widget A)
		$this->assistant_id_1 = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Widget A Assistant',
			)
		);

		update_post_meta( $this->assistant_id_1, 'wp_mcp_ai_model', 'gpt-4' );
		update_post_meta( $this->assistant_id_1, 'wp_mcp_ai_provider', 'openai' );

		// Create second assistant (Widget B)
		$this->assistant_id_2 = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Widget B Assistant',
			)
		);

		update_post_meta( $this->assistant_id_2, 'wp_mcp_ai_model', 'gpt-4' );
		update_post_meta( $this->assistant_id_2, 'wp_mcp_ai_provider', 'openai' );

		rest_get_server();
		do_action( 'init' );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that transcript saves use the correct assistant_id when provided.
	 *
	 * Scenario: Widget A loads a session from Assistant B, but saves transcripts
	 * to Assistant A (the target widget's assistant).
	 */
	public function test_transcript_save_uses_provided_assistant_id() {
		// Simulate Widget A (assistant_id_1) saving a transcript
		// Even if the session originally came from Assistant B (assistant_id_2)
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'Content-Type', 'application/json' );

		// The client-side code (chat.js) will send originalAssistantId
		// which is Widget A's assistant_id, not the loaded session's assistant_id
		$request->set_body_params(
			array(
				'assistant_id' => $this->assistant_id_1, // Widget A's assistant (originalAssistantId)
				'session_key'  => 'test-session-from-widget-b',
				'messages'     => array(
					array(
						'role'    => 'user',
						'content' => 'This is a new message in Widget A',
					),
					array(
						'role'    => 'assistant',
						'content' => 'Response in Widget A context',
					),
				),
			)
		);

		$response = rest_do_request( $request );
		$this->assertEquals( 200, $response->get_status(), 'Transcript save should succeed' );

		$data = $response->get_data();
		$this->assertTrue( $data['success'], 'Save should be successful' );
		$this->assertEquals( $this->assistant_id_1, $data['assistant_id'], 'Saved transcript should use Widget A assistant_id' );
	}

	/**
	 * Test that retrieving a session returns the correct assistant_id.
	 */
	public function test_session_retrieval_includes_assistant_id() {
		// First, save a transcript for assistant 1
		$session_key = 'test-session-' . time();

		$save_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$save_request->set_header( 'Content-Type', 'application/json' );
		$save_request->set_body_params(
			array(
				'assistant_id' => $this->assistant_id_1,
				'session_key'  => $session_key,
				'messages'     => array(
					array(
						'role'    => 'user',
						'content' => 'Test message',
					),
				),
			)
		);

		$save_response = rest_do_request( $save_request );
		$this->assertEquals( 200, $save_response->get_status() );

		// Now retrieve it
		$get_request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts/' . $session_key );
		$get_request->set_query_params(
			array(
				'assistant_id' => $this->assistant_id_1,
			)
		);

		$get_response = rest_do_request( $get_request );
		$this->assertEquals( 200, $get_response->get_status(), 'Session retrieval should succeed' );

		$data = $get_response->get_data();
		$this->assertArrayHasKey( 'session', $data );
		$this->assertEquals( $this->assistant_id_1, $data['session']['assistant_id'], 'Retrieved session should have correct assistant_id' );
	}

	/**
	 * Test that different assistants maintain separate transcripts.
	 *
	 * This ensures that Widget A and Widget B each maintain their own
	 * transcripts even if they use the same session_key prefix.
	 */
	public function test_different_assistants_separate_transcripts() {
		$session_key_1 = 'shared-session-' . time();
		$session_key_2 = 'shared-session-' . time();

		// Save to assistant 1
		$request_1 = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request_1->set_header( 'Content-Type', 'application/json' );
		$request_1->set_body_params(
			array(
				'assistant_id' => $this->assistant_id_1,
				'session_key'  => $session_key_1,
				'messages'     => array(
					array(
						'role'    => 'user',
						'content' => 'Message for Assistant 1',
					),
				),
			)
		);

		// Save to assistant 2
		$request_2 = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request_2->set_header( 'Content-Type', 'application/json' );
		$request_2->set_body_params(
			array(
				'assistant_id' => $this->assistant_id_2,
				'session_key'  => $session_key_2,
				'messages'     => array(
					array(
						'role'    => 'user',
						'content' => 'Message for Assistant 2',
					),
				),
			)
		);

		$response_1 = rest_do_request( $request_1 );
		$response_2 = rest_do_request( $request_2 );

		$this->assertEquals( 200, $response_1->get_status() );
		$this->assertEquals( 200, $response_2->get_status() );

		$data_1 = $response_1->get_data();
		$data_2 = $response_2->get_data();

		$this->assertEquals( $this->assistant_id_1, $data_1['assistant_id'] );
		$this->assertEquals( $this->assistant_id_2, $data_2['assistant_id'] );
		$this->assertNotEquals( $data_1['assistant_id'], $data_2['assistant_id'], 'Different assistants should maintain separate transcripts' );
	}
}
