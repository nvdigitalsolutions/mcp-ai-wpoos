<?php
/**
 * Tests for response_payload construction when manually saving transcripts.
 *
 * Verifies that when saving a conversation via POST /chat-transcripts,
 * the response_payload is properly constructed with assistant messages
 * in the OpenAI API format so they can be extracted when retrieving the transcript.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for response payload construction in manual transcript saves.
 */
class Test_Chat_Transcript_Response_Payload_Construction extends WP_UnitTestCase {
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
				'post_title'  => 'Test Assistant for Response Payload',
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
	 * Provide a mock handler that captures transcript records.
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
	 * Test that response_payload contains assistant messages in choices array.
	 *
	 * This is the fix for the issue where manually saving a conversation
	 * creates an empty choices array in response_payload, causing assistant
	 * messages to be lost when the transcript is retrieved.
	 */
	public function test_response_payload_contains_assistant_messages() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		$session_key = 'test-response-payload-' . wp_generate_uuid4();
		$messages    = array(
			array(
				'role'    => 'user',
				'content' => 'Hello, assistant!',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Hello! How can I help you today?',
			),
			array(
				'role'    => 'user',
				'content' => 'What is 2 + 2?',
			),
			array(
				'role'    => 'assistant',
				'content' => 'The answer is 4.',
			),
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'Content-Type', 'application/json' );
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

		// Verify save succeeded.
		$this->assertEquals( 200, $response->get_status(), 'Save request should succeed' );

		// Get the stored record.
		$this->assertNotNull( $this->transcript_handler, 'Transcript handler should be initialized' );
		$this->assertCount( 1, $this->transcript_handler->records, 'One record should have been saved' );

		$record = $this->transcript_handler->records[0];

		// Verify response_payload exists.
		$this->assertArrayHasKey( 'response_payload', $record, 'Record should have response_payload' );

		// Decode the response_payload.
		$response_payload = json_decode( $record['response_payload'], true );
		$this->assertIsArray( $response_payload, 'response_payload should be valid JSON' );

		// Verify the response has the expected structure.
		$this->assertArrayHasKey( 'model', $response_payload, 'response_payload should have model' );
		$this->assertEquals( 'gpt-4', $response_payload['model'], 'Model should match assistant configuration' );

		$this->assertArrayHasKey( 'choices', $response_payload, 'response_payload should have choices array' );
		$this->assertIsArray( $response_payload['choices'], 'choices should be an array' );

		// Verify that assistant messages are included in choices.
		$this->assertCount( 2, $response_payload['choices'], 'Should have 2 assistant messages in choices' );

		// Verify first assistant message.
		$choice_0 = $response_payload['choices'][0];
		$this->assertArrayHasKey( 'index', $choice_0, 'Choice should have index' );
		$this->assertEquals( 0, $choice_0['index'], 'First choice should have index 0' );
		$this->assertArrayHasKey( 'message', $choice_0, 'Choice should have message' );
		$this->assertEquals( 'assistant', $choice_0['message']['role'], 'Message role should be assistant' );
		$this->assertEquals( 'Hello! How can I help you today?', $choice_0['message']['content'], 'Content should match first assistant message' );
		$this->assertArrayHasKey( 'finish_reason', $choice_0, 'Choice should have finish_reason' );
		$this->assertEquals( 'stop', $choice_0['finish_reason'], 'finish_reason should be stop' );

		// Verify second assistant message.
		$choice_1 = $response_payload['choices'][1];
		$this->assertEquals( 1, $choice_1['index'], 'Second choice should have index 1' );
		$this->assertEquals( 'assistant', $choice_1['message']['role'], 'Message role should be assistant' );
		$this->assertEquals( 'The answer is 4.', $choice_1['message']['content'], 'Content should match second assistant message' );
		$this->assertEquals( 'stop', $choice_1['finish_reason'], 'finish_reason should be stop' );
	}

	/**
	 * Test that response_payload preserves tool_calls in assistant messages.
	 *
	 * Assistant messages with tool_calls (agentic flows) should have the
	 * tool_calls preserved in the response_payload choices.
	 */
	public function test_response_payload_preserves_tool_calls() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		$session_key = 'test-tool-calls-' . wp_generate_uuid4();
		$messages    = array(
			array(
				'role'    => 'user',
				'content' => 'Generate an image of a cat',
			),
			array(
				'role'       => 'assistant',
				'content'    => null,
				'tool_calls' => array(
					array(
						'id'       => 'call_abc123',
						'type'     => 'function',
						'function' => array(
							'name'      => 'generate_image',
							'arguments' => '{"prompt":"a cat"}',
						),
					),
				),
			),
			array(
				'role'         => 'tool',
				'tool_call_id' => 'call_abc123',
				'content'      => '{"image_url":"https://example.com/cat.png"}',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Here is an image of a cat.',
			),
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'Content-Type', 'application/json' );
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
		$this->assertEquals( 200, $response->get_status(), 'Save request should succeed' );

		// Get the stored record.
		$record           = $this->transcript_handler->records[0];
		$response_payload = json_decode( $record['response_payload'], true );

		// Should have 2 assistant messages in choices.
		$this->assertCount( 2, $response_payload['choices'], 'Should have 2 assistant messages' );

		// First assistant message should have tool_calls.
		$choice_0 = $response_payload['choices'][0];
		$this->assertArrayHasKey( 'tool_calls', $choice_0['message'], 'First assistant message should have tool_calls' );
		$this->assertIsArray( $choice_0['message']['tool_calls'], 'tool_calls should be an array' );
		$this->assertCount( 1, $choice_0['message']['tool_calls'], 'Should have 1 tool call' );

		$tool_call = $choice_0['message']['tool_calls'][0];
		$this->assertEquals( 'call_abc123', $tool_call['id'], 'Tool call ID should match' );
		$this->assertEquals( 'function', $tool_call['type'], 'Tool call type should be function' );
		$this->assertEquals( 'generate_image', $tool_call['function']['name'], 'Function name should match' );

		// Second assistant message should not have tool_calls.
		$choice_1 = $response_payload['choices'][1];
		$this->assertArrayNotHasKey( 'tool_calls', $choice_1['message'], 'Second assistant message should not have tool_calls' );
	}

	/**
	 * Test that user and tool messages are not included in response_payload choices.
	 *
	 * Only assistant messages should be in the response_payload. User, system,
	 * and tool messages are stored in request_payload.
	 */
	public function test_response_payload_excludes_non_assistant_messages() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		$session_key = 'test-exclude-non-assistant-' . wp_generate_uuid4();
		$messages    = array(
			array(
				'role'    => 'system',
				'content' => 'You are a helpful assistant.',
			),
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Hi there!',
			),
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'Content-Type', 'application/json' );
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
		$this->assertEquals( 200, $response->get_status(), 'Save request should succeed' );

		// Get the stored record.
		$record           = $this->transcript_handler->records[0];
		$response_payload = json_decode( $record['response_payload'], true );

		// Only the 1 assistant message should be in choices.
		$this->assertCount( 1, $response_payload['choices'], 'Should have only 1 assistant message in choices' );
		$this->assertEquals( 'assistant', $response_payload['choices'][0]['message']['role'], 'Choice should contain assistant message' );
		$this->assertEquals( 'Hi there!', $response_payload['choices'][0]['message']['content'], 'Content should match' );
	}
}
