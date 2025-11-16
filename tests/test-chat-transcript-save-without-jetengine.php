<?php
/**
 * Test that save endpoint returns proper error when JetEngine is not available.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for chat transcript save validation.
 */
class Test_Chat_Transcript_Save_Without_JetEngine extends WP_UnitTestCase {
	/**
	 * Administrator user ID for authenticated requests.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Assistant post ID used in requests.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( function_exists( 'wp_mcp_ai_bootstrap' ) ) {
			wp_mcp_ai_bootstrap();
		}

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant for Save Validation',
			)
		);

		// Set up assistant configuration.
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_model', 'gpt-4' );
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_provider', 'openai' );

		rest_get_server();
		do_action( 'init' );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		remove_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'return_null_handler' ), 10 );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Return null handler to simulate JetEngine not being available.
	 *
	 * @return null
	 */
	public function return_null_handler() {
		return null;
	}

	/**
	 * Test that save endpoint returns error when recorder fails.
	 *
	 * This simulates the case where JetEngine is not available or not properly configured.
	 */
	public function test_save_returns_error_when_recorder_fails() {
		// Install a filter that returns null handler (simulates JetEngine not available).
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'return_null_handler' ), 10 );

		// Prepare test data.
		$session_key = 'test-session-' . wp_generate_uuid4();
		$messages    = array(
			array(
				'role'    => 'user',
				'content' => 'Hello, this is a test message.',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Hi! How can I help you today?',
			),
		);

		// Attempt to save the conversation.
		$save_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$save_request->set_header( 'Content-Type', 'application/json' );
		$save_request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'session_key'  => $session_key,
					'messages'     => $messages,
				)
			)
		);

		$save_response = rest_get_server()->dispatch( $save_request );

		// Verify that save returns an error.
		$this->assertEquals( 500, $save_response->get_status(), 'Save should fail with 500 error' );

		$save_data = $save_response->get_data();
		$this->assertArrayHasKey( 'code', $save_data, 'Error response should have code' );
		$this->assertEquals( 'wp_mcp_ai_transcript_save_failed', $save_data['code'], 'Error code should indicate save failure' );
		$this->assertArrayHasKey( 'message', $save_data, 'Error response should have message' );
		$this->assertStringContainsString( 'JetEngine', $save_data['message'], 'Error message should mention JetEngine' );
	}

	/**
	 * Test that the error message is helpful for diagnosing the issue.
	 */
	public function test_error_message_is_helpful() {
		// Install a filter that returns null handler.
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'return_null_handler' ), 10 );

		// Prepare test data.
		$session_key = 'test-session-' . wp_generate_uuid4();
		$messages    = array(
			array(
				'role'    => 'user',
				'content' => 'Test message',
			),
		);

		// Attempt to save.
		$save_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$save_request->set_header( 'Content-Type', 'application/json' );
		$save_request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'session_key'  => $session_key,
					'messages'     => $messages,
				)
			)
		);

		$save_response = rest_get_server()->dispatch( $save_request );
		$save_data     = $save_response->get_data();

		// Verify error message provides actionable information.
		$message = $save_data['message'];
		$this->assertStringContainsString( 'Failed to save transcript', $message, 'Error should indicate failure' );
		$this->assertStringContainsString( 'JetEngine Custom Content Types', $message, 'Error should mention JetEngine CCT' );
		$this->assertStringContainsString( 'properly configured', $message, 'Error should mention configuration' );
	}

	/**
	 * Test that success and failure responses have different structures.
	 */
	public function test_success_vs_failure_response_structure() {
		// First, test failure case (no handler).
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'return_null_handler' ), 10 );

		$session_key = 'test-session-' . wp_generate_uuid4();
		$messages    = array(
			array(
				'role'    => 'user',
				'content' => 'Test',
			),
		);

		$failure_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$failure_request->set_header( 'Content-Type', 'application/json' );
		$failure_request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'session_key'  => $session_key,
					'messages'     => $messages,
				)
			)
		);

		$failure_response = rest_get_server()->dispatch( $failure_request );
		$failure_data     = $failure_response->get_data();

		// Verify failure structure.
		$this->assertArrayHasKey( 'code', $failure_data, 'Failure should have error code' );
		$this->assertArrayHasKey( 'message', $failure_data, 'Failure should have error message' );
		$this->assertArrayNotHasKey( 'success', $failure_data, 'Failure should not have success flag' );

		// Now test success case with a mock handler.
		remove_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'return_null_handler' ), 10 );

		add_filter(
			'wp_mcp_ai_chat_transcript_handler',
			function () {
				return new class() {
					public function update_item( $record ) {
						return true;
					}
				};
			},
			10
		);

		$success_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$success_request->set_header( 'Content-Type', 'application/json' );
		$success_request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'session_key'  => $session_key,
					'messages'     => $messages,
				)
			)
		);

		$success_response = rest_get_server()->dispatch( $success_request );
		$success_data     = $success_response->get_data();

		// Verify success structure.
		$this->assertArrayHasKey( 'success', $success_data, 'Success should have success flag' );
		$this->assertTrue( $success_data['success'], 'Success flag should be true' );
		$this->assertArrayHasKey( 'session_key', $success_data, 'Success should have session_key' );
		$this->assertArrayHasKey( 'message', $success_data, 'Success should have message' );
		$this->assertArrayNotHasKey( 'code', $success_data, 'Success should not have error code' );
	}
}
