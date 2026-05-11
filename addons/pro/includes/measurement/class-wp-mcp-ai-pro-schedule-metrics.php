<?php
/**
 * Pro Schedule Metric Definitions
 *
 * Registers the two metrics emitted for every Pro Schedule run:
 *   - `schedule.run.duration_ms` — wall-clock execution time per run
 *     (histogram, tagged by schedule_id, schedule_type, and success).
 *   - `schedule.run.failure.count` — monotonic counter of failed runs
 *     (counter, tagged by schedule_id and schedule_type).
 *
 * Both metrics are registered under the canonical
 * `wp_mcp_ai_register_metrics` hook at priority 30, after base and
 * Pro core metrics (priority 20), so third-party registrations at
 * priority 10 can still pre-empt them by id.
 *
 * Goodhart pairing:
 *   `schedule.run.duration_ms` is paired with
 *   `schedule.run.failure.count` so that a "fast run" that always
 *   fails does not look like a health improvement.
 *
 * Opt-out: return an empty array from the
 * `wp_mcp_ai_pro_schedule_metrics_definitions` filter to disable all
 * Pro schedule metric emission.
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
 * Pro schedule metric registrar.
 */
class WP_MCP_AI_Pro_Schedule_Metrics {

	/**
	 * Metric id: wall-clock run duration.
	 */
	const RUN_DURATION_MS = 'schedule.run.duration_ms';

	/**
	 * Metric id: failed schedule runs (monotonic counter).
	 */
	const RUN_FAILURE_COUNT = 'schedule.run.failure.count';

	/**
	 * Return the Pro schedule metric definitions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function definitions() {
		if ( ! class_exists( 'WP_MCP_AI_Measurement_Registry' ) ) {
			return array();
		}

		$definitions = array(
			array(
				'id'             => self::RUN_DURATION_MS,
				'label'          => __( 'Schedule run duration (ms)', 'mcp-ai-wpoos-pro' ),
				'description'    => __( 'Wall-clock execution time of a single Pro Schedule run, from dispatch start to completion hook.', 'mcp-ai-wpoos-pro' ),
				'type'           => WP_MCP_AI_Measurement_Registry::TYPE_HISTOGRAM,
				'unit'           => 'ms',
				'direction'      => WP_MCP_AI_Measurement_Registry::DIRECTION_LOWER_IS_BETTER,
				'privacy_tier'   => WP_MCP_AI_Measurement_Registry::PRIVACY_INTERNAL,
				'counter_metric' => self::RUN_FAILURE_COUNT,
				'goodhart_note'  => 'Faster-is-better can be gamed by short-circuiting work. Always read alongside schedule.run.failure.count to catch "fast failures".',
				'otel_attribute' => 'mcp_ai.schedule.run.duration_ms',
			),
			array(
				'id'             => self::RUN_FAILURE_COUNT,
				'label'          => __( 'Schedule run failures', 'mcp-ai-wpoos-pro' ),
				'description'    => __( 'Monotonic count of Pro Schedule runs that completed with success=false.', 'mcp-ai-wpoos-pro' ),
				'type'           => WP_MCP_AI_Measurement_Registry::TYPE_COUNTER,
				'unit'           => 'runs',
				'direction'      => WP_MCP_AI_Measurement_Registry::DIRECTION_LOWER_IS_BETTER,
				'privacy_tier'   => WP_MCP_AI_Measurement_Registry::PRIVACY_INTERNAL,
				'counter_metric' => self::RUN_DURATION_MS,
				'goodhart_note'  => 'Lower-is-better can be gamed by swallowing errors. Pair with run duration to detect "instant failures".',
				'otel_attribute' => 'mcp_ai.schedule.run.failure.count',
			),
		);

		/**
		 * Filters the Pro schedule metric definitions before registration.
		 *
		 * Return an empty array to disable all Pro schedule metrics.
		 * Return a subset to register only specific metrics.
		 *
		 * @since 1.3.1
		 *
		 * @param array<int,array<string,mixed>> $definitions Metric definitions.
		 */
		$filtered = apply_filters( 'wp_mcp_ai_pro_schedule_metrics_definitions', $definitions );
		return is_array( $filtered ) ? $filtered : $definitions;
	}

	/**
	 * Register Pro schedule metrics on the measurement registry.
	 *
	 * Attached to `wp_mcp_ai_register_metrics` at priority 30.
	 *
	 * @param WP_MCP_AI_Measurement_Registry $registry Registry.
	 * @return int Count of metrics newly registered.
	 */
	public static function register( $registry ) {
		if ( ! $registry instanceof WP_MCP_AI_Measurement_Registry ) {
			return 0;
		}
		return $registry->register_many( self::definitions() );
	}
}
