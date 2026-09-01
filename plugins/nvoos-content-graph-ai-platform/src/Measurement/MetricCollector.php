<?php
/**
 * Metric Collector
 *
 * Ingests metric values from plugin hooks and fans them out via the
 * `wp_mcp_ai_metric_recorded` action and the `wp_mcp_ai_measurement_export`
 * filter. Storage is intentionally pluggable — this core class buffers
 * in-memory only, so it is safe to load on every request. Persistent storage
 * (custom table, OTel exporter, APM bridges) is provided by later PRs and
 * attaches via the filter.
 *
 * Sampling uses a deterministic hash of the metric id + salt + bucket so the
 * same event is either always sampled in or always sampled out on a given
 * request, which keeps counter-metric pairing consistent.
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
 * Metric Collector singleton.
 */
class MetricCollector {

	/**
	 * Default reservoir buffer size. The buffer is kept in memory only; when
	 * the limit is reached the oldest entries are dropped (FIFO ring buffer).
	 */
	const DEFAULT_BUFFER_SIZE = 1024;

	/**
	 * Singleton instance.
	 *
	 * @var MetricCollector|null
	 */
	private static $instance = null;

	/**
	 * The metric registry used to validate recorded metrics.
	 *
	 * @var MeasurementRegistry
	 */
	private $registry;

	/**
	 * Ring buffer of recorded events.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $buffer = array();

	/**
	 * Maximum buffer size.
	 *
	 * @var int
	 */
	private $buffer_size;

	/**
	 * Default sample rate (0.0 - 1.0).
	 *
	 * @var float
	 */
	private $sample_rate = 1.0;

	/**
	 * Per-metric sample rate overrides.
	 *
	 * @var array<string,float>
	 */
	private $sample_rates = array();

	/**
	 * Private constructor.
	 *
	 * @param MeasurementRegistry|null $registry Optional registry override (for tests).
	 */
	private function __construct( $registry = null ) {
		$this->registry    = $registry instanceof MeasurementRegistry
			? $registry
			: MeasurementRegistry::get_instance();
		$this->buffer_size = self::DEFAULT_BUFFER_SIZE;
	}

	/**
	 * Get the singleton instance.
	 *
	 * @return MetricCollector
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Reset the singleton. Intended for tests only.
	 *
	 * @return void
	 */
	public static function reset_instance() {
		self::$instance = null;
	}

	/**
	 * Override the maximum in-memory buffer size.
	 *
	 * @param int $size Buffer size (minimum 1).
	 * @return void
	 */
	public function set_buffer_size( $size ) {
		$size              = max( 1, (int) $size );
		$this->buffer_size = $size;
		if ( count( $this->buffer ) > $this->buffer_size ) {
			$this->buffer = array_slice( $this->buffer, -$this->buffer_size );
		}
	}

	/**
	 * Set the default sample rate.
	 *
	 * @param float $rate Rate in the inclusive range [0.0, 1.0].
	 * @return void
	 */
	public function set_sample_rate( $rate ) {
		$rate              = (float) $rate;
		$this->sample_rate = max( 0.0, min( 1.0, $rate ) );
	}

	/**
	 * Set a sample rate override for a specific metric id.
	 *
	 * @param string $metric_id Metric id.
	 * @param float  $rate      Sample rate [0.0, 1.0].
	 * @return void
	 */
	public function set_metric_sample_rate( $metric_id, $rate ) {
		$metric_id = is_string( $metric_id ) ? strtolower( trim( $metric_id ) ) : '';
		if ( '' === $metric_id ) {
			return;
		}
		$rate                             = max( 0.0, min( 1.0, (float) $rate ) );
		$this->sample_rates[ $metric_id ] = $rate;
	}

	/**
	 * Record a metric observation.
	 *
	 * Returns true if the event was kept (recorded in the buffer and fanned
	 * out via hooks). Returns false if it was dropped — either because the
	 * metric id is unknown, the value is invalid, or sampling excluded it.
	 *
	 * The $context array may include:
	 *   - assistant_id  (int)    Associated assistant post id.
	 *   - model         (string) Model identifier (e.g. `gpt-4o-mini`).
	 *   - provider      (string) Provider identifier (e.g. `openai`).
	 *   - tool          (string) Tool slug.
	 *   - user_id       (int)    WordPress user id, 0 for guest.
	 *   - session_id    (string) Chat/eval session id.
	 *   - profession    (string) Profession/playbook label.
	 *   - attributes    (array)  Arbitrary structured attributes.
	 *
	 * Context values are sanitized before storage. Do not pass raw prompt or
	 * user content here — use attribute hashes per privacy policy.
	 *
	 * @param string $metric_id Metric id.
	 * @param float  $value     Observed numeric value.
	 * @param array  $context   Optional context attributes.
	 * @return bool
	 */
	public function record( $metric_id, $value, array $context = array() ) {
		$metric_id = is_string( $metric_id ) ? strtolower( trim( $metric_id ) ) : '';
		if ( '' === $metric_id ) {
			return false;
		}
		if ( ! is_numeric( $value ) ) {
			return false;
		}
		$value = (float) $value;

		$definition = $this->registry->get( $metric_id );
		if ( null === $definition ) {
			return false;
		}

		if ( ! $this->should_sample( $metric_id ) ) {
			return false;
		}

		$event = array(
			'id'        => $metric_id,
			'value'     => $value,
			'type'      => $definition['type'],
			'unit'      => $definition['unit'],
			'privacy'   => $definition['privacy_tier'],
			'timestamp' => time(),
			'context'   => $this->sanitize_context( $context ),
		);

		$this->push_buffer( $event );

		/**
		 * Fires after a metric has been recorded and buffered.
		 *
		 * Consumers (custom table persister, OTel exporter, APM bridges)
		 * attach here. This hook is fired synchronously — listeners must be
		 * fast and must not raise. Listeners that need to send to external
		 * systems should queue work for async processing.
		 *
		 * @since 1.3.0
		 *
		 * @param array                          $event      Recorded event.
		 * @param array                          $definition Metric definition.
		 * @param MetricCollector     $collector  Collector instance.
		 */
		do_action( 'wp_mcp_ai_metric_recorded', $event, $definition, $this );

		return true;
	}

