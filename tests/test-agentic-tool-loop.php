<?php
/**
 * Tests for agentic tool execution loop ensuring assistant messages with tool_calls
 * are properly added to the conversation before tool response messages.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Agentic_Tool_Loop_Test extends WP_UnitTestCase {

	/**
	 * Test that assistant message with tool_calls is added before tool responses.
	 */
	public function test_assistant_message_added_before_tool_responses() {
		$assistant_id = $this->create_assistant_post();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Track the sequence of messages sent to the LLM.
		$message_sequence = array();

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		// First call: LLM returns a response with tool_calls.
		// Second call: Verify assistant message and tool responses are in correct order.
		$mock_client
			->expects( $this->exactly( 2 ) )
			->method( 'create_chat_completion' )
			->willReturnCallback(
				function ( $messages ) use ( &$message_sequence ) {
					$message_sequence[] = $messages;

					// First call: return assistant message with tool_calls.
					if ( 1 === count( $message_sequence ) ) {
						return array(
							'id'      => 'chatcmpl-test-agentic',
							'choices' => array(
								array(
									'message' => array(
										'role'       => 'assistant',
										'content'    => 'Let me check the weather for you.',
										'tool_calls' => array(
											array(
												'id'       => 'call_weather_123',
												'type'     => 'function',
												'function' => array(
													'name' => 'get_open_meteo_forecast',
													'arguments' => wp_json_encode(
														array(
															'latitude'  => 48.8566,
															'longitude' => 2.3522,
															'hourly'    => 'temperature_2m',
														)
													),
												),
											),
										),
									),
								),
							),
						);
					}

					// Second call: verify message sequence and return final response.
					$this->assertCount( 3, $messages, 'Should have 3 messages: user, assistant with tool_calls, and tool response' );

					// Verify the sequence: user -> assistant with tool_calls -> tool response.
					$this->assertSame( 'user', $messages[0]['role'], 'First message should be user' );
					$this->assertSame( 'assistant', $messages[1]['role'], 'Second message should be assistant' );
					$this->assertArrayHasKey( 'tool_calls', $messages[1], 'Assistant message should have tool_calls' );
					$this->assertSame( 'tool', $messages[2]['role'], 'Third message should be tool response' );
					$this->assertSame( 'call_weather_123', $messages[2]['tool_call_id'], 'Tool response should reference correct tool_call_id' );

					return array(
						'id'      => 'chatcmpl-test-final',
						'choices' => array(
							array(
								'message' => array(
									'role'    => 'assistant',
									'content' => 'The weather in Paris is sunny.',
								),
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
					'content' => 'What is the weather in Paris?',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		// Verify we got two calls as expected.
		$this->assertCount( 2, $message_sequence );
	}

	/**
	 * Test multiple tool calls in a single response.
	 */
	public function test_multiple_tool_calls_in_response() {
		$assistant_id = $this->create_assistant_post();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$message_sequence = array();

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->expects( $this->exactly( 2 ) )
			->method( 'create_chat_completion' )
			->willReturnCallback(
				function ( $messages ) use ( &$message_sequence ) {
					$message_sequence[] = $messages;

					// First call: return assistant message with multiple tool_calls.
					if ( 1 === count( $message_sequence ) ) {
						return array(
							'id'      => 'chatcmpl-test-multi',
							'choices' => array(
								array(
									'message' => array(
										'role'       => 'assistant',
										'content'    => 'Let me get the weather and time.',
										'tool_calls' => array(
											array(
												'id'       => 'call_weather_456',
												'type'     => 'function',
												'function' => array(
													'name' => 'get_open_meteo_forecast',
													'arguments' => wp_json_encode(
														array(
															'latitude'  => 51.5074,
															'longitude' => -0.1278,
															'hourly'    => 'temperature_2m',
														)
													),
												),
											),
											array(
												'id'       => 'call_time_789',
												'type'     => 'function',
												'function' => array(
													'name' => 'get_current_time',
													'arguments' => wp_json_encode( array() ),
												),
											),
										),
									),
								),
							),
						);
					}

					// Second call: verify all tool calls have responses.
					$this->assertCount( 4, $messages, 'Should have 4 messages: user, assistant, tool response 1, tool response 2' );

					$this->assertSame( 'user', $messages[0]['role'] );
					$this->assertSame( 'assistant', $messages[1]['role'] );
					$this->assertArrayHasKey( 'tool_calls', $messages[1] );
					$this->assertCount( 2, $messages[1]['tool_calls'] );

					$this->assertSame( 'tool', $messages[2]['role'] );
					$this->assertSame( 'call_weather_456', $messages[2]['tool_call_id'] );

					$this->assertSame( 'tool', $messages[3]['role'] );
					$this->assertSame( 'call_time_789', $messages[3]['tool_call_id'] );

					return array(
						'id'      => 'chatcmpl-test-final-multi',
						'choices' => array(
							array(
								'message' => array(
									'role'    => 'assistant',
									'content' => 'Here is the information you requested.',
								),
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
					'content' => 'Get me the weather and time in London.',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $message_sequence );
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
				'post_title'  => 'Agentic Loop Test Assistant',
				'post_status' => 'publish',
			)
		);

		$this->assertNotWPError( $assistant_id );
		$this->assertNotEmpty( $assistant_id );

		return $assistant_id;
	}
}
