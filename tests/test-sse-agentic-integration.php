<?php
/**
 * SSE Integration Tests for Agentic Workflow
 *
 * Tests Server-Sent Events streaming during agentic tool execution loops.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
		$request->set_param( 'stream', true );
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

		list( $response, $output ) = $this->dispatch_chat_and_capture( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

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
		$request->set_param( 'stream', true );
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

		list( $response, $output ) = $this->dispatch_chat_and_capture( $request );

		$this->assertSame( 200, $response->get_status() );

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
		$request->set_param( 'stream', true );
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

		list( $response, $output ) = $this->dispatch_chat_and_capture( $request );

		$this->assertSame( 200, $response->get_status() );

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
		$request->set_param( 'stream', true );
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

		list( $response, $output ) = $this->dispatch_chat_and_capture( $request );

		$this->assertSame( 200, $response->get_status() );

		// The streaming loop makes one initial LLM call, then one further call per
		// tool-execution iteration. With max_iterations = 2 the loop runs two
		// iterations (each ending in an LLM call), so 3 calls total.
		$this->assertEquals( 3, $iteration_count, 'Should stop at max iterations (2): initial call + 2 loop iterations' );

		// The stream should announce that the iteration limit was reached.
		$this->assertStringContainsString( '"type":"max_iterations"', $output );
	}

	/**
	 * Test SSE streaming handles tool execution errors.
	 */
	public function test_sse_streaming_handles_tool_errors() {
		// Create a mock client that requests a failing tool.
		$mock_client = $this->create_mock_client_with_tool_call( 'failing_test_tool' );
		$this->bootstrap_rest_controller( $mock_client );

		// Register a failing tool and allowlist it on the assistant so the agentic
		// loop actually executes it (unallowed tools are rejected up-front).
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( $this->create_failing_tool() );
		update_post_meta( $this->assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, array( 'failing_test_tool' ) );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'stream', true );
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

		list( $response, $output ) = $this->dispatch_chat_and_capture( $request );

		$this->assertSame( 200, $response->get_status() );

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
		// Register mock image tool that returns attachment data, and allowlist it on
		// the assistant so the agentic loop actually executes it.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( $this->create_mock_image_tool() );
		update_post_meta( $this->assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, array( 'generate_test_image' ) );

		$mock_client = $this->create_mock_client_with_tool_call( 'generate_test_image' );
		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'stream', true );
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

		list( $response, $output ) = $this->dispatch_chat_and_capture( $request );

		$this->assertSame( 200, $response->get_status() );

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
		$request->set_param( 'stream', true );
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

		list( $response, $output ) = $this->dispatch_chat_and_capture( $request );

		$this->assertSame( 200, $response->get_status() );

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
	 * Dispatch a chat request and capture any echoed SSE frames.
	 *
	 * The streaming path echoes frames directly and cleans output buffers
	 * inside send_sse_headers(), so capture requires a callback buffer that
	 * survives the handler's buffer cleanup. Existing buffers are flattened
	 * first and the original level restored afterwards so PHPUnit's
	 * output-buffer tracking stays balanced.
	 *
	 * @param WP_REST_Request $request Request to dispatch.
	 * @return array{0: WP_REST_Response, 1: string} Response and captured output.
	 */
	protected function dispatch_chat_and_capture( WP_REST_Request $request ) {
		$initial_level = ob_get_level();

		// Flatten all buffers so the handler's buffer cleanup (which keeps only
		// the outermost buffer alive) cannot destroy our capture buffer.
		while ( ob_get_level() > 0 ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Deliberate: end_clean may fail on restricted hosts; level is re-checked next iteration.
			@ob_end_clean();
		}

		$captured = '';
		ob_start(
			static function ( $chunk ) use ( &$captured ) {
				$captured .= $chunk;
				return '';
			}
		);

		$response = rest_get_server()->dispatch( $request );

		while ( ob_get_level() > 0 ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Deliberate: see above.
			@ob_end_clean();
		}

		// Restore the original buffer count so PHPUnit does not flag the test
		// as risky for leaving output buffers open.
		for ( $i = 0; $i < $initial_level; $i++ ) {
			ob_start();
		}

		return array( $response, $captured );
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
			use WP_MCP_AI_Tool_Default_Capability;

			/**
			 * Get the tool slug.
			 *
			 * @return string Tool slug.
			 */
			public function get_slug() {
				return 'failing_test_tool';
			}

			/**
			 * Get the tool name.
			 *
			 * @return string Tool name.
			 */
			public function get_name() {
				return 'Failing Test Tool';
			}

			/**
			 * Get the tool description.
			 *
			 * @return string Tool description.
			 */
			public function get_description() {
				return 'A tool that always fails';
			}

			/**
			 * Get the parameters schema.
			 *
			 * @return array Parameters schema.
			 */
			public function get_parameters_schema() {
				return array(
					'type'       => 'object',
					'properties' => array(),
				);
			}

			/**
			 * Execute the tool.
			 *
			 * @param array $arguments Tool arguments.
			 * @param array $context Execution context.
			 * @return array|WP_Error Tool result.
			 */
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
			use WP_MCP_AI_Tool_Default_Capability;

			/**
			 * Get the tool slug.
			 *
			 * @return string Tool slug.
			 */
			public function get_slug() {
				return 'generate_test_image';
			}

			/**
			 * Get the tool name.
			 *
			 * @return string Tool name.
			 */
			public function get_name() {
				return 'Generate Test Image';
			}

			/**
			 * Get the tool description.
			 *
			 * @return string Tool description.
			 */
			public function get_description() {
				return 'Generate a test image';
			}

			/**
			 * Get the parameters schema.
			 *
			 * @return array Parameters schema.
			 */
			public function get_parameters_schema() {
				return array(
					'type'       => 'object',
					'properties' => array(),
				);
			}

			/**
			 * Execute the tool.
			 *
			 * @param array $arguments Tool arguments.
			 * @param array $context Execution context.
			 * @return array|WP_Error Tool result.
			 */
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
