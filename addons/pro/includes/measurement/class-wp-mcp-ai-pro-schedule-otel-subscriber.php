<?php
/**
 * Pro Schedule OTel Subscriber
 *
 * Subscribes to the `wp_mcp_ai_pro_schedule_run_completed` action and
 * records two OTel-compatible metrics for every run:
 *
 *   - `schedule.run.duration_ms`  — execution time in milliseconds.
 *   - `schedule.run.failure.count` — incremented by 1 on failure.
 *
 * After recording, the subscriber calls
 * `WP_MCP_AI_OTel_Exporter::dispatch()` so any transport layer
 * attached to `wp_mcp_ai_otel_payload_ready` receives a fresh payload
 * immediately. Sites that batch dispatches should remove this
 * just-in-time dispatch via the
 * `wp_mcp_ai_pro_schedule_otel_jit_dispatch` filter.
 *
 * The subscriber is intentionally stateless: all per-run data arrives
 * via the `$result` parameter of the action, and the collector buffer
 * persists across requests only through the OTel exporter's rolling
 * buffer (see `WP_MCP_AI_OTel_Exporter::append_rolling_buffer()`).
 *
 * Opt-out at any granularity:
 *   - Return `false` from `wp_mcp_ai_pro_schedule_otel_enabled` to
 *     disable the entire subscriber.
 *   - Return an empty array from
 *     `wp_mcp_ai_pro_schedule_metrics_definitions` to stop the
 *     metrics from being registered (no registration → no recording).
 *
 * @package WP_MCP_AI_Pro
 * @since   1.3.1
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Subscribes to Pro Schedule run-completed events and records OTel metrics.
 */
class WP_MCP_AI_Pro_Schedule_Otel_Subscriber {

	/**
	 * Whether boot() has run (idempotent guard).
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Wire this subscriber into WordPress lifecycle hooks.
	 *
	 * Safe to call multiple times; subsequent calls are no-ops.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}

		/**
		 * Filters whether the Pro Schedule OTel subscriber is active.
		 *
		 * Return false to disable metric recording for schedule runs
		 * without removing the subscriber class from the bootstrap.
		 *
		 * @since 1.3.1
		 *
		 * @param bool $enabled Whether the subscriber is enabled.
		 */
		if ( ! (bool) apply_filters( 'wp_mcp_ai_pro_schedule_otel_enabled', true ) ) {
			return;
		}

		// Guard: base measurement infrastructure must exist.
		if (
			! class_exists( 'WP_MCP_AI_Measurement_Registry' ) ||
			! class_exists( 'WP_MCP_AI_Metric_Collector' )
		) {
			return;
		}

		self::$booted = true;

		// Register Pro schedule metric definitions at priority 30 so
		// base/Pro core metrics (priority 20) are already in place.
		add_action(
			'wp_mcp_ai_register_metrics',
			array( 'WP_MCP_AI_Pro_Schedule_Metrics', 'register' ),
			30
		);

		// Subscribe to run-completed events.
		add_action(
			'wp_mcp_ai_pro_schedule_run_completed',
			array( __CLASS__, 'on_run_completed' ),
			10,
			2
		);
	}

	/**
	 * Reset internal state (tests only).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$booted = false;
		remove_action(
			'wp_mcp_ai_register_metrics',
			array( 'WP_MCP_AI_Pro_Schedule_Metrics', 'register' ),
			30
		);
		remove_action(
			'wp_mcp_ai_pro_schedule_run_completed',
			array( __CLASS__, 'on_run_completed' ),
			10
		);
	}

	/**
	 * Handle a schedule run-completed event.
	 *
	 * Records `schedule.run.duration_ms` for every run and
	 * `schedule.run.failure.count` for failed runs, then optionally
	 * dispatches the OTel exporter.
	 *
	 * @param string $schedule_id Schedule identifier.
	 * @param array  $result {
	 *     Run summary emitted by WP_MCP_AI_Pro_Schedule_Manager.
	 *
	 *     @type bool   $success       Whether the run finished without error.
	 *     @type float  $duration      Execution time in seconds.
	 *     @type string $error         Last error message ('' on success).
	 *     @type array  $action_log    Type-specific structured log.
	 *     @type array  $schedule      The schedule record at dispatch time.
	 * }
	 * @return void
	 */
	public static function on_run_completed( $schedule_id, $result ) {
		if ( ! class_exists( 'WP_MCP_AI_Metric_Collector' ) ) {
			return;
		}
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Metrics' ) ) {
			return;
		}

		$schedule_id = is_string( $schedule_id ) ? sanitize_text_field( $schedule_id ) : '';
		if ( '' === $schedule_id ) {
			return;
		}

		$result        = is_array( $result ) ? $result : array();
		$success       = ! empty( $result['success'] );
		$duration_secs = isset( $result['duration'] ) ? (float) $result['duration'] : 0.0;
		$duration_ms   = $duration_secs * 1000.0;

		$schedule_record = isset( $result['schedule'] ) && is_array( $result['schedule'] )
			? $result['schedule']
			: array();
		$schedule_type   = isset( $schedule_record['schedule_type'] )
			? sanitize_text_field( (string) $schedule_record['schedule_type'] )
			: 'unknown';

		$context = array(
			'attributes' => array(
				'schedule_id'   => $schedule_id,
				'schedule_type' => $schedule_type,
				'success'       => $success ? 'true' : 'false',
			),
		);

		$collector = WP_MCP_AI_Metric_Collector::get_instance();

		// Record duration for every run that has a positive duration.
		if ( $duration_ms > 0.0 ) {
			$collector->record(
				WP_MCP_AI_Pro_Schedule_Metrics::RUN_DURATION_MS,
				$duration_ms,
				$context
			);
		}

		// Record failure count for failed runs.
		if ( ! $success ) {
			$collector->record(
				WP_MCP_AI_Pro_Schedule_Metrics::RUN_FAILURE_COUNT,
				1.0,
				$context
			);
		}

		// Just-in-time OTel dispatch: give any registered transport
		// layer a fresh payload immediately after this run. Sites that
		// batch-dispatch can disable this via the filter below.

		/**
		 * Filters whether the subscriber dispatches the OTel exporter
		 * immediately after recording metrics for a schedule run.
		 *
		 * Disable (return false) if you prefer to batch dispatches via
		 * a cron job or `shutdown` hook, or if the exporter is not
		 * configured.
		 *
		 * @since 1.3.1
		 *
		 * @param bool   $dispatch    Whether to dispatch immediately.
		 * @param string $schedule_id Schedule that just completed.
		 * @param array  $result      Run result summary.
		 */
		$should_dispatch = (bool) apply_filters(
			'wp_mcp_ai_pro_schedule_otel_jit_dispatch',
			true,
			$schedule_id,
			$result
		);

		if ( $should_dispatch && class_exists( 'WP_MCP_AI_OTel_Exporter' ) ) {
			$exporter = new WP_MCP_AI_OTel_Exporter();
			$exporter->dispatch();
		}
	}
}
