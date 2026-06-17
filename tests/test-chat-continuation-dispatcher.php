<?php
/**
 * Tests for the Chat Continuation Dispatcher.
 *
 * Verifies the end-to-end signal chain:
 *   wp_mcp_ai_job_completed → dispatcher schedules cron → cron worker fires
 *   wp_mcp_ai_chat_continuation_ready and wp_mcp_ai_chat_continuation_dispatched.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * @group continuation
 */
class Test_Chat_Continuation_Dispatcher extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Chat_Continuation_Store::reset_for_tests();
		// Dispatcher is already initialized via chat-continuation-init.php; we
		// rely on its idempotent init() guarding against duplicate hooks.
	}

	public function tearDown(): void {
		WP_MCP_AI_Chat_Continuation_Store::reset_for_tests();
		remove_all_filters( 'wp_mcp_ai_chat_continuation_enabled' );
		remove_all_filters( 'wp_mcp_ai_chat_continuation_should_dispatch' );
		remove_all_filters( 'wp_mcp_ai_chat_continuation_message' );
		remove_all_actions( 'wp_mcp_ai_chat_continuation_ready' );
		remove_all_actions( 'wp_mcp_ai_chat_continuation_dispatched' );
		parent::tearDown();
	}

	/**
	 * Helper: store a continuation snapshot.
	 *
	 * @param string $job_id     Job identifier.
	 * @param string $session_id Session identifier.
	 * @return void
	 */
	protected function seed_snapshot( $job_id, $session_id = 'sess_test' ) {
		WP_MCP_AI_Chat_Continuation_Store::store(
			$job_id,
			array(
				'chat_session_id' => $session_id,
				'assistant_id'    => 100,
				'user_id'         => 1,
				'tool_call_id'    => 'call_' . $job_id,
				'tool_name'       => 'generate_veo_video',
				'provider'        => 'gemini',
				'model'           => 'gemini-1.5-pro',
				'messages'        => array(
					array( 'role' => 'user', 'content' => 'Make a video.' ),
				),
			)
		);
	}

	/**
	 * job_completed without a snapshot should not schedule cron.
	 */
	public function test_no_snapshot_no_cron() {
		$job_id = 'job_nosnapshot_' . wp_generate_uuid4();
		do_action( 'wp_mcp_ai_job_completed', $job_id, array( 'data' => 1 ), array() );

		$this->assertFalse(
			wp_next_scheduled( WP_MCP_AI_Chat_Continuation_Dispatcher::CRON_HOOK, array( $job_id ) )
		);
	}

	/**
	 * job_completed with a snapshot schedules cron.
	 */
	public function test_snapshot_present_schedules_cron() {
		$job_id = 'job_sched_' . wp_generate_uuid4();
		$this->seed_snapshot( $job_id );

		do_action( 'wp_mcp_ai_job_completed', $job_id, array( 'data' => 'ok' ), array() );

		$this->assertNotFalse(
			wp_next_scheduled( WP_MCP_AI_Chat_Continuation_Dispatcher::CRON_HOOK, array( $job_id ) )
		);
	}

	/**
	 * The kill-switch filter prevents dispatch.
	 */
	public function test_kill_switch_filter_blocks_dispatch() {
		$job_id = 'job_kill_' . wp_generate_uuid4();
		$this->seed_snapshot( $job_id );

		add_filter( 'wp_mcp_ai_chat_continuation_enabled', '__return_false' );

		do_action( 'wp_mcp_ai_job_completed', $job_id, array(), array() );

		$this->assertFalse(
			wp_next_scheduled( WP_MCP_AI_Chat_Continuation_Dispatcher::CRON_HOOK, array( $job_id ) )
		);
	}

	/**
	 * should_dispatch filter blocks dispatch.
	 */
	public function test_should_dispatch_filter_blocks() {
		$job_id = 'job_should_' . wp_generate_uuid4();
		$this->seed_snapshot( $job_id );

		add_filter( 'wp_mcp_ai_chat_continuation_should_dispatch', '__return_false' );

		do_action( 'wp_mcp_ai_job_completed', $job_id, array(), array() );
		$this->assertFalse(
			wp_next_scheduled( WP_MCP_AI_Chat_Continuation_Dispatcher::CRON_HOOK, array( $job_id ) )
		);
	}

	/**
	 * The cron worker fires both ready and dispatched actions and injects a
	 * tool-result message into the messages array.
	 */
	public function test_process_resume_fires_actions_and_appends_message() {
		$job_id = 'job_resume_' . wp_generate_uuid4();
		$this->seed_snapshot( $job_id );

		// Simulate completion: this stores terminal_status etc on the row.
		do_action( 'wp_mcp_ai_job_completed', $job_id, array( 'url' => 'https://example.com/v.mp4' ), array() );

		$ready_args = null;
		$dispatched_args = null;
		add_action(
			'wp_mcp_ai_chat_continuation_ready',
			function ( $snapshot, $terminal_status, $terminal_result ) use ( &$ready_args ) {
				$ready_args = compact( 'snapshot', 'terminal_status', 'terminal_result' );
			},
			10,
			3
		);
		add_action(
			'wp_mcp_ai_chat_continuation_dispatched',
			function ( $job_id, $snapshot, $terminal_status ) use ( &$dispatched_args ) {
				$dispatched_args = compact( 'job_id', 'snapshot', 'terminal_status' );
			},
			10,
			3
		);

		// Drive the worker directly.
		$this->assertTrue( WP_MCP_AI_Chat_Continuation_Dispatcher::process_resume( $job_id ) );

		$this->assertIsArray( $ready_args );
		$this->assertEquals( 'completed', $ready_args['terminal_status'] );
		$this->assertNotEmpty( $ready_args['snapshot']['messages'] );

		// The last message must be the injected tool-result.
		$last = end( $ready_args['snapshot']['messages'] );
		$this->assertEquals( 'tool', $last['role'] );
		$this->assertEquals( 'call_' . $job_id, $last['tool_call_id'] );
		$this->assertEquals( 'generate_veo_video', $last['name'] );

		$decoded = json_decode( $last['content'], true );
		$this->assertIsArray( $decoded );
		$this->assertEquals( 'completed', $decoded['status'] );
		$this->assertEquals( $job_id, $decoded['job_id'] );

		$this->assertIsArray( $dispatched_args );
		$this->assertEquals( $job_id, $dispatched_args['job_id'] );
		$this->assertEquals( 'completed', $dispatched_args['terminal_status'] );
	}

	/**
	 * process_resume must be idempotent — second call should be a no-op.
	 */
	public function test_process_resume_is_idempotent() {
		$job_id = 'job_idem_' . wp_generate_uuid4();
		$this->seed_snapshot( $job_id );
		do_action( 'wp_mcp_ai_job_completed', $job_id, array(), array() );

		// Manually acquire and HOLD the lock to simulate an in-flight worker.
		$this->assertTrue(
			WP_MCP_AI_Chat_Continuation_Store::acquire_processing_lock( $job_id, 300 )
		);

		$this->assertFalse( WP_MCP_AI_Chat_Continuation_Dispatcher::process_resume( $job_id ) );
	}

	/**
	 * job_failed routes the failure into the tool-result message.
	 */
	public function test_job_failed_produces_failed_tool_message() {
		$job_id = 'job_failed_' . wp_generate_uuid4();
		$this->seed_snapshot( $job_id );

		$wp_error = new WP_Error( 'gemini_quota', 'Quota exceeded.' );
		do_action( 'wp_mcp_ai_job_failed', $job_id, $wp_error, array() );

		$ready = null;
		add_action(
			'wp_mcp_ai_chat_continuation_ready',
			function ( $snapshot, $status ) use ( &$ready ) {
				$ready = compact( 'snapshot', 'status' );
			},
			10,
			2
		);

		WP_MCP_AI_Chat_Continuation_Dispatcher::process_resume( $job_id );

		$this->assertIsArray( $ready );
		$this->assertEquals( 'failed', $ready['status'] );
		$last = end( $ready['snapshot']['messages'] );
		$decoded = json_decode( $last['content'], true );
		$this->assertEquals( 'failed', $decoded['status'] );
		$this->assertEquals( 'gemini_quota', $decoded['result']['error']['code'] );
	}

	/**
	 * The continuation_message filter can rewrite the appended message.
	 */
	public function test_continuation_message_filter() {
		$job_id = 'job_filter_' . wp_generate_uuid4();
		$this->seed_snapshot( $job_id );
		do_action( 'wp_mcp_ai_job_completed', $job_id, array( 'foo' => 'bar' ), array() );

		add_filter(
			'wp_mcp_ai_chat_continuation_message',
			function ( $msg ) {
				$msg['content'] = '{"replaced":true}';
				return $msg;
			}
		);

		$ready = null;
		add_action(
			'wp_mcp_ai_chat_continuation_ready',
			function ( $snapshot ) use ( &$ready ) {
				$ready = $snapshot;
			}
		);

		WP_MCP_AI_Chat_Continuation_Dispatcher::process_resume( $job_id );

		$last = end( $ready['messages'] );
		$this->assertEquals( '{"replaced":true}', $last['content'] );
	}
}
