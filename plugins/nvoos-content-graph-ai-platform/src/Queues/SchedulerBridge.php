<?php
/**
 * Action Scheduler bridge for the Content Graph AI Platform addon.
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Async_Scheduler_Bridge`
 * (Wave E2): byte-identical runner hook (`wp_mcp_ai_run_async_job`),
 * default Action Scheduler group (`wp-mcp-ai-jobs`), availability
 * contract (`as_enqueue_async_action` presence +
 * `wp_mcp_ai_async_scheduler_bridge_enabled` filter), idempotent
 * `register_hooks()`, group filter with empty-value fallback, and
 * enqueue semantics (action ID on success, `false` = fall back to the
 * WP-Cron path). When Action Scheduler is unavailable, all calls are
 * safe no-ops and the legacy WP-Cron path continues unchanged.
 *
 * Decoupling (documented, additive):
 * - `run_job()` delegates to the queue's `process_specific_job()` per
 *   install mode: the base `WP_MCP_AI_Async_Job_Queue` in monolith
 *   installs (boot-gated probe), this package's `AsyncJobQueue`
 *   standalone — sharing the same retry / dead-letter logic between
 *   AS-driven and WP-Cron-driven dispatch.
 * - `AsyncJobQueue`'s dormant bridge seams now resolve per install mode
 *   (`scheduler_bridge_available()` + `scheduler_bridge_class()`), so
 *   queued jobs dispatch through the base bridge monolith and this
 *   bridge standalone.
 *
 * @package NvoosContentGraphAiPlatform\Queues
 * @since   2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Queues;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Action Scheduler bridge for the async job queue.
 *
 * @since 2.1.0
 */
class SchedulerBridge {

	/**
	 * Action Scheduler hook used to run a single queued job — byte-identical.
	 */
	const RUN_HOOK = 'wp_mcp_ai_run_async_job';

	/**
	 * Default Action Scheduler group — byte-identical.
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
		 * @since 2.1.0
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
	public static function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		add_action( self::RUN_HOOK, array( static::class, 'run_job' ), 10, 1 );
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
	 * Delegates to the queue's `process_specific_job()` per install mode
	 * so the same retry / dead-letter logic is shared between AS-driven
	 * and WP-Cron-driven dispatch.
	 *
	 * @param int $job_id Job row ID.
	 * @return void
	 */
	public static function run_job( $job_id ): void {
		$job_id = (int) $job_id;
		if ( $job_id <= 0 ) {
			return;
		}

		$queue_class = static::queue_class();

		if ( null === $queue_class || ! method_exists( $queue_class, 'process_specific_job' ) ) {
			return;
		}

		$queue_class::process_specific_job( $job_id );
	}

	// ─── Seams ──────────────────────────────────────────────────────

	/**
	 * Resolve the async job queue class that executes individual jobs.
	 *
	 * The base `WP_MCP_AI_Async_Job_Queue` owns the queue in monolith
	 * installs; standalone resolves this package's `AsyncJobQueue`. The
	 * base probe is gated on the base plugin being booted — the monorepo
	 * autoloader can resolve base classes in standalone installs.
	 *
	 * @return string|null Fully-qualified class name, or null when unavailable.
	 */
	protected static function queue_class(): ?string {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Async_Job_Queue' ) ) {
			return 'WP_MCP_AI_Async_Job_Queue';
		}

		return __NAMESPACE__ . '\AsyncJobQueue';
	}
}
