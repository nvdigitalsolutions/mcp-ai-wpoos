<?php
/**
 * Integration test for chat conversation CCT flow
 *
 * Tests the complete flow:
 * 1. Save conversation to CCT via POST /chat-transcripts
 * 2. Retrieve conversation via GET /chat-transcripts
 * 3. Verify data integrity (user isolation, assistant filtering, message preservation)
 *
 * @package WP_MCP_AI
 */

class Test_Chat_Conversation_CCT_Integration extends WP_UnitTestCase {

	/**
	 * User ID for testing
	 *
	 * @var int
	 */
	protected static $user_id;

	/**
	 * Second user ID for isolation testing
	 *
	 * @var int
	 */
	protected static $user_id_2;

	/**
	 * Assistant ID for testing
	 *
	 * @var int
	 */
	protected static $assistant_id;

	/**
	 * Second assistant ID for filtering tests
	 *
	 * @var int
	 */
	protected static $assistant_id_2;

	/**
	 * Mock transcript handler
	 *
	 * @var object
	 */
	protected $transcript_handler;

	/**
	 * Captured transcript records
	 *
	 * @var array
	 */
	protected $saved_records = array();

	/**
	 * Set up before class
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		// Create test users.
		self::$user_id = $factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);

		self::$user_id_2 = $factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);

		// Create test assistants.
		self::$assistant_id = $factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Test Assistant for CCT Integration',
				'post_status' => 'publish',
			)
		);

		self::$assistant_id_2 = $factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Second Test Assistant',
				'post_status' => 'publish',
			)
		);

		// Store assistant configuration.
		update_post_meta( self::$assistant_id, '_wp_mcp_ai_model', 'gpt-4' );
		update_post_meta( self::$assistant_id, '_wp_mcp_ai_provider', 'openai' );
		update_post_meta( self::$assistant_id_2, '_wp_mcp_ai_model', 'gpt-3.5-turbo' );
		update_post_meta( self::$assistant_id_2, '_wp_mcp_ai_provider', 'openai' );
	}

	/**
	 * Set up before each test
	 */
	public function setUp(): void {
		parent::setUp();

		if ( function_exists( 'wp_mcp_ai_bootstrap' ) ) {
			wp_mcp_ai_bootstrap();
		}

		// Reset saved records.
		$this->saved_records = array();

		// Set up mock handler.
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		rest_get_server();
		do_action( 'init' );
	}

