<?php
/**
 * SSE/Stream Stock Metric Definitions
 *
 * Registers the baseline set of metrics emitted for each SSE stream
 * dispatched by `WP_MCP_AI_SSE_Stream::stream_job_status()`. PR 6
 * instrumented tool calls; PR 7 instrumented chat turns; PR 8
 * instruments the streaming delivery layer — TTFB, chunk cadence,
 * total duration, and the cancellation / failure / timeout breakdown.
 *
 * Design notes:
 *   - Every metric declares a `counter_metric` (Goodhart pairing).
 *   - All definitions stay in the `internal` privacy tier. Metrics
 *     intentionally carry no chunk/status payload content — only the
 *     `job_id` (sanitised) and a fixed-vocabulary `outcome`. The
 *     observer enforces this separately with a canary scan test.
 *   - Client cancellation is a first-class outcome, NOT an error:
 *     `stream.cancelled.count` is its own metric so `stream.error.count`
 *     does not confound quality regressions with user behaviour.
 *   - Metrics are registered under `wp_mcp_ai_register_metrics` at
 *     priority 20 so third-party registrations at priority 10 can
 *     pre-empt a stock metric by id (first registration wins).
 *
 * Opt-out: sites that want to disable stock SSE metric emission
 * entirely can return an empty array from the
 * `wp_mcp_ai_sse_metrics_definitions` filter. The observer is opt-out
 * separately via `wp_mcp_ai_sse_observer_enabled`.
 *
 * @package NvoosContentGraphAiPlatform
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Measurement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SSE stock metric registrar.
 */
class SseMetrics {

	/**
	 * Metric id: SSE streams started (total).
	 */
	const STREAM_COUNT = 'stream.count';

	/**
	 * Metric id: time from stream start to first non-connected chunk.
	 */
	const STREAM_TTFB_MS = 'stream.ttfb_ms';

	/**
	 * Metric id: interval between successive non-heartbeat chunks.
	 */
	const STREAM_CHUNK_INTERVAL_MS = 'stream.chunk_interval_ms';

	/**
	 * Metric id: total wall-clock duration of the SSE stream.
	 */
	const STREAM_TOTAL_DURATION_MS = 'stream.total_duration_ms';

	/**
	 * Metric id: non-heartbeat chunks emitted per stream.
	 */
	const STREAM_CHUNKS_COUNT = 'stream.chunks.count';

	/**
	 * Metric id: streams ended by client or job-level cancellation.
	 */
	const STREAM_CANCELLED_COUNT = 'stream.cancelled.count';

	/**
	 * Metric id: streams ended by a terminal `failed` job state.
	 */
	const STREAM_ERROR_COUNT = 'stream.error.count';

