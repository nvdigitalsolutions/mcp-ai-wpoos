<?php
/**
 * Test for save and immediate retrieve of chat transcripts.
 *
 * This test reproduces the issue where a conversation is saved via POST /chat-transcripts
 * but then cannot be retrieved immediately via GET /chat-transcripts?session_key=xxx
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for chat transcript save→retrieve cycle.
 */
class Test_Chat_Transcript_Save_Retrieve_Cycle extends WP_UnitTestCase {
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
	 * Mock transcript handler that captures stored records.
	 *
	 * @var object
	 */
	protected $transcript_handler;

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
				'post_title'  => 'Test Assistant for Save-Retrieve',
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
					// Store records indexed by session_key and cct_author_id for retrieval.
					$session_key = isset( $record['session_key'] ) ? $record['session_key'] : '';
					$user_id     = isset( $record['cct_author_id'] ) ? $record['cct_author_id'] : 0;

					if ( '' === $session_key || 0 === $user_id ) {
						return new WP_Error( 'invalid_record', 'Invalid session_key or user_id' );
					}

					// Create a unique key for storage.
					$key = $session_key . '_' . $user_id;

					// Store the record.
					$this->records[ $key ] = $record;

					return true;
				}

				/**
				 * Get records for a session_key and user_id.
				 *
				 * @param string $session_key Session key.
				 * @param int    $user_id     User ID.
				 * @return array Array of records (may be empty).
				 */
				public function get_records( $session_key, $user_id ) {
					$key = $session_key . '_' . $user_id;
					if ( isset( $this->records[ $key ] ) ) {
						return array( $this->records[ $key ] );
					}
					return array();
				}
			};
		}

		return $this->transcript_handler;
	}

	/**
	 * Test that a saved conversation can be retrieved immediately.
	 *
	 * This reproduces the reported issue where POST /chat-transcripts succeeds
	 * but GET /chat-transcripts?session_key=xxx fails with 404.
	 */
	public function test_save_and_retrieve_conversation() {
		// Install the mock handler.
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

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
			array(
				'role'    => 'user',
				'content' => 'Can you tell me about PHP testing?',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Sure! PHPUnit is a popular testing framework for PHP.',
			),
		);

		// Step 1: Save the conversation via POST /chat-transcripts.
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

		// Verify save succeeded.
		$this->assertEquals( 200, $save_response->get_status(), 'Save request should succeed' );
		$save_data = $save_response->get_data();
		$this->assertTrue( $save_data['success'], 'Save response should indicate success' );
		$this->assertEquals( $session_key, $save_data['session_key'], 'Returned session_key should match' );

		// Verify record was stored in mock handler.
		$handler        = $this->provide_transcript_handler();
		$stored_records = $handler->get_records( $session_key, $this->admin_id );
		$this->assertNotEmpty( $stored_records, 'Mock handler should have stored the record' );
		$this->assertCount( 1, $stored_records, 'Should have exactly one record' );

		// Verify stored record structure.
		$stored_record = $stored_records[0];
		$this->assertEquals( $session_key, $stored_record['session_key'], 'Stored session_key should match' );
		$this->assertEquals( $this->admin_id, $stored_record['cct_author_id'], 'Stored user_id should match' );
		$this->assertEquals( (string) $this->assistant_id, $stored_record['assistant_id'], 'Stored assistant_id should match' );

		// Verify request_payload contains the messages.
		$this->assertArrayHasKey( 'request_payload', $stored_record, 'Record should have request_payload' );
		$request_payload = json_decode( $stored_record['request_payload'], true );
		$this->assertIsArray( $request_payload, 'request_payload should be valid JSON' );
		$this->assertArrayHasKey( 'messages', $request_payload, 'request_payload should have messages' );
		$this->assertCount( 4, $request_payload['messages'], 'Should have all 4 messages' );

		// Step 2: Retrieve the conversation via GET /chat-transcripts?session_key=xxx.
		$retrieve_request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$retrieve_request->set_param( 'session_key', $session_key );
		$retrieve_request->set_param( 'user_id', $this->admin_id );
		$retrieve_request->set_param( 'assistant_id', $this->assistant_id );

		$retrieve_response = rest_get_server()->dispatch( $retrieve_request );

		// This is where the bug occurs - the retrieve should succeed but it fails.
		$this->assertEquals( 200, $retrieve_response->get_status(), 'Retrieve request should succeed' );
		$retrieve_data = $retrieve_response->get_data();

		$this->assertArrayHasKey( 'session', $retrieve_data, 'Response should have session data' );
		$this->assertNotNull( $retrieve_data['session'], 'Session should not be null' );

		// Verify session structure.
		$session = $retrieve_data['session'];
		$this->assertArrayHasKey( 'session_key', $session, 'Session should have session_key' );
		$this->assertEquals( $session_key, $session['session_key'], 'Session key should match' );
		$this->assertArrayHasKey( 'messages', $session, 'Session should have messages' );
		$this->assertCount( 4, $session['messages'], 'Should have all 4 messages' );
	}
}
