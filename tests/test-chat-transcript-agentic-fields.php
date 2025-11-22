<?php
/**
 * Tests for saving chat transcripts with provider-specific fields from agentic workflows.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for agentic flow message fields.
 */
class WP_MCP_AI_Chat_Transcript_Agentic_Fields_Test extends WP_UnitTestCase {
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
	 * Set up test fixtures.
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
				'post_title'  => 'Agentic Fields Test Assistant',
			)
		);

		// Set up assistant configuration.
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_model', 'gpt-4' );
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_provider', 'openai' );

		rest_get_server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Tear down test fixtures.
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
				 * Records saved.
				 *
				 * @var array
				 */
				public $records = array();

				/**
				 * Mock update_item method.
				 *
				 * @param array $record Record to save.
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
	 * Test that messages with OpenAI's 'refusal' field can be saved.
	 */
	public function test_save_message_with_refusal_field() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'session_key', 'test-session-refusal-123' );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Tell me how to hack a website',
				),
				array(
					'role'    => 'assistant',
					'content' => null,
					'refusal' => 'I cannot provide assistance with hacking or any illegal activities.',
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status(), 'Should return 200 for message with refusal field' );
		$this->assertArrayHasKey( 'success', $data, 'Response should include success flag' );
		$this->assertTrue( $data['success'], 'Success flag should be true' );

		// Verify the transcript was saved with the refusal field preserved.
		$this->assertNotNull( $this->transcript_handler, 'Transcript handler should be initialized' );
		$this->assertCount( 1, $this->transcript_handler->records, 'One record should have been saved' );

		$record   = $this->transcript_handler->records[0];
		$messages = json_decode( $record['messages_json'], true );

		$this->assertIsArray( $messages, 'Messages should be an array' );
		$this->assertCount( 2, $messages, 'Should have 2 messages' );
		$this->assertArrayHasKey( 'refusal', $messages[1], 'Second message should have refusal field' );
		$this->assertEquals(
			'I cannot provide assistance with hacking or any illegal activities.',
			$messages[1]['refusal'],
			'Refusal field should be preserved'
		);
	}

	/**
	 * Test that messages with audio field can be saved.
	 */
	public function test_save_message_with_audio_field() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'session_key', 'test-session-audio-456' );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Say hello',
				),
				array(
					'role'    => 'assistant',
					'content' => 'Hello there!',
					'audio'   => array(
						'id'         => 'audio_abc123',
						'expires_at' => 1234567890,
						'data'       => 'base64encodeddata',
						'transcript' => 'Hello there!',
					),
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status(), 'Should return 200 for message with audio field' );
		$this->assertTrue( $data['success'], 'Success flag should be true' );

		// Verify the audio field was preserved.
		$record   = $this->transcript_handler->records[0];
		$messages = json_decode( $record['messages_json'], true );

		$this->assertArrayHasKey( 'audio', $messages[1], 'Second message should have audio field' );
		$this->assertIsArray( $messages[1]['audio'], 'Audio field should be an array' );
		$this->assertArrayHasKey( 'id', $messages[1]['audio'], 'Audio should have id' );
		$this->assertEquals( 'audio_abc123', $messages[1]['audio']['id'], 'Audio ID should be preserved' );
	}

	/**
	 * Test that messages with multiple provider-specific fields can be saved.
	 */
	public function test_save_message_with_multiple_provider_fields() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'session_key', 'test-session-multi-789' );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Test message',
				),
				array(
					'role'     => 'assistant',
					'content'  => 'Test response',
					'model'    => 'gpt-4-1106-preview',
					'logprobs' => array( 'token_logprobs' => array( -0.1, -0.2 ) ),
					'metadata' => array(
						'custom_field'  => 'custom_value',
						'another_field' => 123,
					),
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status(), 'Should return 200 for message with multiple fields' );
		$this->assertTrue( $data['success'], 'Success flag should be true' );

		// Verify all provider-specific fields were preserved.
		$record   = $this->transcript_handler->records[0];
		$messages = json_decode( $record['messages_json'], true );

		$this->assertArrayHasKey( 'model', $messages[1], 'Message should have model field' );
		$this->assertEquals( 'gpt-4-1106-preview', $messages[1]['model'], 'Model should be preserved' );

		$this->assertArrayHasKey( 'logprobs', $messages[1], 'Message should have logprobs field' );
		$this->assertIsArray( $messages[1]['logprobs'], 'Logprobs should be an array' );

		$this->assertArrayHasKey( 'metadata', $messages[1], 'Message should have metadata field' );
		$this->assertIsArray( $messages[1]['metadata'], 'Metadata should be an array' );
		$this->assertArrayHasKey( 'custom_field', $messages[1]['metadata'], 'Metadata should have custom_field' );
	}

	/**
	 * Test that agentic workflow messages with tool_calls and additional fields work.
	 */
	public function test_save_agentic_workflow_messages() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'session_key', 'test-session-agentic-999' );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Create an image of a sunset',
				),
				array(
					'role'          => 'assistant',
					'content'       => null,
					'tool_calls'    => array(
						array(
							'id'       => 'call_abc123',
							'type'     => 'function',
							'function' => array(
								'name'      => 'create_image',
								'arguments' => wp_json_encode( array( 'prompt' => 'sunset' ) ),
							),
						),
					),
					// Provider-specific fields in agentic flow.
					'finish_reason' => 'tool_calls',
					'model'         => 'gpt-4-vision-preview',
				),
				array(
					'role'         => 'tool',
					'content'      => 'Image created successfully',
					'tool_call_id' => 'call_abc123',
					'name'         => 'create_image',
				),
				array(
					'role'          => 'assistant',
					'content'       => 'I have created an image of a sunset for you.',
					// Final response may also have provider-specific metadata.
					'finish_reason' => 'stop',
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status(), 'Should return 200 for agentic workflow' );
		$this->assertTrue( $data['success'], 'Success flag should be true' );

		// Verify the complete agentic workflow was saved with all fields.
		$record   = $this->transcript_handler->records[0];
		$messages = json_decode( $record['messages_json'], true );

		$this->assertCount( 4, $messages, 'Should have 4 messages in agentic flow' );

		// Verify first assistant message has tool_calls and provider fields.
		$this->assertArrayHasKey( 'tool_calls', $messages[1], 'Assistant message should have tool_calls' );
		$this->assertArrayHasKey( 'finish_reason', $messages[1], 'Assistant message should have finish_reason' );
		$this->assertEquals( 'tool_calls', $messages[1]['finish_reason'], 'Finish reason should be preserved' );
		$this->assertArrayHasKey( 'model', $messages[1], 'Assistant message should have model field' );

		// Verify tool message.
		$this->assertEquals( 'tool', $messages[2]['role'], 'Third message should be a tool message' );
		$this->assertArrayHasKey( 'tool_call_id', $messages[2], 'Tool message should have tool_call_id' );

		// Verify final assistant response has provider fields.
		$this->assertArrayHasKey( 'finish_reason', $messages[3], 'Final message should have finish_reason' );
		$this->assertEquals( 'stop', $messages[3]['finish_reason'], 'Final finish_reason should be preserved' );
	}
}