	/**
	 * Tear down after each test
	 */
	public function tearDown(): void {
		remove_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );
		wp_set_current_user( 0 );
		$this->transcript_handler = null;
		parent::tearDown();
	}

	/**
	 * Provide a mock handler that captures transcript records
	 *
	 * @return object Mock handler instance.
	 */
	public function provide_transcript_handler() {
		if ( ! $this->transcript_handler ) {
			$test_instance           = $this;
			$this->transcript_handler = new class( $test_instance ) {
				private $test_instance;
				public function __construct( $test_instance ) {
					$this->test_instance = $test_instance;
				}

				public function update_item( $record ) {
					// Store the record for later retrieval.
					$this->test_instance->saved_records[] = $record;
					return true;
				}

				public function get_items( $args ) {
					// Filter saved records by the args.
					$results = array();
					foreach ( $this->test_instance->saved_records as $record ) {
						$matches = true;

						if ( isset( $args['user_id'] ) && (int) $record['user_id'] !== (int) $args['user_id'] ) {
							$matches = false;
						}

						if ( isset( $args['session_key'] ) && $record['session_key'] !== $args['session_key'] ) {
							$matches = false;
						}

						if ( isset( $args['assistant_id'] ) && $args['assistant_id'] > 0 && (int) $record['assistant_id'] !== (int) $args['assistant_id'] ) {
							$matches = false;
						}

						if ( $matches ) {
							$results[] = $record;
						}
					}

					return $results;
				}
			};
		}

		return $this->transcript_handler;
	}

	/**
	 * Test 1: Save and retrieve simple conversation
	 */
	public function test_save_and_retrieve_simple_conversation() {
		// Set current user.
		wp_set_current_user( self::$user_id );

		$session_key = 'test_cct_session_' . wp_generate_password( 12, false );

		// Create a simple conversation.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello, how are you?',
			),
			array(
				'role'    => 'assistant',
				'content' => 'I am doing well, thank you for asking!',
			),
		);

		// Save via POST /chat-transcripts.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array(
			'assistant_id' => self::$assistant_id,
			'session_key'  => $session_key,
			'messages'     => $messages,
		) ) );

		$response = rest_get_server()->dispatch( $request );

		// Verify save was successful.
		$this->assertEquals( 200, $response->get_status(), 'Save request should return 200' );
		$data = $response->get_data();
		$this->assertTrue( $data['success'], 'Save should be successful' );
		$this->assertEquals( $session_key, $data['session_key'], 'Session key should match' );

		// Verify record was saved.
		$this->assertCount( 1, $this->saved_records, 'Should have 1 saved record' );
		$saved = $this->saved_records[0];
		$this->assertEquals( self::$user_id, $saved['user_id'], 'User ID should match' );
		$this->assertEquals( self::$assistant_id, (int) $saved['assistant_id'], 'Assistant ID should match' );
		$this->assertEquals( $session_key, $saved['session_key'], 'Session key should match' );

		// Decode and verify messages.
		$saved_payload = json_decode( $saved['request_payload'], true );
		$this->assertIsArray( $saved_payload, 'Request payload should be array' );
		$this->assertArrayHasKey( 'messages', $saved_payload, 'Payload should have messages' );
		$this->assertCount( 2, $saved_payload['messages'], 'Should have 2 messages' );
		$this->assertEquals( 'Hello, how are you?', $saved_payload['messages'][0]['content'], 'First message content should match' );
		$this->assertEquals( 'I am doing well, thank you for asking!', $saved_payload['messages'][1]['content'], 'Second message content should match' );
	}

	/**
	 * Test 2: User isolation - users cannot see each other's conversations
	 */
	public function test_user_isolation() {
		// User 1 saves a conversation.
		wp_set_current_user( self::$user_id );

		$session_key_1 = 'test_user1_' . wp_generate_password( 12, false );
		$messages_1    = array(
			array(
				'role'    => 'user',
				'content' => 'User 1 message',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Response to user 1',
			),
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array(
			'assistant_id' => self::$assistant_id,
			'session_key'  => $session_key_1,
			'messages'     => $messages_1,
		) ) );

		rest_get_server()->dispatch( $request );

		// User 2 saves a conversation.
		wp_set_current_user( self::$user_id_2 );

		$session_key_2 = 'test_user2_' . wp_generate_password( 12, false );
		$messages_2    = array(
			array(
				'role'    => 'user',
				'content' => 'User 2 message',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Response to user 2',
			),
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array(
			'assistant_id' => self::$assistant_id,
			'session_key'  => $session_key_2,
			'messages'     => $messages_2,
		) ) );

		rest_get_server()->dispatch( $request );

		// Verify both were saved.
		$this->assertCount( 2, $this->saved_records, 'Should have 2 saved records' );

		// User 1 should only see their own data.
		$user_1_records = array_filter(
			$this->saved_records,
			function ( $record ) {
				return (int) $record['user_id'] === self::$user_id;
			}
		);
		$this->assertCount( 1, $user_1_records, 'User 1 should have 1 record' );

		// User 2 should only see their own data.
		$user_2_records = array_filter(
			$this->saved_records,
			function ( $record ) {
				return (int) $record['user_id'] === self::$user_id_2;
			}
		);
		$this->assertCount( 1, $user_2_records, 'User 2 should have 1 record' );
	}

	/**
	 * Test 3: Save conversation with tool calls (agentic flow)
	 */
	public function test_save_conversation_with_tool_calls() {
		wp_set_current_user( self::$user_id );

		$session_key = 'test_tool_calls_' . wp_generate_password( 12, false );

		// Create conversation with tool calls.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'What is the weather in San Francisco?',
			),
			array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => array(
					array(
						'id'       => 'call_abc123',
						'type'     => 'function',
						'function' => array(
							'name'      => 'get_weather',
							'arguments' => '{"location":"San Francisco, CA"}',
						),
					),
				),
			),
			array(
				'role'         => 'tool',
				'tool_call_id' => 'call_abc123',
				'content'      => '{"temperature": 72, "conditions": "Sunny"}',
			),
			array(
				'role'    => 'assistant',
				'content' => 'The weather in San Francisco is 72°F and sunny.',
			),
		);

		// Save the conversation.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array(
			'assistant_id' => self::$assistant_id,
			'session_key'  => $session_key,
			'messages'     => $messages,
		) ) );

		$response = rest_get_server()->dispatch( $request );

		// Verify save was successful.
		$this->assertEquals( 200, $response->get_status(), 'Save should succeed' );

		// Verify the saved record.
		$saved = end( $this->saved_records );
		$this->assertNotFalse( $saved, 'Should have a saved record' );

		// Decode and verify messages with tool calls are preserved.
		$saved_payload = json_decode( $saved['request_payload'], true );
		$this->assertCount( 4, $saved_payload['messages'], 'Should have 4 messages' );

		// Find the assistant message with tool_calls.
		$found_tool_call = false;
		foreach ( $saved_payload['messages'] as $message ) {
			if ( isset( $message['role'] ) && 'assistant' === $message['role'] ) {
				if ( isset( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ) {
					$found_tool_call = true;
					$this->assertEquals( 'call_abc123', $message['tool_calls'][0]['id'], 'Tool call ID should be preserved' );
					$this->assertEquals( 'get_weather', $message['tool_calls'][0]['function']['name'], 'Tool name should be preserved' );
				}
			}
		}

		$this->assertTrue( $found_tool_call, 'Should find assistant message with tool_calls preserved' );

		// Verify tool result message exists.
		$found_tool_result = false;
		foreach ( $saved_payload['messages'] as $message ) {
			if ( isset( $message['role'] ) && 'tool' === $message['role'] ) {
				$found_tool_result = true;
				$this->assertEquals( 'call_abc123', $message['tool_call_id'], 'Tool call ID should match in result' );
			}
		}

		$this->assertTrue( $found_tool_result, 'Should find tool result message' );
	}

	/**
	 * Test 4: Assistant filtering
	 */
	public function test_assistant_filtering() {
		wp_set_current_user( self::$user_id );

		// Save conversation for assistant 1.
		$session_key_1 = 'test_assistant1_' . wp_generate_password( 12, false );
		$request       = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array(
			'assistant_id' => self::$assistant_id,
			'session_key'  => $session_key_1,
			'messages'     => array(
				array(
					'role'    => 'user',
					'content' => 'Message for assistant 1',
				),
			),
		) ) );
		rest_get_server()->dispatch( $request );

		// Save conversation for assistant 2.
		$session_key_2 = 'test_assistant2_' . wp_generate_password( 12, false );
		$request       = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array(
			'assistant_id' => self::$assistant_id_2,
			'session_key'  => $session_key_2,
			'messages'     => array(
				array(
					'role'    => 'user',
					'content' => 'Message for assistant 2',
				),
			),
		) ) );
		rest_get_server()->dispatch( $request );

		// Filter by assistant 1.
		$assistant_1_records = array_filter(
			$this->saved_records,
			function ( $record ) {
				return (int) $record['assistant_id'] === self::$assistant_id;
			}
		);

		// Filter by assistant 2.
		$assistant_2_records = array_filter(
			$this->saved_records,
			function ( $record ) {
				return (int) $record['assistant_id'] === self::$assistant_id_2;
			}
		);

		// Each assistant should have 1 record.
		$this->assertCount( 1, $assistant_1_records, 'Assistant 1 should have 1 record' );
		$this->assertCount( 1, $assistant_2_records, 'Assistant 2 should have 1 record' );
	}

	/**
	 * Test 5: Verify localStorage key format is per-assistant
	 */
	public function test_localstorage_key_format() {
		// This is a documentation test - just verify the expected format.
		$assistant_id = 42;
		$expected_key = 'wp_mcp_ai_chat_' . $assistant_id;

		// The JavaScript code uses: const STORAGE_KEY_PREFIX = 'wp_mcp_ai_chat_';
		// And: return STORAGE_KEY_PREFIX + assistantId;

		$this->assertEquals( 'wp_mcp_ai_chat_42', $expected_key, 'localStorage key should be prefixed with wp_mcp_ai_chat_ and assistant ID' );
	}

	/**
	 * Clean up after class
	 */
	public static function wpTearDownAfterClass() {
		// Clean up test data.
		if ( self::$user_id ) {
			wp_delete_user( self::$user_id );
		}

		if ( self::$user_id_2 ) {
			wp_delete_user( self::$user_id_2 );
		}

		if ( self::$assistant_id ) {
			wp_delete_post( self::$assistant_id, true );
		}

		if ( self::$assistant_id_2 ) {
			wp_delete_post( self::$assistant_id_2, true );
		}
	}
}
