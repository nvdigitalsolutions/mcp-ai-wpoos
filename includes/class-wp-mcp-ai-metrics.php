<?php
/**
 * Metrics and observability for WP oOS.
 *
 * Provides counters and metrics for monitoring failures, timeouts,
 * and other operational metrics.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Metrics collector for observability.
 */
class WP_MCP_AI_Metrics {

	/**
	 * Transient prefix for storing metrics.
	 */
	const METRICS_PREFIX = 'wp_mcp_ai_metrics_';

	/**
	 * Metrics storage duration (24 hours).
	 */
	const METRICS_TTL = 86400;

	/**
	 * Metric categories.
	 */
	const CATEGORY_API_CALLS = 'api_calls';
	const CATEGORY_FAILURES  = 'failures';
	const CATEGORY_TIMEOUTS  = 'timeouts';
	const CATEGORY_RETRIES   = 'retries';
	const CATEGORY_CIRCUIT   = 'circuit_breaker';

	/**
	 * Increment a metric counter.
	 *
	 * @param string $category Metric category.
	 * @param string $metric   Metric name.
	 * @param int    $value    Value to increment by. Default 1.
	 * @param array  $tags     Optional tags for the metric.
	 */
	public static function increment( $category, $metric, $value = 1, array $tags = array() ) {
		$key     = self::get_metric_key( $category, $metric, $tags );
		$current = self::get_counter( $key );

		self::set_counter( $key, $current + absint( $value ) );

		// Log significant events.
		if ( self::CATEGORY_FAILURES === $category || self::CATEGORY_TIMEOUTS === $category ) {
			WP_MCP_AI_Logger::log_debug(
				sprintf( 'Metric incremented: %s/%s = %d', $category, $metric, $current + 1 ),
				array(
					'category' => $category,
					'metric'   => $metric,
					'tags'     => $tags,
				)
			);
		}
	}

	/**
	 * Record a timing metric.
	 *
	 * @param string $category Metric category.
	 * @param string $metric   Metric name.
	 * @param float  $duration Duration in seconds.
	 * @param array  $tags     Optional tags for the metric.
	 */
	public static function record_timing( $category, $metric, $duration, array $tags = array() ) {
		$key   = self::get_metric_key( $category, $metric . '_timing', $tags );
		$stats = self::get_timing_stats( $key );

		$stats['count']   = isset( $stats['count'] ) ? $stats['count'] + 1 : 1;
		$stats['total']   = isset( $stats['total'] ) ? $stats['total'] + $duration : $duration;
		$stats['min']     = isset( $stats['min'] ) ? min( $stats['min'], $duration ) : $duration;
		$stats['max']     = isset( $stats['max'] ) ? max( $stats['max'], $duration ) : $duration;
		$stats['average'] = $stats['total'] / $stats['count'];

		self::set_timing_stats( $key, $stats );
	}

	/**
	 * Get a counter value.
	 *
	 * @param string $category Metric category.
	 * @param string $metric   Metric name.
	 * @param array  $tags     Optional tags for the metric.
	 * @return int Counter value.
	 */
	public static function get( $category, $metric, array $tags = array() ) {
		$key = self::get_metric_key( $category, $metric, $tags );
		return self::get_counter( $key );
	}

	/**
	 * Get timing statistics.
	 *
	 * @param string $category Metric category.
	 * @param string $metric   Metric name.
	 * @param array  $tags     Optional tags for the metric.
	 * @return array Timing statistics.
	 */
	public static function get_timing( $category, $metric, array $tags = array() ) {
		$key = self::get_metric_key( $category, $metric . '_timing', $tags );
		return self::get_timing_stats( $key );
	}

