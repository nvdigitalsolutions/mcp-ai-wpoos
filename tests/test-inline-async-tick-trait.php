<?php
/**
 * Tests for the inline-async-tick trait primitives.
 *
 * Exercises the trait in isolation against a fake host class to verify
 * lock acquire/release, the DISABLE_WP_CRON loop decision, the global
 * escape-hatch filter, and the observability action — independently of
 * any concrete consumer (Mine Memories, Tool Async Executor, etc.) so
 * future trait changes can be validated without dragging in a full
 * job's test fixtures.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-inline-async-tick.php';

/**
 * Minimal fake host class that exposes the trait's protected helpers as
 * public static methods so the test case can call them directly.
 *
 * Only used by Test_Inline_Async_Tick_Trait. Defined at the file scope
 * so the autoloader does not see it as a production class.
 */
class Fake_Inline_Async_Tick_Host {
	use WP_MCP_AI_Inline_Async_Tick_Trait;

	const TICK_LOCK_PREFIX      = 'wp_mcp_ai_test_inline_lock_';
	const TICK_LOCK_CACHE_GROUP = 'wp_mcp_ai_test_inline';

	public static function acquire( $job_id ) {
		return self::inline_async_acquire_tick_lock(
			self::TICK_LOCK_PREFIX . $job_id,
			self::TICK_LOCK_CACHE_GROUP,
			60
		);
	}

	public static function release( $job_id ) {
		self::inline_async_release_tick_lock(
			self::TICK_LOCK_PREFIX . $job_id,
			self::TICK_LOCK_CACHE_GROUP
		);
	}

	public static function should_loop( $started_at, $queue_has_work, $budget = 20 ) {
		return self::inline_async_should_loop( $started_at, $queue_has_work, $budget );
	}

	public static function kick_enabled( $job_id ) {
		return self::inline_async_kick_enabled( $job_id, __CLASS__ );
	}

	public static function run_kick( $job_id, $callable ) {
		self::inline_async_run_kick( __CLASS__, $job_id, $callable );
	}
}

/**
 * @group inline-async-tick
 */
class Test_Inline_Async_Tick_Trait extends WP_UnitTestCase {

	/**
	 * Clean up any test transients between cases so a leaked lock
	 * cannot poison sibling tests.
	 */
	public function tearDown(): void {
		Fake_Inline_Async_Tick_Host::release( 'job_a' );
		Fake_Inline_Async_Tick_Host::release( 'job_b' );
		// Cleanly remove any filters installed by individual tests.
		remove_all_filters( 'wp_mcp_ai_inline_kick_enabled' );
		remove_all_actions( 'wp_mcp_ai_inline_kick_completed' );
		parent::tearDown();
	}

	/**
	 * The first acquire wins; the second acquire on the same key
	 * fails until the lock is released. Different keys do not block
	 * each other.
	 */
	public function test_acquire_release_is_mutually_exclusive_per_key() {
		$this->assertTrue( Fake_Inline_Async_Tick_Host::acquire( 'job_a' ) );
		$this->assertFalse( Fake_Inline_Async_Tick_Host::acquire( 'job_a' ), 'second acquire on same key must fail' );
		$this->assertTrue( Fake_Inline_Async_Tick_Host::acquire( 'job_b' ), 'unrelated key must not be blocked' );

		Fake_Inline_Async_Tick_Host::release( 'job_a' );
		$this->assertTrue( Fake_Inline_Async_Tick_Host::acquire( 'job_a' ), 'release must allow re-acquire' );
	}

	/**
	 * `inline_async_should_loop()` returns true only when WP-Cron is
	 * disabled, more work remains, and the wall-clock budget is not
	 * yet exhausted.
	 */
	public function test_should_loop_only_when_disable_wp_cron_and_within_budget() {
		// The WordPress PHPUnit suite defines DISABLE_WP_CRON in
		// wp-tests-config.php, so we cannot assert the
		// "no DISABLE_WP_CRON" branch here without polluting global
		// state. Fall through to the cases that do not depend on it.

		// Empty queue → never loop, even with DISABLE_WP_CRON.
		$this->assertFalse(
			Fake_Inline_Async_Tick_Host::should_loop( time(), false, 20 ),
			'no work remaining → no loop'
		);

		// Budget exhausted.
		$this->assertFalse(
			Fake_Inline_Async_Tick_Host::should_loop( time() - 100, true, 20 ),
			'expired budget → no loop'
		);

		// Happy path: only meaningful if DISABLE_WP_CRON is set, which
		// is the default in the WP test suite.
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$this->assertTrue(
				Fake_Inline_Async_Tick_Host::should_loop( time(), true, 20 ),
				'fresh start + work + DISABLE_WP_CRON → loop'
			);
		}
	}

	/**
	 * The escape-hatch filter `wp_mcp_ai_inline_kick_enabled` must be
	 * able to globally disable the inline kick.
	 */
	public function test_inline_kick_enabled_filter_can_disable_pattern() {
		$this->assertTrue( Fake_Inline_Async_Tick_Host::kick_enabled( 'job_a' ) );

		add_filter( 'wp_mcp_ai_inline_kick_enabled', '__return_false' );
		$this->assertFalse(
			Fake_Inline_Async_Tick_Host::kick_enabled( 'job_a' ),
			'__return_false filter must disable the pattern'
		);

		// Per-job override: enable globally, disable for job_b only.
		remove_all_filters( 'wp_mcp_ai_inline_kick_enabled' );
		add_filter(
			'wp_mcp_ai_inline_kick_enabled',
			static function ( $enabled, $job_id ) {
				return 'job_b' === $job_id ? false : $enabled;
			},
			10,
			2
		);
		$this->assertTrue( Fake_Inline_Async_Tick_Host::kick_enabled( 'job_a' ) );
		$this->assertFalse( Fake_Inline_Async_Tick_Host::kick_enabled( 'job_b' ) );
	}

	/**
	 * `inline_async_run_kick()` must always emit the
	 * `wp_mcp_ai_inline_kick_completed` action, with success=true on a
	 * clean call and success=false when the callable throws.
	 */
	public function test_run_kick_emits_observability_action() {
		$captured = array();
		add_action(
			'wp_mcp_ai_inline_kick_completed',
			static function ( $class, $job_id, $duration_ms, $success ) use ( &$captured ) {
				$captured[] = compact( 'class', 'job_id', 'duration_ms', 'success' );
			},
			10,
			4
		);

		Fake_Inline_Async_Tick_Host::run_kick(
			'job_a',
			static function () {
				// Healthy tick body.
			}
		);

		Fake_Inline_Async_Tick_Host::run_kick(
			'job_b',
			static function () {
				throw new RuntimeException( 'simulated tick failure' );
			}
		);

		$this->assertCount( 2, $captured );
		$this->assertSame( 'job_a', $captured[0]['job_id'] );
		$this->assertTrue( $captured[0]['success'] );
		$this->assertIsFloat( $captured[0]['duration_ms'] );
		$this->assertGreaterThanOrEqual( 0.0, $captured[0]['duration_ms'] );

		$this->assertSame( 'job_b', $captured[1]['job_id'] );
		$this->assertFalse( $captured[1]['success'], 'thrown exception must mark kick as unsuccessful' );
	}
}
