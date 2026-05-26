<?php
/**
 * OpenTelemetry JSON Exporter
 *
 * Dependency-free bridge that serializes buffered metric events into an
 * OTLP/JSON-shaped payload. This exporter deliberately does NOT require
 * the opentelemetry-php SDK: shipping the SDK in a WordPress plugin adds
 * significant weight and a PHP 8+ requirement that would break the base
 * plugin's PHP 7.4 floor. The output is the plain OTLP/JSON shape an
 * OpenTelemetry Collector accepts over HTTP, so sites that want full SDK
 * integration can drop this exporter's payload straight into one.
 *
 * What the exporter guarantees:
 *  - Payload is serializable (no objects, no resources, no closures).
 *  - No raw prompts / tool arguments / user content in the payload — only
 *    the sanitized context the collector already stored.
 *  - A redaction filter (`wp_mcp_ai_otel_redact`) runs over every data
 *    point so operators can strip or hash values before transport.
 *  - A rolling, size-capped buffer is persisted to a site option so sites
 *    without an APM agent can still inspect the last N events across
 *    requests. The buffer is NOT autoloaded and is bounded.
 *
 * What the exporter does NOT do:
 *  - Send data over the wire. Transport is deliberately the operator's
 *    choice; hook `wp_mcp_ai_otel_payload_ready` to ship it.
 *  - Provide histograms or exponential-histogram aggregation. Gauge /
 *    sum / rate data points are enough for the current catalogue; a
 *    future PR can add aggregation if we grow histograms in-core.
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
 * OTel JSON Exporter.
 */
class WP_MCP_AI_OTel_Exporter {

	/**
	 * Rolling buffer option key.
	 */
	const BUFFER_OPTION = 'wp_mcp_ai_otel_rolling_buffer';

	/**
	 * Default maximum events retained in the persistent buffer.
	 */
	const DEFAULT_BUFFER_MAX = 512;

	/**
	 * Destination identifier passed to the measurement export filter.
	 */
	const DESTINATION = 'otel_json';

	/**
	 * Collector.
	 *
	 * @var WP_MCP_AI_Metric_Collector
	 */
	private $collector;

	/**
	 * Measurement registry.
	 *
	 * @var WP_MCP_AI_Measurement_Registry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_Metric_Collector|null     $collector Collector.
	 * @param WP_MCP_AI_Measurement_Registry|null $registry  Registry.
	 */
	public function __construct( $collector = null, $registry = null ) {
		$this->collector = $collector instanceof WP_MCP_AI_Metric_Collector
			? $collector
			: WP_MCP_AI_Metric_Collector::get_instance();
		$this->registry  = $registry instanceof WP_MCP_AI_Measurement_Registry
			? $registry
			: WP_MCP_AI_Measurement_Registry::get_instance();
	}

	/**
	 * Build an OTLP/JSON payload from the currently buffered events.
	 *
	 * @param array $options Options: `service_name`, `service_version`,
	 *                       `deployment_environment`, `resource_attributes`.
	 * @return array
	 */
	public function build_payload( array $options = array() ) {
		$events = $this->collector->export( self::DESTINATION );
		return $this->serialize_events( is_array( $events ) ? $events : array(), $options );
	}

