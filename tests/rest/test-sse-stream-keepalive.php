<?php
/**
 * Tests for inter-step SSE keepalive frames in the streaming chat path.
 *
 * Long-running agentic chats go silent while PHP is blocked inside a tool
 * call or waiting on the model. Proxies (Cloudflare, nginx) reset
 * connections they consider idle, which surfaces in the chat client as
 * `net::ERR_HTTP2_PROTOCOL_ERROR` / `SSE stream processing error`. These
 * tests pin the four keepalive emission points in
 * `WP_MCP_AI_REST::handle_chat_request_with_streaming()`:
 *
 *  1. immediately after the SSE headers,
 *  2. before the initial LLM call,
 *  3. after the `tool_start` event and before tool execution,
 *  4. before each in-loop LLM call.
 *
 * @package WP_MCP_AI
 * @since 1.9.5
 */

/**
 * Test case for SSE keepalive emission.
 */
class Test_SSE_Stream_Keepalive extends WP_UnitTestCase {

	/**
	 * Tool slug used by the stub tool.
	 *
	 * @var string
	 */
	const STUB_SLUG = 'keepalive_stub_tool';

	/**
	 * Set up: register the stub tool on the shared registry.
	 */
	public function setUp(): void {
		parent::setUp();

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( $this->build_stub_tool() );
	}

	/**
	 * Tear down: unregister the stub tool so no state leaks to other suites.
	 */
	public function tearDown(): void {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->unregister_tool( self::STUB_SLUG );

		parent::tearDown();
	}

	/**
	 * The keepalive helper delegates to the injected SSE handler.
	 */
	public function test_send_sse_keepalive_delegates_to_handler() {
		$handler = $this->build_recording_handler();
		$rest    = new WP_MCP_AI_REST(
			WP_MCP_AI_Tool_Registry::get_instance(),
			$this->createMock( WP_MCP_AI_Language_Model_Router::class ),
			null,
			null,
			$handler
		);

		$method = new ReflectionMethod( WP_MCP_AI_REST::class, 'send_sse_keepalive' );
		$method->setAccessible( true );
		$method->invoke( $rest );

		$this->assertSame( array( array( 'comment', 'keepalive' ) ), $handler->calls );
	}

	/**
	 * A streaming chat turn with one tool call emits a keepalive at each of
	 * the four inter-step boundaries.
	 */
	public function test_stream_emits_keepalives_between_steps() {
		// Admin user so the stub tool is allowed to execute.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Mock router: first response asks for the stub tool, second is final.
		$router = $this->createMock( WP_MCP_AI_Language_Model_Router::class );
		$router->method( 'create_chat_completion' )->willReturnOnConsecutiveCalls(
			array(
				'choices' => array(
					array(
						'message' => array(
							'role'       => 'assistant',
							'content'    => '',
							'tool_calls' => array(
								array(
									'id'       => 'call_keepalive_1',
									'function' => array(
										'name'      => self::STUB_SLUG,
										'arguments' => '{}',
									),
								),
							),
						),
					),
				),
			),
			array(
				'choices' => array(
					array(
						'message' => array(
							'role'    => 'assistant',
							'content' => 'All done.',
						),
					),
				),
			)
		);

		$handler = $this->build_recording_handler();
		$rest    = new WP_MCP_AI_REST(
			WP_MCP_AI_Tool_Registry::get_instance(),
			$router,
			null,
			null,
			$handler
		);

		$request          = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-client' );
		$messages         = array(
			array(
				'role'    => 'user',
				'content' => 'Run the stub tool.',
			),
		);
		$options          = array(
			'provider' => 'openai',
			'model'    => 'gpt-4o-mini',
		);
		$assistant_config = array( 'tools' => array( self::STUB_SLUG ) );

		$method = new ReflectionMethod( WP_MCP_AI_REST::class, 'handle_chat_request_with_streaming' );
		$method->setAccessible( true );
		$method->invoke(
			$rest,
			42,
			$messages,
			$options,
			$assistant_config,
			array( 'session_key' => 'keepalive-test-session' ),
			$request,
			$user_id,
			5
		);

		$calls = $handler->calls;

		// Boundary 1: keepalive immediately after the SSE headers.
		$this->assertSame( array( 'headers' ), $calls[0] );
		$this->assertSame( array( 'comment', 'keepalive' ), $calls[1] );

		// Exactly four keepalives for one tool call (headers, initial LLM,
		// tool execution, in-loop LLM).
		$keepalive_indexes = $this->indexes_of( $calls, array( 'comment', 'keepalive' ) );
		$this->assertCount( 4, $keepalive_indexes );

		// Boundary 2: between the pre-LLM 'generating' status and the first
		// tool_execution start event (the initial LLM call sits between them).
		$idx_generating = $this->index_of( $calls, array( 'event', 'status', 'generating' ) );
		$idx_start      = $this->index_of( $calls, array( 'event', 'tool_execution', 'start' ) );
		$this->assertNotFalse( $idx_generating, 'Expected a generating status event.' );
		$this->assertNotFalse( $idx_start, 'Expected a tool_execution start event.' );
		$between = array_values(
			array_filter(
				$keepalive_indexes,
				function ( $index ) use ( $idx_generating, $idx_start ) {
					return $index > $idx_generating && $index < $idx_start;
				}
			)
		);
		$this->assertCount( 1, $between, 'Expected exactly one keepalive before the first tool execution.' );

		// Boundary 3: the keepalive is the very next frame after tool_start.
		$idx_tool_start = $this->index_of( $calls, array( 'event', 'tool_execution', 'tool_start' ) );
		$this->assertNotFalse( $idx_tool_start, 'Expected a tool_start event.' );
		$this->assertSame( array( 'comment', 'keepalive' ), $calls[ $idx_tool_start + 1 ] );

		// The tool actually ran: a tool_result event follows.
		$idx_tool_result = $this->index_of( $calls, array( 'event', 'tool_execution', 'tool_result' ) );
		$this->assertNotFalse( $idx_tool_result, 'Expected a tool_result event.' );

		// Boundary 4: between the tool result and the [DONE] marker.
		$idx_done = $this->index_of( $calls, array( 'done' ) );
		$this->assertNotFalse( $idx_done, 'Expected a done marker.' );
		$between = array_values(
			array_filter(
				$keepalive_indexes,
				function ( $index ) use ( $idx_tool_result, $idx_done ) {
					return $index > $idx_tool_result && $index < $idx_done;
				}
			)
		);
		$this->assertCount( 1, $between, 'Expected exactly one keepalive before the in-loop LLM call.' );
	}

