<?php
/**
 * Tests for the transcript mining background job.
 *
 * Covers enqueue, tick batch processing, completion, cancellation, and the
 * progress projection used by the REST controller. Uses the same filter
 * hooks as `tests/test-mine-transcripts-source.php` to inject mock
 * sessions/messages so the suite runs without a live JetEngine CCT.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for WP_MCP_AI_Transcript_Mining_Job.
 */
class Test_Transcript_Mining_Job extends WP_UnitTestCase {

	/**
	 * Mock session list.
	 *
	 * @var array
	 */
	private $mock_sessions = array();

	/**
	 * Mock messages keyed by session_key.
	 *
	 * @var array<string,array>
	 */
	private $mock_messages = array();

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->mock_sessions = array();
		$this->mock_messages = array();

		add_filter(
			'wp_mcp_ai_mine_transcripts_sessions',
			array( $this, 'inject_sessions' ),
			10,
			2
		);
		add_filter(
			'wp_mcp_ai_mine_transcripts_session_messages',
			array( $this, 'inject_messages' ),
			10,
			3
		);
	}

	/**
	 * Tear down.
	 *
	 * Cleans up two transient prefixes:
	 * - `_transient_mcp_ai_ctx_*`            — written by the underlying
	 *   `mine_agent_memory` tool when a tick stores agent memory contexts.
	 * - `_transient_wp_mcp_ai_tx_mine_job_*` — the job state records this
	 *   suite exercises directly.
	 */
	public function tearDown(): void {
		remove_filter( 'wp_mcp_ai_mine_transcripts_sessions', array( $this, 'inject_sessions' ), 10 );
		remove_filter( 'wp_mcp_ai_mine_transcripts_session_messages', array( $this, 'inject_messages' ), 10 );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_mcp_ai_ctx_' ) . '%',
				$wpdb->esc_like( '_transient_wp_mcp_ai_tx_mine_job_' ) . '%'
			)
		);

		parent::tearDown();
	}

	/**
	 * Filter callback: inject sessions.
	 *
	 * @param array $sessions Existing.
	 * @param array $args     Args.
	 * @return array
	 */
	public function inject_sessions( $sessions, $args ) {
		unset( $sessions, $args );
		return $this->mock_sessions;
	}

	/**
	 * Filter callback: inject messages.
	 *
	 * @param array  $messages    Existing.
	 * @param string $session_key Key.
	 * @param array  $args        Args.
	 * @return array
	 */
	public function inject_messages( $messages, $session_key, $args ) {
		unset( $messages, $args );
		return isset( $this->mock_messages[ $session_key ] ) ? $this->mock_messages[ $session_key ] : array();
	}

	/**
	 * Seed N independent sessions in the mock store.
	 *
	 * @param int $count Number of sessions.
	 */
	private function seed_sessions( $count ) {
		for ( $i = 0; $i < $count; $i++ ) {
			$key                         = 'sess_' . $i;
			$this->mock_sessions[]       = array(
				'session_key'  => $key,
				'assistant_id' => '99',
				'turn_count'   => 1,
				'started_at'   => '2026-01-01 00:00:00',
				'last_created' => '2026-01-01 00:01:00',
			);
			$this->mock_messages[ $key ] = array(
				array(
					'role'          => 'user',
					'content'       => 'message ' . $i,
					'message_index' => 0,
				),
				array(
					'role'          => 'assistant',
					'content'       => 'reply ' . $i,
					'message_index' => 1,
				),
			);
		}
	}

	/**
	 * The `enqueue()` method must require an agent_id.
	 */
	public function test_enqueue_requires_agent_id() {
		$result = WP_MCP_AI_Transcript_Mining_Job::enqueue( array() );
		$this->assertWPError( $result );
		$this->assertSame( 'missing_agent_id', $result->get_error_code() );
	}

	/**
	 * The `enqueue()` method returns a queued state record and persists it.
	 */
	public function test_enqueue_creates_queued_state() {
		$state = WP_MCP_AI_Transcript_Mining_Job::enqueue(
			array( 'agent_id' => 8001 ),
			array(
				'session_keys' => array( 'sess_a', 'sess_b', 'sess_c' ),
				'batch_size'   => 2,
			)
		);

		$this->assertIsArray( $state );
		$this->assertSame( 'queued', $state['status'] );
		$this->assertSame( 3, $state['total'] );
		$this->assertSame( 2, $state['batch_size'] );
		$this->assertNotEmpty( $state['id'] );

		$progress = WP_MCP_AI_Transcript_Mining_Job::get_progress( $state['id'] );
		$this->assertSame( 'queued', $progress['status'] );
		$this->assertSame( 0, $progress['percent'] );
	}

	/**
	 * A single tick should drain `batch_size` items from the queue and
	 * persist mined/skipped/failed counters from the underlying tool.
	 */
	public function test_handle_tick_processes_a_batch() {
		$this->seed_sessions( 3 );

		$state = WP_MCP_AI_Transcript_Mining_Job::enqueue(
			array( 'agent_id' => 8002 ),
			array(
				'session_keys' => array( 'sess_0', 'sess_1', 'sess_2' ),
				'batch_size'   => 2,
			)
		);

		WP_MCP_AI_Transcript_Mining_Job::handle_tick( $state['id'] );

		$progress = WP_MCP_AI_Transcript_Mining_Job::get_progress( $state['id'] );
		$this->assertSame( 'running', $progress['status'], 'one batch left, status should still be running' );
		$this->assertSame( 2, $progress['processed'] );
		$this->assertSame( 2, $progress['mined_count'] );
		$this->assertSame( 0, $progress['failed_count'] );
		$this->assertGreaterThan( 0, $progress['percent'] );

		// Second tick drains the rest.
		WP_MCP_AI_Transcript_Mining_Job::handle_tick( $state['id'] );
		$progress = WP_MCP_AI_Transcript_Mining_Job::get_progress( $state['id'] );
		$this->assertSame( 'completed', $progress['status'] );
		$this->assertSame( 3, $progress['processed'] );
		$this->assertSame( 3, $progress['mined_count'] );
		$this->assertSame( 100, $progress['percent'] );
	}

	/**
	 * The `cancel()` method must short-circuit further ticks.
	 */
	public function test_cancel_halts_remaining_ticks() {
		$this->seed_sessions( 3 );

		$state = WP_MCP_AI_Transcript_Mining_Job::enqueue(
			array( 'agent_id' => 8003 ),
			array(
				'session_keys' => array( 'sess_0', 'sess_1', 'sess_2' ),
				'batch_size'   => 1,
			)
		);

		WP_MCP_AI_Transcript_Mining_Job::handle_tick( $state['id'] );
		$this->assertSame( 1, WP_MCP_AI_Transcript_Mining_Job::get_progress( $state['id'] )['processed'] );

		$cancelled = WP_MCP_AI_Transcript_Mining_Job::cancel( $state['id'] );
		$this->assertSame( 'cancelled', $cancelled['status'] );

		// Subsequent ticks must be no-ops.
		WP_MCP_AI_Transcript_Mining_Job::handle_tick( $state['id'] );
		$progress = WP_MCP_AI_Transcript_Mining_Job::get_progress( $state['id'] );
		$this->assertSame( 'cancelled', $progress['status'] );
		$this->assertSame( 1, $progress['processed'], 'no further work after cancel' );
	}

	/**
	 * The `cancel()` method on an unknown id returns a 404-like WP_Error.
	 */
	public function test_cancel_unknown_job_returns_error() {
		$result = WP_MCP_AI_Transcript_Mining_Job::cancel( 'does-not-exist' );
		$this->assertWPError( $result );
		$this->assertSame( 'job_not_found', $result->get_error_code() );
	}

	/**
	 * Total session count is bounded by MAX_TOTAL_SESSIONS.
	 */
	public function test_session_queue_is_capped() {
		$keys = array();
		for ( $i = 0; $i < 600; $i++ ) {
			$keys[] = 'sess_' . $i;
		}
		$state = WP_MCP_AI_Transcript_Mining_Job::enqueue(
			array( 'agent_id' => 8004 ),
			array( 'session_keys' => $keys )
		);
		$this->assertSame( WP_MCP_AI_Transcript_Mining_Job::MAX_TOTAL_SESSIONS, $state['total'] );
	}

	/**
	 * The inline shutdown handler registered by enqueue() drives the first
	 * tick to completion when no cron loopback ever fires. This is the
	 * primary regression test for the "job sits at status: queued forever"
	 * bug on hosts with DISABLE_WP_CRON or broken loopback HTTP.
	 *
	 * We do not need to actually set DISABLE_WP_CRON to exercise this path
	 * — the shutdown closure runs `kick_inline()`, which in turn invokes
	 * `handle_tick()` regardless of the cron configuration. Manually
	 * firing `do_action('shutdown')` simulates the end of the REST request
	 * lifecycle.
	 */
	public function test_inline_shutdown_kick_drives_queued_job_to_completion() {
		$this->seed_sessions( 2 );

		$state = WP_MCP_AI_Transcript_Mining_Job::enqueue(
			array( 'agent_id' => 8005 ),
			array(
				'session_keys' => array( 'sess_0', 'sess_1' ),
				'batch_size'   => 5,
			)
		);

		$this->assertSame( 'queued', $state['status'] );

		// Sanity-check that the job state landed in the transient store
		// the shutdown handler will read.
		$persisted = WP_MCP_AI_Transcript_Mining_Job::get_state( $state['id'] );
		$this->assertIsArray( $persisted );

		// Fire WordPress' `shutdown` action manually. This invokes the
		// closure that enqueue() just registered, which calls
		// kick_inline() → handle_tick() in-process.
		do_action( 'shutdown' );

		$progress = WP_MCP_AI_Transcript_Mining_Job::get_progress( $state['id'] );
		$this->assertSame( 'completed', $progress['status'], 'inline shutdown should drive the job to completion' );
		$this->assertSame( 2, $progress['processed'] );
		$this->assertSame( 100, $progress['percent'] );
	}

	/**
	 * The per-job cooperative tick lock must prevent a re-entrant
	 * `handle_tick()` call from double-processing a batch. We simulate a
	 * still-running parallel worker by manually seeding the lock
	 * transient, then invoking handle_tick() and asserting no work was
	 * advanced; releasing the lock and re-invoking should then drain the
	 * queue normally.
	 */
	public function test_handle_tick_is_guarded_by_cooperative_lock() {
		$this->seed_sessions( 2 );

		$state = WP_MCP_AI_Transcript_Mining_Job::enqueue(
			array( 'agent_id' => 8006 ),
			array(
				'session_keys' => array( 'sess_0', 'sess_1' ),
				'batch_size'   => 2,
			)
		);

		$lock_key = WP_MCP_AI_Transcript_Mining_Job::TICK_LOCK_PREFIX . $state['id'];
		set_transient( $lock_key, 1, WP_MCP_AI_Transcript_Mining_Job::TICK_LOCK_TTL );

		WP_MCP_AI_Transcript_Mining_Job::handle_tick( $state['id'] );

		$progress = WP_MCP_AI_Transcript_Mining_Job::get_progress( $state['id'] );
		$this->assertSame( 0, $progress['processed'], 'a held lock must block tick processing' );
		$this->assertSame( 'queued', $progress['status'] );

		delete_transient( $lock_key );
		// wp_cache_add may also have seeded an in-process entry inside the
		// blocked attempt; clear it so the next call can re-acquire.
		wp_cache_delete( $lock_key, 'wp_mcp_ai_tx_mine' );

		WP_MCP_AI_Transcript_Mining_Job::handle_tick( $state['id'] );

		$progress = WP_MCP_AI_Transcript_Mining_Job::get_progress( $state['id'] );
		$this->assertSame( 2, $progress['processed'], 'releasing the lock must allow tick processing' );
		$this->assertSame( 'completed', $progress['status'] );
	}

	/**
	 * `kick_inline()` is the entry point used by both the shutdown
	 * handler registered in enqueue() and the self-healing REST poll
	 * branch. It must drive a stale `queued` job forward and become a
	 * no-op for non-queued statuses.
	 */
	public function test_kick_inline_drives_stale_queued_job() {
		$this->seed_sessions( 1 );

		$state = WP_MCP_AI_Transcript_Mining_Job::enqueue(
			array( 'agent_id' => 8007 ),
			array(
				'session_keys' => array( 'sess_0' ),
				'batch_size'   => 1,
			)
		);

		$this->assertSame( 'queued', $state['status'] );

		WP_MCP_AI_Transcript_Mining_Job::kick_inline( $state['id'] );

		$progress = WP_MCP_AI_Transcript_Mining_Job::get_progress( $state['id'] );
		$this->assertSame( 'completed', $progress['status'] );
		$this->assertSame( 1, $progress['processed'] );

		// Second call must be a no-op (state is no longer `queued`); the
		// job stays completed and counters are unchanged.
		WP_MCP_AI_Transcript_Mining_Job::kick_inline( $state['id'] );
		$progress2 = WP_MCP_AI_Transcript_Mining_Job::get_progress( $state['id'] );
		$this->assertSame( 'completed', $progress2['status'] );
		$this->assertSame( 1, $progress2['processed'] );
	}
}