	/**
	 * Serialize an explicit sequence of events.
	 *
	 * @param array $events  Collector events (as returned by `buffered()`).
	 * @param array $options Options (see {@see self::build_payload()}).
	 * @return array
	 */
	public function serialize_events( array $events, array $options = array() ) {
		$resource_attrs = $this->build_resource_attributes( $options );

		$data_points_by_metric = array();
		$now_nanos             = $this->now_unix_nanos();

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) || ! isset( $event['id'], $event['value'] ) ) {
				continue;
			}
			$event = $this->redact( $event );
			if ( null === $event ) {
				continue;
			}

			$metric_id = (string) $event['id'];
			if ( ! isset( $data_points_by_metric[ $metric_id ] ) ) {
				$data_points_by_metric[ $metric_id ] = array(
					'definition'  => $this->registry->get( $metric_id ),
					'event_type'  => isset( $event['type'] ) ? (string) $event['type'] : 'gauge',
					'unit'        => isset( $event['unit'] ) ? (string) $event['unit'] : '',
					'data_points' => array(),
				);
			}

			$timestamp_nanos = isset( $event['timestamp'] )
				? ( (int) $event['timestamp'] * 1000000000 )
				: $now_nanos;

			$data_points_by_metric[ $metric_id ]['data_points'][] = array(
				'attributes'        => $this->flatten_attributes( isset( $event['context'] ) ? $event['context'] : array() ),
				'startTimeUnixNano' => (string) $timestamp_nanos,
				'timeUnixNano'      => (string) $timestamp_nanos,
				'asDouble'          => (float) $event['value'],
			);
		}

		$metrics = array();
		foreach ( $data_points_by_metric as $metric_id => $bucket ) {
			$definition = $bucket['definition'];
			$unit       = $bucket['unit'];
			$kind       = isset( $definition['type'] ) ? (string) $definition['type'] : $bucket['event_type'];

			$metric = array(
				'name'        => $metric_id,
				'unit'        => $unit,
				'description' => isset( $definition['description'] ) ? (string) $definition['description'] : '',
			);

			switch ( $kind ) {
				case 'counter':
				case 'rate':
					$metric['sum'] = array(
						'aggregationTemporality' => 2, // AGGREGATION_TEMPORALITY_CUMULATIVE.
						'isMonotonic'            => true,
						'dataPoints'             => $bucket['data_points'],
					);
					break;
				case 'histogram':
					// We don't aggregate into buckets here — downstream may.
					$metric['gauge'] = array(
						'dataPoints' => $bucket['data_points'],
					);
					break;
				case 'gauge':
				default:
					$metric['gauge'] = array(
						'dataPoints' => $bucket['data_points'],
					);
					break;
			}

			$metrics[] = $metric;
		}

		$payload = array(
			'resourceMetrics' => array(
				array(
					'resource'     => array(
						'attributes' => $resource_attrs,
					),
					'scopeMetrics' => array(
						array(
							'scope'   => array(
								'name'    => 'wp-mcp-ai',
								'version' => defined( 'WP_MCP_AI_VERSION' ) ? (string) WP_MCP_AI_VERSION : '',
							),
							'metrics' => $metrics,
						),
					),
				),
			),
		);

		/**
		 * Filters the OTLP/JSON payload before it is dispatched to
		 * `wp_mcp_ai_otel_payload_ready`. Use this to inject extra
		 * resource attributes, re-bucket metrics, or strip data points
		 * that shouldn't leave the site.
		 *
		 * @since 1.3.0
		 *
		 * @param array $payload OTLP payload.
		 * @param array $events  Source events.
		 * @param array $options Serializer options.
		 */
		$payload = apply_filters( 'wp_mcp_ai_otel_payload', $payload, $events, $options );

		return is_array( $payload ) ? $payload : array();
	}

	/**
	 * Convenience: build the payload, fire the ready hook, and push the
	 * events into the rolling buffer. Returns the payload so callers can
	 * serialize it themselves.
	 *
	 * @param array $options Options.
	 * @return array
	 */
	public function dispatch( array $options = array() ) {
		$events  = $this->collector->buffered();
		$payload = $this->serialize_events( $events, $options );

		/**
		 * Fires after the exporter has built a payload. Transport layers
		 * (HTTP, file write, Logstash) subscribe here.
		 *
		 * @since 1.3.0
		 *
		 * @param array $payload OTLP payload.
		 * @param array $events  Source events.
		 * @param array $options Serializer options.
		 */
		do_action( 'wp_mcp_ai_otel_payload_ready', $payload, $events, $options );

		$this->append_rolling_buffer( $events );
		return $payload;
	}

	/**
	 * Append to the rolling persistent buffer (size-capped, non-autoload).
	 *
	 * @param array $events Events.
	 * @return void
	 */
	public function append_rolling_buffer( array $events ) {
		if ( empty( $events ) || ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}
		$max = (int) apply_filters( 'wp_mcp_ai_otel_buffer_max', self::DEFAULT_BUFFER_MAX );
		if ( $max <= 0 ) {
			return;
		}
		$existing = get_option( self::BUFFER_OPTION, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		// Redact before storage so the rolling buffer never holds anything
		// the operator filtered out.
		foreach ( $events as $e ) {
			$redacted = $this->redact( $e );
			if ( null !== $redacted ) {
				$existing[] = $redacted;
			}
		}
		$overflow = count( $existing ) - $max;
		if ( $overflow > 0 ) {
			$existing = array_slice( $existing, $overflow );
		}
		update_option( self::BUFFER_OPTION, $existing, false );
	}

	/**
	 * Read the rolling buffer.
	 *
	 * @param int $limit Max events.
	 * @return array
	 */
	public function read_rolling_buffer( $limit = 0 ) {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}
		$data = get_option( self::BUFFER_OPTION, array() );
		if ( ! is_array( $data ) ) {
			return array();
		}
		$limit = (int) $limit;
		if ( $limit > 0 && count( $data ) > $limit ) {
			$data = array_slice( $data, -1 * $limit );
		}
		return $data;
	}

	/**
	 * Clear the rolling buffer.
	 *
	 * @return void
	 */
	public function clear_rolling_buffer() {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::BUFFER_OPTION );
		}
	}

	/**
	 * Build resource attributes. OTLP resource attributes are "what
	 * produced these metrics" (service name, environment, host).
	 *
	 * @param array $options Options.
	 * @return array
	 */
	private function build_resource_attributes( array $options ) {
		$attrs = array(
			'service.name'    => isset( $options['service_name'] ) ? (string) $options['service_name'] : 'wp-mcp-ai',
			'service.version' => isset( $options['service_version'] )
				? (string) $options['service_version']
				: ( defined( 'WP_MCP_AI_VERSION' ) ? (string) WP_MCP_AI_VERSION : '' ),
		);
		if ( ! empty( $options['deployment_environment'] ) ) {
			$attrs['deployment.environment'] = (string) $options['deployment_environment'];
		}
		if ( ! empty( $options['resource_attributes'] ) && is_array( $options['resource_attributes'] ) ) {
			foreach ( $options['resource_attributes'] as $k => $v ) {
				if ( is_string( $k ) && is_scalar( $v ) ) {
					$attrs[ $k ] = (string) $v;
				}
			}
		}
		return $this->flatten_attributes( $attrs );
	}

	/**
	 * Flatten a PHP assoc array into OTLP attribute list shape.
	 *
	 * @param array $attrs Attributes.
	 * @return array
	 */
	private function flatten_attributes( array $attrs ) {
		$out = array();
		foreach ( $attrs as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}
			if ( is_array( $value ) ) {
				// Recursively flatten one level using dotted keys — OTLP's
				// attribute values don't accept nested maps natively.
				foreach ( $value as $sub_k => $sub_v ) {
					if ( ! is_string( $sub_k ) ) {
						continue;
					}
					$out[] = $this->attribute_entry( $key . '.' . $sub_k, $sub_v );
				}
				continue;
			}
			$out[] = $this->attribute_entry( $key, $value );
		}
		return array_values( array_filter( $out ) );
	}

	/**
	 * Build a single OTLP attribute entry.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Scalar value.
	 * @return array|null
	 */
	private function attribute_entry( $key, $value ) {
		if ( is_bool( $value ) ) {
			return array(
				'key'   => $key,
				'value' => array( 'boolValue' => $value ),
			);
		}
		if ( is_int( $value ) ) {
			return array(
				'key'   => $key,
				'value' => array( 'intValue' => (string) $value ),
			);
		}
		if ( is_float( $value ) ) {
			return array(
				'key'   => $key,
				'value' => array( 'doubleValue' => $value ),
			);
		}
		if ( is_string( $value ) ) {
			return array(
				'key'   => $key,
				'value' => array( 'stringValue' => $value ),
			);
		}
		return null;
	}

	/**
	 * Apply the redaction filter to a single event.
	 *
	 * Returning `null` from the filter drops the event entirely — useful
	 * when a deployment wants to block a metric id from ever leaving.
	 *
	 * @param array $event Event.
	 * @return array|null
	 */
	private function redact( array $event ) {
		/**
		 * Filter a single metric event before it is serialized or buffered.
		 *
		 * Return `null` to drop the event.
		 *
		 * @since 1.3.0
		 *
		 * @param array $event Event.
		 */
		$event = apply_filters( 'wp_mcp_ai_otel_redact', $event );
		return is_array( $event ) ? $event : null;
	}

	/**
	 * Current time in Unix nanoseconds (string-safe for 32-bit systems).
	 *
	 * @return int
	 */
	private function now_unix_nanos() {
		$parts   = explode( ' ', microtime() );
		$micro   = isset( $parts[0] ) ? (float) $parts[0] : 0.0;
		$seconds = isset( $parts[1] ) ? (int) $parts[1] : time();
		return ( $seconds * 1000000000 ) + (int) round( $micro * 1000000000 );
	}
}
