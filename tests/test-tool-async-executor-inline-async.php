<?php
/**
 * Tests for the inline-async fallback wired into WP_MCP_AI_Tool_Async_Executor.
 *
 * Covers:
 * - `queue_tool()` registers a `shutdown` action that drives a stuck job
 *   forward when the WP-Cron loopback never fires.
 * - `kick_inline()` short-circuits for non-`pending` jobs so a delayed
 *   cron loopback cannot re-execute a completed job.
 * - The cooperative tick lock around `execute_async_tool()` blocks a
 *   second worker (inline + cron racing for the same job).
 * - The REST self-heal helper `kick_inline_if_stale()` only schedules a
 *   kick once the stale-pending threshold has elapsed and respects the
 *   `wp_mcp_ai_inline_kick_enabled` escape-hatch filter.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';

/**
 * @group inline-async-tick
 */
class Test_Tool_Async_Executor_Inline_Async extends WP_UnitTestCase {

	/**
	 * Executor under test.
	 *
	 * @var WP_MCP_AI_Tool_Async_Executor
	 */
	private $executor;

	public function setUp(): void {
		parent::setUp();
		_set_cron_array( array() );
		$this->executor = new WP_MCP_AI_Tool_Async_Executor();
		// Note: not calling ->init() to avoid scheduling the hourly
		// cleanup job inside the per-test cron array.
	}

	public function tearDown(): void {
		_set_cron_array( array() );
		remove_all_filters( 'wp_mcp_ai_inline_kick_enabled' );
		remove_all_actions( 'wp_mcp_ai_inline_kick_completed' );
		// Strip the per-test shutdown closures so they do not leak into
		// sibling tests (which would call get_metadata() on stale IDs).
		remove_all_actions( 'shutdown' );
		parent::tearDown();
	}

	/**
	 * Read the active 'shutdown' callbacks at priority 20 — used by all
	 * our inline-kick assertions.
	 *
	 * @return int Count of priority-20 shutdown callbacks.
	 */
	private function shutdown_callback_count_at_20() {
		global $wp_filter;
		if ( empty( $wp_filter['shutdown'] ) ) {
			return 0;
		}
		/** @var WP_Hook $hook */
		$hook = $wp_filter['shutdown'];
		$cbs  = $hook->callbacks;
		return isset( $cbs[20] ) ? count( $cbs[20] ) : 0;
	}

	/**
	 * `queue_tool()` must register a shutdown action that runs the
	 * inline kick. This is the primary regression test for "async tools
	 * never start on hosts with DISABLE_WP_CRON or broken loopback".
	 */
	public function test_queue_tool_registers_inline_shutdown_kick() {
		$before = $this->shutdown_callback_count_at_20();

		$job_id = $this->executor->queue_tool(
			'no_such_tool',
			array( 'param' => 'value' ),
			array( 'user_id' => 1 )
		);

		$this->assertIsString( $job_id );
		$this->assertGreaterThan(
			$before,
			$this->shutdown_callback_count_at_20(),
			'queue_tool() must register a priority-20 shutdown closure'
		);
	}

	/**
	 * The escape-hatch filter must prevent `queue_tool()` from
	 * registering the shutdown closure entirely.
	 */
	public function test_inline_kick_enabled_filter_skips_shutdown_registration() {
		add_filter( 'wp_mcp_ai_inline_kick_enabled', '__return_false' );

		$before = $this->shutdown_callback_count_at_20();
		$job_id = $this->executor->queue_tool(
			'no_such_tool',
			array(),
			array( 'user_id' => 1 )
		);
		$this->assertIsString( $job_id );

		$this->assertSame(
			$before,
			$this->shutdown_callback_count_at_20(),
			'with the filter returning false, no shutdown closure should be registered'
		);
	}

