<?php
/**
 * SSE Stream Observer
 *
 * Bridges the `wp_mcp_ai_sse_stream_started`, `wp_mcp_ai_sse_stream_chunk_sent`,
 * and `wp_mcp_ai_sse_stream_ended` action hooks into the measurement
 * collector. This is the third live-traffic emission path after the
 * tool-execution observer (PR 6) and the chat-turn observer (PR 7).
 *
 * Concurrency model:
 *   A single PHP request typically services one SSE stream at a time,
 *   but nothing forbids nested or concurrent dispatches (for example a
 *   fan-out task that opens sub-streams). The observer keeps an
 *   **invocation stack keyed by job_id**. `started` pushes a frame;
 *   `chunk_sent` and `ended` look up the matching frame by job_id
 *   rather than assuming top-of-stack — SSE streams do not nest in
 *   LIFO order in practice, so key-based lookup is safer.
 *
 * Outcome vocabulary (from the emitter):
 *   - `complete`              — job reached terminal `completed` state
 *   - `failed`                — job reached terminal `failed` state (error path)
 *   - `cancelled_by_job`      — job reached terminal `cancelled` state (first-class, NOT error)
 *   - `cancelled_by_client`   — `connection_aborted()` returned true   (first-class, NOT error)
 *   - `timeout`               — max_duration reached                   (operational, NOT error)
 *   - `iteration_exhausted`   — safety cap tripped                     (rare edge case)
 *
 * Only `failed` increments `stream.error.count`. Cancellations and
 * timeouts are tracked in their own dedicated metrics so aggregate
 * error rates stay clean.
 *
 * Privacy:
 *   The context payload sent to `record()` contains only `job_id`
 *   (sanitised) and `outcome`. Chunk payloads, status summaries,
 *   messages and headers are never included — the Internal privacy
 *   tier explicitly forbids them (see
 *   `docs/reference/measurement/privacy-matrix.md`).
 *
 * Opt-out:
 *   Return `false` from the `wp_mcp_ai_sse_observer_enabled` filter
 *   to skip observer installation entirely. Stock definitions remain
 *   registered so third parties can still emit them directly.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SSE stream observer.
 */
class WP_MCP_AI_SSE_Observer {

	/**
	 * Active stream frames keyed by sanitised job_id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $frames = array();

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_SSE_Observer|null
	 */
	private static $instance = null;

	/**
	 * Whether hooks have been attached.
	 *
	 * @var bool
	 */
	private $attached = false;

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_SSE_Observer
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Reset the singleton (tests only).
	 *
	 * @return void
	 */
	public static function reset_instance() {
		if ( null !== self::$instance ) {
			self::$instance->detach();
		}
		self::$instance = null;
	}

