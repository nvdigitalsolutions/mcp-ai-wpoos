<?php
/**
 * Tests for chat transcript latency fix
 *
 * Verifies that latency_ms is calculated correctly when agentic tool execution occurs.
 * This test validates the fix for the issue where response_completed_at was not updated
 * after the agentic loop, causing inaccurate latency measurements.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Chat Transcript Latency Fix
 */
class Test_Chat_Transcript_Latency_Fix extends WP_UnitTestCase {

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
				'post_title'  => 'Latency Test Assistant',
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
	 * Test that latency is calculated correctly without tool execution
	 *
	 * When there are no tool calls, the latency should be the time between
	 * request_started_at and response_completed_at (just the first API call).
	 */
	public function test_latency_without_tools() {
		$this->register_transcript_handler();

		// Create mock language model router.
		$mock_router = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		// Mock client that returns a simple response without tool calls.
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
						'content' => 'Hello! No tool calls here.',
					),
				),
			),
			'usage'    => array(
				'prompt_tokens'     => 15,
				'completion_tokens' => 10,
				'total_tokens'      => 25,
			),
		);

		// Simulate a 100ms delay for the API call.
		$mock_client->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturnCallback(
				function () use ( $response_payload ) {
					usleep( 100000 ); // 100ms delay.
					return $response_payload;
				}
			);

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
				'content' => 'Hello',
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
			'session_key'     => 'latency-test-no-tools',
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'messages', $messages );

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
		$this->assertIsArray( $result );

		// Verify transcript was saved.
		$this->assertNotEmpty( $this->transcript_handler->records );
		$record = $this->transcript_handler->records[0];

		// Verify latency is present and reasonable (around 100ms).
		$this->assertArrayHasKey( 'latency_ms', $record );
		$this->assertGreaterThan( 90, $record['latency_ms'], 'Latency should be at least 90ms (100ms API call with some overhead)' );
		$this->assertLessThan( 200, $record['latency_ms'], 'Latency should be less than 200ms for simple request' );
	}

	/**
	 * Test that latency is calculated correctly with tool execution
	 *
	 * When tools are executed in an agentic loop, the latency should include
	 * the time for all API calls and tool execution, not just the first call.
	 */
	public function test_latency_with_agentic_tools() {
		$this->register_transcript_handler();

		// Create mock language model router.
		$mock_router = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		// Mock client that returns a response with tool calls, then a final response.
		$mock_client = $this->getMockBuilder( stdClass::class )
			->addMethods( array( 'create_chat_completion' ) )
			->getMock();

		$first_response = array(
			'id'       => 'chatcmpl-test-1',
			'model'    => 'gpt-4o-mini',
			'provider' => 'openai',
			'choices'  => array(
				array(
					'index'         => 0,
					'finish_reason' => 'tool_calls',
					'message'       => array(
						'role'       => 'assistant',
						'content'    => '',
						'tool_calls' => array(
							array(
								'id'       => 'call_test',
								'type'     => 'function',
								'function' => array(
									'name'      => 'get_current_time',
									'arguments' => '{}',
								),
							),
						),
					),
				),
			),
		);

		$final_response = array(
			'id'       => 'chatcmpl-test-2',
			'model'    => 'gpt-4o-mini',
			'provider' => 'openai',
			'status'   => 'completed',
			'choices'  => array(
				array(
					'index'         => 0,
					'finish_reason' => 'stop',
					'message'       => array(
						'role'    => 'assistant',
						'content' => 'Based on the tool result, here is the answer.',
					),
				),
			),
			'usage'    => array(
				'prompt_tokens'     => 50,
				'completion_tokens' => 20,
				'total_tokens'      => 70,
			),
		);

		// Mock two API calls: first with tools, second with final response.
		// Each call takes 100ms, so total should be ~200ms plus tool execution time.
		$call_count = 0;
		$mock_client->expects( $this->exactly( 2 ) )
			->method( 'create_chat_completion' )
			->willReturnCallback(
				function () use ( $first_response, $final_response, &$call_count ) {
					$call_count++;
					usleep( 100000 ); // 100ms delay.
					return $call_count === 1 ? $first_response : $final_response;
				}
			);

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
				'content' => 'What time is it?',
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
			'session_key'     => 'latency-test-with-tools',
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'messages', $messages );

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
		$this->assertIsArray( $result );

		// Verify transcript was saved.
		$this->assertNotEmpty( $this->transcript_handler->records );
		$record = $this->transcript_handler->records[0];

		// Verify latency includes the entire agentic loop (both API calls).
		// Should be at least 200ms (2 x 100ms API calls) plus tool execution overhead.
		$this->assertArrayHasKey( 'latency_ms', $record );
		$this->assertGreaterThan( 190, $record['latency_ms'], 'Latency should include both API calls (at least 190ms)' );

		// This is the key assertion - without the fix, latency would only be ~100ms.
		// (just the first API call). With the fix, it should include both calls.
		$this->assertGreaterThan( 150, $record['latency_ms'], 'Latency must reflect agentic loop completion, not just first API call' );
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
