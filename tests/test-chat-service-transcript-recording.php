<?php
/**
 * Tests for WP_MCP_AI_Chat_Service transcript recording
 *
 * Verifies that the chat service correctly saves transcripts to CCT
 * using the proper static method call.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Chat Service Transcript Recording
 */
class Test_Chat_Service_Transcript_Recording extends WP_UnitTestCase {

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
	 * Set up before each test
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
				'post_title'  => 'Chat Service Test Assistant',
			)
		);

		// Configure assistant with basic settings.
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_model', 'gpt-4o-mini' );
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_provider', 'openai' );

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
	 * Test that chat service calls transcript recorder with correct parameters
	 *
	 * This test verifies the fix for the issue where:
	 * 1. Chat service was trying to instantiate WP_MCP_AI_Chat_Transcript_Recorder (which only has static methods)
	 * 2. Chat service was calling non-existent method record_transcript() instead of static record()
	 * 3. Parameters didn't match the expected signature
	 */
	public function test_chat_service_saves_transcript_correctly() {
		$this->register_transcript_handler();

		// Create mock language model router.
		$mock_router = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		// Mock client that returns a simple response.
		$mock_client = $this->getMockBuilder( stdClass::class )
			->addMethods( array( 'create_chat_completion' ) )
			->getMock();

		$response_payload = array(
			'id'       => 'chatcmpl-test',
			'model'    => 'gpt-4o-mini',
			'provider' => 'openai',
			'status'   => 'completed',
			'choices'  => array(
				array(
					'index'         => 0,
					'finish_reason' => 'stop',
					'message'       => array(
						'role'    => 'assistant',
						'content' => 'Hello! How can I help you today?',
					),
				),
			),
			'usage'    => array(
				'prompt_tokens'     => 15,
				'completion_tokens' => 10,
				'total_tokens'      => 25,
			),
		);

		$mock_client->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn( $response_payload );

		// Mock router to return our mock client.
		$mock_router->expects( $this->once() )
			->method( 'get_client' )
			->willReturn( $mock_client );

		// Create chat service with mock dependencies.
		$mock_rate_limiter         = $this->getMockBuilder( WP_MCP_AI_Rate_Limit_Manager::class )
			->disableOriginalConstructor()
			->getMock();
		$mock_token_budget_manager = $this->getMockBuilder( WP_MCP_AI_Token_Budget_Manager::class )
			->disableOriginalConstructor()
			->getMock();
		$tool_registry             = WP_MCP_AI_Tool_Registry::get_instance();

		$chat_service = new WP_MCP_AI_Chat_Service(
			$mock_router,
			$mock_rate_limiter,
			$mock_token_budget_manager,
			$tool_registry
		);

		// Prepare test data.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello, test message',
			),
		);

		$options = array(
			'model'    => 'gpt-4o-mini',
			'provider' => 'openai',
		);

		$assistant_config = array(
			'model'    => 'gpt-4o-mini',
			'provider' => 'openai',
		);

		$transcript_context = array(
			'save_transcript' => true,
			'session_key'     => 'test-session-key-123',
		);

		// Create a mock WP_REST_Request.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'messages', $messages );
		$request->set_param( 'session_key', 'test-session-key-123' );

		// Process the chat request.
		$result = $chat_service->process_chat_request(
			$this->assistant_id,
			$messages,
			$options,
			$assistant_config,
			$transcript_context,
			$this->admin_id,
			5,
			$request
		);

		// Verify the chat processed successfully.
		$this->assertIsArray( $result, 'Chat service should return response array' );
		$this->assertArrayHasKey( 'choices', $result );

		// Verify transcript was saved.
		$this->assertNotEmpty( $this->transcript_handler->records, 'Transcript handler should capture a saved record.' );

		$record = $this->transcript_handler->records[0];

		// Verify the record has the correct structure.
		$this->assertSame( 'test-session-key-123', $record['session_key'], 'Session key should match' );
		$this->assertSame( $this->admin_id, (int) $record['user_id'], 'User ID should match' );
		$this->assertSame( (string) $this->assistant_id, $record['assistant_id'], 'Assistant ID should match' );
		$this->assertSame( 'gpt-4o-mini', $record['assistant_model'], 'Model should match' );

		// Verify timing fields are present.
		$this->assertArrayHasKey( 'request_started_at', $record, 'Record should have request_started_at' );
		$this->assertArrayHasKey( 'response_completed_at', $record, 'Record should have response_completed_at' );

		// Verify request payload is saved correctly.
		$this->assertArrayHasKey( 'request_payload', $record, 'Record should have request_payload' );
		$request_payload = json_decode( $record['request_payload'], true );
		$this->assertIsArray( $request_payload, 'Request payload should be valid JSON' );
		$this->assertArrayHasKey( 'messages', $request_payload, 'Request payload should contain messages' );

		// Verify messages in payload.
		$this->assertCount( 1, $request_payload['messages'], 'Should have 1 user message in payload' );
		$this->assertSame( 'user', $request_payload['messages'][0]['role'], 'First message should be user' );
		$this->assertSame( 'Hello, test message', $request_payload['messages'][0]['content'], 'Message content should match' );

		// Verify response payload is saved.
		$this->assertArrayHasKey( 'response_payload', $record, 'Record should have response_payload' );
		$saved_response = json_decode( $record['response_payload'], true );
		$this->assertIsArray( $saved_response, 'Response payload should be valid JSON' );
		$this->assertSame( 'chatcmpl-test', $saved_response['id'], 'Response ID should match' );
	}

	/**
	 * Test that chat service handles missing request gracefully
	 *
	 * When no WP_REST_Request is provided, the service should log an error
	 * but not crash.
	 */
	public function test_chat_service_handles_missing_request_gracefully() {
		$this->register_transcript_handler();

		// Create mock language model router.
		$mock_router = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		// Mock client.
		$mock_client = $this->getMockBuilder( stdClass::class )
			->addMethods( array( 'create_chat_completion' ) )
			->getMock();

		$response_payload = array(
			'id'      => 'chatcmpl-test',
			'model'   => 'gpt-4o-mini',
			'choices' => array(
				array(
					'index'   => 0,
					'message' => array(
						'role'    => 'assistant',
						'content' => 'Response',
					),
				),
			),
		);

		$mock_client->method( 'create_chat_completion' )
			->willReturn( $response_payload );

		$mock_router->method( 'get_client' )
			->willReturn( $mock_client );

		// Create chat service.
		$mock_rate_limiter         = $this->getMockBuilder( WP_MCP_AI_Rate_Limit_Manager::class )
			->disableOriginalConstructor()
			->getMock();
		$mock_token_budget_manager = $this->getMockBuilder( WP_MCP_AI_Token_Budget_Manager::class )
			->disableOriginalConstructor()
			->getMock();
		$tool_registry             = WP_MCP_AI_Tool_Registry::get_instance();

		$chat_service = new WP_MCP_AI_Chat_Service(
			$mock_router,
			$mock_rate_limiter,
			$mock_token_budget_manager,
			$tool_registry
		);

		// Process chat WITHOUT providing a request object.
		$result = $chat_service->process_chat_request(
			$this->assistant_id,
			array(
				array(
					'role'    => 'user',
					'content' => 'Test',
				),
			),
			array( 'model' => 'gpt-4o-mini' ),
			array( 'model' => 'gpt-4o-mini' ),
			array(
				'save_transcript' => true,
				'session_key'     => 'test',
			),
			$this->admin_id,
			5,
			null // No request object.
		);

		// Chat should still succeed.
		$this->assertIsArray( $result, 'Chat should succeed even without request object' );

		// But transcript should NOT be saved (handler should not have any records).
		$this->assertEmpty( $this->transcript_handler->records, 'Transcript should not be saved without request object' );
	}

	/**
	 * Register a mock transcript handler that captures stored records.
	 */
	protected function register_transcript_handler() {
		$this->transcript_handler = new class() {
			/**
			 * Captured records
			 *
			 * @var array
			 */
			public $records = array();

			/**
			 * Update item (mock implementation)
			 *
			 * @param array $item Item data to store.
			 * @return int
			 */
			public function update_item( $item ) {
				$this->records[] = $item;
				return count( $this->records );
			}
		};

		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10, 7 );
	}

	/**
	 * Provide the mock transcript handler for storage.
	 *
	 * @param object|null     $handler      Custom handler instance.
	 * @param int             $assistant_id Assistant identifier.
	 * @param array           $messages     Sanitised chat messages.
	 * @param array           $options      Prepared chat options.
	 * @param array           $response     Language model response payload.
	 * @param WP_REST_Request $request      REST request instance.
	 * @param array           $context      Additional context (timings, session key, etc.).
	 * @return object
	 */
	public function provide_transcript_handler( $handler, $assistant_id, $messages, $options, $response, $request, $context ) {
		return $this->transcript_handler;
	}
}
