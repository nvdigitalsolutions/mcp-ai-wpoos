<?php
/**
 * Test that saving a conversation to an existing session updates instead of creating duplicates.
 *
 * This test verifies the fix for the bug where repeated saves to the same session_key
 * would create multiple transcript records instead of updating the existing one.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for session update vs duplicate creation.
 */
class Test_Chat_Transcript_Session_Update_Not_Duplicate extends WP_UnitTestCase {
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
	 * Mock transcript handler that tracks update vs insert operations.
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
				'post_title'  => 'Test Assistant for Duplicate Prevention',
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
		remove_filter( 'wp_mcp_ai_find_existing_transcript_id', array( $this, 'mock_find_existing_transcript_id' ), 10 );
		wp_set_current_user( 0 );
		$this->transcript_handler = null;
		parent::tearDown();
	}

	/**
	 * Provide a mock handler that simulates JetEngine's update_item behavior.
	 *
	 * The handler tracks whether _ID is provided (update) or not (insert).
	 *
	 * @return object Mock handler instance.
	 */
	public function provide_transcript_handler() {
		if ( ! $this->transcript_handler ) {
			$this->transcript_handler = new class() {
				public $records = array();
				public $next_id = 1;
				public $update_count = 0;
				public $insert_count = 0;

				public function update_item( $record ) {
					$session_key = isset( $record['session_key'] ) ? $record['session_key'] : '';
					$user_id     = isset( $record['cct_author_id'] ) ? $record['cct_author_id'] : 0;

					if ( '' === $session_key || 0 === $user_id ) {
						return new WP_Error( 'invalid_record', 'Invalid session_key or user_id' );
					}

					// If _ID is provided, this is an update operation.
					if ( isset( $record['_ID'] ) && $record['_ID'] > 0 ) {
						$this->update_count++;
						$record_id = absint( $record['_ID'] );

						// Find and update the existing record.
						foreach ( $this->records as $key => $existing ) {
							if ( isset( $existing['_ID'] ) && absint( $existing['_ID'] ) === $record_id ) {
								$this->records[ $key ] = $record;
								return $record_id;
							}
						}

						// If _ID was provided but record not found, treat as error.
						return new WP_Error( 'record_not_found', 'Record with _ID not found' );
					}

					// If no _ID provided, this is an insert operation.
					$this->insert_count++;
					$new_id           = $this->next_id++;
					$record['_ID']    = $new_id;
					$key              = $session_key . '_' . $user_id;
					$this->records[ $key ] = $record;

					return $new_id;
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

				/**
				 * Find existing transcript ID by session_key and user_id.
				 *
				 * Simulates the database query in find_existing_transcript_id.
				 *
				 * @param string $session_key  Session key.
				 * @param int    $user_id      User ID.
				 * @param int    $assistant_id Assistant ID (unused in this mock).
				 * @return int The _ID if found, 0 otherwise.
				 */
				public function find_existing_transcript_id( $session_key, $user_id, $assistant_id = 0 ) {
					$key = $session_key . '_' . $user_id;
					if ( isset( $this->records[ $key ] ) && isset( $this->records[ $key ]['_ID'] ) ) {
						return absint( $this->records[ $key ]['_ID'] );
					}
					return 0;
				}
			};
		}

		return $this->transcript_handler;
	}

	/**
	 * Filter to provide existing transcript ID for testing.
	 *
	 * @param int|null $default_id   Default value (null to trigger lookup).
	 * @param string   $session_key  Session key.
	 * @param int      $user_id      User ID.
	 * @param int      $assistant_id Assistant ID.
	 * @return int The existing _ID or 0.
	 */
	public function mock_find_existing_transcript_id( $default_id, $session_key, $user_id, $assistant_id ) {
		$handler = $this->provide_transcript_handler();
		return $handler->find_existing_transcript_id( $session_key, $user_id, $assistant_id );
	}

	/**
	 * Test that saving to the same session multiple times updates instead of creating duplicates.
	 */
	public function test_repeated_save_updates_not_duplicates() {
		// Install the mock handler and the find existing transcript ID filter.
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );
		add_filter( 'wp_mcp_ai_find_existing_transcript_id', array( $this, 'mock_find_existing_transcript_id' ), 10, 4 );

		// Prepare test data.
		$session_key = 'test-session-' . wp_generate_uuid4();
		$messages_v1 = array(
			array(
				'role'    => 'user',
				'content' => 'First message in conversation.',
			),
			array(
				'role'    => 'assistant',
				'content' => 'First assistant response.',
			),
		);

		// Step 1: Save initial conversation.
		$save_request_1 = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$save_request_1->set_header( 'Content-Type', 'application/json' );
		$save_request_1->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'session_key'  => $session_key,
					'messages'     => $messages_v1,
				)
			)
		);

		$save_response_1 = rest_get_server()->dispatch( $save_request_1 );
		$this->assertEquals( 200, $save_response_1->get_status(), 'First save should succeed' );

		// Verify it was an insert (not update).
		$handler = $this->provide_transcript_handler();
		$this->assertEquals( 1, $handler->insert_count, 'First save should be an insert' );
		$this->assertEquals( 0, $handler->update_count, 'First save should not be an update' );

		// Get the _ID that was assigned.
		$records = $handler->get_records( $session_key, $this->admin_id );
		$this->assertCount( 1, $records, 'Should have exactly one record after first save' );
		$first_record_id = isset( $records[0]['_ID'] ) ? $records[0]['_ID'] : 0;
		$this->assertGreaterThan( 0, $first_record_id, 'First record should have a valid _ID' );

		// Step 2: Save updated conversation to same session.
		$messages_v2 = array(
			array(
				'role'    => 'user',
				'content' => 'First message in conversation.',
			),
			array(
				'role'    => 'assistant',
				'content' => 'First assistant response.',
			),
			array(
				'role'    => 'user',
				'content' => 'Follow-up question.',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Follow-up answer.',
			),
		);

		$save_request_2 = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$save_request_2->set_header( 'Content-Type', 'application/json' );
		$save_request_2->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'session_key'  => $session_key,
					'messages'     => $messages_v2,
				)
			)
		);

		$save_response_2 = rest_get_server()->dispatch( $save_request_2 );
		$this->assertEquals( 200, $save_response_2->get_status(), 'Second save should succeed' );

		// Verify it was an update (not another insert).
		// This is the key assertion - should be 1 update, not 2 inserts.
		$this->assertEquals( 1, $handler->insert_count, 'Should still have only 1 insert' );
		$this->assertEquals( 1, $handler->update_count, 'Second save should be an update' );

		// Verify we still have only one record.
		$records_after_update = $handler->get_records( $session_key, $this->admin_id );
		$this->assertCount( 1, $records_after_update, 'Should still have exactly one record after update' );

		// Verify the _ID is the same (not a new record).
		$updated_record_id = isset( $records_after_update[0]['_ID'] ) ? $records_after_update[0]['_ID'] : 0;
		$this->assertEquals( $first_record_id, $updated_record_id, 'Updated record should have same _ID' );

		// Verify the messages were updated (should have 4 messages now).
		$this->assertArrayHasKey( 'request_payload', $records_after_update[0], 'Record should have request_payload' );
		$request_payload = json_decode( $records_after_update[0]['request_payload'], true );
		$this->assertIsArray( $request_payload, 'request_payload should be valid JSON' );
		$this->assertArrayHasKey( 'messages', $request_payload, 'request_payload should have messages' );
		$this->assertCount( 4, $request_payload['messages'], 'Should have updated to 4 messages' );

		// Step 3: Save again to verify consistent update behavior.
		$messages_v3 = array_merge(
			$messages_v2,
			array(
				array(
					'role'    => 'user',
					'content' => 'Another question.',
				),
				array(
					'role'    => 'assistant',
					'content' => 'Another answer.',
				),
			)
		);

		$save_request_3 = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$save_request_3->set_header( 'Content-Type', 'application/json' );
		$save_request_3->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'session_key'  => $session_key,
					'messages'     => $messages_v3,
				)
			)
		);

		$save_response_3 = rest_get_server()->dispatch( $save_request_3 );
		$this->assertEquals( 200, $save_response_3->get_status(), 'Third save should succeed' );

		// Verify it was also an update.
		$this->assertEquals( 1, $handler->insert_count, 'Should still have only 1 insert' );
		$this->assertEquals( 2, $handler->update_count, 'Should now have 2 updates' );

		// Verify we still have only one record.
		$records_final = $handler->get_records( $session_key, $this->admin_id );
		$this->assertCount( 1, $records_final, 'Should still have exactly one record after third save' );

		// Verify messages updated to 6.
		$final_payload = json_decode( $records_final[0]['request_payload'], true );
		$this->assertCount( 6, $final_payload['messages'], 'Should have updated to 6 messages' );
	}

	/**
	 * Test that different sessions create separate records.
	 */
	public function test_different_sessions_create_separate_records() {
		// Install the mock handler.
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		$session_key_1 = 'test-session-1-' . wp_generate_uuid4();
		$session_key_2 = 'test-session-2-' . wp_generate_uuid4();
		$messages      = array(
			array(
				'role'    => 'user',
				'content' => 'Test message.',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Test response.',
			),
		);

		// Save to first session.
		$request_1 = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request_1->set_header( 'Content-Type', 'application/json' );
		$request_1->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'session_key'  => $session_key_1,
					'messages'     => $messages,
				)
			)
		);

		rest_get_server()->dispatch( $request_1 );

		// Save to second session.
		$request_2 = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request_2->set_header( 'Content-Type', 'application/json' );
		$request_2->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'session_key'  => $session_key_2,
					'messages'     => $messages,
				)
			)
		);

		rest_get_server()->dispatch( $request_2 );

		// Verify two separate inserts (not updates).
		$handler = $this->provide_transcript_handler();
		$this->assertEquals( 2, $handler->insert_count, 'Should have 2 inserts for different sessions' );
		$this->assertEquals( 0, $handler->update_count, 'Should have 0 updates for different sessions' );

		// Verify both records exist and are distinct.
		$records_1 = $handler->get_records( $session_key_1, $this->admin_id );
		$records_2 = $handler->get_records( $session_key_2, $this->admin_id );

		$this->assertCount( 1, $records_1, 'Should have one record for session 1' );
		$this->assertCount( 1, $records_2, 'Should have one record for session 2' );

		$this->assertEquals( $session_key_1, $records_1[0]['session_key'], 'Session 1 key should match' );
		$this->assertEquals( $session_key_2, $records_2[0]['session_key'], 'Session 2 key should match' );

		// Verify different _IDs.
		$this->assertNotEquals( $records_1[0]['_ID'], $records_2[0]['_ID'], 'Different sessions should have different _IDs' );
	}
}
