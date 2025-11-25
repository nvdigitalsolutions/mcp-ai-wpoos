<?php
/**
 * Tests for preserving async tool results in conversation state for agentic flow.
 *
 * This test ensures that intermediate assistant messages with tool_calls are
 * included in the response so the frontend can maintain complete conversation history.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for agentic tool messages preservation.
 */
class WP_MCP_AI_Agentic_Tool_Messages_Preservation_Test extends WP_UnitTestCase {

	/**
	 * Test that agentic_tool_messages are included in response when tool calls are made.
	 */
	public function test_agentic_tool_messages_included_in_response() {
		$assistant_id = $this->create_assistant_post();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$call_count = 0;

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->exactly( 2 ) )
			->method( 'create_chat_completion' )
			->willReturnCallback(
				function ( $messages ) use ( &$call_count ) {
					++$call_count;

					if ( 1 === $call_count ) {
						// First call: return assistant message with tool_calls.
						return array(
							'id'      => 'chatcmpl-test-agentic-msg',
							'choices' => array(
								array(
									'message'       => array(
										'role'       => 'assistant',
										'content'    => 'Let me check the time for you.',
										'tool_calls' => array(
											array(
												'id'       => 'call_time_001',
												'type'     => 'function',
												'function' => array(
													'name' => 'get_current_time',
													'arguments' => '{}',
												),
											),
										),
									),
									'finish_reason' => 'tool_calls',
								),
							),
						);
					}

					// Second call: final response.
					return array(
						'id'      => 'chatcmpl-test-final',
						'choices' => array(
							array(
								'message'       => array(
									'role'    => 'assistant',
									'content' => 'The current time is 12:00 PM.',
								),
								'finish_reason' => 'stop',
							),
						),
					);
				}
			);

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'What time is it?',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		// Verify agentic_tool_messages is present in response.
		$this->assertArrayHasKey( 'agentic_tool_messages', $data, 'Response should include agentic_tool_messages array' );
		$this->assertIsArray( $data['agentic_tool_messages'], 'agentic_tool_messages should be an array' );
		$this->assertCount( 1, $data['agentic_tool_messages'], 'Should have 1 intermediate assistant message' );

		// Verify the structure of the agentic tool message.
		$agentic_message = $data['agentic_tool_messages'][0];
		$this->assertSame( 'assistant', $agentic_message['role'], 'Agentic message should have role: assistant' );
		$this->assertSame( 'Let me check the time for you.', $agentic_message['content'], 'Agentic message content should match' );
		$this->assertArrayHasKey( 'tool_calls', $agentic_message, 'Agentic message should have tool_calls' );
		$this->assertCount( 1, $agentic_message['tool_calls'], 'Should have 1 tool call' );

		// Verify tool call details.
		$tool_call = $agentic_message['tool_calls'][0];
		$this->assertSame( 'call_time_001', $tool_call['id'], 'Tool call ID should match' );
		$this->assertSame( 'get_current_time', $tool_call['function']['name'], 'Tool function name should match' );
	}

	/**
	 * Test that multiple tool calls across iterations are all captured.
	 */
	public function test_multiple_iterations_captured_in_agentic_tool_messages() {
		$assistant_id = $this->create_assistant_post();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$call_count = 0;

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->exactly( 3 ) )
			->method( 'create_chat_completion' )
			->willReturnCallback(
				function ( $messages ) use ( &$call_count ) {
					++$call_count;

					if ( 1 === $call_count ) {
						// First iteration: get time.
						return array(
							'id'      => 'chatcmpl-iter-1',
							'choices' => array(
								array(
									'message'       => array(
										'role'       => 'assistant',
										'content'    => 'First I\'ll get the time.',
										'tool_calls' => array(
											array(
												'id'       => 'call_time_002',
												'type'     => 'function',
												'function' => array(
													'name' => 'get_current_time',
													'arguments' => '{}',
												),
											),
										),
									),
									'finish_reason' => 'tool_calls',
								),
							),
						);
					}

					if ( 2 === $call_count ) {
						// Second iteration: get weather.
						return array(
							'id'      => 'chatcmpl-iter-2',
							'choices' => array(
								array(
									'message'       => array(
										'role'       => 'assistant',
										'content'    => 'Now I\'ll check the weather.',
										'tool_calls' => array(
											array(
												'id'       => 'call_weather_001',
												'type'     => 'function',
												'function' => array(
													'name' => 'get_open_meteo_forecast',
													'arguments' => '{"latitude": 51.5, "longitude": -0.1}',
												),
											),
										),
									),
									'finish_reason' => 'tool_calls',
								),
							),
						);
					}

					// Third call: final response.
					return array(
						'id'      => 'chatcmpl-final',
						'choices' => array(
							array(
								'message'       => array(
									'role'    => 'assistant',
									'content' => 'It\'s 12:00 PM and the weather is sunny.',
								),
								'finish_reason' => 'stop',
							),
						),
					);
				}
			);

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'What\'s the time and weather in London?',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		// Verify agentic_tool_messages has both iterations.
		$this->assertArrayHasKey( 'agentic_tool_messages', $data );
		$this->assertCount( 2, $data['agentic_tool_messages'], 'Should have 2 intermediate assistant messages from 2 iterations' );

		// Verify first iteration.
		$first_msg = $data['agentic_tool_messages'][0];
		$this->assertSame( 'First I\'ll get the time.', $first_msg['content'] );
		$this->assertSame( 'get_current_time', $first_msg['tool_calls'][0]['function']['name'] );

		// Verify second iteration.
		$second_msg = $data['agentic_tool_messages'][1];
		$this->assertSame( 'Now I\'ll check the weather.', $second_msg['content'] );
		$this->assertSame( 'get_open_meteo_forecast', $second_msg['tool_calls'][0]['function']['name'] );
	}

	/**
	 * Test that agentic_tool_messages is not included when no tool calls are made.
	 */
	public function test_no_agentic_tool_messages_when_no_tools() {
		$assistant_id = $this->create_assistant_post();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn(
				array(
					'id'      => 'chatcmpl-no-tools',
					'choices' => array(
						array(
							'message'       => array(
								'role'    => 'assistant',
								'content' => 'Hello! How can I help you today?',
							),
							'finish_reason' => 'stop',
						),
					),
				)
			);

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello!',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		// Verify agentic_tool_messages is not present when no tools are called.
		$this->assertArrayNotHasKey( 'agentic_tool_messages', $data, 'Response should not include agentic_tool_messages when no tools are called' );
	}

	/**
	 * Prepare the REST controller instance for testing.
	 *
	 * @param WP_MCP_AI_Language_Model_Router $mock_client Mocked language model router.
	 */
	protected function bootstrap_rest_controller( WP_MCP_AI_Language_Model_Router $mock_client ) {
		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		$registry                             = WP_MCP_AI_Tool_Registry::get_instance();
		$GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $registry, $mock_client );

		rest_get_server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Create a published assistant post for testing.
	 *
	 * @return int
	 */
	protected function create_assistant_post() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Agentic Tool Messages Test Assistant',
				'post_status' => 'publish',
			)
		);

		$this->assertNotWPError( $assistant_id );
		$this->assertNotEmpty( $assistant_id );

		return $assistant_id;
	}
}
