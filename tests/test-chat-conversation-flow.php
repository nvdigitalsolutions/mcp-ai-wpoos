<?php
/**
 * Test Chat Conversation Flow - Agentic, Widget Settings, and Load Back
 *
 * This test verifies:
 * 1. Agentic flow works correctly with tool calls preserved
 * 2. Widget settings properly filter conversations
 * 3. Conversations can be loaded back into chat with full context
 *
 *
 * @package WP_MCP_AI
 */

class Test_Chat_Conversation_Flow extends WP_UnitTestCase {

	/**
	 * User ID for testing
	 *
	 * @var int
	 */
	protected static $user_id;

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
	 * Session key for testing
	 *
	 * @var string
	 */
	protected static $session_key;

	/**
	 * REST API instance
	 *
	 * @var WP_MCP_AI_REST
	 */
	protected $rest_api;

	/**
	 * Set up before class
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		// Create test user.
		self::$user_id = $factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);

		// Create test assistants.
		self::$assistant_id = $factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Test Assistant for Conversation Flow',
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

		self::$session_key = 'test_session_' . wp_generate_password( 12, false );
	}

	/**
	 * Set up before each test
	 */
	public function setUp(): void {
		parent::setUp();

		// Initialize REST API.
		$this->rest_api = new WP_MCP_AI_REST(
			WP_MCP_AI_Tool_Registry::get_instance(),
			$this->createMock( WP_MCP_AI_Language_Model_Router::class ),
			$this->createMock( WP_MCP_AI_Chat_Service::class ),
			$this->createMock( WP_MCP_AI_Assistant_Service::class ),
			$this->createMock( WP_MCP_AI_Tool_Service::class )
		);

		// Set current user.
		wp_set_current_user( self::$user_id );
	}

