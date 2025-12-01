<?php
/**
 * Tests for agentic tool execution parameter validation.
 *
 * Ensures that tool arguments are properly validated and error handling
 * works correctly for invalid JSON and malformed parameters.
 */
class WP_MCP_AI_Agentic_Tool_Parameters_Test extends WP_UnitTestCase {

	/**
	 * Test that malformed JSON in tool arguments returns proper error.
	 */
	public function test_malformed_json_tool_arguments_returns_error() {
		$assistant_id = $this->create_assistant_post();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$message_sequence = array();

		// The agentic loop makes TWO calls:
		// 1. Initial call returns assistant message with tool_calls.
		// 2. Second call after tool execution (even when tool returns error)
		$mock_client
			->expects( $this->exactly( 2 ) )
			->method( 'create_chat_completion' )
			->willReturnCallback(
				function ( $messages ) use ( &$message_sequence ) {
					$message_sequence[] = $messages;

					// First call: return assistant message with malformed tool_call.
					if ( 1 === count( $message_sequence ) ) {
						return array(
							'id'      => 'chatcmpl-test-malformed',
							'choices' => array(
								array(
									'message' => array(
										'role'       => 'assistant',
										'content'    => 'Let me check that for you.',
										'tool_calls' => array(
											array(
												'id'       => 'call_test_123',
												'type'     => 'function',
												'function' => array(
													'name' => 'get_open_meteo_forecast',
													'arguments' => '{invalid json here}',
												),
											),
										),
									),
								),
							),
						);
					}

					// Second call: verify error message was added and return final response.
					$this->assertCount( 3, $messages, 'Should have 3 messages: user, assistant with tool_calls, and tool error' );
					$this->assertSame( 'tool', $messages[2]['role'], 'Third message should be tool result with error' );
					$this->assertStringContainsString( 'invalid JSON arguments', $messages[2]['content'] );

					return array(
						'id'      => 'chatcmpl-test-final',
						'choices' => array(
							array(
								'message' => array(
									'role'    => 'assistant',
									'content' => 'There was an error with the tool parameters.',
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
					'content' => 'Test message',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();

		// Should get error response for malformed JSON.
		$this->assertArrayHasKey( 'tool_results', $data );
		$this->assertNotEmpty( $data['tool_results'] );

		$tool_result = $data['tool_results'][0];
		$this->assertStringContainsString( 'invalid JSON arguments', $tool_result['content'] );
	}

	/**
	 * Test that empty string arguments are handled correctly.
	 */
	public function test_empty_string_tool_arguments() {
		$assistant_id = $this->create_assistant_post();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$message_sequence = array();

		// First call: Return tool call with empty string arguments.
		// Second call: Verify tool was called and return final response.
		$mock_client
			->expects( $this->exactly( 2 ) )
			->method( 'create_chat_completion' )
			->willReturnCallback(
				function ( $messages ) use ( &$message_sequence ) {
					$message_sequence[] = $messages;

					if ( 1 === count( $message_sequence ) ) {
						return array(
							'id'      => 'chatcmpl-test-empty',
							'choices' => array(
								array(
									'message' => array(
										'role'       => 'assistant',
										'content'    => 'Getting current time.',
										'tool_calls' => array(
											array(
												'id'       => 'call_time_123',
												'type'     => 'function',
												'function' => array(
													'name' => 'get_current_time',
													'arguments' => '', // Empty string is valid - no args needed.
												),
											),
										),
									),
								),
							),
						);
					}

					// Verify tool was called.
					$this->assertCount( 3, $messages );
					$this->assertSame( 'tool', $messages[2]['role'] );

					return array(
						'id'      => 'chatcmpl-test-final',
						'choices' => array(
							array(
								'message' => array(
									'role'    => 'assistant',
									'content' => 'The current time is 12:00 PM.',
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
					'content' => 'What time is it?',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Test that non-array JSON decoded arguments return proper error.
	 */
	public function test_non_array_json_tool_arguments_returns_error() {
		$assistant_id = $this->create_assistant_post();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$message_sequence = array();

		// The agentic loop makes TWO calls:
		// 1. Initial call returns assistant message with tool_calls.
		// 2. Second call after tool execution (even when tool returns error)
		$mock_client
			->expects( $this->exactly( 2 ) )
			->method( 'create_chat_completion' )
			->willReturnCallback(
				function ( $messages ) use ( &$message_sequence ) {
					$message_sequence[] = $messages;

					// First call: return tool call with non-array JSON.
					if ( 1 === count( $message_sequence ) ) {
						return array(
							'id'      => 'chatcmpl-test-string',
							'choices' => array(
								array(
									'message' => array(
										'role'       => 'assistant',
										'content'    => 'Processing request.',
										'tool_calls' => array(
											array(
												'id'       => 'call_test_456',
												'type'     => 'function',
												'function' => array(
													'name' => 'get_open_meteo_forecast',
													'arguments' => '"just a string"', // Valid JSON but not an object.
												),
											),
										),
									),
								),
							),
						);
					}

					// Second call: verify error message was added and return final response.
					$this->assertCount( 3, $messages, 'Should have 3 messages: user, assistant with tool_calls, and tool error' );
					$this->assertSame( 'tool', $messages[2]['role'], 'Third message should be tool result with error' );
					$this->assertStringContainsString( 'expected JSON object', $messages[2]['content'] );

					return array(
						'id'      => 'chatcmpl-test-final',
						'choices' => array(
							array(
								'message' => array(
									'role'    => 'assistant',
									'content' => 'There was an error with the tool parameters.',
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
					'content' => 'Test message',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();

		// Should get error response for non-array arguments.
		$this->assertArrayHasKey( 'tool_results', $data );
		$this->assertNotEmpty( $data['tool_results'] );

		$tool_result = $data['tool_results'][0];
		$this->assertStringContainsString( 'expected JSON object', $tool_result['content'] );
	}

	/**
	 * Test that valid array arguments work correctly.
	 */
	public function test_valid_array_tool_arguments() {
		$assistant_id = $this->create_assistant_post();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$message_sequence = array();

		$mock_client
			->expects( $this->exactly( 2 ) )
			->method( 'create_chat_completion' )
			->willReturnCallback(
				function ( $messages ) use ( &$message_sequence ) {
					$message_sequence[] = $messages;

					if ( 1 === count( $message_sequence ) ) {
						return array(
							'id'      => 'chatcmpl-test-valid',
							'choices' => array(
								array(
									'message' => array(
										'role'       => 'assistant',
										'content'    => 'Getting weather data.',
										'tool_calls' => array(
											array(
												'id'       => 'call_weather_789',
												'type'     => 'function',
												'function' => array(
													'name' => 'get_open_meteo_forecast',
													// Arguments provided as array instead of JSON string.
													'arguments' => array(
														'latitude'  => 48.8566,
														'longitude' => 2.3522,
														'hourly'    => 'temperature_2m',
													),
												),
											),
										),
									),
								),
							),
						);
					}

					// Verify tool was called with correct arguments.
					$this->assertCount( 3, $messages );
					$this->assertSame( 'tool', $messages[2]['role'] );

					return array(
						'id'      => 'chatcmpl-test-final',
						'choices' => array(
							array(
								'message' => array(
									'role'    => 'assistant',
									'content' => 'The weather is sunny.',
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
					'content' => 'What is the weather?',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Create a test assistant post.
	 */
	private function create_assistant_post() {
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant',
			)
		);

		// Add required meta.
		update_post_meta( $assistant_id, '_wp_mcp_ai_provider', 'openai' );
		update_post_meta( $assistant_id, '_wp_mcp_ai_model', 'gpt-4o-mini' );
		update_post_meta(
			$assistant_id,
			'_wp_mcp_ai_tools',
			array( 'get_open_meteo_forecast', 'get_current_time' )
		);

		return $assistant_id;
	}

	/**
	 * Bootstrap the REST controller with a mock client.
	 */
	private function bootstrap_rest_controller( $mock_client ) {
		$rest = WP_MCP_AI_REST::get_instance();

		// Use reflection to inject mock client.
		$reflection = new ReflectionClass( $rest );
		$property   = $reflection->getProperty( 'client' );
		$property->setAccessible( true );
		$property->setValue( $rest, $mock_client );

		$rest->register_routes();
	}
}