	/**
	 * `kick_inline()` is a no-op when the job has already advanced past
	 * `pending` — a delayed cron loopback must not re-execute a job
	 * that the inline worker (or any other worker) has finished.
	 */
	public function test_kick_inline_is_noop_for_non_pending_jobs() {
		// Manually seed a `completed` job in the metadata transient.
		$job_id = 'async_test_completed_001';
		$this->seed_metadata(
			$job_id,
			array(
				'job_id'       => $job_id,
				'tool_slug'    => 'no_such_tool',
				'arguments'    => array(),
				'context'      => array(),
				'status'       => 'completed',
				'queued_at'    => time() - 100,
				'started_at'   => time() - 90,
				'completed_at' => time() - 60,
				'result'       => null,
				'error'        => null,
			)
		);

		$kick_completed_fired = 0;
		add_action(
			'wp_mcp_ai_inline_kick_completed',
			static function () use ( &$kick_completed_fired ) {
				++$kick_completed_fired;
			}
		);

		$this->executor->kick_inline( $job_id );

		// Status must be unchanged and the observability action must
		// NOT have fired (we short-circuited before run_kick).
		$result = $this->executor->get_result( $job_id );
		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 0, $kick_completed_fired, 'short-circuit path must not emit kick_completed action' );
	}

	/**
	 * The cooperative tick lock must prevent re-entrant calls to
	 * `execute_async_tool()` from double-processing a job. We simulate
	 * a still-running parallel worker by manually seeding the lock
	 * transient, then invoke `execute_async_tool()` and assert no work
	 * was advanced.
	 */
	public function test_cooperative_lock_blocks_concurrent_execute_async_tool() {
		$job_id = 'async_test_locked_002';
		$this->seed_metadata(
			$job_id,
			array(
				'job_id'       => $job_id,
				'tool_slug'    => 'no_such_tool',
				'arguments'    => array(),
				'context'      => array(),
				'status'       => 'pending',
				'queued_at'    => time(),
				'started_at'   => null,
				'completed_at' => null,
				'result'       => null,
				'error'        => null,
			)
		);

		// Hold the lock from outside.
		$lock_key = WP_MCP_AI_Tool_Async_Executor::TICK_LOCK_PREFIX . $job_id;
		set_transient( $lock_key, 1, WP_MCP_AI_Tool_Async_Executor::TICK_LOCK_TTL );

		$this->executor->execute_async_tool( $job_id );

		// Status must remain `pending` because the lock blocked us
		// before we could flip it to `running`.
		$result = $this->executor->get_result( $job_id );
		$this->assertSame( 'pending', $result['status'], 'a held lock must block tick processing' );

		delete_transient( $lock_key );
		wp_cache_delete( $lock_key, WP_MCP_AI_Tool_Async_Executor::TICK_LOCK_CACHE_GROUP );
	}

	/**
	 * `kick_inline_if_stale()` must NOT schedule a shutdown closure
	 * for a job that was just queued — staleness is bounded below by
	 * STALE_PENDING_THRESHOLD_SECONDS to avoid racing the initial
	 * enqueue's own shutdown handler.
	 */
	public function test_kick_inline_if_stale_is_noop_for_fresh_jobs() {
		$job_id = 'async_test_fresh_003';
		$this->seed_metadata(
			$job_id,
			array(
				'job_id'    => $job_id,
				'tool_slug' => 'no_such_tool',
				'status'    => 'pending',
				'queued_at' => time(), // fresh
			)
		);

		$before = $this->shutdown_callback_count_at_20();
		$result = $this->executor->kick_inline_if_stale( $job_id );

		$this->assertFalse( $result );
		$this->assertSame(
			$before,
			$this->shutdown_callback_count_at_20(),
			'fresh pending job must not trigger the self-heal kick'
		);
	}

	/**
	 * `kick_inline_if_stale()` must schedule a shutdown closure for a
	 * job that has been stuck in `pending` past the threshold.
	 */
	public function test_kick_inline_if_stale_schedules_kick_for_stale_pending_jobs() {
		$job_id = 'async_test_stale_004';
		$this->seed_metadata(
			$job_id,
			array(
				'job_id'    => $job_id,
				'tool_slug' => 'no_such_tool',
				'status'    => 'pending',
				'queued_at' => time() - ( WP_MCP_AI_Tool_Async_Executor::STALE_PENDING_THRESHOLD_SECONDS + 30 ),
			)
		);

		$before = $this->shutdown_callback_count_at_20();
		$result = $this->executor->kick_inline_if_stale( $job_id );

		$this->assertTrue( $result );
		$this->assertSame(
			$before + 1,
			$this->shutdown_callback_count_at_20(),
			'stale pending job must schedule one shutdown closure'
		);
	}

	/**
	 * `kick_inline_if_stale()` must respect the escape-hatch filter so
	 * an operator can disable the entire pattern at the REST tier.
	 */
	public function test_kick_inline_if_stale_respects_escape_hatch_filter() {
		add_filter( 'wp_mcp_ai_inline_kick_enabled', '__return_false' );

		$job_id = 'async_test_filtered_005';
		$this->seed_metadata(
			$job_id,
			array(
				'job_id'    => $job_id,
				'tool_slug' => 'no_such_tool',
				'status'    => 'pending',
				'queued_at' => time() - 60,
			)
		);

		$before = $this->shutdown_callback_count_at_20();
		$result = $this->executor->kick_inline_if_stale( $job_id );

		$this->assertFalse( $result );
		$this->assertSame( $before, $this->shutdown_callback_count_at_20() );
	}

	/**
	 * Helper: write a metadata transient using the same key shape that
	 * WP_MCP_AI_Tool_Async_Executor::save_metadata() uses.
	 *
	 * @param string $job_id Job identifier.
	 * @param array  $metadata Metadata to persist.
	 */
	private function seed_metadata( $job_id, array $metadata ) {
		$transient_key = WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $job_id;
		set_transient(
			$transient_key,
			$metadata,
			WP_MCP_AI_Tool_Async_Executor::DEFAULT_RESULT_EXPIRY
		);
	}
}
