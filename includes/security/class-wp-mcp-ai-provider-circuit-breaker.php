<?php
/**
 * Provider Circuit Breaker — Prevents cascading failures during AI provider outages.
 *
 * Implements the Circuit Breaker pattern (Closed → Open → Half-Open) for
 * AI provider API calls. When a provider returns 5xx errors consecutively,
 * the circuit opens and subsequent calls return 503 immediately without
 * making HTTP requests — saving resources and preventing cascading failures.
 *
 * Transient-based with per-provider state keys. Independent breakers for
 * each provider (openai, gemini, anthropic, etc.).
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Circuit breaker for AI provider API calls.
 */
class WP_MCP_AI_Provider_Circuit_Breaker {

	/**
	 * Transient prefix for circuit breaker state.
	 */
	const STATE_KEY_PREFIX = 'wp_mcp_ai_cb_';

	/**
	 * Default consecutive failure threshold before opening.
	 *
	 * @var int
	 */
	const DEFAULT_THRESHOLD = 5;

	/**
	 * Default timeout in seconds while circuit is OPEN.
	 *
	 * @var int
	 */
	const DEFAULT_TIMEOUT = 60;

	/**
	 * Circuit state constants.
	 */
	const STATE_CLOSED    = 'closed';
	const STATE_OPEN      = 'open';
	const STATE_HALF_OPEN = 'half_open';

	/**
	 * Check whether a provider is currently allowed to receive requests.
	 *
	 * Returns false when the circuit is OPEN and the timeout has not elapsed.
	 * When the timeout has elapsed, transitions to HALF_OPEN and allows one
	 * trial request.
	 *
	 * @since 1.2.0
	 *
	 * @param string $provider Provider slug (e.g. 'openai', 'gemini').
	 * @return bool True if requests are allowed.
	 */
	public static function is_allowed( $provider ) {
		$key = self::STATE_KEY_PREFIX . sanitize_key( $provider );
		$raw = get_transient( $key );

		if ( false === $raw ) {
			return true; // No state recorded — allow.
		}

		$state = json_decode( $raw, true );
		if ( ! is_array( $state ) || ! isset( $state['state'] ) ) {
			return true;
		}

		if ( self::STATE_OPEN === $state['state'] ) {
			$retry_after = isset( $state['retry_after'] ) ? (int) $state['retry_after'] : 0;
			if ( time() >= $retry_after ) {
				// Transition to half-open and allow one trial.
				set_transient(
					$key,
					wp_json_encode(
						array(
							'state'       => self::STATE_HALF_OPEN,
							'failures'    => isset( $state['failures'] ) ? (int) $state['failures'] : 0,
							'retry_after' => 0,
						)
					),
					self::DEFAULT_TIMEOUT * 2
				);
				return true;
			}
			return false; // Still open.
		}

		return true; // Closed or half-open.
	}

	/**
	 * Record a failed API call to a provider.
	 *
	 * Increments the consecutive failure counter. When the counter reaches
	 * the threshold, opens the circuit.
	 *
	 * @since 1.2.0
	 *
	 * @param string $provider Provider slug.
	 * @return void
	 */
	public static function record_failure( $provider ) {
		$key = self::STATE_KEY_PREFIX . sanitize_key( $provider );
		$raw = get_transient( $key );

		$data = array(
			'state'       => self::STATE_CLOSED,
			'failures'    => 1,
			'retry_after' => 0,
		);

		if ( false !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$data['failures'] = isset( $decoded['failures'] ) ? (int) $decoded['failures'] + 1 : 1;
			}
		}

		$threshold = self::get_threshold();
		if ( $data['failures'] >= $threshold ) {
			$data['state']       = self::STATE_OPEN;
			$data['retry_after'] = time() + self::get_timeout();
		}

		set_transient( $key, wp_json_encode( $data ), self::get_timeout() * 2 );
	}

	/**
	 * Record a successful API call to a provider.
	 *
	 * Resets the circuit breaker: deletes all failure state, returning
	 * the circuit to CLOSED.
	 *
	 * @since 1.2.0
	 *
	 * @param string $provider Provider slug.
	 * @return void
	 */
	public static function record_success( $provider ) {
		$key = self::STATE_KEY_PREFIX . sanitize_key( $provider );
		delete_transient( $key );
	}

	/**
	 * Get the current circuit state for a provider.
	 *
	 * @since 1.2.0
	 *
	 * @param string $provider Provider slug.
	 * @return string One of STATE_CLOSED, STATE_OPEN, STATE_HALF_OPEN.
	 */
	public static function get_state( $provider ) {
		$key = self::STATE_KEY_PREFIX . sanitize_key( $provider );
		$raw = get_transient( $key );

		if ( false === $raw ) {
			return self::STATE_CLOSED;
		}

		$state = json_decode( $raw, true );
		return isset( $state['state'] ) ? $state['state'] : self::STATE_CLOSED;
	}

	/**
	 * Reset the circuit breaker for a provider (admin action).
	 *
	 * @since 1.2.0
	 *
	 * @param string $provider Provider slug.
	 * @return void
	 */
	public static function reset( $provider ) {
		$key = self::STATE_KEY_PREFIX . sanitize_key( $provider );
		delete_transient( $key );
	}

	/**
	 * Get the consecutive failure threshold from the filter or default.
	 *
	 * @since 1.2.0
	 * @return int
	 */
	private static function get_threshold() {
		/**
		 * Filter the consecutive failure threshold before opening the circuit.
		 *
		 * @since 1.2.0
		 *
		 * @param int $threshold Consecutive failures. Default 5.
		 */
		return (int) apply_filters( 'wp_mcp_ai_circuit_breaker_threshold', self::DEFAULT_THRESHOLD );
	}

	/**
	 * Get the circuit-open timeout from the filter or default.
	 *
	 * @since 1.2.0
	 * @return int Timeout in seconds.
	 */
	private static function get_timeout() {
		/**
		 * Filter the circuit-open timeout in seconds.
		 *
		 * @since 1.2.0
		 *
		 * @param int $timeout Timeout in seconds. Default 60.
		 */
		return (int) apply_filters( 'wp_mcp_ai_circuit_breaker_timeout', self::DEFAULT_TIMEOUT );
	}
}