	/**
	 * Build an SSE handler that records every call instead of writing output.
	 *
	 * @return WP_MCP_AI_SSE_Handler Recording handler instance.
	 */
	private function build_recording_handler() {
		return new class() extends WP_MCP_AI_SSE_Handler {
			/**
			 * Ordered call log.
			 *
			 * Each entry is a small tuple, e.g. array( 'comment', 'keepalive' ).
			 *
			 * @var array
			 */
			public $calls = array();

			/**
			 * Record header emission.
			 */
			public function send_sse_headers() {
				$this->calls[] = array( 'headers' );
			}

			/**
			 * Record an event.
			 *
			 * @param string $event Event name.
			 * @param array  $data  Event data.
			 */
			public function send_sse_event( $event, $data ) {
				$type          = is_array( $data ) && isset( $data['type'] ) ? $data['type'] : '';
				$this->calls[] = array( 'event', $event, $type );
			}

			/**
			 * Record a comment (keepalive) frame.
			 *
			 * @param string $text Optional comment text.
			 */
			public function send_sse_comment( $text = '' ) {
				$this->calls[] = array( 'comment', $text );
			}

			/**
			 * Record the done marker.
			 */
			public function send_sse_done() {
				$this->calls[] = array( 'done' );
			}

			/**
			 * Record stream finish.
			 */
			public function finish() {
				$this->calls[] = array( 'finish' );
			}
		};
	}

	/**
	 * Build the stub tool executed by the agentic loop.
	 *
	 * @return WP_MCP_AI_Tool_Interface Stub tool instance.
	 */
	private function build_stub_tool() {
		return new class() implements WP_MCP_AI_Tool_Interface {
			use WP_MCP_AI_Tool_Default_Capability;

			/**
			 * Get the tool slug.
			 *
			 * @return string Tool slug.
			 */
			public function get_slug() {
				return 'keepalive_stub_tool';
			}

			/**
			 * Get the tool name.
			 *
			 * @return string Tool name.
			 */
			public function get_name() {
				return 'Keepalive Stub Tool';
			}

			/**
			 * Get the tool description.
			 *
			 * @return string Tool description.
			 */
			public function get_description() {
				return 'Stub tool used by the SSE keepalive tests.';
			}

			/**
			 * Get the parameters schema.
			 *
			 * @return array Parameters schema.
			 */
			public function get_parameters_schema() {
				return array();
			}

			/**
			 * Execute the stub tool.
			 *
			 * @param array $arguments Tool arguments.
			 * @param array $context   Execution context.
			 * @return array Canonical success envelope.
			 */
			public function execute( array $arguments = array(), array $context = array() ) {
				unset( $arguments, $context );
				return array(
					'success' => true,
					'message' => 'stub executed',
				);
			}
		};
	}

	/**
	 * Find the first index of a call tuple in the log.
	 *
	 * @param array $calls Call log.
	 * @param array $tuple Tuple to match (exact match).
	 * @return int|false Index or false when absent.
	 */
	private function index_of( $calls, $tuple ) {
		foreach ( $calls as $index => $call ) {
			if ( $call === $tuple ) {
				return $index;
			}
		}
		return false;
	}

	/**
	 * Find every index of a call tuple in the log.
	 *
	 * @param array $calls Call log.
	 * @param array $tuple Tuple to match (exact match).
	 * @return array Matching indexes.
	 */
	private function indexes_of( $calls, $tuple ) {
		$indexes = array();
		foreach ( $calls as $index => $call ) {
			if ( $call === $tuple ) {
				$indexes[] = $index;
			}
		}
		return $indexes;
	}
}
