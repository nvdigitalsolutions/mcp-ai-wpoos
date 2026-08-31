<?php
/**
 * Metric Persister
 *
 * Bridges the `wp_mcp_ai_metric_recorded` action into the metric event
 * store. Buffers events per-request and flushes once on `shutdown` so
 * each `Metric_Collector::record()` call stays on the hot path (no
 * per-event INSERT).
 *
 * Design rules this class enforces:
 *   1. **Zero synchronous DB work during `record()`.** Listeners on
 *      `wp_mcp_ai_metric_recorded` must be fast and must not raise
 *      (contract documented in `Metric_Collector::record()`). The
 *      persister only appends to an in-memory array — the single
 *      batched INSERT happens on `shutdown`.
 *   2. **Restricted tier is dropped before buffering.** This is the
 *      primary enforcement point for the `privacy-matrix.md`
 *      invariant that Restricted raw events are never persisted.
 *      `Metric_Event_Store::insert_batch()` also drops them, but
 *      dropping earlier saves memory and keeps Restricted payloads
 *      off the request's buffer entirely.
 *   3. **Idempotent attach/detach.** Mirrors the observer pattern from
 *      PRs 6/7/8 so tests can install/uninstall at will.
 *   4. **Filterable.** `wp_mcp_ai_persister_enabled` (skip install),
 *      `wp_mcp_ai_persister_buffer_max` (cap per request),
 *      `wp_mcp_ai_persister_should_persist` (per-event veto).
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
 * Metric persister.
 */
class MetricPersister {

	/**
	 * Hard upper bound for the per-request buffer. Protects against
	 * pathological cases where a single request records tens of
	 * thousands of events. Filterable via `wp_mcp_ai_persister_buffer_max`.
	 */
	const DEFAULT_BUFFER_MAX = 2048;

	/**
	 * Singleton instance.
	 *
	 * @var MetricPersister|null
	 */
	private static $instance = null;

	/**
	 * Whether hooks are attached.
	 *
	 * @var bool
	 */
	private $attached = false;

	/**
	 * Whether the shutdown flush has been registered for this request.
	 *
	 * @var bool
	 */
	private $shutdown_registered = false;

	/**
	 * Per-request buffer of events awaiting flush.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $buffer = array();

	/**
	 * Resolved buffer cap (computed lazily so the filter can be added
	 * after the persister is constructed).
	 *
	 * @var int|null
	 */
	private $buffer_max = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return MetricPersister
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
		 * Filter whether the metric persister is enabled.
		 *
		 * Return false to disable custom-table persistence entirely
		 * (the in-memory buffer in `Metric_Collector` still works,
		 * and any other exporter attached to `wp_mcp_ai_metric_recorded`
		 * is unaffected).
		 *
		 * @since 1.3.0
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'wp_mcp_ai_persister_enabled', true ) ) {
			return false;
		}

		add_action( 'wp_mcp_ai_metric_recorded', array( $this, 'on_metric_recorded' ), 50, 3 );
		$this->attached = true;
		return true;
	}

	/**
	 * Detach hooks.
	 *
	 * @return void
	 */
	public function detach() {
		if ( ! $this->attached ) {
			return;
		}
		remove_action( 'wp_mcp_ai_metric_recorded', array( $this, 'on_metric_recorded' ), 50 );
		$this->attached = false;
		$this->buffer   = array();
	}

	/**
	 * Listener for `wp_mcp_ai_metric_recorded`. Appends to the
	 * per-request buffer; never touches the database here.
	 *
	 * @param array<string,mixed> $event      The buffered event.
	 * @param array<string,mixed> $definition The metric definition.
	 * @param mixed               $collector  Collector instance (unused).
	 * @return void
	 */
	public function on_metric_recorded( $event, $definition = null, $collector = null ) {
		unset( $definition, $collector );

		if ( ! is_array( $event ) ) {
			return;
		}

		// Barrier 1: Restricted tier never persisted.
		$privacy = isset( $event['privacy'] ) ? (string) $event['privacy'] : '';
		if ( MeasurementRegistry::PRIVACY_RESTRICTED === $privacy ) {
			return;
		}

		/**
		 * Per-event veto for persistence. Return false to skip writing
		 * this event to the custom table.
		 *
		 * @since 1.3.0
		 *
		 * @param bool                $should_persist Default true.
		 * @param array<string,mixed> $event          The buffered event.
		 */
		if ( ! apply_filters( 'wp_mcp_ai_persister_should_persist', true, $event ) ) {
			return;
		}

		$max = $this->resolve_buffer_max();
		if ( count( $this->buffer ) >= $max ) {
			// Buffer full — drop the new event rather than growing
			// unbounded. The in-memory collector still retains it.
			return;
		}

		$this->buffer[] = $event;

		if ( ! $this->shutdown_registered && function_exists( 'add_action' ) ) {
			$this->shutdown_registered = true;
			add_action( 'shutdown', array( $this, 'flush' ), 999 );
		}
	}

	/**
	 * Flush the buffer to the event store in one batched INSERT.
	 * Safe to call multiple times; empties the buffer each time.
	 *
	 * @return int Rows written.
	 */
	public function flush() {
		if ( array() === $this->buffer ) {
			return 0;
		}
		if ( ! class_exists( 'MetricEventStore' ) ) {
			$this->buffer = array();
			return 0;
		}

		$events       = $this->buffer;
		$this->buffer = array();

		$store = MetricEventStore::get_instance();
		return $store->insert_batch( $events );
	}

	/**
	 * Current in-memory buffer size. Diagnostic / tests only.
	 *
	 * @return int
	 */
	public function buffer_size() {
		return count( $this->buffer );
	}

	/**
	 * Resolve the buffer cap via filter with a sane floor.
	 *
	 * @return int
	 */
	private function resolve_buffer_max() {
		if ( null !== $this->buffer_max ) {
			return $this->buffer_max;
		}
		$filtered = apply_filters( 'wp_mcp_ai_persister_buffer_max', self::DEFAULT_BUFFER_MAX );
		$max      = is_numeric( $filtered ) ? (int) $filtered : self::DEFAULT_BUFFER_MAX;
		if ( $max < 1 ) {
			$max = self::DEFAULT_BUFFER_MAX;
		}
		$this->buffer_max = $max;
		return $this->buffer_max;
	}
}
