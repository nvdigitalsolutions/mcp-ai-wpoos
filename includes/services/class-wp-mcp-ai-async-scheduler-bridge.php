<?php
/**
 * Action Scheduler bridge for the async job queue.
 *
 * Phase 4 of the massive-data hardening plan. Dispatches queued jobs to
 * Action Scheduler so they execute on the next runner tick instead of
 * waiting up to 60 seconds for WP-Cron's minute-resolution `process_queue`
 * tick. When Action Scheduler is unavailable, all calls become safe no-ops
 * and the legacy WP-Cron path continues to work unchanged.
 *
 * Public surface:
 *   - `is_available()` — whether AS functions are present.
 *   - `register_hooks()` — attaches the per-job runner hook (idempotent).
 *   - `enqueue_job( $job_id )` — schedule a single job for immediate execution.
 *
 * Filters:
 *   - `wp_mcp_ai_async_scheduler_bridge_enabled` (bool) — global on/off
 *     switch. Default `true` when AS is detected.
 *   - `wp_mcp_ai_async_scheduler_group` (string) — Action Scheduler group
 *     name. Default `'wp-mcp-ai-jobs'`.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Action Scheduler bridge for the async job queue.
 */
class WP_MCP_AI_Async_Scheduler_Bridge {

	/**
	 * Action Scheduler hook used to run a single queued job.
	 */
	const RUN_HOOK = 'wp_mcp_ai_run_async_job';

	/**
	 * Default Action Scheduler group.
	 */
	const DEFAULT_GROUP = 'wp-mcp-ai-jobs';

	/**
	 * Whether `register_hooks()` has already been called.
	 *
	 * @var bool
	 */
	protected static $hooks_registered = false;

	/**
	 * Detect whether Action Scheduler is loaded and usable.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}

		/**
		 * Filters whether the Action Scheduler bridge should be considered
		 * available. Use this to disable AS dispatch even when AS is loaded
		 * (e.g. during diagnostics or controlled rollouts).
		 *
		 * @since 1.2.0
		 *
		 * @param bool $enabled Whether the bridge is enabled.
		 */
		return (bool) apply_filters( 'wp_mcp_ai_async_scheduler_bridge_enabled', true );
	}

	/**
	 * Register the per-job runner hook. Idempotent.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		if ( self::$hooks_registered ) {
			return;
		}

		add_action( self::RUN_HOOK, array( __CLASS__, 'run_job' ), 10, 1 );
		self::$hooks_registered = true;
	}

	/**
	 * Resolve the Action Scheduler group used for queued jobs.
	 *
	 * @return string
	 */
	public static function get_group() {
		$group = (string) apply_filters( 'wp_mcp_ai_async_scheduler_group', self::DEFAULT_GROUP );

		return '' === $group ? self::DEFAULT_GROUP : $group;
	}

	/**
	 * Enqueue a single queued job for immediate execution via Action Scheduler.
	 *
	 * Returns the action ID on success, `false` if the bridge is unavailable
	 * or scheduling failed. Callers should treat `false` as "fall back to
	 * WP-Cron" rather than as a fatal error.
	 *
	 * @param int $job_id Job row ID returned by `WP_MCP_AI_Async_Job_Queue::queue_job()`.
	 * @return int|false
	 */
	public static function enqueue_job( $job_id ) {
		$job_id = (int) $job_id;
		if ( $job_id <= 0 ) {
			return false;
		}

		if ( ! self::is_available() ) {
			return false;
		}

		// Ensure our runner is bound before AS picks the action up.
		self::register_hooks();

		$group = self::get_group();

		$action_id = as_enqueue_async_action(
			self::RUN_HOOK,
			array( 'job_id' => $job_id ),
			$group
		);

		$action_id = (int) $action_id;

		return $action_id > 0 ? $action_id : false;
	}

	/**
	 * Run a single job by ID. Bound to Action Scheduler's `RUN_HOOK`.
	 *
	 * Delegates to `WP_MCP_AI_Async_Job_Queue::process_specific_job()` when
	 * available so the same retry / dead-letter logic is shared between
	 * AS-driven and WP-Cron-driven dispatch.
	 *
	 * @param int $job_id Job row ID.
	 * @return void
	 */
	public static function run_job( $job_id ) {
		$job_id = (int) $job_id;
		if ( $job_id <= 0 ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Async_Job_Queue' ) ) {
			return;
		}

		if ( ! method_exists( 'WP_MCP_AI_Async_Job_Queue', 'process_specific_job' ) ) {
			return;
		}

		WP_MCP_AI_Async_Job_Queue::process_specific_job( $job_id );
	}
}
