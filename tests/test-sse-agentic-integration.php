<?php
/**
 * SSE Integration Tests for Agentic Workflow
 *
 * Tests Server-Sent Events streaming during agentic tool execution loops.
 *
 * @package WP_MCP_AI
 */

/**
 * Test SSE streaming with agentic tool execution.
 */
class WP_MCP_AI_Test_SSE_Agentic_Integration extends WP_UnitTestCase {

	/**
	 * Test assistant ID.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create test assistant.
		$this->assistant_id = $this->factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'SSE Agentic Test Assistant',
			)
		);

		// Create admin user.
		$this->user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );
	}

	/**
	 * Test SSE streaming with single tool execution.
	 */
	public function test_sse_streaming_with_single_tool() {
		$mock_client = $this->create_mock_client_with_tool_call( 'get_current_time' );
		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $this->assistant_id );
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
		$request->set_header( 'Accept', 'text/event-stream' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$headers = $response->get_headers();
		$this->assertStringStartsWith( 'text/event-stream', $headers['Content-Type'] ?? '' );

		// Extract and verify SSE events.
		$output = $this->extract_sse_output( $response );

		// Should contain tool execution events.
		$this->assertStringContainsString( 'event: tool_execution', $output );
		$this->assertStringContainsString( 'type":"start"', $output );
		$this->assertStringContainsString( 'get_current_time', $output );
		$this->assertStringContainsString( 'type":"tool_start"', $output );
		$this->assertStringContainsString( 'type":"tool_result"', $output );

		// Should contain final message event.
		$this->assertStringContainsString( 'event: message', $output );
		$this->assertStringContainsString( 'data: [DONE]', $output );
	}

	/**
	 * Test SSE streaming with multiple tool calls.
	 */
	public function test_sse_streaming_with_multiple_tools() {
		$mock_client = $this->create_mock_client_with_multiple_tools();
		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Get time and site summary.',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Accept', 'text/event-stream' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$output = $this->extract_sse_output( $response );

		// Should show multiple tools being executed.
		$this->assertStringContainsString( '"tool_count":2', $output );
		$this->assertStringContainsString( 'get_current_time', $output );
		$this->assertStringContainsString( 'get_site_summary', $output );

		// Should have tool_start events for each tool.
		$tool_start_count = substr_count( $output, '"type":"tool_start"' );
		$this->assertEquals( 2, $tool_start_count, 'Should have 2 tool_start events' );

		// Should have tool_result events for each tool.
		$tool_result_count = substr_count( $output, '"type":"tool_result"' );
		$this->assertEquals( 2, $tool_result_count, 'Should have 2 tool_result events' );
	}

	/**
	 * Test SSE streaming with multi-iteration agentic loop.
	 */
	public function test_sse_streaming_with_multi_iteration_loop() {
		$iteration_count = 0;

		$mock_client = $this->getMockBuilder( 'WP_MCP_AI_Language_Model_Router' )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->method( 'create_chat_completion' )
			->willReturnCallback(
				function ( $messages ) use ( &$iteration_count ) {
					++$iteration_count;

					// First iteration: request tool.
					if ( 1 === $iteration_count ) {
						return $this->create_response_with_tool_call( 'get_current_time' );
					}

					// Second iteration: request another tool.
					if ( 2 === $iteration_count ) {
						return $this->create_response_with_tool_call( 'get_site_summary' );
					}

					// Third iteration: final response.
					return $this->create_simple_response( 'Here is the information.' );
				}
			);

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Get me time and site info.',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Accept', 'text/event-stream' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$output = $this->extract_sse_output( $response );

		// Should have multiple iterations.
		$this->assertStringContainsString( '"iteration":0', $output );
		$this->assertStringContainsString( '"iteration":1', $output );

		// Should execute both tools across iterations.
		$this->assertStringContainsString( 'get_current_time', $output );
		$this->assertStringContainsString( 'get_site_summary', $output );

		// Verify iteration count matches.
		$this->assertEquals( 3, $iteration_count, 'Should have 3 LLM calls' );
	}

