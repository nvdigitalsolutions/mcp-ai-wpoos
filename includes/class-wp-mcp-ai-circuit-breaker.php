<?php
/**
 * Circuit Breaker Pattern for Provider Health Tracking.
 *
 * Implements the circuit breaker pattern to prevent cascading failures
 * when AI providers are experiencing issues. Tracks provider health
 * and temporarily stops sending requests to failing providers.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Circuit Breaker for AI provider health tracking.
 *
 * States:
 * - CLOSED: Normal operation, requests pass through
 * - OPEN: Too many failures, requests blocked
 * - HALF_OPEN: Testing if provider has recovered
 */
class WP_MCP_AI_Circuit_Breaker {

	/**
	 * Circuit state: Closed (normal operation).
	 */
	const STATE_CLOSED = 'closed';

	/**
	 * Circuit state: Open (blocking requests).
	 */
	const STATE_OPEN = 'open';

	/**
	 * Circuit state: Half-open (testing recovery).
	 */
	const STATE_HALF_OPEN = 'half_open';

	/**
	 * Transient prefix for storing circuit state.
	 */
	const STATE_PREFIX = 'wp_mcp_ai_circuit_';

	/**
	 * Default failure threshold before opening circuit.
	 */
	const DEFAULT_FAILURE_THRESHOLD = 5;

	/**
	 * Default success threshold for closing circuit from half-open.
	 */
	const DEFAULT_SUCCESS_THRESHOLD = 2;

	/**
	 * Default timeout before transitioning from open to half-open (seconds).
	 */
	const DEFAULT_TIMEOUT = 60;

	/**
	 * Default window size for tracking failures (seconds).
	 */
	const DEFAULT_WINDOW_SIZE = 300; // 5 minutes.

	/**
	 * Check if a provider is available (circuit is closed or half-open).
	 *
	 * @param string $provider Provider identifier (openai, gemini, etc.).
	 * @return bool True if provider is available, false if circuit is open.
	 */
	public static function is_available( $provider ) {
		$state = self::get_circuit_state( $provider );

		// If circuit is open, check if timeout has elapsed.
		if ( self::STATE_OPEN === $state['state'] ) {
			$timeout = self::get_timeout( $provider );
			if ( time() - $state['opened_at'] >= $timeout ) {
				// Transition to half-open.
				self::set_circuit_state(
					$provider,
					array(
						'state'       => self::STATE_HALF_OPEN,
						'opened_at'   => $state['opened_at'],
						'half_open_at' => time(),
						'successes'   => 0,
					)
				);
				return true;
			}

			WP_MCP_AI_Logger::log_debug(
				sprintf( 'Circuit breaker for %s is OPEN, blocking request.', $provider ),
				array( 'provider' => $provider )
			);

			return false;
		}

		return true;
	}

	/**
	 * Record a successful request.
	 *
	 * @param string $provider Provider identifier.
	 */
	public static function record_success( $provider ) {
		$state = self::get_circuit_state( $provider );

		if ( self::STATE_HALF_OPEN === $state['state'] ) {
			$successes      = isset( $state['successes'] ) ? absint( $state['successes'] ) + 1 : 1;
			$success_threshold = self::get_success_threshold( $provider );

			if ( $successes >= $success_threshold ) {
				// Close the circuit.
				self::reset_circuit( $provider );

				WP_MCP_AI_Logger::log_event(
					'circuit_breaker_closed',
					sprintf( 'Circuit breaker for %s transitioned to CLOSED after recovery.', $provider ),
					array(
						'provider'  => $provider,
						'successes' => $successes,
					)
				);
			} else {
				// Update success count.
				self::set_circuit_state(
					$provider,
					array_merge(
						$state,
						array( 'successes' => $successes )
					)
				);
			}
		} elseif ( self::STATE_CLOSED === $state['state'] ) {
			// Reset failure count on success.
			self::clear_failures( $provider );
		}
	}

	/**
	 * Record a failed request.
	 *
	 * @param string $provider Provider identifier.
	 * @param array  $error    Error information.
	 */
	public static function record_failure( $provider, $error = array() ) {
		$state = self::get_circuit_state( $provider );

		if ( self::STATE_HALF_OPEN === $state['state'] ) {
			// Any failure in half-open state reopens the circuit.
			self::open_circuit( $provider, $error );
			return;
		}

		if ( self::STATE_CLOSED === $state['state'] ) {
			// Increment failure count.
			$failures          = self::get_failures( $provider );
			$failures[]        = array(
				'timestamp' => time(),
				'error'     => is_array( $error ) ? $error : array( 'message' => (string) $error ),
			);
			$failure_threshold = self::get_failure_threshold( $provider );

			// Clean old failures outside the window.
			$window_size = self::get_window_size( $provider );
			$cutoff_time = time() - $window_size;
			$failures    = array_filter(
				$failures,
				function ( $failure ) use ( $cutoff_time ) {
					return isset( $failure['timestamp'] ) && $failure['timestamp'] >= $cutoff_time;
				}
			);

			self::store_failures( $provider, $failures );

			// Check if threshold is reached.
			if ( count( $failures ) >= $failure_threshold ) {
				self::open_circuit( $provider, $error );
			}
		}
	}

	/**
	 * Open the circuit (block requests to failing provider).
	 *
	 * @param string $provider Provider identifier.
	 * @param array  $error    Error information.
	 */
	protected static function open_circuit( $provider, $error = array() ) {
		self::set_circuit_state(
			$provider,
			array(
				'state'     => self::STATE_OPEN,
				'opened_at' => time(),
				'error'     => $error,
			)
		);

		WP_MCP_AI_Logger::log_warning(
			sprintf( 'Circuit breaker for %s transitioned to OPEN due to failures.', $provider ),
			array(
				'provider' => $provider,
				'error'    => $error,
			)
		);
	}