	/**
	 * Return the canonical SSE/stream metric definitions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function definitions() {
		$definitions = array(
			array(
				'id'             => self::STREAM_COUNT,
				'label'          => __( 'SSE streams (total)', 'nvoos-content-graph-ai-platform' ),
				'description'    => __( 'Total number of SSE streams dispatched through the stream handler, regardless of outcome.', 'nvoos-content-graph-ai-platform' ),
				'type'           => MeasurementRegistry::TYPE_COUNTER,
				'unit'           => 'streams',
				'direction'      => MeasurementRegistry::DIRECTION_NEUTRAL,
				'privacy_tier'   => MeasurementRegistry::PRIVACY_INTERNAL,
				'counter_metric' => self::STREAM_ERROR_COUNT,
				'goodhart_note'  => 'Paired with stream.error.count so "more streams" alone does not look like improvement.',
				'otel_attribute' => 'mcp_ai.stream.count',
			),
			array(
				'id'             => self::STREAM_ERROR_COUNT,
				'label'          => __( 'SSE streams (error)', 'nvoos-content-graph-ai-platform' ),
				'description'    => __( 'Streams ended because the underlying job reached a terminal failed state. Client cancellations and timeouts are tracked separately and are NOT counted here.', 'nvoos-content-graph-ai-platform' ),
				'type'           => MeasurementRegistry::TYPE_COUNTER,
				'unit'           => 'streams',
				'direction'      => MeasurementRegistry::DIRECTION_LOWER_IS_BETTER,
				'privacy_tier'   => MeasurementRegistry::PRIVACY_INTERNAL,
				'counter_metric' => self::STREAM_COUNT,
				'goodhart_note'  => 'Lower-is-better can be gamed by silently retrying failed jobs upstream; pair with stream.count and job-level success metrics.',
				'owasp_llm_risk' => 'LLM07',
				'otel_attribute' => 'mcp_ai.stream.error.count',
			),
			array(
				'id'             => self::STREAM_CANCELLED_COUNT,
				'label'          => __( 'SSE streams (cancelled)', 'nvoos-content-graph-ai-platform' ),
				'description'    => __( 'Streams ended by explicit cancellation — either the client disconnected (connection_aborted) or the underlying job transitioned to cancelled. Tracked separately from stream.error.count because cancellation is a first-class user outcome, not a quality regression.', 'nvoos-content-graph-ai-platform' ),
				'type'           => MeasurementRegistry::TYPE_COUNTER,
				'unit'           => 'streams',
				'direction'      => MeasurementRegistry::DIRECTION_NEUTRAL,
				'privacy_tier'   => MeasurementRegistry::PRIVACY_INTERNAL,
				'counter_metric' => self::STREAM_COUNT,
				'goodhart_note'  => 'Cancellations are neither good nor bad — a spike may indicate UI regressions (users bailing out) or simply shorter sessions. Read alongside stream.ttfb_ms and stream.total_duration_ms.',
				'otel_attribute' => 'mcp_ai.stream.cancelled.count',
			),
			array(
				'id'             => self::STREAM_TTFB_MS,
				'label'          => __( 'SSE time-to-first-byte (ms)', 'nvoos-content-graph-ai-platform' ),
				'description'    => __( 'Wall-clock time from stream start to the first non-heartbeat status chunk. Streams that end before any status chunk is emitted do not record this metric.', 'nvoos-content-graph-ai-platform' ),
				'type'           => MeasurementRegistry::TYPE_HISTOGRAM,
				'unit'           => 'ms',
				'direction'      => MeasurementRegistry::DIRECTION_LOWER_IS_BETTER,
				'privacy_tier'   => MeasurementRegistry::PRIVACY_INTERNAL,
				'counter_metric' => self::STREAM_ERROR_COUNT,
				'goodhart_note'  => 'Faster-is-better can be gamed by emitting empty placeholder chunks. Pair with stream.error.count and verifier output coverage.',
				'otel_attribute' => 'mcp_ai.stream.ttfb_ms',
			),
			array(
				'id'             => self::STREAM_CHUNK_INTERVAL_MS,
				'label'          => __( 'SSE chunk interval (ms)', 'nvoos-content-graph-ai-platform' ),
				'description'    => __( 'Time between successive non-heartbeat status chunks. Excludes heartbeat comments so the metric reflects real progress cadence.', 'nvoos-content-graph-ai-platform' ),
				'type'           => MeasurementRegistry::TYPE_HISTOGRAM,
				'unit'           => 'ms',
				'direction'      => MeasurementRegistry::DIRECTION_NEUTRAL,
				'privacy_tier'   => MeasurementRegistry::PRIVACY_INTERNAL,
				'counter_metric' => self::STREAM_ERROR_COUNT,
				'goodhart_note'  => 'Neither direction is desirable — very short intervals can indicate status thrashing, very long intervals can indicate stalled jobs. Read with total duration.',
				'otel_attribute' => 'mcp_ai.stream.chunk_interval_ms',
			),
			array(
				'id'             => self::STREAM_TOTAL_DURATION_MS,
				'label'          => __( 'SSE total duration (ms)', 'nvoos-content-graph-ai-platform' ),
				'description'    => __( 'Wall-clock duration from stream start to stream end, regardless of outcome.', 'nvoos-content-graph-ai-platform' ),
				'type'           => MeasurementRegistry::TYPE_HISTOGRAM,
				'unit'           => 'ms',
				'direction'      => MeasurementRegistry::DIRECTION_LOWER_IS_BETTER,
				'privacy_tier'   => MeasurementRegistry::PRIVACY_INTERNAL,
				'counter_metric' => self::STREAM_ERROR_COUNT,
				'goodhart_note'  => 'Shorter-is-better can be gamed by truncating jobs or increasing cancellation rates. Pair with stream.error.count, stream.cancelled.count and verifier outputs.',
				'otel_attribute' => 'mcp_ai.stream.total_duration_ms',
			),
			array(
				'id'             => self::STREAM_CHUNKS_COUNT,
				'label'          => __( 'SSE chunks per stream', 'nvoos-content-graph-ai-platform' ),
				'description'    => __( 'Number of non-heartbeat chunks (status updates) emitted during a single stream.', 'nvoos-content-graph-ai-platform' ),
				'type'           => MeasurementRegistry::TYPE_HISTOGRAM,
				'unit'           => 'chunks',
				'direction'      => MeasurementRegistry::DIRECTION_NEUTRAL,
				'privacy_tier'   => MeasurementRegistry::PRIVACY_INTERNAL,
				'counter_metric' => self::STREAM_ERROR_COUNT,
				'goodhart_note'  => 'Neither direction is desirable — too few may indicate an unresponsive job, too many may indicate needless churn. Read with chunk interval and total duration.',
				'otel_attribute' => 'mcp_ai.stream.chunks.count',
			),
		);

		/**
		 * Filters the SSE stock metric definitions before registration.
		 *
		 * Return an empty array to disable the entire SSE stock metric
		 * pack. Return a subset to register only specific metrics.
		 *
		 * @since 1.3.0
		 *
		 * @param array<int,array<string,mixed>> $definitions SSE metric definitions.
		 */
		$filtered = apply_filters( 'wp_mcp_ai_sse_metrics_definitions', $definitions );
		return is_array( $filtered ) ? $filtered : $definitions;
	}

	/**
	 * Register SSE metrics on the measurement registry.
	 *
	 * Attached to `wp_mcp_ai_register_metrics` at priority 20.
	 *
	 * @param MeasurementRegistry $registry Registry.
	 * @return int Count of metrics that were newly registered.
	 */
	public static function register( $registry ) {
		if ( ! $registry instanceof MeasurementRegistry ) {
			return 0;
		}
		return $registry->register_many( self::definitions() );
	}
}
