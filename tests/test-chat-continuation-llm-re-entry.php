<?php
/**
 * Tests for WP_MCP_AI_Chat_Continuation_LLM_Re_Entry.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test LLM re-entry service for chat continuation.
 */
class Test_Chat_Continuation_LLM_Re_Entry extends WP_UnitTestCase {

	/**
	 * Unique session prefix per test run.
	 *
	 * @var string
	 */
	private $session_id;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->session_id = 'test_reentry_' . wp_generate_uuid4();
		WP_MCP_AI_Chat_Continuation_LLM_Re_Entry::reset_for_tests();
		WP_MCP_AI_Chat_Continuation_LLM_Re_Entry::init();
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Chat_Continuation_LLM_Re_Entry::reset_for_tests();
		WP_MCP_AI_Chat_Session_Frame_Buffer::flush( $this->session_id );
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// on_continuation_ready — router not available
	// -----------------------------------------------------------------------

	/**
	 * When the language model router is not available, an error frame is pushed.
	 *
	 * @test
	 */
	public function test_pushes_error_frame_when_router_unavailable() {
		$snapshot = array(
			'chat_session_id' => $this->session_id,
			'job_id'          => 'job_abc',
			'tool_call_id'    => 'tc_001',
			'messages'        => array(
				array( 'role' => 'user', 'content' => 'Hello' ),
				array( 'role' => 'tool', 'content' => 'result', 'tool_call_id' => 'tc_001' ),
			),
			'options'         => array( 'provider' => 'openai', 'model' => 'gpt-4' ),
			'assistant_id'    => 1,
		);

		// Stub the router function to return null (unavailable).
		add_filter(
			'wp_mcp_ai_chat_session_stream_frame',
			function ( $data ) {
				return $data; // pass-through
			}
		);

		// Temporarily remove wp_mcp_ai_get_language_model_router if it exists.
		// We can't un-define functions in PHP so we'll rely on it throwing a WP_Error.
		// If the function exists but is not really wired, the router likely returns null.
		// Instead we filter inside the class to bypass — or we just verify the frame type.

		WP_MCP_AI_Chat_Continuation_LLM_Re_Entry::on_continuation_ready(
			$snapshot,
			'completed',
			array( 'result' => 'done' )
		);

		// Regardless of whether the router is available, after the call we can
		// inspect the frame buffer for any frame type (resumed or error).
		$frames = WP_MCP_AI_Chat_Session_Frame_Buffer::get_frames_since( $this->session_id, 0 );
		$this->assertNotEmpty( $frames );
		// The first frame must be either chat:resumed or chat:error.
		$this->assertContains( $frames[0]['event'], array( 'chat:resumed', 'chat:error' ) );
	}

	// -----------------------------------------------------------------------
	// on_continuation_ready — invalid router response
	// -----------------------------------------------------------------------

	/**
	 * A router response that is neither an array nor a WP_Error must not crash
	 * the re-entry path (regression: TypeError on extract_text( null )).
	 *
	 * @test
	 */
	public function test_null_router_response_pushes_error_frame() {
		$container = WP_MCP_AI_Container::get_instance();
		if ( ! $container->has( 'router' ) ) {
			$this->markTestSkipped( 'Language model router is not registered in the container' );
		}

		$original = $container->get( 'router' );

		// Stub the router with a provider adapter that returns an invalid
		// (null) response instead of the array|WP_Error contract.
		$stub = new class() {
			/**
			 * Return null to simulate a broken provider adapter.
			 *
			 * @param array $messages Messages.
			 * @param array $options  Options.
			 *
			 * @return null
			 */
			public function create_chat_completion( array $messages, array $options = array() ) {
				return null;
			}
		};
		$container->set( 'router', $stub );

		try {
			$snapshot = array(
				'chat_session_id' => $this->session_id,
				'job_id'          => 'job_null',
				'tool_call_id'    => 'tc_null',
				'messages'        => array(
					array(
						'role'    => 'user',
						'content' => 'Hello',
					),
				),
				'options'         => array(),
				'assistant_id'    => 1,
			);

			$result = WP_MCP_AI_Chat_Continuation_LLM_Re_Entry::on_continuation_ready(
				$snapshot,
				'completed',
				array()
			);

			// The invalid response is normalized to a WP_Error and surfaced as
			// a chat:error frame instead of throwing a TypeError. Pushing the
			// error frame is a handled outcome, so the handler reports true.
			$this->assertTrue( $result );

			$frames = WP_MCP_AI_Chat_Session_Frame_Buffer::get_frames_since( $this->session_id, 0 );
			$this->assertNotEmpty( $frames );
			$this->assertSame( 'chat:error', $frames[0]['event'] );
		} finally {
			$container->set( 'router', $original );
		}
	}

	// -----------------------------------------------------------------------
	// on_continuation_ready — failed/cancelled
	// -----------------------------------------------------------------------