	/**
	 * Test SSE streaming respects max iterations.
	 */
	public function test_sse_streaming_respects_max_iterations() {
		$iteration_count = 0;

		$mock_client = $this->getMockBuilder( 'WP_MCP_AI_Language_Model_Router' )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->method( 'create_chat_completion' )
			->willReturnCallback(
				function ( $messages ) use ( &$iteration_count ) {
					++$iteration_count;
					// Always request a tool to hit the limit.
					return $this->create_response_with_tool_call( 'get_current_time' );
				}
			);

		$this->bootstrap_rest_controller( $mock_client );

		// Set low max iterations for testing.
		add_filter(
			'wp_mcp_ai_max_agentic_iterations',
			function () {
				return 2;
			}
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Keep calling tools.',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Accept', 'text/event-stream' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		// Should stop at max iterations.
		$this->assertEquals( 2, $iteration_count, 'Should stop at max iterations (2)' );
	}

	/**
	 * Test SSE streaming handles tool execution errors.
	 */
	public function test_sse_streaming_handles_tool_errors() {
		// Create a mock client that requests a failing tool.
		$mock_client = $this->create_mock_client_with_tool_call( 'failing_test_tool' );
		$this->bootstrap_rest_controller( $mock_client );

		// Register a failing tool.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( $this->create_failing_tool() );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Call failing tool.',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Accept', 'text/event-stream' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$output = $this->extract_sse_output( $response );

		// Should contain tool execution events.
		$this->assertStringContainsString( 'event: tool_execution', $output );
		$this->assertStringContainsString( 'failing_test_tool', $output );

		// Tool result should contain error.
		$this->assertStringContainsString( '"type":"tool_result"', $output );

		// Should complete with DONE marker even after error.
		$this->assertStringContainsString( 'data: [DONE]', $output );
	}

	/**
	 * Test SSE streaming with tool results containing attachments.
	 */
	public function test_sse_streaming_with_tool_attachments() {
		// Register mock image tool that returns attachment data.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( $this->create_mock_image_tool() );

		$mock_client = $this->create_mock_client_with_tool_call( 'generate_test_image' );
		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Generate an image.',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Accept', 'text/event-stream' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$output = $this->extract_sse_output( $response );

		// Should contain tool execution for image generation.
		$this->assertStringContainsString( 'generate_test_image', $output );

		// Tool result should contain attachment data.
		$this->assertStringContainsString( '"type":"tool_result"', $output );
		$this->assertStringContainsString( 'url', $output );
		$this->assertStringContainsString( 'attachment_id', $output );
	}

	/**
	 * Test SSE event ordering during agentic loop.
	 */
	public function test_sse_event_ordering() {
		$mock_client = $this->create_mock_client_with_tool_call( 'get_current_time' );
		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Get time.',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Accept', 'text/event-stream' );

		$response = rest_get_server()->dispatch( $request );

		$output = $this->extract_sse_output( $response );

		// Extract event types in order.
		$events = array();
		preg_match_all( '/event: (\w+)/', $output, $matches );
		if ( ! empty( $matches[1] ) ) {
			$events = $matches[1];
		}

		// Expected event sequence: status -> tool_execution -> tool_execution -> ... -> message.
		$this->assertContains( 'status', $events, 'Should have status event' );
		$this->assertContains( 'tool_execution', $events, 'Should have tool_execution events' );
		$this->assertContains( 'message', $events, 'Should have message event' );

		// Status should come first.
		$this->assertEquals( 'status', $events[0], 'Status event should be first' );

		// Message should come last (before implicit DONE).
		$last_event = end( $events );
		$this->assertEquals( 'message', $last_event, 'Message event should be last' );
	}

	/**
	 * Extract SSE output from response.
	 *
	 * @param WP_REST_Response $response Response object.
	 * @return string SSE output.
	 */
	protected function extract_sse_output( $response ) {
		// Get the pre_serve_request callback that was registered.
		$hook = isset( $GLOBALS['wp_filter']['rest_pre_serve_request'] ) && $GLOBALS['wp_filter']['rest_pre_serve_request'] instanceof WP_Hook
			? $GLOBALS['wp_filter']['rest_pre_serve_request']
			: null;

		if ( ! $hook || empty( $hook->callbacks[999] ) ) {
			return '';
		}

		// Get the last registered callback (should be our SSE handler).
		$callbacks   = $hook->callbacks[999];
		$closure_key = array_key_last( $callbacks );
		$closure     = $callbacks[ $closure_key ]['function'];

		// Capture output.
		ob_start();
		try {
			call_user_func( $closure, true, rest_get_server(), new WP_REST_Request() );
		} catch ( Exception $e ) {
			// SSE handler calls exit, which we catch here.
		}
		$output = ob_get_clean();

		return $output;
	}

