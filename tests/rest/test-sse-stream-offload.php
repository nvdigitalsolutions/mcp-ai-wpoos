<?php
/**
 * Tests for the SSE long-run offload path (Phase 4b).
 *
 * A chat SSE stream that is about to exceed proxy idle-read timeouts hands
 * the remaining agentic work to the background job queue and closes the
 * stream with a `job_offload` event. These tests pin:
 *
 *  1. the deadline decision (under / over threshold, filter off-switch),
 *  2. the snapshot round-trip incl. the offload-specific fields,
 *  3. the pre-tool offload emission (job_offload + done, no tool result on
 *     the live stream),
 *  4. the default threshold keeping fast turns on the stream,
 *  5. the worker resuming the agentic loop and buffering the final message,
 *  6. the worker's missing-snapshot failure path.
 *
 * @package WP_MCP_AI
 * @since 1.2.10
 */

/**
 * Test case for SSE long-run offload.
 */
class Test_SSE_Stream_Offload extends WP_UnitTestCase {

	/**
	 * Tool slug used by the stub tool.
	 *
	 * @var string
	 */
	const STUB_SLUG = 'offload_stub_tool';

	/**
	 * Queue job IDs created by tests, cancelled in tearDown.
	 *
	 * @var array<int>
	 */
	private $queued_job_ids = array();

	/**
	 * Continuation keys created by tests, deleted in tearDown.
	 *
	 * @var array<string>
	 */
	private $continuation_keys = array();

	/**
	 * Set up: admin user, stub tool, clean filters.
	 */
	public function setUp(): void {
		parent::setUp();

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( $this->build_stub_tool() );

		remove_all_filters( 'wp_mcp_ai_sse_offload_elapsed_seconds' );
		remove_all_filters( 'wp_mcp_ai_sse_offload_worker_cap_seconds' );
	}

	/**
	 * Tear down: cancel leftover jobs, drop snapshots, unregister the stub.
	 */
	public function tearDown(): void {
		foreach ( $this->queued_job_ids as $job_id ) {
			if ( class_exists( 'WP_MCP_AI_Async_Job_Queue' ) ) {
				WP_MCP_AI_Async_Job_Queue::cancel_job( $job_id );
			}
		}
		foreach ( $this->continuation_keys as $key ) {
			if ( class_exists( 'WP_MCP_AI_Chat_Continuation_Store' ) ) {
				WP_MCP_AI_Chat_Continuation_Store::delete( $key );
			}
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->unregister_tool( self::STUB_SLUG );

		remove_all_filters( 'wp_mcp_ai_sse_offload_elapsed_seconds' );
		remove_all_filters( 'wp_mcp_ai_sse_offload_worker_cap_seconds' );

		parent::tearDown();
	}

	/**
	 * Elapsed below the threshold: no offload.
	 */
	public function test_deadline_check_under_threshold() {
		$rest   = $this->build_rest();
		$method = new ReflectionMethod( WP_MCP_AI_REST::class, 'sse_offload_deadline_exceeded' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( $rest, microtime( true ) ) );
	}

	/**
	 * Elapsed past the threshold: offload.
	 */
	public function test_deadline_check_past_threshold() {
		add_filter(
			'wp_mcp_ai_sse_offload_elapsed_seconds',
			function () {
				return 5;
			}
		);

		$rest   = $this->build_rest();
		$method = new ReflectionMethod( WP_MCP_AI_REST::class, 'sse_offload_deadline_exceeded' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $rest, microtime( true ) - 6 ) );
	}

	/**
	 * A zero threshold disables offload entirely.
	 */
	public function test_deadline_check_disabled_by_zero_filter() {
		add_filter(
			'wp_mcp_ai_sse_offload_elapsed_seconds',
			function () {
				return 0;
			}
		);

		$rest   = $this->build_rest();
		$method = new ReflectionMethod( WP_MCP_AI_REST::class, 'sse_offload_deadline_exceeded' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( $rest, microtime( true ) - 3600 ) );
	}