	/**
	 * Test 1: Verify agentic flow preserves tool calls in saved conversations
	 */
	public function test_agentic_flow_preserves_tool_calls() {
		$this->markTestSkipped( 'Requires JetEngine CCT to be active and configured' );

		// Create a conversation with tool calls (agentic flow).
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
				'content' => 'The weather in San Francisco is currently 72°F and sunny.',
			),
		);

		// Save the conversation via transcript recorder.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'assistant_id', self::$assistant_id );
		$request->set_param( 'session_key', self::$session_key );
		$request->set_param( 'messages', $messages );

		$response = $this->rest_api->handle_chat_transcript_save( $request );

		$this->assertNotInstanceOf( 'WP_Error', $response, 'Transcript save should not return error' );
		$this->assertTrue( isset( $response->data['success'] ), 'Transcript save should return success' );

		// Retrieve the conversation.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'user_id', self::$user_id );
		$request->set_param( 'session_key', self::$session_key );

		$response = $this->rest_api->handle_chat_transcripts( $request );

		$this->assertNotInstanceOf( 'WP_Error', $response, 'Transcript retrieval should not return error' );
		$this->assertIsArray( $response->data, 'Response data should be array' );
		$this->assertArrayHasKey( 'session', $response->data, 'Response should have session key' );

		$session = $response->data['session'];

		// Verify tool calls are preserved.
		$this->assertArrayHasKey( 'messages', $session, 'Session should have messages' );
		$this->assertIsArray( $session['messages'], 'Messages should be array' );
		$this->assertGreaterThanOrEqual( 4, count( $session['messages'] ), 'Should have at least 4 messages' );

		// Find the assistant message with tool_calls.
		$found_tool_call = false;
		foreach ( $session['messages'] as $message ) {
			if ( isset( $message['role'] ) && 'assistant' === $message['role'] ) {
				if ( isset( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ) {
					$found_tool_call = true;
					$this->assertCount( 1, $message['tool_calls'], 'Should have 1 tool call' );
					$this->assertEquals( 'call_abc123', $message['tool_calls'][0]['id'], 'Tool call ID should match' );
					$this->assertEquals( 'get_weather', $message['tool_calls'][0]['function']['name'], 'Tool name should match' );
				}
			}
		}

		$this->assertTrue( $found_tool_call, 'Should find assistant message with tool_calls preserved' );

		// Verify tool result message exists.
		$found_tool_result = false;
		foreach ( $session['messages'] as $message ) {
			if ( isset( $message['role'] ) && 'tool' === $message['role'] ) {
				$found_tool_result = true;
				$this->assertEquals( 'call_abc123', $message['tool_call_id'], 'Tool call ID should match in result' );
			}
		}

		$this->assertTrue( $found_tool_result, 'Should find tool result message' );
	}

	/**
	 * Test 2: Verify widget settings filter conversations correctly
	 */
	public function test_widget_settings_filter_conversations() {
		$this->markTestSkipped( 'Requires JetEngine CCT to be active and configured' );

		// Create conversations for different assistants.
		$session_key_1 = 'test_filter_1_' . wp_generate_password( 12, false );
		$session_key_2 = 'test_filter_2_' . wp_generate_password( 12, false );

		$messages_1 = array(
			array(
				'role'    => 'user',
				'content' => 'Hello from assistant 1',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Response from assistant 1',
			),
		);

		$messages_2 = array(
			array(
				'role'    => 'user',
				'content' => 'Hello from assistant 2',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Response from assistant 2',
			),
		);

		// Save conversation for assistant 1.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'assistant_id', self::$assistant_id );
		$request->set_param( 'session_key', $session_key_1 );
		$request->set_param( 'messages', $messages_1 );
		$this->rest_api->handle_chat_transcript_save( $request );

		// Save conversation for assistant 2.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'assistant_id', self::$assistant_id_2 );
		$request->set_param( 'session_key', $session_key_2 );
		$request->set_param( 'messages', $messages_2 );
		$this->rest_api->handle_chat_transcript_save( $request );

		// Test 2a: Filter by assistant_id (should only return assistant 1 conversations).
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'user_id', self::$user_id );
		$request->set_param( 'assistant_id', self::$assistant_id );

		$response = $this->rest_api->handle_chat_transcripts( $request );

		$this->assertNotInstanceOf( 'WP_Error', $response, 'Filtered retrieval should not return error' );
		$this->assertIsArray( $response->data['sessions'], 'Sessions should be array' );

		// Verify only assistant 1 sessions are returned.
		foreach ( $response->data['sessions'] as $session ) {
			$this->assertEquals( self::$assistant_id, $session['assistant_id'], 'Should only return sessions for assistant 1' );
		}

		// Test 2b: No filter (should return both).
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'user_id', self::$user_id );

		$response = $this->rest_api->handle_chat_transcripts( $request );

		$this->assertNotInstanceOf( 'WP_Error', $response, 'Unfiltered retrieval should not return error' );
		$this->assertGreaterThanOrEqual( 2, count( $response->data['sessions'] ), 'Should return sessions from both assistants' );

		// Test 2c: Pagination with per_page.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'user_id', self::$user_id );
		$request->set_param( 'per_page', 1 );

		$response = $this->rest_api->handle_chat_transcripts( $request );

		$this->assertNotInstanceOf( 'WP_Error', $response, 'Paginated retrieval should not return error' );
		$this->assertCount( 1, $response->data['sessions'], 'Should return only 1 session per page' );
		$this->assertEquals( 1, $response->data['per_page'], 'per_page should be 1' );
	}

	/**
	 * Test 3: Verify conversation can be loaded back into chat with full context
	 */
	public function test_conversation_loads_back_into_chat() {
		$this->markTestSkipped( 'Requires JetEngine CCT to be active and configured' );

		// Create a multi-turn conversation with varied message types.
		$session_key = 'test_load_' . wp_generate_password( 12, false );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'First user message',
			),
			array(
				'role'    => 'assistant',
				'content' => 'First assistant response',
			),
			array(
				'role'    => 'user',
				'content' => 'Second user message',
			),
			array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => array(
					array(
						'id'       => 'call_xyz789',
						'type'     => 'function',
						'function' => array(
							'name'      => 'search_database',
							'arguments' => '{"query":"test"}',
						),
					),
				),
			),
			array(
				'role'         => 'tool',
				'tool_call_id' => 'call_xyz789',
				'content'      => '{"results": ["result1", "result2"]}',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Found 2 results for you.',
			),
		);

		// Save the conversation.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'assistant_id', self::$assistant_id );
		$request->set_param( 'session_key', $session_key );
		$request->set_param( 'messages', $messages );

		$save_response = $this->rest_api->handle_chat_transcript_save( $request );
		$this->assertNotInstanceOf( 'WP_Error', $save_response, 'Save should succeed' );

		// Load the conversation back.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'user_id', self::$user_id );
		$request->set_param( 'session_key', $session_key );

		$load_response = $this->rest_api->handle_chat_transcripts( $request );

		$this->assertNotInstanceOf( 'WP_Error', $load_response, 'Load should succeed' );
		$this->assertArrayHasKey( 'session', $load_response->data, 'Response should have session' );

		$loaded_session = $load_response->data['session'];

		// Verify session metadata.
		$this->assertEquals( $session_key, $loaded_session['session_key'], 'Session key should match' );
		$this->assertEquals( self::$assistant_id, $loaded_session['assistant_id'], 'Assistant ID should match' );
		$this->assertArrayHasKey( 'assistant_title', $loaded_session, 'Should have assistant title' );
		$this->assertArrayHasKey( 'assistant_model', $loaded_session, 'Should have assistant model' );
		$this->assertArrayHasKey( 'started_at', $loaded_session, 'Should have started timestamp' );
		$this->assertArrayHasKey( 'updated_at', $loaded_session, 'Should have updated timestamp' );

		// Verify message count.
		$this->assertArrayHasKey( 'messages', $loaded_session, 'Session should have messages' );
		$this->assertCount( 6, $loaded_session['messages'], 'Should have 6 messages' );

		// Verify message order and content.
		$loaded_messages = $loaded_session['messages'];

		// Message 0: First user message.
		$this->assertEquals( 'user', $loaded_messages[0]['role'], 'First message should be user' );
		$this->assertEquals( 'First user message', $loaded_messages[0]['content'], 'Content should match' );

		// Message 1: First assistant response.
		$this->assertEquals( 'assistant', $loaded_messages[1]['role'], 'Second message should be assistant' );
		$this->assertEquals( 'First assistant response', $loaded_messages[1]['content'], 'Content should match' );

		// Message 2: Second user message.
		$this->assertEquals( 'user', $loaded_messages[2]['role'], 'Third message should be user' );
		$this->assertEquals( 'Second user message', $loaded_messages[2]['content'], 'Content should match' );

		// Message 3: Assistant with tool_calls.
		$this->assertEquals( 'assistant', $loaded_messages[3]['role'], 'Fourth message should be assistant' );
		$this->assertArrayHasKey( 'tool_calls', $loaded_messages[3], 'Should have tool_calls' );
		$this->assertIsArray( $loaded_messages[3]['tool_calls'], 'tool_calls should be array' );
		$this->assertEquals( 'call_xyz789', $loaded_messages[3]['tool_calls'][0]['id'], 'Tool call ID should match' );

		// Message 4: Tool result.
		$this->assertEquals( 'tool', $loaded_messages[4]['role'], 'Fifth message should be tool' );
		$this->assertEquals( 'call_xyz789', $loaded_messages[4]['tool_call_id'], 'Tool call ID should match' );

		// Message 5: Final assistant response.
		$this->assertEquals( 'assistant', $loaded_messages[5]['role'], 'Sixth message should be assistant' );
		$this->assertEquals( 'Found 2 results for you.', $loaded_messages[5]['content'], 'Content should match' );

		// Verify the conversation can be continued (all messages are in proper format).
		foreach ( $loaded_messages as $index => $message ) {
			$this->assertArrayHasKey( 'role', $message, "Message $index should have role" );
			$this->assertArrayHasKey( 'content', $message, "Message $index should have content" );

			// Verify OpenAI message format compliance.
			if ( 'assistant' === $message['role'] && isset( $message['tool_calls'] ) ) {
				$this->assertIsArray( $message['tool_calls'], "Message $index tool_calls should be array" );
				foreach ( $message['tool_calls'] as $tool_call ) {
					$this->assertArrayHasKey( 'id', $tool_call, 'Tool call should have ID' );
					$this->assertArrayHasKey( 'function', $tool_call, 'Tool call should have function' );
				}
			}

			if ( 'tool' === $message['role'] ) {
				$this->assertArrayHasKey( 'tool_call_id', $message, "Message $index should have tool_call_id" );
			}
		}
	}

	/**
	 * Test 4: Verify session metadata is correctly populated
	 */
	public function test_session_metadata_population() {
		$this->markTestSkipped( 'Requires JetEngine CCT to be active and configured' );

		// Retrieve a session and verify metadata fields.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'user_id', self::$user_id );
		$request->set_param( 'session_key', self::$session_key );

		$response = $this->rest_api->handle_chat_transcripts( $request );

		if ( ! is_wp_error( $response ) && isset( $response->data['session'] ) ) {
			$session = $response->data['session'];

			// Verify required metadata fields.
			$this->assertArrayHasKey( 'session_key', $session, 'Should have session_key' );
			$this->assertArrayHasKey( 'assistant_id', $session, 'Should have assistant_id' );
			$this->assertArrayHasKey( 'assistant_title', $session, 'Should have assistant_title' );
			$this->assertArrayHasKey( 'assistant_model', $session, 'Should have assistant_model' );
			$this->assertArrayHasKey( 'started_at', $session, 'Should have started_at' );
			$this->assertArrayHasKey( 'updated_at', $session, 'Should have updated_at' );
			$this->assertArrayHasKey( 'turn_count', $session, 'Should have turn_count' );
			$this->assertArrayHasKey( 'messages', $session, 'Should have messages' );

			// Verify types.
			$this->assertIsString( $session['session_key'], 'session_key should be string' );
			$this->assertIsInt( $session['assistant_id'], 'assistant_id should be int' );
			$this->assertIsString( $session['assistant_title'], 'assistant_title should be string' );
			$this->assertIsString( $session['assistant_model'], 'assistant_model should be string' );
			$this->assertIsInt( $session['turn_count'], 'turn_count should be int' );
			$this->assertIsArray( $session['messages'], 'messages should be array' );
		}
	}

	/**
	 * Clean up after class
	 */
	public static function wpTearDownAfterClass() {
		// Clean up test data.
		if ( self::$user_id ) {
			wp_delete_user( self::$user_id );
		}

		if ( self::$assistant_id ) {
			wp_delete_post( self::$assistant_id, true );
		}

		if ( self::$assistant_id_2 ) {
			wp_delete_post( self::$assistant_id_2, true );
		}
	}
}