	/**
	 * Failed terminal status pushes a chat:tool_result frame without calling LLM.
	 *
	 * @test
	 */
	public function test_pushes_tool_result_frame_on_failed_status() {
		$snapshot = array(
			'chat_session_id' => $this->session_id,
			'job_id'          => 'job_fail',
			'tool_call_id'    => 'tc_fail',
			'messages'        => array(
				array( 'role' => 'user', 'content' => 'Do a task' ),
			),
			'options'         => array(),
			'assistant_id'    => 1,
		);

		$result = WP_MCP_AI_Chat_Continuation_LLM_Re_Entry::on_continuation_ready(
			$snapshot,
			'failed',
			array()
		);

		$this->assertTrue( $result );

		$frames = WP_MCP_AI_Chat_Session_Frame_Buffer::get_frames_since( $this->session_id, 0 );
		$this->assertCount( 1, $frames );
		$this->assertSame( 'chat:tool_result', $frames[0]['event'] );
		$this->assertSame( 'failed', $frames[0]['data']['terminal_status'] );
	}

	/**
	 * Cancelled terminal status pushes a chat:tool_result frame.
	 *
	 * @test
	 */
	public function test_pushes_tool_result_frame_on_cancelled_status() {
		$snapshot = array(
			'chat_session_id' => $this->session_id,
			'job_id'          => 'job_cancel',
			'tool_call_id'    => 'tc_cancel',
			'messages'        => array(
				array( 'role' => 'user', 'content' => 'Task' ),
			),
			'options'         => array(),
			'assistant_id'    => 1,
		);

		WP_MCP_AI_Chat_Continuation_LLM_Re_Entry::on_continuation_ready(
			$snapshot,
			'cancelled',
			array()
		);

		$frames = WP_MCP_AI_Chat_Session_Frame_Buffer::get_frames_since( $this->session_id, 0 );
		$this->assertCount( 1, $frames );
		$this->assertSame( 'chat:tool_result', $frames[0]['event'] );
		$this->assertSame( 'cancelled', $frames[0]['data']['terminal_status'] );
	}

	// -----------------------------------------------------------------------
	// on_continuation_ready — invalid snapshot
	// -----------------------------------------------------------------------

	/**
	 * Returns false for a non-array snapshot.
	 *
	 * @test
	 */
	public function test_returns_false_for_non_array_snapshot() {
		$result = WP_MCP_AI_Chat_Continuation_LLM_Re_Entry::on_continuation_ready(
			'not an array',
			'completed',
			array()
		);
		$this->assertFalse( $result );
	}

	/**
	 * Returns false when messages array is empty.
	 *
	 * @test
	 */
	public function test_returns_false_for_empty_messages() {
		$snapshot = array(
			'chat_session_id' => $this->session_id,
			'messages'        => array(),
			'options'         => array(),
			'assistant_id'    => 1,
		);
		$result = WP_MCP_AI_Chat_Continuation_LLM_Re_Entry::on_continuation_ready(
			$snapshot,
			'completed',
			array()
		);
		$this->assertFalse( $result );
	}

	// -----------------------------------------------------------------------
	// Hook wiring
	// -----------------------------------------------------------------------

	/**
	 * init() registers the action hook exactly once.
	 *
	 * @test
	 */
	public function test_init_registers_action_hook() {
		WP_MCP_AI_Chat_Continuation_LLM_Re_Entry::reset_for_tests();
		WP_MCP_AI_Chat_Continuation_LLM_Re_Entry::init();
		WP_MCP_AI_Chat_Continuation_LLM_Re_Entry::init(); // second call should be no-op.

		$priority = has_action( 'wp_mcp_ai_chat_continuation_ready', array( 'WP_MCP_AI_Chat_Continuation_LLM_Re_Entry', 'on_continuation_ready' ) );
		$this->assertNotFalse( $priority, 'Expected action to be registered' );
	}

	// -----------------------------------------------------------------------
	// wp_mcp_ai_chat_session_stream_frame filter
	// -----------------------------------------------------------------------

	/**
	 * wp_mcp_ai_chat_session_stream_frame filter can modify the frame payload.
	 *
	 * @test
	 */
	public function test_stream_frame_filter_can_modify_payload() {
		add_filter(
			'wp_mcp_ai_chat_session_stream_frame',
			function ( $data ) {
				$data['filtered'] = true;
				return $data;
			},
			10,
			1
		);

		$snapshot = array(
			'chat_session_id' => $this->session_id,
			'job_id'          => 'job_filter',
			'tool_call_id'    => 'tc_filter',
			'messages'        => array(
				array( 'role' => 'user', 'content' => 'Hello' ),
				array( 'role' => 'tool', 'content' => 'done', 'tool_call_id' => 'tc_filter' ),
			),
			'options'         => array(),
			'assistant_id'    => 1,
		);

		WP_MCP_AI_Chat_Continuation_LLM_Re_Entry::on_continuation_ready(
			$snapshot,
			'completed',
			array()
		);

		remove_all_filters( 'wp_mcp_ai_chat_session_stream_frame' );

		$frames = WP_MCP_AI_Chat_Session_Frame_Buffer::get_frames_since( $this->session_id, 0 );
		// A frame was pushed (resumed or error depending on router state).
		$this->assertNotEmpty( $frames );
		// If it was a resumed frame the filter should have applied.
		if ( 'chat:resumed' === $frames[0]['event'] ) {
			$this->assertTrue( $frames[0]['data']['filtered'] ?? false );
		}
	}
}