	/**
	 * A stream past the deadline offloads BEFORE the blocking tool runs:
	 * job_offload + done on the stream, no tool result, snapshot + queue job
	 * created with the pending tool batch.
	 */
	public function test_stream_offloads_before_blocking_tool() {
		add_filter(
			'wp_mcp_ai_sse_offload_elapsed_seconds',
			function () {
				// Effectively always over: the first boundary check triggers.
				return 0.0001;
			}
		);

		$router = $this->createMock( WP_MCP_AI_Language_Model_Router::class );
		$router->method( 'create_chat_completion' )->willReturn(
			array(
				'choices' => array(
					array(
						'message' => array(
							'role'       => 'assistant',
							'content'    => '',
							'tool_calls' => array(
								array(
									'id'       => 'call_offload_1',
									'function' => array(
										'name'      => self::STUB_SLUG,
										'arguments' => '{}',
									),
								),
							),
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

		$request  = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-client' );
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Run the stub tool.',
			),
		);
		$options  = array(
			'provider' => 'openai',
			'model'    => 'gpt-4o-mini',
		);
		$config   = array( 'tools' => array( self::STUB_SLUG ) );

		$method = new ReflectionMethod( WP_MCP_AI_REST::class, 'handle_chat_request_with_streaming' );
		$method->setAccessible( true );
		$method->invoke(
			$rest,
			42,
			$messages,
			$options,
			$config,
			array( 'session_key' => 'offload-test-session' ),
			$request,
			get_current_user_id(),
			5
		);

		// The offload event fired with a job id + session key.
		$idx = $this->index_of( $handler->calls, array( 'event', 'job_offload', '' ) );
		$this->assertNotFalse( $idx, 'Expected a job_offload event.' );
		$this->assertNotEmpty( $handler->calls[ $idx ][3] ?? '' );
		$job_id = $handler->calls[ $idx ][3];

		// Done + finish followed, and no tool result ever hit the live stream.
		$this->assertNotFalse( $this->index_of( $handler->calls, array( 'done' ) ), 'Expected done marker.' );
		$this->assertNotFalse( $this->index_of( $handler->calls, array( 'finish' ) ), 'Expected finish call.' );
		$this->assertFalse( $this->index_of( $handler->calls, array( 'event', 'tool_execution', 'tool_result' ) ), 'Tool must not execute on the live stream after offload.' );

		// The snapshot holds the offload fields and the pending tool batch.
		$snapshots = WP_MCP_AI_Chat_Continuation_Store::get_jobs_for_session( 'offload-test-session' );
		$this->assertNotEmpty( $snapshots, 'Expected a continuation snapshot for the session.' );
		$snapshot = WP_MCP_AI_Chat_Continuation_Store::get( end( $snapshots ) );
		$this->assertIsArray( $snapshot );
		$this->assertSame( 'stream_offload', $snapshot['continuation_kind'] );
		$this->assertCount( 1, $snapshot['pending_tool_calls'] );
		$this->assertSame( 0, $snapshot['iteration'] );
		$this->assertSame( 5, $snapshot['max_iterations'] );
		$this->assertSame( array( self::STUB_SLUG ), $snapshot['assistant_config']['tools'] );

		// The queue job exists with the right type.
		$jobs = WP_MCP_AI_Async_Job_Queue::get_jobs_by_status( 'queued', 10 );
		$this->assertNotEmpty( $jobs );
		$found = false;
		foreach ( $jobs as $job ) {
			if ( WP_MCP_AI_Chat_Stream_Offload_Worker::JOB_TYPE === $job['job_type'] ) {
				$found                  = true;
				$this->queued_job_ids[] = (int) $job['id'];
			}
		}
		$this->assertTrue( $found, 'Expected a queued chat_stream_continuation job.' );

		foreach ( $snapshots as $key ) {
			$this->continuation_keys[] = $key;
		}

		$this->assertNotEmpty( $job_id );
	}

	/**
	 * A fast turn under the default threshold never offloads and completes
	 * normally (final message event on the stream).
	 */
	public function test_stream_does_not_offload_below_deadline() {
		$router = $this->createMock( WP_MCP_AI_Language_Model_Router::class );
		$router->method( 'create_chat_completion' )->willReturn(
			array(
				'choices' => array(
					array(
						'message' => array(
							'role'    => 'assistant',
							'content' => 'Fast answer.',
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

		$request  = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-client' );
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hi.',
			),
		);
		$options  = array(
			'provider' => 'openai',
			'model'    => 'gpt-4o-mini',
		);

		$method = new ReflectionMethod( WP_MCP_AI_REST::class, 'handle_chat_request_with_streaming' );
		$method->setAccessible( true );
		$method->invoke(
			$rest,
			42,
			$messages,
			$options,
			array( 'tools' => array() ),
			array( 'session_key' => 'offload-fast-session' ),
			$request,
			get_current_user_id(),
			5
		);

		$this->assertFalse( $this->index_of( $handler->calls, array( 'event', 'job_offload', '' ) ), 'Fast turns must never offload.' );
		$this->assertNotFalse( $this->index_of( $handler->calls, array( 'done' ) ) );
	}

	/**
	 * The snapshot round-trip preserves the offload-specific fields.
	 */
	public function test_snapshot_round_trip_preserves_offload_fields() {
		$key     = wp_generate_uuid4();
		$payload = array(
			'chat_session_id'          => 'round-trip-session',
			'assistant_id'             => 7,
			'user_id'                  => 1,
			'options'                  => array( 'model' => 'gpt-4o-mini' ),
			'messages'                 => array(
				array(
					'role'    => 'user',
					'content' => 'hello',
				),
			),
			'assistant_config'         => array( 'tools' => array( self::STUB_SLUG ) ),
			'iteration'                => 2,
			'max_iterations'           => 8,
			'agentic_cost_accumulator' => array( 'cost_usd' => 0.01 ),
			'pending_tool_calls'       => array( array( 'id' => 'call_1' ) ),
			'continuation_kind'        => 'stream_offload',
		);

		$stored = WP_MCP_AI_Chat_Continuation_Store::store( $key, $payload );
		$this->assertTrue( $stored );
		$this->continuation_keys[] = $key;

		$snapshot = WP_MCP_AI_Chat_Continuation_Store::get( $key );
		$this->assertIsArray( $snapshot );
		$this->assertSame( 'stream_offload', $snapshot['continuation_kind'] );
		$this->assertSame( 2, $snapshot['iteration'] );
		$this->assertSame( 8, $snapshot['max_iterations'] );
		$this->assertSame( array( self::STUB_SLUG ), $snapshot['assistant_config']['tools'] );
		$this->assertCount( 1, $snapshot['pending_tool_calls'] );
		$this->assertSame( 0.01, $snapshot['agentic_cost_accumulator']['cost_usd'] );
	}

	/**
	 * A worker run with an unknown continuation key throws (queue marks the
	 * job failed — zero retries for this job type).
	 */
	public function test_worker_run_throws_on_missing_snapshot() {
		$this->expectException( Exception::class );

		WP_MCP_AI_Chat_Stream_Offload_Worker::run(
			array(
				'continuation_key' => 'does-not-exist',
				'chat_session_id'  => 'missing-snapshot-session',
				'assistant_id'     => 7,
				'user_id'          => 1,
			),
			999
		);
	}

	/**
	 * The notifier carries the terminal `failed` state and the session buffer
	 * carries a `chat:error` frame when the offload snapshot is gone.
	 */
	public function test_missing_snapshot_records_terminal_failure() {
		$job_id = 'missing-snapshot-job';
		try {
			WP_MCP_AI_Chat_Stream_Offload_Worker::run(
				array(
					'continuation_key' => 'does-not-exist',
					'chat_session_id'  => 'missing-snapshot-session',
					'assistant_id'     => 7,
					'user_id'          => 1,
				),
				$job_id
			);
			$this->fail( 'Expected the worker to throw on a missing snapshot.' );
		} catch ( Exception $e ) {
			$this->assertStringContainsString( 'missing or expired', $e->getMessage() );
		}

		// Terminal failure is visible to the client's job watcher.
		$status = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );
		$this->assertIsArray( $status );
		$this->assertSame( 'failed', $status['status'] );
		$this->assertSame( 'chat_stream_offload_failed', $status['error']['code'] );
		$this->assertSame( 'Chat stream offload snapshot is missing or expired.', $status['error']['message'] );

		// The session buffer carries the renderable terminal error.
		$frames = WP_MCP_AI_Chat_Session_Frame_Buffer::get_frames_since( 'missing-snapshot-session', 0 );
		$found  = false;
		foreach ( $frames as $frame ) {
			if ( isset( $frame['event'], $frame['data']['error'] ) && 'chat:error' === $frame['event'] ) {
				$found = true;
				$this->assertStringContainsString( 'missing or expired', $frame['data']['error'] );
			}
		}
		$this->assertTrue( $found, 'Expected a chat:error frame in the session buffer.' );
	}

	/**
	 * The worker resumes the agentic loop off-request: executes the pending
	 * tool batch, calls the LLM again, and buffers the final message.
	 */
	public function test_worker_resumes_loop_and_buffers_final_message() {
		// Mock router injected into the container.
		$router = $this->createMock( WP_MCP_AI_Language_Model_Router::class );
		$router->method( 'create_chat_completion' )->willReturn(
			array(
				'choices' => array(
					array(
						'message' => array(
							'role'    => 'assistant',
							'content' => 'Resumed answer.',
						),
					),
				),
			)
		);
		WP_MCP_AI_Container::get_instance()->set( 'router', $router );

		$key = wp_generate_uuid4();
		WP_MCP_AI_Chat_Continuation_Store::store(
			$key,
			array(
				'chat_session_id'    => 'worker-resume-session',
				'assistant_id'       => 42,
				'user_id'            => get_current_user_id(),
				'provider'           => 'openai',
				'model'              => 'gpt-4o-mini',
				'options'            => array(
					'provider' => 'openai',
					'model'    => 'gpt-4o-mini',
				),
				'assistant_config'   => array( 'tools' => array( self::STUB_SLUG ) ),
				'messages'           => array(
					array(
						'role'    => 'user',
						'content' => 'Run the stub tool.',
					),
					array(
						'role'       => 'assistant',
						'content'    => '',
						'tool_calls' => array(
							array(
								'id'       => 'call_offload_1',
								'function' => array(
									'name'      => self::STUB_SLUG,
									'arguments' => '{}',
								),
							),
						),
					),
				),
				'iteration'          => 0,
				'max_iterations'     => 5,
				'pending_tool_calls' => array(
					array(
						'id'       => 'call_offload_1',
						'function' => array(
							'name'      => self::STUB_SLUG,
							'arguments' => '{}',
						),
					),
				),
				'continuation_kind'  => 'stream_offload',
			)
		);
		$this->continuation_keys[] = $key;

		$result = WP_MCP_AI_Chat_Stream_Offload_Worker::run(
			array( 'continuation_key' => $key ),
			4242
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Resumed answer.', $result['message'] );

		// The final frame landed in the session buffer.
		$frames = WP_MCP_AI_Chat_Session_Frame_Buffer::get_frames_since( 'worker-resume-session', 0 );
		$this->assertIsArray( $frames );
		$found = false;
		foreach ( $frames as $frame ) {
			if ( isset( $frame['event'], $frame['data']['message'] ) && 'chat:resumed' === $frame['event'] ) {
				$found = true;
				$this->assertSame( 'Resumed answer.', $frame['data']['message'] );
			}
		}
		$this->assertTrue( $found, 'Expected a chat:resumed frame in the session buffer.' );

		// The notifier recorded completion.
		$status = WP_MCP_AI_Job_Notifier::get_job_status( '4242' );
		$this->assertIsArray( $status );
		$this->assertSame( 'completed', $status['status'] );
	}

	/**
	 * Build a REST instance with a recording SSE handler.
	 *
	 * @return WP_MCP_AI_REST
	 */
	private function build_rest() {
		return new WP_MCP_AI_REST(
			WP_MCP_AI_Tool_Registry::get_instance(),
			$this->createMock( WP_MCP_AI_Language_Model_Router::class ),
			null,
			null,
			$this->build_recording_handler()
		);
	}

	/**
	 * Build an SSE handler that records every call instead of writing output.
	 *
	 * @return WP_MCP_AI_SSE_Handler Recording handler instance.
	 */
	private function build_recording_handler() {
		return new class() extends WP_MCP_AI_SSE_Handler {
			/**
			 * Ordered call log. Event entries are array( 'event', $event, $type, $job_id? ).
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
				$job           = is_array( $data ) && isset( $data['job_id'] ) ? (string) $data['job_id'] : '';
				$this->calls[] = array( 'event', $event, $type, $job );
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
			 * Record the finish call.
			 */
			public function finish() {
				$this->calls[] = array( 'finish' );
			}
		};
	}

	/**
	 * Index of the first call matching a prefix tuple, or false.
	 *
	 * @param array $calls  Recorded calls.
	 * @param array $prefix Tuple prefix to match.
	 * @return int|false
	 */
	private function index_of( array $calls, array $prefix ) {
		foreach ( $calls as $index => $call ) {
			$match = true;
			foreach ( $prefix as $offset => $value ) {
				if ( ! isset( $call[ $offset ] ) || $call[ $offset ] !== $value ) {
					$match = false;
					break;
				}
			}
			if ( $match ) {
				return $index;
			}
		}
		return false;
	}

	/**
	 * Build a stub tool that echoes its arguments.
	 *
	 * @return WP_MCP_AI_Tool_Interface
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
				return Test_SSE_Stream_Offload::STUB_SLUG;
			}

			/**
			 * Get the tool name.
			 *
			 * @return string Tool name.
			 */
			public function get_name() {
				return 'Offload Stub Tool';
			}

			/**
			 * Get the tool description.
			 *
			 * @return string Tool description.
			 */
			public function get_description() {
				return 'Stub tool used by the SSE offload tests.';
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
}
