<?php
/**
 * Tests — Slice 5b: WP_MCP_AI_Harness_Eval_Scheduler inline-async-tick integration.
 *
 * Validates that:
 *  1. maybe_schedule_cron() registers a shutdown action when the cron event
 *     is scheduled for the first time.
 *  2. The tick lock prevents two concurrent tick() calls from running
 *     do_tick() simultaneously.
 *  3. The wp_mcp_ai_inline_kick_enabled filter set to false skips the
 *     shutdown registration in maybe_schedule_cron().
 *  4. tick() returns an empty summary (no-op) when the lock is held.
 *
 * @package WP_MCP_AI
 * @since   1.4.1
 */

/**
 * Slice 5b inline-kick tests.
 */
class Test_Harness_Eval_Scheduler_Inline_Kick extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		// Ensure no pre-existing cron event so maybe_schedule_cron() will
		// always see a "first schedule" situation.
		WP_MCP_AI_Harness_Eval_Scheduler::unschedule();
	}

	public function tearDown(): void {
		// Clean up cron event and tick lock.
		WP_MCP_AI_Harness_Eval_Scheduler::unschedule();

		delete_transient( WP_MCP_AI_Harness_Eval_Scheduler::TICK_LOCK_KEY );
		wp_cache_delete(
			WP_MCP_AI_Harness_Eval_Scheduler::TICK_LOCK_KEY,
			WP_MCP_AI_Harness_Eval_Scheduler::TICK_LOCK_CACHE_GROUP
		);

		remove_all_filters( 'wp_mcp_ai_inline_kick_enabled' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------

	/**
	 * maybe_schedule_cron() must register a shutdown action the first time
	 * the cron event is scheduled (when the inline kick is enabled).
	 */
	public function test_maybe_schedule_cron_adds_shutdown_action() {
		// Confirm no cron event is pending.
		$this->assertFalse(
			(bool) wp_next_scheduled( WP_MCP_AI_Harness_Eval_Scheduler::CRON_HOOK ),
			'Pre-condition: cron event must not be scheduled before maybe_schedule_cron().'
		);

		WP_MCP_AI_Harness_Eval_Scheduler::maybe_schedule_cron();

		$this->assertGreaterThan(
			0,
			has_action( 'shutdown' ),
			'Shutdown action should be registered by maybe_schedule_cron() on first schedule.'
		);
		$this->assertNotFalse(
			wp_next_scheduled( WP_MCP_AI_Harness_Eval_Scheduler::CRON_HOOK ),
			'Cron event must be registered after maybe_schedule_cron().'
		);
	}

	/**
	 * When the tick lock is already held, tick() must return an all-zero
	 * summary without calling do_tick().
	 */
	public function test_tick_lock_prevents_double_tick() {
		// Pre-acquire the lock.
		set_transient(
			WP_MCP_AI_Harness_Eval_Scheduler::TICK_LOCK_KEY,
			1,
			WP_MCP_AI_Harness_Eval_Scheduler::TICK_LOCK_TTL
		);
		wp_cache_add(
			WP_MCP_AI_Harness_Eval_Scheduler::TICK_LOCK_KEY,
			1,
			WP_MCP_AI_Harness_Eval_Scheduler::TICK_LOCK_CACHE_GROUP,
			WP_MCP_AI_Harness_Eval_Scheduler::TICK_LOCK_TTL
		);

		$result = WP_MCP_AI_Harness_Eval_Scheduler::tick();

		$this->assertSame(
			array( 'processed' => 0, 'skipped' => 0, 'errors' => 0 ),
			$result,
			'tick() must return an all-zero summary when the lock is already held.'
		);
	}

	/**
	 * When the wp_mcp_ai_inline_kick_enabled filter returns false,
	 * maybe_schedule_cron() must NOT add a shutdown action.
	 */
	public function test_filter_disables_inline_kick_in_maybe_schedule_cron() {
		add_filter( 'wp_mcp_ai_inline_kick_enabled', '__return_false' );

		$hooks_before = has_action( 'shutdown' );

		WP_MCP_AI_Harness_Eval_Scheduler::maybe_schedule_cron();

		$this->assertEquals(
			$hooks_before,
			has_action( 'shutdown' ),
			'No shutdown action should be registered when the filter disables the inline kick.'
		);
	}

	/**
	 * do_tick() on a site with no opted-in assistants must return a
	 * processed=0 summary without errors.
	 */
	public function test_do_tick_with_no_opted_in_assistants() {
		$result = WP_MCP_AI_Harness_Eval_Scheduler::do_tick();

		$this->assertArrayHasKey( 'processed', $result );
		$this->assertArrayHasKey( 'skipped', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertSame( 0, $result['processed'] );
	}
}
