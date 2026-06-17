<?php
/**
 * Tests for the Action Scheduler bridge (Phase 4).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * @covers WP_MCP_AI_Async_Scheduler_Bridge
 */
class Test_Async_Scheduler_Bridge extends WP_UnitTestCase {

	/**
	 * Reset filters between tests.
	 */
	public function tear_down() {
		remove_all_filters( 'wp_mcp_ai_async_scheduler_bridge_enabled' );
		remove_all_filters( 'wp_mcp_ai_async_scheduler_group' );
		parent::tear_down();
	}

	/**
	 * `is_available()` returns false when Action Scheduler is not loaded.
	 */
	public function test_is_available_false_when_action_scheduler_missing() {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler is loaded; cannot assert the unavailable branch.' );
		}

		$this->assertFalse( WP_MCP_AI_Async_Scheduler_Bridge::is_available() );
	}

	/**
	 * `enqueue_job()` returns false when AS is unavailable (graceful no-op).
	 */
	public function test_enqueue_job_returns_false_when_unavailable() {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler is loaded; cannot assert the unavailable branch.' );
		}

		$this->assertFalse( WP_MCP_AI_Async_Scheduler_Bridge::enqueue_job( 1 ) );
	}

	/**
	 * Non-positive job IDs are rejected before any AS call is attempted.
	 */
	public function test_enqueue_job_rejects_invalid_ids() {
		$this->assertFalse( WP_MCP_AI_Async_Scheduler_Bridge::enqueue_job( 0 ) );
		$this->assertFalse( WP_MCP_AI_Async_Scheduler_Bridge::enqueue_job( -1 ) );
	}

	/**
	 * Filter can disable the bridge even when AS is loaded.
	 */
	public function test_bridge_filter_can_disable_when_available() {
		add_filter( 'wp_mcp_ai_async_scheduler_bridge_enabled', '__return_false' );
		$this->assertFalse( WP_MCP_AI_Async_Scheduler_Bridge::is_available() );
	}

	/**
	 * `register_hooks()` is idempotent and binds the runner exactly once.
	 */
	public function test_register_hooks_is_idempotent() {
		WP_MCP_AI_Async_Scheduler_Bridge::register_hooks();
		WP_MCP_AI_Async_Scheduler_Bridge::register_hooks();

		$this->assertSame(
			10,
			has_action(
				WP_MCP_AI_Async_Scheduler_Bridge::RUN_HOOK,
				array( 'WP_MCP_AI_Async_Scheduler_Bridge', 'run_job' )
			)
		);
	}

	/**
	 * Group name is filterable and falls back to the default when blank.
	 */
	public function test_group_filter_with_fallback() {
		$this->assertSame(
			WP_MCP_AI_Async_Scheduler_Bridge::DEFAULT_GROUP,
			WP_MCP_AI_Async_Scheduler_Bridge::get_group()
		);

		add_filter(
			'wp_mcp_ai_async_scheduler_group',
			static function () {
				return 'custom-group';
			}
		);
		$this->assertSame( 'custom-group', WP_MCP_AI_Async_Scheduler_Bridge::get_group() );

		remove_all_filters( 'wp_mcp_ai_async_scheduler_group' );
		add_filter(
			'wp_mcp_ai_async_scheduler_group',
			static function () {
				return '';
			}
		);
		$this->assertSame(
			WP_MCP_AI_Async_Scheduler_Bridge::DEFAULT_GROUP,
			WP_MCP_AI_Async_Scheduler_Bridge::get_group()
		);
	}

	/**
	 * `run_job()` is a no-op for invalid IDs and missing dependencies.
	 *
	 * Verifies the early return fires before any DB / dependency call by
	 * relying on PHPUnit's "no exception" contract.
	 */
	public function test_run_job_is_safe_noop_for_invalid_ids() {
		$this->expectNotToPerformAssertions();

		WP_MCP_AI_Async_Scheduler_Bridge::run_job( 0 );
		WP_MCP_AI_Async_Scheduler_Bridge::run_job( -5 );
		WP_MCP_AI_Async_Scheduler_Bridge::run_job( '' );
	}
}
