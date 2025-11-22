<?php
/**
 * Tests for chat transcript endpoint with additional message properties.
 *
 * Validates that messages with provider-specific fields (OpenAI refusal,
 * audio, Gemini metadata, etc.) are accepted by the REST API schema.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for additional message properties in chat transcripts.
 */
class WP_MCP_AI_Chat_Transcript_Additional_Properties_Test extends WP_UnitTestCase {
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

		$this->admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Additional Properties Test Assistant',
			)
		);

		// Set up assistant configuration.
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_model', 'gpt-4' );
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
	 * Provide a mock handler that captures transcript records without requiring JetEngine.
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
	 * Test that messages with OpenAI 'refusal' field are accepted.
	 *
	 * OpenAI returns a 'refusal' field when the model declines to respond
	 * to certain requests. This should be allowed in the schema.
	 */
	public function test_save_messages_with_openai_refusal_field() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'session_key', 'test-session-refusal' );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Tell me how to do something harmful',
				),
				array(
					'role'    => 'assistant',
					'content' => null,
					'refusal' => 'I cannot help with that request.',
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status(), 'Should accept messages with refusal field' );
		$this->assertTrue( $data['success'], 'Save should succeed with refusal field' );
	}

	/**
	 * Test that messages with OpenAI 'audio' field are accepted.
	 *
	 * OpenAI can return audio data in messages. This should be allowed.
	 */
	public function test_save_messages_with_openai_audio_field() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'session_key', 'test-session-audio' );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
				array(
					'role'    => 'assistant',
					'content' => 'Hello there!',
					'audio'   => array(
						'id'         => 'audio_abc123',
						'expires_at' => 1234567890,
						'transcript' => 'Hello there!',
					),
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status(), 'Should accept messages with audio field' );
		$this->assertTrue( $data['success'], 'Save should succeed with audio field' );
	}

	/**
	 * Test that messages with deprecated 'function_call' field are accepted.
	 *
	 * Older OpenAI responses may include the deprecated function_call format.
	 */
	public function test_save_messages_with_function_call_field() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'session_key', 'test-session-function-call' );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'What is the weather?',
				),
				array(
					'role'          => 'assistant',
					'content'       => null,
					'function_call' => array(
						'name'      => 'get_weather',
						'arguments' => '{"location":"London"}',
					),
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status(), 'Should accept messages with function_call field' );
		$this->assertTrue( $data['success'], 'Save should succeed with function_call field' );
	}

	/**
	 * Test that messages with multiple additional properties are accepted.
	 *
	 * Simulates a real agentic workflow where messages may have multiple
	 * provider-specific fields.
	 */
	public function test_save_messages_with_multiple_additional_properties() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'session_key', 'test-session-multi-props' );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
				array(
					'role'              => 'assistant',
					'content'           => 'Hello! Let me help you.',
					'provider_metadata' => array(
						'model'           => 'gpt-4',
						'finish_reason'   => 'stop',
						'usage'           => array(
							'prompt_tokens'     => 10,
							'completion_tokens' => 15,
							'total_tokens'      => 25,
						),
					),
					'custom_field'      => 'custom_value',
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status(), 'Should accept messages with multiple additional properties' );
		$this->assertTrue( $data['success'], 'Save should succeed with multiple additional properties' );
	}

	/**
	 * Test that tool call messages with additional properties are accepted.
	 *
	 * During agentic workflows, tool call messages may have provider-specific
	 * metadata that should be preserved.
	 */
	public function test_save_tool_call_messages_with_additional_properties() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'session_key', 'test-session-tool-calls' );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Search for information',
				),
				array(
					'role'       => 'assistant',
					'content'    => null,
					'tool_calls' => array(
						array(
							'id'       => 'call_abc123',
							'type'     => 'function',
							'function' => array(
								'name'      => 'search',
								'arguments' => '{"query":"test"}',
							),
						),
					),
					'metadata'   => array(
						'timestamp' => time(),
						'provider'  => 'openai',
					),
				),
				array(
					'role'         => 'tool',
					'tool_call_id' => 'call_abc123',
					'content'      => 'Search results here',
					'execution_ms' => 150,
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status(), 'Should accept tool call messages with additional properties' );
		$this->assertTrue( $data['success'], 'Save should succeed with tool call messages having additional properties' );
	}
}
