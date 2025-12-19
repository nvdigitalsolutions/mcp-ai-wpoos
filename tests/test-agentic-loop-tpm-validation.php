<?php
/**
 * Tests for agentic tool execution loop TPM validation and message truncation.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Agentic_Loop_TPM_Validation_Test extends WP_UnitTestCase {

	/**
	 * Test that the agentic loop validates TPM limits before making subsequent API calls.
	 */
	public function test_agentic_loop_validates_tpm_before_iteration() {
		$assistant_id = $this->create_assistant_post();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$call_count = 0;

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		// First call: LLM returns a response with tool_calls.
		// Second call should never happen because TPM validation should fail.
		$mock_client
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturnCallback(
				function ( $messages ) use ( &$call_count ) {
					++$call_count;

					// First call: return assistant message with tool_calls that produces huge result.
					return array(
						'id'      => 'chatcmpl-test-tpm',
						'choices' => array(
							array(
								'message' => array(
									'role'       => 'assistant',
									'content'    => 'Let me crawl that for you.',
									'tool_calls' => array(
										array(
											'id'       => 'call_crawl_123',
											'type'     => 'function',
											'function' => array(
												'name' => 'run_crawl4ai_job',
												'arguments' => wp_json_encode(
													array(
														'url' => 'https://example.com',
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
			);

		$this->bootstrap_rest_controller( $mock_client );

		// Mock the crawl4ai tool to return very large result that exceeds TPM.
		add_filter(
			'wp_mcp_ai_tool_execute_run_crawl4ai_job',
			function ( $result, $arguments, $context ) {
				// Simulate a very large crawl result (300k tokens worth of content).
				$huge_content = str_repeat( 'This is test content. ', 60000 );
				return array(
					'status'  => 'completed',
					'task_id' => 'test-task-123',
					'results' => array(
						array(
							'url'      => 'https://example.com',
							'markdown' => $huge_content,
							'text'     => $huge_content,
							'html'     => $huge_content,
						),
					),
				);
			},
			10,
			3
		);

		// Set up TPM limit for gpt-4o-mini (200k).
		update_option( 'wp_mcp_ai_model_rate_limits', array() );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Crawl https://example.com',
				),
			)
		);
		$request->set_param( 'model', 'gpt-4o-mini' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		// Should return an error about TPM limits.
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();

		// The response should be an error.
		$this->assertArrayHasKey( 'code', $data, 'Response should have error code' );
		$this->assertStringContainsString( 'tpm', strtolower( $data['code'] ), 'Error code should mention TPM' );

		// Verify only one LLM call was made (the initial one).
		$this->assertSame( 1, $call_count, 'Should only make one LLM call before TPM validation fails' );
	}

	/**
	 * Test that messages are truncated when they exceed TPM limits in agentic loop.
	 */
	public function test_agentic_loop_truncates_messages_when_exceeding_tpm() {
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

					// First call: return tool_calls.
					if ( 1 === count( $message_sequence ) ) {
						return array(
							'id'      => 'chatcmpl-test-truncate',
							'choices' => array(
								array(
									'message' => array(
										'role'       => 'assistant',
										'content'    => 'Getting weather.',
										'tool_calls' => array(
											array(
												'id'       => 'call_weather_456',
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

					// Second call: verify messages were present and return final response.
					// After truncation, some old messages might be removed but recent ones kept.
					$this->assertGreaterThan( 0, count( $messages ), 'Should have messages after truncation' );

					return array(
						'id'      => 'chatcmpl-test-final',
						'choices' => array(
							array(
								'message' => array(
									'role'    => 'assistant',
									'content' => 'Here is the weather.',
								),
							),
						),
					);
				}
			);

		$this->bootstrap_rest_controller( $mock_client );

		// Mock TPM limit to be very low to trigger truncation.
		add_filter(
			'wp_mcp_ai_model_tpm_limit',
			function ( $limit, $model ) {
				if ( 'gpt-4o-mini' === $model ) {
					return 5000; // Very low limit to trigger truncation.
				}
				return $limit;
			},
			10,
			2
		);

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
		$request->set_param( 'model', 'gpt-4o-mini' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		// Verify we got two calls.
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
				'post_title'  => 'TPM Validation Test Assistant',
				'post_status' => 'publish',
			)
		);

		$this->assertNotWPError( $assistant_id );
		$this->assertNotEmpty( $assistant_id );

		return $assistant_id;
	}
}