	/**
	 * Attach hooks. Idempotent.
	 *
	 * @return bool True if attached (or already attached).
	 */
	public function attach() {
		if ( $this->attached ) {
			return true;
		}

		/**
		 * Filters whether the SSE observer is installed.
		 *
		 * Return false to suppress all baseline SSE metric emission.
		 * Stock definitions remain registered so third parties can
		 * still emit them directly.
		 *
		 * @since 1.3.0
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'wp_mcp_ai_sse_observer_enabled', true ) ) {
			return false;
		}

		add_action( 'wp_mcp_ai_sse_stream_started', array( $this, 'on_started' ), 5, 2 );
		add_action( 'wp_mcp_ai_sse_stream_chunk_sent', array( $this, 'on_chunk_sent' ), 95, 3 );
		add_action( 'wp_mcp_ai_sse_stream_ended', array( $this, 'on_ended' ), 95, 3 );

		$this->attached = true;
		return true;
	}

	/**
	 * Detach hooks. Primarily for tests.
	 *
	 * @return void
	 */
	public function detach() {
		if ( ! $this->attached ) {
			return;
		}
		remove_action( 'wp_mcp_ai_sse_stream_started', array( $this, 'on_started' ), 5 );
		remove_action( 'wp_mcp_ai_sse_stream_chunk_sent', array( $this, 'on_chunk_sent' ), 95 );
		remove_action( 'wp_mcp_ai_sse_stream_ended', array( $this, 'on_ended' ), 95 );
		$this->attached = false;
		$this->frames   = array();
	}

	/**
	 * Observability hook: open a stream frame.
	 *
	 * Signature matches `do_action( 'wp_mcp_ai_sse_stream_started', $job_id, $params )`.
	 * `$params` is accepted but only used for the frame's `started_at` fallback.
	 *
	 * @param mixed $job_id Job identifier.
	 * @param mixed $params Stream params (unused aside from started_at fallback).
	 * @return void
	 */
	public function on_started( $job_id = null, $params = null ) {
		$key = self::sanitize_job_id( $job_id );
		if ( '' === $key ) {
			return;
		}

		$started_at = microtime( true );
		if ( is_array( $params ) && isset( $params['started_at'] ) && is_numeric( $params['started_at'] ) ) {
			// Core hook reports integer seconds from time(); prefer microtime for ms precision.
			// If caller supplied a float timestamp keep it.
			$maybe_float = (float) $params['started_at'];
			if ( $maybe_float > 1.0e9 && $maybe_float < $started_at + 1.0 ) {
				$started_at = $maybe_float;
			}
		}

		$this->frames[ $key ] = array(
			'job_id'         => $key,
			'started_at'     => $started_at,
			'first_chunk_at' => null,
			'last_chunk_at'  => null,
			'chunk_count'    => 0,
		);
	}

	/**
	 * Observability hook: emit TTFB (first chunk) or chunk-interval
	 * histograms and increment the frame's chunk counter.
	 *
	 * Signature matches `do_action( 'wp_mcp_ai_sse_stream_chunk_sent', $job_id, $event_type, $iteration )`.
	 *
	 * @param mixed $job_id     Job identifier.
	 * @param mixed $event_type SSE event name (unused in metrics — only informational).
	 * @param mixed $iteration  Iteration count (unused).
	 * @return void
	 */
	public function on_chunk_sent( $job_id = null, $event_type = null, $iteration = null ) {
		unset( $event_type, $iteration );

		$key = self::sanitize_job_id( $job_id );
		if ( '' === $key || ! isset( $this->frames[ $key ] ) ) {
			return;
		}

		$now       = microtime( true );
		$collector = $this->collector();

		if ( null === $this->frames[ $key ]['first_chunk_at'] ) {
			$this->frames[ $key ]['first_chunk_at'] = $now;
			$ttfb_ms                                = max( 0.0, ( $now - (float) $this->frames[ $key ]['started_at'] ) * 1000.0 );
			if ( null !== $collector ) {
				$collector->record(
					WP_MCP_AI_SSE_Metrics::STREAM_TTFB_MS,
					$ttfb_ms,
					self::base_context( $key, null )
				);
			}
		} elseif ( null !== $this->frames[ $key ]['last_chunk_at'] ) {
			$interval_ms = max( 0.0, ( $now - (float) $this->frames[ $key ]['last_chunk_at'] ) * 1000.0 );
			if ( null !== $collector ) {
				$collector->record(
					WP_MCP_AI_SSE_Metrics::STREAM_CHUNK_INTERVAL_MS,
					$interval_ms,
					self::base_context( $key, null )
				);
			}
		}

		$this->frames[ $key ]['last_chunk_at'] = $now;
		++$this->frames[ $key ]['chunk_count'];
	}

	/**
	 * Observability hook: close out a stream frame and emit end-of-stream metrics.
	 *
	 * Signature matches `do_action( 'wp_mcp_ai_sse_stream_ended', $job_id, $outcome, $summary )`.
	 *
	 * @param mixed $job_id  Job identifier.
	 * @param mixed $outcome Outcome string.
	 * @param mixed $summary Stream summary array (`duration_ms`, `iterations`).
	 * @return void
	 */
	public function on_ended( $job_id = null, $outcome = null, $summary = null ) {
		$key     = self::sanitize_job_id( $job_id );
		$outcome = self::sanitize_outcome( $outcome );

		$frame = isset( $this->frames[ $key ] ) ? $this->frames[ $key ] : null;
		if ( '' !== $key && isset( $this->frames[ $key ] ) ) {
			unset( $this->frames[ $key ] );
		}

		$collector = $this->collector();
		if ( null === $collector ) {
			return;
		}

		$ctx = self::base_context( $key, $outcome );

		$collector->record( WP_MCP_AI_SSE_Metrics::STREAM_COUNT, 1, $ctx );

		if ( 'failed' === $outcome ) {
			$collector->record( WP_MCP_AI_SSE_Metrics::STREAM_ERROR_COUNT, 1, $ctx );
		} elseif ( 'cancelled_by_client' === $outcome || 'cancelled_by_job' === $outcome ) {
			$collector->record( WP_MCP_AI_SSE_Metrics::STREAM_CANCELLED_COUNT, 1, $ctx );
		}

		// Prefer frame-derived duration (microtime precision); fall back
		// to summary-reported ms (integer second precision).
		$duration_ms = null;
		if ( is_array( $frame ) && isset( $frame['started_at'] ) ) {
			$duration_ms = max( 0.0, ( microtime( true ) - (float) $frame['started_at'] ) * 1000.0 );
		} elseif ( is_array( $summary ) && isset( $summary['duration_ms'] ) ) {
			$duration_ms = max( 0.0, (float) $summary['duration_ms'] );
		}
		if ( null !== $duration_ms ) {
			$collector->record( WP_MCP_AI_SSE_Metrics::STREAM_TOTAL_DURATION_MS, $duration_ms, $ctx );
		}

		$chunk_count = null;
		if ( is_array( $frame ) && isset( $frame['chunk_count'] ) ) {
			$chunk_count = (int) $frame['chunk_count'];
		}
		if ( null !== $chunk_count ) {
			$collector->record( WP_MCP_AI_SSE_Metrics::STREAM_CHUNKS_COUNT, $chunk_count, $ctx );
		}
	}

	/**
	 * Number of active stream frames (tests / assertions).
	 *
	 * @return int
	 */
	public function active_streams() {
		return count( $this->frames );
	}

	/**
	 * Build the context payload passed to `record()`. Kept deliberately
	 * small to stay inside the Internal privacy tier.
	 *
	 * The collector's built-in `sanitize_context` has a fixed allowlist
	 * of top-level keys (assistant_id / user_id / model / provider / …);
	 * anything outside that list is dropped unless it lives under
	 * `attributes`. We stash `job_id` and `outcome` in `attributes` so
	 * they round-trip through the collector intact.
	 *
	 * @param string      $job_id  Sanitised job id.
	 * @param string|null $outcome Outcome string or null.
	 * @return array<string,mixed>
	 */
	private static function base_context( $job_id, $outcome ) {
		$attrs = array();
		if ( '' !== $job_id ) {
			$attrs['job_id'] = $job_id;
		}
		if ( null !== $outcome && '' !== $outcome ) {
			$attrs['outcome'] = $outcome;
		}
		if ( array() === $attrs ) {
			return array();
		}
		return array( 'attributes' => $attrs );
	}

	/**
	 * Sanitize a job_id for context inclusion. Accepts strings and
	 * numerics; strips anything outside `[a-zA-Z0-9._\-]` to avoid
	 * accidental leakage of URL-encoded payloads or injected content.
	 *
	 * @param mixed $value Raw job_id.
	 * @return string
	 */
	private static function sanitize_job_id( $value ) {
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$str = (string) $value;
		$str = preg_replace( '/[^a-zA-Z0-9._\-]/', '', $str );
		if ( null === $str ) {
			return '';
		}
		return substr( $str, 0, 128 );
	}

	/**
	 * Constrain outcome to the known vocabulary. Unknown or empty
	 * values collapse to `unknown` so context cardinality stays
	 * bounded.
	 *
	 * @param mixed $value Raw outcome.
	 * @return string
	 */
	private static function sanitize_outcome( $value ) {
		$allowed = array(
			'complete',
			'failed',
			'cancelled_by_job',
			'cancelled_by_client',
			'timeout',
			'iteration_exhausted',
		);
		if ( is_string( $value ) && in_array( $value, $allowed, true ) ) {
			return $value;
		}
		return 'unknown';
	}

	/**
	 * Resolve the collector lazily.
	 *
	 * @return WP_MCP_AI_Metric_Collector|null
	 */
	private function collector() {
		if ( ! class_exists( 'WP_MCP_AI_Metric_Collector' ) ) {
			return null;
		}
		return WP_MCP_AI_Metric_Collector::get_instance();
	}
}
