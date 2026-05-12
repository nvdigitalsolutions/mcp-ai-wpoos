<?php
/**
 * Tests — Slice 5a: NV_oOS_Graphify inline-async-tick integration.
 *
 * Validates that:
 *  1. on_save_post() registers a shutdown action when the inline kick is
 *     enabled (default).
 *  2. The tick lock prevents run_scheduled_build() from running two
 *     concurrent builds.
 *  3. The wp_mcp_ai_inline_kick_enabled filter set to false skips the
 *     shutdown registration.
 *  4. run_scheduled_build() is a no-op when a lock is already held.
 *
 * @package NV_oOS_Graphify
 * @since   0.6.1
 */

/**
 * Slice 5a inline-kick tests.
 */
class Test_Graphify_Inline_Kick extends WP_UnitTestCase {

	/**
	 * Published post used across tests.
	 *
	 * @var int
	 */
	private $post_id;

	public function setUp(): void {
		parent::setUp();
		$this->post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Graphify Inline Kick Test',
			)
		);
	}

	public function tearDown(): void {
		// Clear the tick lock transient and object-cache entry.
		delete_transient( NV_oOS_Graphify::TICK_LOCK_KEY );
		wp_cache_delete( NV_oOS_Graphify::TICK_LOCK_KEY, NV_oOS_Graphify::TICK_LOCK_CACHE_GROUP );

		remove_all_filters( 'wp_mcp_ai_inline_kick_enabled' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------

	/**
	 * on_save_post() must register a shutdown action when the inline kick
	 * is enabled (the default).
	 */
	public function test_on_save_post_registers_shutdown_action() {
		NV_oOS_Graphify::on_save_post( $this->post_id );

		$this->assertGreaterThan(
			0,
			has_action( 'shutdown' ),
			'Shutdown action should be registered after on_save_post() for a published post.'
		);
	}

	/**
	 * When the tick lock is already held, run_scheduled_build() must return
	 * without calling NV_oOS_Graphify_Builder::build() a second time (the
	 * lock prevents double-builds).
	 *
	 * We simulate the lock being held by calling run_scheduled_build() from
	 * inside itself via a filter override on inline_async_acquire_tick_lock.
	 * The simpler approach: acquire the lock externally using the transient,
	 * then assert run_scheduled_build() is a no-op (do_build() not called).
	 */
	public function test_tick_lock_prevents_double_build() {
		// Pre-acquire the lock using the transient layer.
		set_transient(
			NV_oOS_Graphify::TICK_LOCK_KEY,
			1,
			NV_oOS_Graphify::TICK_LOCK_TTL
		);
		wp_cache_add(
			NV_oOS_Graphify::TICK_LOCK_KEY,
			1,
			NV_oOS_Graphify::TICK_LOCK_CACHE_GROUP,
			NV_oOS_Graphify::TICK_LOCK_TTL
		);

		// Spy: record whether NV_oOS_Graphify_Builder::build() would be called.
		$build_called = false;
		add_filter(
			'nvoos_graphify_before_build',
			function () use ( &$build_called ) {
				$build_called = true;
				return false; // prevent actual DB writes.
			}
		);

		// run_scheduled_build() should short-circuit on the lock check.
		NV_oOS_Graphify::run_scheduled_build();

		// Since the inner body was not reached, the filter never fired.
		$this->assertFalse(
			$build_called,
			'do_build() must not be called when the tick lock is already held.'
		);
	}

	/**
	 * When the wp_mcp_ai_inline_kick_enabled filter returns false,
	 * on_save_post() must NOT add a shutdown action.
	 */
	public function test_filter_disables_inline_kick() {
		add_filter( 'wp_mcp_ai_inline_kick_enabled', '__return_false' );

		// Count hooks before call — in most test runs this will be 0.
		$hooks_before = has_action( 'shutdown' );

		NV_oOS_Graphify::on_save_post( $this->post_id );

		$hooks_after = has_action( 'shutdown' );

		$this->assertEquals(
			$hooks_before,
			$hooks_after,
			'No additional shutdown action should be registered when the filter disables the inline kick.'
		);
	}

	/**
	 * on_save_post() must bail early (no shutdown action) for non-published
	 * posts and revisions.
	 */
	public function test_draft_post_skips_inline_kick() {
		$draft_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_title'  => 'Graphify Draft Post',
			)
		);

		$hooks_before = has_action( 'shutdown' );

		NV_oOS_Graphify::on_save_post( $draft_id );

		$this->assertEquals(
			$hooks_before,
			has_action( 'shutdown' ),
			'on_save_post() must skip inline kick for draft posts.'
		);
	}
}
