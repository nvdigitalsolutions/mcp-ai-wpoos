<?php
/**
 * Tests for NV_oOS_Docs_Hub_Rebuild_Pipeline inline-async-tick integration (Slice 4).
 *
 * Verifies:
 *   1. enqueue() registers a shutdown action when the inline kick is enabled.
 *   2. tick() acquires the cooperative tick lock and prevents double-ticking.
 *   3. The wp_mcp_ai_inline_kick_enabled filter can disable the inline kick.
 *   4. do_tick() short-circuits when the rebuild state is not running.
 *
 * @package NV_oOS_Docs_Hub
 * @since   1.2.0
 */
class Test_Docs_Hub_Rebuild_Pipeline_Inline_Kick extends WP_UnitTestCase {

	/**
	 * Reset rebuild state after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		NV_oOS_Docs_Hub_Rebuild_State::reset();
		// Release any tick lock that may have been left behind.
		delete_transient( NV_oOS_Docs_Hub_Rebuild_Pipeline::TICK_LOCK_KEY );

		// Remove any shutdown actions registered during the test.
		global $wp_filter;
		if ( isset( $wp_filter['shutdown'] ) ) {
			unset( $wp_filter['shutdown'] );
		}

		remove_all_filters( 'wp_mcp_ai_inline_kick_enabled' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// 1. Shutdown kick registration
	// -------------------------------------------------------------------------

	/**
	 * Enqueue() must register a shutdown action when the inline kick is enabled.
	 */
	public function test_enqueue_registers_shutdown_action() {
		// Clear any pre-existing shutdown hooks.
		global $wp_filter;
		if ( isset( $wp_filter['shutdown'] ) ) {
			unset( $wp_filter['shutdown'] );
		}

		NV_oOS_Docs_Hub_Rebuild_Pipeline::enqueue();

		$this->assertGreaterThan( 0, has_action( 'shutdown' ), 'enqueue() should register a shutdown action for the inline kick' );
	}

	/**
	 * Enqueue() must NOT register a shutdown action when the
	 * wp_mcp_ai_inline_kick_enabled filter returns false.
	 */
	public function test_enqueue_skips_shutdown_action_when_filter_disabled() {
		add_filter( 'wp_mcp_ai_inline_kick_enabled', '__return_false' );

		// Clear any pre-existing shutdown hooks.
		global $wp_filter;
		if ( isset( $wp_filter['shutdown'] ) ) {
			unset( $wp_filter['shutdown'] );
		}

		NV_oOS_Docs_Hub_Rebuild_Pipeline::enqueue();

		$this->assertFalse( has_action( 'shutdown' ), 'Shutdown action should NOT be registered when filter disables the inline kick' );
	}

	// -------------------------------------------------------------------------
	// 2. Tick-lock prevents double-ticking
	// -------------------------------------------------------------------------

	/**
	 * Tick() must bail early when the cooperative tick lock is already held,
	 * preventing two concurrent ticks from processing the same chunk.
	 */
	public function test_tick_bails_when_lock_held() {
		NV_oOS_Docs_Hub_Rebuild_Pipeline::enqueue();

		// Pre-acquire the lock externally to simulate a concurrent tick.
		set_transient(
			NV_oOS_Docs_Hub_Rebuild_Pipeline::TICK_LOCK_KEY,
			1,
			NV_oOS_Docs_Hub_Rebuild_Pipeline::TICK_LOCK_TTL
		);

		$state_before = NV_oOS_Docs_Hub_Rebuild_State::get();

		// tick() should bail; the state cursor should not advance.
		NV_oOS_Docs_Hub_Rebuild_Pipeline::tick();

		$state_after = NV_oOS_Docs_Hub_Rebuild_State::get();
		$this->assertSame(
			$state_before['cursor'],
			$state_after['cursor'],
			'Cursor should not advance when the tick lock is already held'
		);
	}

	// -------------------------------------------------------------------------
	// 3. do_tick() short-circuits on non-running state
	// -------------------------------------------------------------------------

	/**
	 * Do_tick() must return without processing when the rebuild state is idle.
	 */
	public function test_do_tick_bails_on_idle_state() {
		// State is idle (nothing enqueued).
		$state_before = NV_oOS_Docs_Hub_Rebuild_State::get();
		$this->assertSame( NV_oOS_Docs_Hub_Rebuild_State::PHASE_IDLE, $state_before['phase'] );

		NV_oOS_Docs_Hub_Rebuild_Pipeline::do_tick();

		// State should still be idle.
		$state_after = NV_oOS_Docs_Hub_Rebuild_State::get();
		$this->assertSame( NV_oOS_Docs_Hub_Rebuild_State::PHASE_IDLE, $state_after['phase'] );
	}

	/**
	 * Do_tick() must return without processing when the rebuild phase is DONE.
	 */
	public function test_do_tick_bails_on_done_state() {
		NV_oOS_Docs_Hub_Rebuild_State::update(
			array(
				'phase' => NV_oOS_Docs_Hub_Rebuild_State::PHASE_DONE,
			)
		);

		NV_oOS_Docs_Hub_Rebuild_Pipeline::do_tick();

		$state = NV_oOS_Docs_Hub_Rebuild_State::get();
		$this->assertSame( NV_oOS_Docs_Hub_Rebuild_State::PHASE_DONE, $state['phase'] );
	}
}