	/**
	 * Reset the circuit (close it).
	 *
	 * @param string $provider Provider identifier.
	 */
	public static function reset_circuit( $provider ) {
		self::clear_circuit_state( $provider );
		self::clear_failures( $provider );
	}

	/**
	 * Get circuit state for a provider.
	 *
	 * @param string $provider Provider identifier.
	 * @return array Circuit state data.
	 */
	protected static function get_circuit_state( $provider ) {
		$transient_key = self::STATE_PREFIX . sanitize_key( $provider );
		$state         = get_transient( $transient_key );

		if ( false === $state || ! is_array( $state ) ) {
			return array( 'state' => self::STATE_CLOSED );
		}

		return $state;
	}

	/**
	 * Set circuit state for a provider.
	 *
	 * @param string $provider Provider identifier.
	 * @param array  $state    State data.
	 */
	protected static function set_circuit_state( $provider, $state ) {
		$transient_key = self::STATE_PREFIX . sanitize_key( $provider );
		$timeout       = self::get_timeout( $provider );

		set_transient( $transient_key, $state, $timeout * 2 );
	}

	/**
	 * Clear circuit state for a provider.
	 *
	 * @param string $provider Provider identifier.
	 */
	protected static function clear_circuit_state( $provider ) {
		$transient_key = self::STATE_PREFIX . sanitize_key( $provider );
		delete_transient( $transient_key );
	}

	/**
	 * Get failures for a provider.
	 *
	 * @param string $provider Provider identifier.
	 * @return array Array of failure records.
	 */
	protected static function get_failures( $provider ) {
		$transient_key = self::STATE_PREFIX . 'failures_' . sanitize_key( $provider );
		$failures      = get_transient( $transient_key );

		return is_array( $failures ) ? $failures : array();
	}

	/**
	 * Store failures for a provider.
	 *
	 * @param string $provider Provider identifier.
	 * @param array  $failures Failure records.
	 */
	protected static function store_failures( $provider, $failures ) {
		$transient_key = self::STATE_PREFIX . 'failures_' . sanitize_key( $provider );
		$window_size   = self::get_window_size( $provider );

		set_transient( $transient_key, $failures, $window_size );
	}

	/**
	 * Clear failures for a provider.
	 *
	 * @param string $provider Provider identifier.
	 */
	protected static function clear_failures( $provider ) {
		$transient_key = self::STATE_PREFIX . 'failures_' . sanitize_key( $provider );
		delete_transient( $transient_key );
	}

	/**
	 * Get failure threshold for a provider.
	 *
	 * @param string $provider Provider identifier.
	 * @return int Failure threshold.
	 */
	protected static function get_failure_threshold( $provider ) {
		/**
		 * Filter the failure threshold before circuit opens.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $threshold Failure threshold. Default 5.
		 * @param string $provider  Provider identifier.
		 */
		return apply_filters(
			'wp_mcp_ai_circuit_breaker_failure_threshold',
			self::DEFAULT_FAILURE_THRESHOLD,
			$provider
		);
	}

	/**
	 * Get success threshold for closing circuit from half-open.
	 *
	 * @param string $provider Provider identifier.
	 * @return int Success threshold.
	 */
	protected static function get_success_threshold( $provider ) {
		/**
		 * Filter the success threshold for closing circuit.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $threshold Success threshold. Default 2.
		 * @param string $provider  Provider identifier.
		 */
		return apply_filters(
			'wp_mcp_ai_circuit_breaker_success_threshold',
			self::DEFAULT_SUCCESS_THRESHOLD,
			$provider
		);
	}

	/**
	 * Get timeout before transitioning from open to half-open.
	 *
	 * @param string $provider Provider identifier.
	 * @return int Timeout in seconds.
	 */
	protected static function get_timeout( $provider ) {
		/**
		 * Filter the circuit breaker timeout.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $timeout  Timeout in seconds. Default 60.
		 * @param string $provider Provider identifier.
		 */
		return apply_filters(
			'wp_mcp_ai_circuit_breaker_timeout',
			self::DEFAULT_TIMEOUT,
			$provider
		);
	}

	/**
	 * Get window size for tracking failures.
	 *
	 * @param string $provider Provider identifier.
	 * @return int Window size in seconds.
	 */
	protected static function get_window_size( $provider ) {
		/**
		 * Filter the circuit breaker window size.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $window_size Window size in seconds. Default 300 (5 minutes).
		 * @param string $provider    Provider identifier.
		 */
		return apply_filters(
			'wp_mcp_ai_circuit_breaker_window_size',
			self::DEFAULT_WINDOW_SIZE,
			$provider
		);
	}

	/**
	 * Get circuit breaker health metrics for a provider.
	 *
	 * @param string $provider Provider identifier.
	 * @return array Health metrics including state, failure count, etc.
	 */
	public static function get_health_metrics( $provider ) {
		$state    = self::get_circuit_state( $provider );
		$failures = self::get_failures( $provider );

		return array(
			'provider'       => $provider,
			'state'          => isset( $state['state'] ) ? $state['state'] : self::STATE_CLOSED,
			'failure_count'  => count( $failures ),
			'recent_failures' => array_slice( $failures, -3 ), // Last 3 failures.
			'is_available'   => self::is_available( $provider ),
		);
	}
}
