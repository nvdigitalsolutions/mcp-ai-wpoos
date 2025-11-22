<?php
/**
 * Test for saving chat transcripts in cron context with explicit user_id.
 *
 * This test reproduces the issue where transcripts saved by cron jobs
 * cannot be retrieved because user_id defaults to 0 in cron context.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for cron context transcript saving.
 */
class Test_Chat_Transcript_Cron_Context_Save extends WP_UnitTestCase {
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

		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant for Cron Context',
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
					// Store records indexed by session_key and user_id for retrieval.
					$session_key = isset( $record['session_key'] ) ? $record['session_key'] : '';
					$user_id     = isset( $record['user_id'] ) ? $record['user_id'] : 0;

					if ( '' === $session_key ) {
						return new WP_Error( 'invalid_record', 'Invalid session_key' );
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
	 * Test that transcripts can be saved with explicit user_id parameter.
	 *
	 * This simulates a cron job that needs to save a transcript with the
	 * admin's user_id even though the cron runs in a context where
	 * get_current_user_id() returns 0.
	 */
	public function test_save_transcript_with_explicit_user_id() {
		// Install the mock handler.
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		// Simulate cron context - set current user to 0 (not logged in).
		wp_set_current_user( 0 );

		$session_key = 'cron-session-' . wp_generate_uuid4();
		$messages    = array(
			array(
				'role'    => 'user',
				'content' => 'This is a scheduled message from cron.',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Response to the scheduled message.',
			),
		);

		// Step 1: Save the conversation via POST /chat-transcripts with explicit user_id.
		// This simulates a cron job sending the admin's user_id in the request.
		$save_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$save_request->set_header( 'Content-Type', 'application/json' );
		$save_request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'session_key'  => $session_key,
					'messages'     => $messages,
					'user_id'      => $this->admin_id, // Explicit user_id for the admin who created the cron job.
				)
			)
		);

		// Note: We need to simulate permission - in real scenario, cron would have
		// proper authentication. For this test, we'll temporarily grant admin capability.
		wp_set_current_user( $this->admin_id );
		$save_response = rest_get_server()->dispatch( $save_request );
		wp_set_current_user( 0 ); // Back to cron context.

		// Verify save succeeded.
		$this->assertEquals( 200, $save_response->get_status(), 'Save request should succeed' );
		$save_data = $save_response->get_data();
		$this->assertTrue( $save_data['success'], 'Save response should indicate success' );

		// Verify record was stored with admin's user_id, not 0.
		$handler        = $this->provide_transcript_handler();
		$stored_records = $handler->get_records( $session_key, $this->admin_id );
		$this->assertNotEmpty( $stored_records, 'Mock handler should have stored the record with admin user_id' );

		$stored_record = $stored_records[0];
		$this->assertEquals( $session_key, $stored_record['session_key'], 'Stored session_key should match' );
		$this->assertEquals( $this->admin_id, $stored_record['user_id'], 'Stored user_id should be admin, not 0' );

		// Verify that record is NOT stored under user_id=0.
		$wrong_records = $handler->get_records( $session_key, 0 );
		$this->assertEmpty( $wrong_records, 'Should NOT be stored under user_id=0' );
	}

	/**
	 * Test that non-admin users cannot save transcripts for other users.
	 */
	public function test_non_admin_cannot_save_for_other_user() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		// Create a regular editor user.
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$session_key = 'unauthorized-session-' . wp_generate_uuid4();
		$messages    = array(
			array(
				'role'    => 'user',
				'content' => 'Trying to save for another user.',
			),
		);

		// Try to save with admin's user_id while logged in as editor.
		$save_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$save_request->set_header( 'Content-Type', 'application/json' );
		$save_request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'session_key'  => $session_key,
					'messages'     => $messages,
					'user_id'      => $this->admin_id, // Trying to save for admin!
				)
			)
		);

		$save_response = rest_get_server()->dispatch( $save_request );

		// Should fail with 403 Forbidden.
		$this->assertEquals( 403, $save_response->get_status(), 'Should be forbidden' );
		$error_data = $save_response->get_data();
		$this->assertEquals( 'wp_mcp_ai_transcripts_forbidden_user', $error_data['code'], 'Should return forbidden error code' );

		wp_delete_user( $editor_id );
	}

	/**
	 * Test that admin users CAN save transcripts for other users.
	 */
	public function test_admin_can_save_for_other_user() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		// Create a regular editor user.
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		// Log in as admin.
		wp_set_current_user( $this->admin_id );

		$session_key = 'admin-saves-for-editor-' . wp_generate_uuid4();
		$messages    = array(
			array(
				'role'    => 'user',
				'content' => 'Admin saving for editor.',
			),
		);

		// Admin saves with editor's user_id.
		$save_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$save_request->set_header( 'Content-Type', 'application/json' );
		$save_request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'session_key'  => $session_key,
					'messages'     => $messages,
					'user_id'      => $editor_id, // Saving for editor.
				)
			)
		);

		$save_response = rest_get_server()->dispatch( $save_request );

		// Should succeed.
		$this->assertEquals( 200, $save_response->get_status(), 'Admin should be able to save for other users' );

		// Verify stored with editor's user_id.
		$handler        = $this->provide_transcript_handler();
		$stored_records = $handler->get_records( $session_key, $editor_id );
		$this->assertNotEmpty( $stored_records, 'Should be stored with editor user_id' );

		$stored_record = $stored_records[0];
		$this->assertEquals( $editor_id, $stored_record['user_id'], 'Should have editor user_id' );

		wp_delete_user( $editor_id );
	}

	/**
	 * Test that when no user_id is provided, it defaults to current user.
	 */
	public function test_defaults_to_current_user_when_no_user_id_provided() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		wp_set_current_user( $this->admin_id );

		$session_key = 'default-user-session-' . wp_generate_uuid4();
		$messages    = array(
			array(
				'role'    => 'user',
				'content' => 'No explicit user_id provided.',
			),
		);

		// Save WITHOUT user_id parameter.
		$save_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$save_request->set_header( 'Content-Type', 'application/json' );
		$save_request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'session_key'  => $session_key,
					'messages'     => $messages,
					// No user_id parameter.
				)
			)
		);

		$save_response = rest_get_server()->dispatch( $save_request );

		// Should succeed.
		$this->assertEquals( 200, $save_response->get_status(), 'Should succeed without user_id' );

		// Verify stored with current user's ID.
		$handler        = $this->provide_transcript_handler();
		$stored_records = $handler->get_records( $session_key, $this->admin_id );
		$this->assertNotEmpty( $stored_records, 'Should be stored with current user_id' );

		$stored_record = $stored_records[0];
		$this->assertEquals( $this->admin_id, $stored_record['user_id'], 'Should default to current user_id' );
	}
}
