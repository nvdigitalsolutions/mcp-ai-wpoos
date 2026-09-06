<?php
/**
 * Tests for unified team and team member transcript recording.
 *
 * This test verifies that the Chat Transcript Recorder properly handles
 * string assistant IDs for unified teams and team members.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test unified team transcript recording functionality.
 */
class Test_Unified_Team_Transcript_Recording extends WP_UnitTestCase {

	/**
	 * Administrator user ID for authenticated requests.
	 *
	 * @var int
	 */
	protected $admin_id;

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

		// Warm the REST server. NOTE: do NOT re-fire init here — the bootstrap
		// already registered the assistant CPT, and re-firing re-runs WooCommerce
		// block/payment registrations, which fail the test with
		// "already registered" incorrect-usage notices.
		rest_get_server();
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
				/**
				 * Records captured by the mock handler.
				 *
				 * @var array
				 */
				public $records = array();

				/**
				 * Capture a transcript record.
				 *
				 * @param array $record Transcript record payload.
				 * @return bool Always true.
				 */
				public function update_item( $record ) {
					$this->records[] = $record;
					return true;
				}
			};
		}

		return $this->transcript_handler;
	}

	/**
	 * Test that unified team assistant IDs are properly recorded.
	 */
	public function test_unified_team_assistant_id_recording() {
		if ( ! class_exists( 'WP_MCP_AI_Chat_Transcript_Recorder' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Chat_Transcript_Recorder class not available.' );
		}

		// Set up mock handler.
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		// Create mock request.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'session_key', 'test-unified-team-session-123' );

		// Test data with unified team assistant ID.
		$assistant_id = 'unified_team_8866';
		$messages     = array(
			array(
				'role'    => 'user',
				'content' => 'Hello unified team',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Hello from the unified team',
			),
		);
		$options      = array( 'model' => 'gpt-4' );
		$response     = array(
			'id'      => 'test-response-id',
			'model'   => 'gpt-4',
			'choices' => array(
				array(
					'message'       => array(
						'role'    => 'assistant',
						'content' => 'Hello from the unified team',
					),
					'finish_reason' => 'stop',
				),
			),
		);
		$context      = array(
			'session_key'           => 'test-unified-team-session-123',
			'request_started_at'    => microtime( true ),
			'response_completed_at' => microtime( true ),
		);

		// Record the transcript.
		$result = WP_MCP_AI_Chat_Transcript_Recorder::record(
			$assistant_id,
			$messages,
			$options,
			$response,
			$request,
			$this->admin_id,
			$context
		);

		// Verify the transcript was recorded.
		$this->assertNotNull( $result, 'Recorder should return session key for unified team assistant ID' );
		$this->assertEquals( 'test-unified-team-session-123', $result, 'Recorder should return correct session key' );

		// Verify the record was passed to the handler.
		$this->assertCount( 1, $this->transcript_handler->records, 'Handler should receive one record' );

		$record = $this->transcript_handler->records[0];
		$this->assertArrayHasKey( 'assistant_id', $record, 'Record should have assistant_id field' );
		$this->assertEquals( 'unified_team_8866', $record['assistant_id'], 'Record should preserve string assistant_id' );
		$this->assertEquals( 'test-unified-team-session-123', $record['session_key'], 'Record should have correct session key' );
		$this->assertEquals( $this->admin_id, $record['user_id'], 'Record should have correct user_id' );
	}

	/**
	 * Test that team member assistant IDs are properly recorded.
	 */
	public function test_team_member_assistant_id_recording() {
		if ( ! class_exists( 'WP_MCP_AI_Chat_Transcript_Recorder' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Chat_Transcript_Recorder class not available.' );
		}

		// Set up mock handler.
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		// Create mock request.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'session_key', 'test-team-member-session-456' );

		// Test data with team member assistant ID.
		$assistant_id = 'team_8876_member_8325';
		$messages     = array(
			array(
				'role'    => 'user',
				'content' => 'Hello team member',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Hello from the team member',
			),
		);
		$options      = array( 'model' => 'gpt-4' );
		$response     = array(
			'id'      => 'test-response-id-2',
			'model'   => 'gpt-4',
			'choices' => array(
				array(
					'message'       => array(
						'role'    => 'assistant',
						'content' => 'Hello from the team member',
					),
					'finish_reason' => 'stop',
				),
			),
		);
		$context      = array(
			'session_key'           => 'test-team-member-session-456',
			'request_started_at'    => microtime( true ),
			'response_completed_at' => microtime( true ),
		);

		// Record the transcript.
		$result = WP_MCP_AI_Chat_Transcript_Recorder::record(
			$assistant_id,
			$messages,
			$options,
			$response,
			$request,
			$this->admin_id,
			$context
		);

		// Verify the transcript was recorded.
		$this->assertNotNull( $result, 'Recorder should return session key for team member assistant ID' );
		$this->assertEquals( 'test-team-member-session-456', $result, 'Recorder should return correct session key' );

		// Verify the record was passed to the handler.
		$this->assertCount( 1, $this->transcript_handler->records, 'Handler should receive one record' );

		$record = $this->transcript_handler->records[0];
		$this->assertArrayHasKey( 'assistant_id', $record, 'Record should have assistant_id field' );
		$this->assertEquals( 'team_8876_member_8325', $record['assistant_id'], 'Record should preserve string assistant_id' );
		$this->assertEquals( 'test-team-member-session-456', $record['session_key'], 'Record should have correct session key' );
		$this->assertEquals( $this->admin_id, $record['user_id'], 'Record should have correct user_id' );
	}

	/**
	 * Test that regular integer assistant IDs still work.
	 */
	public function test_integer_assistant_id_recording() {
		if ( ! class_exists( 'WP_MCP_AI_Chat_Transcript_Recorder' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Chat_Transcript_Recorder class not available.' );
		}

		// Set up mock handler.
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		// Create a real assistant post for testing.
		$assistant_post_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Regular Assistant',
			)
		);

		// Create mock request.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'session_key', 'test-regular-assistant-789' );

		// Test data with integer assistant ID.
		$assistant_id = $assistant_post_id;
		$messages     = array(
			array(
				'role'    => 'user',
				'content' => 'Hello regular assistant',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Hello from the regular assistant',
			),
		);
		$options      = array( 'model' => 'gpt-4' );
		$response     = array(
			'id'      => 'test-response-id-3',
			'model'   => 'gpt-4',
			'choices' => array(
				array(
					'message'       => array(
						'role'    => 'assistant',
						'content' => 'Hello from the regular assistant',
					),
					'finish_reason' => 'stop',
				),
			),
		);
		$context      = array(
			'session_key'           => 'test-regular-assistant-789',
			'request_started_at'    => microtime( true ),
			'response_completed_at' => microtime( true ),
		);

		// Record the transcript.
		$result = WP_MCP_AI_Chat_Transcript_Recorder::record(
			$assistant_id,
			$messages,
			$options,
			$response,
			$request,
			$this->admin_id,
			$context
		);

		// Verify the transcript was recorded.
		$this->assertNotNull( $result, 'Recorder should return session key for integer assistant ID' );
		$this->assertEquals( 'test-regular-assistant-789', $result, 'Recorder should return correct session key' );

		// Verify the record was passed to the handler.
		$this->assertCount( 1, $this->transcript_handler->records, 'Handler should receive one record' );

		$record = $this->transcript_handler->records[0];
		$this->assertArrayHasKey( 'assistant_id', $record, 'Record should have assistant_id field' );
		// Integer assistant ID should be stored as string in the database.
		$this->assertEquals( (string) $assistant_post_id, $record['assistant_id'], 'Record should convert integer assistant_id to string' );
		$this->assertEquals( 'test-regular-assistant-789', $record['session_key'], 'Record should have correct session key' );
		$this->assertEquals( $this->admin_id, $record['user_id'], 'Record should have correct user_id' );
	}

	/**
	 * Test that invalid assistant IDs are rejected.
	 */
	public function test_invalid_assistant_id_rejected() {
		if ( ! class_exists( 'WP_MCP_AI_Chat_Transcript_Recorder' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Chat_Transcript_Recorder class not available.' );
		}

		// Set up mock handler.
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		// Create mock request.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'session_key', 'test-invalid-assistant' );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);
		$options  = array( 'model' => 'gpt-4' );
		$response = array(
			'id'      => 'test-response-id-4',
			'model'   => 'gpt-4',
			'choices' => array(
				array(
					'message'       => array(
						'role'    => 'assistant',
						'content' => 'Hello',
					),
					'finish_reason' => 'stop',
				),
			),
		);
		$context  = array(
			'session_key'           => 'test-invalid-assistant',
			'request_started_at'    => microtime( true ),
			'response_completed_at' => microtime( true ),
		);

		// Test with zero integer (should be rejected).
		$result = WP_MCP_AI_Chat_Transcript_Recorder::record(
			0,
			$messages,
			$options,
			$response,
			$request,
			$this->admin_id,
			$context
		);
		$this->assertNull( $result, 'Recorder should return null for zero integer assistant_id' );

		// Test with empty string (should be rejected).
		$result = WP_MCP_AI_Chat_Transcript_Recorder::record(
			'',
			$messages,
			$options,
			$response,
			$request,
			$this->admin_id,
			$context
		);
		$this->assertNull( $result, 'Recorder should return null for empty string assistant_id' );

		// Test with invalid format string (should be rejected - not a virtual team ID).
		$result = WP_MCP_AI_Chat_Transcript_Recorder::record(
			'invalid_format_123',
			$messages,
			$options,
			$response,
			$request,
			$this->admin_id,
			$context
		);
		$this->assertNull( $result, 'Recorder should return null for invalid format string assistant_id' );

		// Verify no records were saved. The recorder rejects invalid IDs before
		// resolving a handler, so materialize the mock before asserting on it.
		$this->assertCount( 0, $this->provide_transcript_handler()->records, 'Handler should not receive any records for invalid assistant IDs' );
	}
}
