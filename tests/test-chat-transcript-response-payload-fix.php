<?php
/**
 * Test for response_payload fix in chat transcript save endpoint.
 *
 * Verifies that when saving a conversation explicitly via POST /chat-transcripts,
 * the response_payload is properly constructed with the last assistant message
 * instead of being empty.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for response_payload fix.
 */
class Test_Chat_Transcript_Response_Payload_Fix extends WP_UnitTestCase {
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
				'post_title'  => 'Test Assistant for Response Payload Fix',
			)
		);

		// Set up assistant configuration.
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_model', 'gpt-4.1' );
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_provider', 'openai' );

		rest_get_server();
		do_action( 'rest_api_init' );
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
	 * Provide a mock handler that captures transcript records.
	 *
	 * @return object Mock handler instance.
	 */
	public function provide_transcript_handler() {
		if ( ! $this->transcript_handler ) {
			$this->transcript_handler = new class() {
				public $records = array();

				public function update_item( $record ) {
					$session_key = isset( $record['session_key'] ) ? $record['session_key'] : '';
					$user_id     = isset( $record['user_id'] ) ? $record['user_id'] : 0;

					if ( '' === $session_key || 0 === $user_id ) {
						return new WP_Error( 'invalid_record', 'Invalid session_key or user_id' );
					}

					$key                     = $session_key . '_' . $user_id;
					$this->records[ $key ] = $record;

					return true;
				}
			};
		}

		return $this->transcript_handler;
	}

	/**
	 * Test that response_payload contains proper structure with assistant message.
	 *
	 * Before the fix, response_payload was:
	 * {"model": "gpt-4.1", "choices": []}
	 *
	 * After the fix, it should be:
	 * {"model": "gpt-4.1", "choices": [{"index": 0, "message": {...}, "finish_reason": "stop"}]}
	 */
	public function test_response_payload_not_empty() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		$session_key = 'test-response-' . wp_generate_uuid4();
		$messages    = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Hello, how are you?',
					),
				),
			),
			array(
				'role'    => 'assistant',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'I am doing well, thank you for asking!',
					),
				),
			),
		);

		// Save the conversation.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'session_key'  => $session_key,
					'messages'     => $messages,
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status(), 'Save should succeed' );

		// Get the stored record.
		$handler = $this->provide_transcript_handler();
		$key     = $session_key . '_' . $this->admin_id;
		$this->assertArrayHasKey( $key, $handler->records, 'Record should be stored' );

		$record = $handler->records[ $key ];

		// Verify response_payload exists and is valid JSON.
		$this->assertArrayHasKey( 'response_payload', $record, 'Record should have response_payload' );
		$response_payload = json_decode( $record['response_payload'], true );
		$this->assertIsArray( $response_payload, 'response_payload should be valid JSON' );

		// Verify model is set.
		$this->assertArrayHasKey( 'model', $response_payload, 'response_payload should have model' );
		$this->assertEquals( 'gpt-4.1', $response_payload['model'], 'Model should match assistant config' );

		// Verify choices array is NOT empty.
		$this->assertArrayHasKey( 'choices', $response_payload, 'response_payload should have choices' );
		$this->assertIsArray( $response_payload['choices'], 'choices should be an array' );
		$this->assertNotEmpty( $response_payload['choices'], 'choices should NOT be empty' );

		// Verify choice structure.
		$this->assertCount( 1, $response_payload['choices'], 'Should have exactly one choice' );
		$choice = $response_payload['choices'][0];

		$this->assertArrayHasKey( 'index', $choice, 'Choice should have index' );
		$this->assertEquals( 0, $choice['index'], 'Index should be 0' );

		$this->assertArrayHasKey( 'message', $choice, 'Choice should have message' );
		$this->assertIsArray( $choice['message'], 'Message should be an array' );

		$this->assertArrayHasKey( 'finish_reason', $choice, 'Choice should have finish_reason' );
		$this->assertEquals( 'stop', $choice['finish_reason'], 'finish_reason should be stop' );

		// Verify the message content matches the last assistant message.
		$message = $choice['message'];
		$this->assertArrayHasKey( 'role', $message, 'Message should have role' );
		$this->assertEquals( 'assistant', $message['role'], 'Message role should be assistant' );

		$this->assertArrayHasKey( 'content', $message, 'Message should have content' );
		$this->assertEquals( $messages[1]['content'], $message['content'], 'Content should match last assistant message' );
	}

	/**
	 * Test that timestamps are properly stored.
	 *
	 * Before the fix, request_started_at and response_completed_at were empty.
	 * After the fix, they should contain valid Unix timestamps.
	 */
	public function test_timestamps_are_stored() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		$session_key = 'test-timestamps-' . wp_generate_uuid4();
		$messages    = array(
			array(
				'role'    => 'user',
				'content' => 'Test message',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Test response',
			),
		);

		$before_save = time();

		// Save the conversation.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'session_key'  => $session_key,
					'messages'     => $messages,
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$after_save = time();

		// Get the stored record.
		$handler = $this->provide_transcript_handler();
		$key     = $session_key . '_' . $this->admin_id;
		$record  = $handler->records[ $key ];

		// Verify timestamps exist.
		$this->assertArrayHasKey( 'request_started_at', $record, 'Record should have request_started_at' );
		$this->assertArrayHasKey( 'response_completed_at', $record, 'Record should have response_completed_at' );

		// Verify timestamps are valid (between before and after save).
		$request_started    = (int) $record['request_started_at'];
		$response_completed = (int) $record['response_completed_at'];

		$this->assertGreaterThanOrEqual( $before_save, $request_started, 'request_started_at should be >= before_save' );
		$this->assertLessThanOrEqual( $after_save, $request_started, 'request_started_at should be <= after_save' );

		$this->assertGreaterThanOrEqual( $before_save, $response_completed, 'response_completed_at should be >= before_save' );
		$this->assertLessThanOrEqual( $after_save, $response_completed, 'response_completed_at should be <= after_save' );

		// Timestamps should be the same or very close (same save operation).
		$this->assertEquals( $request_started, $response_completed, 'Timestamps should be the same for explicit save' );
	}

	/**
	 * Test that session_key is preserved through the save process.
	 *
	 * Verifies that the session_key provided by the client is the same
	 * as the session_key stored in the database.
	 */
	public function test_session_key_preservation() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		// Use a specific UUID format that should be preserved.
		$session_key = '97578cc3-fa5c-45b2-adba-76a6d1b7109b';
		$messages    = array(
			array(
				'role'    => 'user',
				'content' => 'Test message',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Test response',
			),
		);

		// Save the conversation.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'session_key'  => $session_key,
					'messages'     => $messages,
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$response_data = $response->get_data();
		$this->assertEquals( $session_key, $response_data['session_key'], 'Returned session_key should match input' );

		// Get the stored record.
		$handler = $this->provide_transcript_handler();
		$key     = $session_key . '_' . $this->admin_id;
		$this->assertArrayHasKey( $key, $handler->records, 'Record should be stored with correct session_key' );

		$record = $handler->records[ $key ];
		$this->assertEquals( $session_key, $record['session_key'], 'Stored session_key should match input exactly' );
	}
}
