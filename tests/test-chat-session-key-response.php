<?php
/**
 * Tests for session key being returned in chat responses.
 *
 * Verifies that the server returns the session_key in the response payload
 * so the client can save it for future reference.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */
class WP_MCP_AI_Chat_Session_Key_Response_Test extends WP_UnitTestCase {
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
	 * Mock client that returns a test response.
	 *
	 * @var object
	 */
	protected $mock_client;

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
				'post_title'  => 'Session Key Test Assistant',
			)
		);

		// Set up assistant configuration.
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_model', 'gpt-4' );
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_provider', 'openai' );
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_api_key', 'test-api-key' );

		rest_get_server();
		do_action( 'init' );

		// Mock the AI client to avoid actual API calls.
		$this->mock_client = new class() {
			public function create_chat_completion( $messages, $options ) {
				return array(
					'model'   => 'gpt-4',
					'choices' => array(
						array(
							'message' => array(
								'role'    => 'assistant',
								'content' => 'Hello! This is a test response.',
							),
						),
					),
					'usage'   => array(
						'prompt_tokens'     => 10,
						'completion_tokens' => 8,
						'total_tokens'      => 18,
					),
				);
			}
		};
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		remove_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );
		remove_filter( 'wp_mcp_ai_client', array( $this, 'provide_mock_client' ), 10 );
		wp_set_current_user( 0 );
		$this->transcript_handler = null;
		$this->mock_client        = null;
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
	 * Provide mock client for testing.
	 *
	 * @return object Mock client instance.
	 */
	public function provide_mock_client() {
		return $this->mock_client;
	}

	/**
	 * Test that chat response includes session_key when transcript is saved.
	 */
	public function test_chat_response_includes_session_key() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );
		add_filter( 'wp_mcp_ai_client', array( $this, 'provide_mock_client' ), 10 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello, assistant!',
				),
			)
		);

		// Don't provide a session_key in the request - let the server generate one.
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status(), 'Should return 200 on successful chat' );
		$this->assertArrayHasKey( 'sessionKey', $data, 'Response should include sessionKey' );
		$this->assertNotEmpty( $data['sessionKey'], 'Session key should not be empty' );

		// Verify the session key was also saved in the transcript.
		$this->assertNotNull( $this->transcript_handler, 'Transcript handler should be initialized' );
		$this->assertCount( 1, $this->transcript_handler->records, 'One record should have been saved' );

		$record = $this->transcript_handler->records[0];
		$this->assertEquals( $data['sessionKey'], $record['session_key'], 'Response session_key should match saved session_key' );
	}

	/**
	 * Test that chat response preserves client-provided session_key.
	 */
	public function test_chat_response_preserves_client_session_key() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );
		add_filter( 'wp_mcp_ai_client', array( $this, 'provide_mock_client' ), 10 );

		$client_session_key = 'client-provided-session-12345';

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'session_key', $client_session_key );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello, assistant!',
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status(), 'Should return 200 on successful chat' );
		$this->assertArrayHasKey( 'sessionKey', $data, 'Response should include sessionKey' );
		$this->assertEquals( $client_session_key, $data['sessionKey'], 'Response should return the client-provided session_key' );

		// Verify the session key was saved in the transcript.
		$this->assertNotNull( $this->transcript_handler, 'Transcript handler should be initialized' );
		$this->assertCount( 1, $this->transcript_handler->records, 'One record should have been saved' );

		$record = $this->transcript_handler->records[0];
		$this->assertEquals( $client_session_key, $record['session_key'], 'Saved session_key should match client-provided value' );
	}

	/**
	 * Test that session_key format is valid.
	 */
	public function test_generated_session_key_format() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );
		add_filter( 'wp_mcp_ai_client', array( $this, 'provide_mock_client' ), 10 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Test message',
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status(), 'Should return 200 on successful chat' );
		$this->assertArrayHasKey( 'sessionKey', $data, 'Response should include sessionKey' );

		$session_key = $data['sessionKey'];

		// Verify session key format - should only contain alphanumeric, hyphens, and underscores.
		$this->assertMatchesRegularExpression(
			'/^[a-zA-Z0-9_-]+$/',
			$session_key,
			'Session key should only contain alphanumeric characters, hyphens, and underscores'
		);

		// Verify session key is not too long (max 96 characters as per MAX_SESSION_KEY_LENGTH).
		$this->assertLessThanOrEqual(
			96,
			strlen( $session_key ),
			'Session key should not exceed 96 characters'
		);
	}

	/**
	 * Test that session_key is not returned when transcript saving is disabled.
	 */
	public function test_no_session_key_when_transcript_disabled() {
		// Don't add transcript handler filter - no handler means no transcript saving.
		add_filter( 'wp_mcp_ai_client', array( $this, 'provide_mock_client' ), 10 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'save_transcript', false );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello, assistant!',
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status(), 'Should return 200 on successful chat' );

		// When transcript is not saved, session_key should not be in response.
		$this->assertArrayNotHasKey( 'sessionKey', $data, 'Response should not include sessionKey when transcript not saved' );
	}
}