	/**
	 * Create mock client with tool call.
	 *
	 * @param string $tool_name Tool name.
	 * @return object Mock client.
	 */
	protected function create_mock_client_with_tool_call( $tool_name = 'get_current_time' ) {
		$mock_client = $this->getMockBuilder( 'WP_MCP_AI_Language_Model_Router' )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$call_count = 0;
		$mock_client
			->method( 'create_chat_completion' )
			->willReturnCallback(
				function ( $messages ) use ( &$call_count, $tool_name ) {
					++$call_count;

					if ( 1 === $call_count ) {
						return $this->create_response_with_tool_call( $tool_name );
					}

					return $this->create_simple_response( 'Task completed.' );
				}
			);

		return $mock_client;
	}

	/**
	 * Create mock client with multiple tools.
	 *
	 * @return object Mock client.
	 */
	protected function create_mock_client_with_multiple_tools() {
		$mock_client = $this->getMockBuilder( 'WP_MCP_AI_Language_Model_Router' )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$call_count = 0;
		$mock_client
			->method( 'create_chat_completion' )
			->willReturnCallback(
				function ( $messages ) use ( &$call_count ) {
					++$call_count;

					if ( 1 === $call_count ) {
						return array(
							'id'      => 'test-multiple-tools',
							'choices' => array(
								array(
									'message' => array(
										'role'       => 'assistant',
										'content'    => 'Getting information...',
										'tool_calls' => array(
											array(
												'id'       => 'call_time_001',
												'type'     => 'function',
												'function' => array(
													'name' => 'get_current_time',
													'arguments' => '{}',
												),
											),
											array(
												'id'       => 'call_summary_002',
												'type'     => 'function',
												'function' => array(
													'name' => 'get_site_summary',
													'arguments' => '{}',
												),
											),
										),
									),
								),
							),
						);
					}

					return $this->create_simple_response( 'Here is the information.' );
				}
			);

		return $mock_client;
	}

	/**
	 * Create simple response.
	 *
	 * @param string $content Response content.
	 * @return array Response data.
	 */
	protected function create_simple_response( $content ) {
		return array(
			'id'      => 'test-response-' . wp_rand(),
			'choices' => array(
				array(
					'message' => array(
						'role'    => 'assistant',
						'content' => $content,
					),
				),
			),
		);
	}

	/**
	 * Create response with tool call.
	 *
	 * @param string $tool_name Tool name.
	 * @return array Response data.
	 */
	protected function create_response_with_tool_call( $tool_name ) {
		return array(
			'id'      => 'test-tool-response-' . wp_rand(),
			'choices' => array(
				array(
					'message' => array(
						'role'       => 'assistant',
						'content'    => 'Let me get that for you.',
						'tool_calls' => array(
							array(
								'id'       => 'call_' . $tool_name . '_' . wp_rand(),
								'type'     => 'function',
								'function' => array(
									'name'      => $tool_name,
									'arguments' => '{}',
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Bootstrap REST controller with mock client.
	 *
	 * @param WP_MCP_AI_Language_Model_Router $mock_client Mock client.
	 */
	protected function bootstrap_rest_controller( $mock_client ) {
		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		$registry                             = WP_MCP_AI_Tool_Registry::get_instance();
		$GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $registry, $mock_client );

		rest_get_server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Create failing tool for error testing.
	 *
	 * @return WP_MCP_AI_Tool_Interface Failing tool.
	 */
	protected function create_failing_tool() {
		return new class() implements WP_MCP_AI_Tool_Interface {
			public function get_slug() {
				return 'failing_test_tool';
			}

			public function get_name() {
				return 'Failing Test Tool';
			}

			public function get_description() {
				return 'A tool that always fails';
			}

			public function get_parameters_schema() {
				return array(
					'type'       => 'object',
					'properties' => array(),
				);
			}

			public function execute( array $arguments = array(), array $context = array() ) {
				return new WP_Error( 'tool_failed', 'This tool always fails.' );
			}
		};
	}

	/**
	 * Create mock image generation tool.
	 *
	 * @return WP_MCP_AI_Tool_Interface Mock tool.
	 */
	protected function create_mock_image_tool() {
		return new class() implements WP_MCP_AI_Tool_Interface {
			public function get_slug() {
				return 'generate_test_image';
			}

			public function get_name() {
				return 'Generate Test Image';
			}

			public function get_description() {
				return 'Generate a test image';
			}

			public function get_parameters_schema() {
				return array(
					'type'       => 'object',
					'properties' => array(),
				);
			}

			public function execute( array $arguments = array(), array $context = array() ) {
				return array(
					'url'           => 'https://example.com/test-image.png',
					'attachment_id' => 123,
					'file_name'     => 'test-image.png',
					'mime_type'     => 'image/png',
				);
			}
		};
	}
}