	/**
	 * Push an event into the ring buffer, evicting the oldest if needed.
	 *
	 * @param array $event Event.
	 * @return void
	 */
	private function push_buffer( array $event ) {
		$this->buffer[] = $event;
		$overflow       = count( $this->buffer ) - $this->buffer_size;
		if ( $overflow > 0 ) {
			$this->buffer = array_slice( $this->buffer, $overflow );
		}
	}

	/**
	 * Snapshot of the current in-memory buffer.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function buffered() {
		return $this->buffer;
	}

	/**
	 * Clear the in-memory buffer. Useful for tests and post-export cleanup.
	 *
	 * @return void
	 */
	public function clear_buffer() {
		$this->buffer = array();
	}

	/**
	 * Export the buffered events through the `wp_mcp_ai_measurement_export`
	 * filter, giving APM bridges (Datadog, Honeycomb, Grafana, etc.) a single
	 * seam to intercept. Returns the filtered payload.
	 *
	 * Exporters are responsible for redaction beyond what the privacy tier
	 * already provides. Core does NOT include raw prompts, raw tool arguments,
	 * or user content in events — only hashes. Export payloads still pass
	 * through this filter so site owners can add custom allow-lists.
	 *
	 * @param string $destination Optional destination identifier (e.g. `otel`).
	 * @return array<int,array<string,mixed>>
	 */
	public function export( $destination = 'default' ) {
		$destination = is_string( $destination ) ? sanitize_key( $destination ) : 'default';
		$payload     = $this->buffer;

		/**
		 * Filters the measurement payload before export.
		 *
		 * @since 1.3.0
		 *
		 * @param array  $payload     Sequence of recorded events.
		 * @param string $destination Destination identifier.
		 */
		$payload = apply_filters( 'wp_mcp_ai_measurement_export', $payload, $destination );

		return is_array( $payload ) ? $payload : array();
	}

	/**
	 * Deterministic sampling decision for a metric id on this request.
	 *
	 * @param string $metric_id Metric id.
	 * @return bool
	 */
	private function should_sample( $metric_id ) {
		$rate = isset( $this->sample_rates[ $metric_id ] )
			? $this->sample_rates[ $metric_id ]
			: $this->sample_rate;

		if ( $rate >= 1.0 ) {
			return true;
		}
		if ( $rate <= 0.0 ) {
			return false;
		}

		// Deterministic within a single PHP request so paired counter-metrics
		// are either both kept or both dropped. Use 7 hex chars (28 bits) to
		// avoid 32-bit hexdec overflow.
		static $request_salt = null;
		if ( null === $request_salt ) {
			$request_salt = wp_generate_password( 12, false, false );
		}

		$hash   = hexdec( substr( md5( $metric_id . '|' . $request_salt ), 0, 7 ) );
		$bucket = ( $hash % 10000 ) / 10000.0;
		return $bucket < $rate;
	}

	/**
	 * Sanitize the context array to prevent leakage of unexpected data types.
	 *
	 * @param array $context Raw context array.
	 * @return array
	 */
	private function sanitize_context( array $context ) {
		$out = array();

		if ( isset( $context['assistant_id'] ) ) {
			$out['assistant_id'] = absint( $context['assistant_id'] );
		}
		if ( isset( $context['user_id'] ) ) {
			$out['user_id'] = absint( $context['user_id'] );
		}
		foreach ( array( 'model', 'provider', 'tool', 'session_id', 'profession' ) as $key ) {
			if ( isset( $context[ $key ] ) && is_scalar( $context[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( (string) $context[ $key ] );
			}
		}

		if ( isset( $context['attributes'] ) && is_array( $context['attributes'] ) ) {
			$attrs = array();
			foreach ( $context['attributes'] as $k => $v ) {
				if ( ! is_scalar( $k ) ) {
					continue;
				}
				$key = sanitize_key( (string) $k );
				if ( '' === $key ) {
					continue;
				}
				if ( is_scalar( $v ) ) {
					$attrs[ $key ] = sanitize_text_field( (string) $v );
				}
			}
			$out['attributes'] = $attrs;
		}

		return $out;
	}
}