	/**
	 * Get all metrics for a category.
	 *
	 * @param string $category Metric category.
	 * @return array All metrics in the category.
	 */
	public static function get_category_metrics( $category ) {
		global $wpdb;

		$prefix  = self::METRICS_PREFIX . sanitize_key( $category ) . '_';
		$pattern = '_transient_' . $prefix . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$pattern
			),
			ARRAY_A
		);

		$metrics = array();
		foreach ( $results as $row ) {
			$metric_key = str_replace( '_transient_' . $prefix, '', $row['option_name'] );
			$value      = maybe_unserialize( $row['option_value'] );

			$metrics[ $metric_key ] = $value;
		}

		return $metrics;
	}

	/**
	 * Get a summary of all metrics.
	 *
	 * @return array Metrics summary grouped by category.
	 */
	public static function get_metrics_summary() {
		return array(
			'api_calls'      => self::get_category_metrics( self::CATEGORY_API_CALLS ),
			'failures'       => self::get_category_metrics( self::CATEGORY_FAILURES ),
			'timeouts'       => self::get_category_metrics( self::CATEGORY_TIMEOUTS ),
			'retries'        => self::get_category_metrics( self::CATEGORY_RETRIES ),
			'circuit_breaker' => self::get_category_metrics( self::CATEGORY_CIRCUIT ),
		);
	}

	/**
	 * Reset all metrics.
	 *
	 * @param string $category Optional category to reset. If empty, resets all.
	 */
	public static function reset( $category = '' ) {
		global $wpdb;

		if ( ! empty( $category ) ) {
			$prefix  = self::METRICS_PREFIX . sanitize_key( $category ) . '_';
			$pattern = '_transient_' . $prefix . '%';
		} else {
			$pattern = '_transient_' . self::METRICS_PREFIX . '%';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$pattern
			)
		);

		// Also delete timeout transients.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				'_transient_timeout' . $pattern
			)
		);
	}

	/**
	 * Generate a metric key.
	 *
	 * @param string $category Metric category.
	 * @param string $metric   Metric name.
	 * @param array  $tags     Optional tags.
	 * @return string Metric key.
	 */
	protected static function get_metric_key( $category, $metric, array $tags = array() ) {
		$key_parts = array(
			sanitize_key( $category ),
			sanitize_key( $metric ),
		);

		if ( ! empty( $tags ) ) {
			ksort( $tags );
			foreach ( $tags as $tag_key => $tag_value ) {
				$key_parts[] = sanitize_key( $tag_key ) . '_' . sanitize_key( $tag_value );
			}
		}

		return implode( '_', $key_parts );
	}

	/**
	 * Get counter value from storage.
	 *
	 * @param string $key Metric key.
	 * @return int Counter value.
	 */
	protected static function get_counter( $key ) {
		$transient_key = self::METRICS_PREFIX . $key;
		$value         = get_transient( $transient_key );

		return is_numeric( $value ) ? absint( $value ) : 0;
	}

	/**
	 * Set counter value in storage.
	 *
	 * @param string $key   Metric key.
	 * @param int    $value Counter value.
	 */
	protected static function set_counter( $key, $value ) {
		$transient_key = self::METRICS_PREFIX . $key;
		set_transient( $transient_key, absint( $value ), self::METRICS_TTL );
	}

	/**
	 * Get timing statistics from storage.
	 *
	 * @param string $key Metric key.
	 * @return array Timing statistics.
	 */
	protected static function get_timing_stats( $key ) {
		$transient_key = self::METRICS_PREFIX . $key;
		$stats         = get_transient( $transient_key );

		return is_array( $stats ) ? $stats : array();
	}

	/**
	 * Set timing statistics in storage.
	 *
	 * @param string $key   Metric key.
	 * @param array  $stats Timing statistics.
	 */
	protected static function set_timing_stats( $key, $stats ) {
		$transient_key = self::METRICS_PREFIX . $key;
		set_transient( $transient_key, $stats, self::METRICS_TTL );
	}

	/**
	 * Helper: Record API call metrics.
	 *
	 * @param string $provider     Provider name.
	 * @param string $endpoint     Endpoint name.
	 * @param bool   $success      Whether the call succeeded.
	 * @param float  $duration     Call duration in seconds.
	 * @param array  $error_info   Error information if failed.
	 */
	public static function record_api_call( $provider, $endpoint, $success, $duration, $error_info = array() ) {
		$tags = array(
			'provider' => $provider,
			'endpoint' => $endpoint,
		);

		// Total calls.
		self::increment( self::CATEGORY_API_CALLS, 'total', 1, $tags );

		// Success or failure.
		if ( $success ) {
			self::increment( self::CATEGORY_API_CALLS, 'success', 1, $tags );
		} else {
			self::increment( self::CATEGORY_FAILURES, 'total', 1, $tags );

			// Categorize failure type.
			if ( isset( $error_info['type'] ) ) {
				self::increment( self::CATEGORY_FAILURES, $error_info['type'], 1, $tags );
			}
		}

		// Record timing.
		self::record_timing( self::CATEGORY_API_CALLS, $endpoint, $duration, $tags );
	}

	/**
	 * Helper: Record timeout event.
	 *
	 * @param string $provider Provider name.
	 * @param string $endpoint Endpoint name.
	 */
	public static function record_timeout( $provider, $endpoint ) {
		$tags = array(
			'provider' => $provider,
			'endpoint' => $endpoint,
		);

		self::increment( self::CATEGORY_TIMEOUTS, 'total', 1, $tags );
	}

	/**
	 * Helper: Record retry event.
	 *
	 * @param string $provider Provider name.
	 * @param int    $attempt  Retry attempt number.
	 */
	public static function record_retry( $provider, $attempt ) {
		$tags = array( 'provider' => $provider );

		self::increment( self::CATEGORY_RETRIES, 'total', 1, $tags );
		self::increment( self::CATEGORY_RETRIES, 'attempt_' . $attempt, 1, $tags );
	}

	/**
	 * Helper: Record circuit breaker state change.
	 *
	 * @param string $provider Provider name.
	 * @param string $state    New circuit state.
	 */
	public static function record_circuit_state( $provider, $state ) {
		$tags = array( 'provider' => $provider );

		self::increment( self::CATEGORY_CIRCUIT, 'state_change', 1, $tags );
		self::increment( self::CATEGORY_CIRCUIT, $state, 1, $tags );
	}
}
