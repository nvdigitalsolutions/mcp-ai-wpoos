<?php
/**
 * Rate limit manager for the Content Graph AI Platform addon.
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Rate_Limit_Manager`
 * (Wave E2): byte-identical transient prefix, delay/retry constants,
 * backoff multiplier, `wp_mcp_ai_rate_limit_*` filters, error codes
 * (`wp_mcp_ai_invalid_callable`, `wp_mcp_ai_max_retries_exceeded`),
 * retriable status/timeout tables, Retry-After resolution, and the
 * `execute_with_retry()` loop contract. A static utility with no hooks
 * of its own — nothing to double-register in monolith installs.
 *
 * Decoupling (documented, additive):
 * - Logging goes through a dormant seam (`log_error`/`log_event`) that
 *   targets the base logger in monolith installs.
 * - The retry loop's `sleep()` is byte-identical; callers control the
 *   wall-clock cost through the delay options/filters (zero delays are
 *   honoured), which keeps the port testable without a sleep seam.
 *
 * @package NvoosContentGraphAiPlatform\Queues
 * @since   2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Queues;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages API rate limiting with exponential backoff and retry logic.
 *
 * @since 2.1.0
 */
class RateLimitManager {

	/**
	 * Transient prefix for storing retry state — byte-identical.
	 */
	const RETRY_STATE_PREFIX = 'wp_mcp_ai_retry_';

	/**
	 * Default initial retry delay in seconds — byte-identical.
	 */
	const DEFAULT_INITIAL_DELAY = 2;

	/**
	 * Default maximum retry delay in seconds — byte-identical.
	 */
	const DEFAULT_MAX_DELAY = 30;

	/**
	 * Default maximum number of retries — byte-identical.
	 */
	const DEFAULT_MAX_RETRIES = 3;

	/**
	 * Exponential backoff multiplier — byte-identical.
	 */
	const BACKOFF_MULTIPLIER = 2;

	/**
	 * Execute a callable with exponential backoff retry logic.
	 *
	 * @param callable $callback      Function to execute.
	 * @param array    $args          Arguments to pass to the callable.
	 * @param array    $retry_options Optional retry configuration.
	 *
	 * @return mixed|\WP_Error Result from callable or WP_Error on failure.
	 */
	public static function execute_with_retry( $callback, array $args = array(), array $retry_options = array() ) {
		if ( ! is_callable( $callback ) ) {
			return new \WP_Error(
				'wp_mcp_ai_invalid_callable',
				__( 'The provided callable is not valid.', 'nvoos-content-graph-ai-platform' )
			);
		}

		/**
		 * Filter the default maximum number of retry attempts.
		 *
		 * @since 2.1.0
		 *
		 * @param int $max_retries Maximum number of retries. Default 3.
		 */
		$default_max_retries = apply_filters( 'wp_mcp_ai_rate_limit_max_retries', self::DEFAULT_MAX_RETRIES );

		/**
		 * Filter the default initial retry delay in seconds.
		 *
		 * @since 2.1.0
		 *
		 * @param int $initial_delay Initial delay in seconds. Default 2.
		 */
		$default_initial_delay = apply_filters( 'wp_mcp_ai_rate_limit_initial_delay', self::DEFAULT_INITIAL_DELAY );

		/**
		 * Filter the default maximum retry delay in seconds.
		 *
		 * @since 2.1.0
		 *
		 * @param int $max_delay Maximum delay in seconds. Default 30.
		 */
		$default_max_delay = apply_filters( 'wp_mcp_ai_rate_limit_max_delay', self::DEFAULT_MAX_DELAY );

		$max_retries   = isset( $retry_options['max_retries'] ) ? absint( $retry_options['max_retries'] ) : $default_max_retries;
		$initial_delay = isset( $retry_options['initial_delay'] ) ? absint( $retry_options['initial_delay'] ) : $default_initial_delay;
		$max_delay     = isset( $retry_options['max_delay'] ) ? absint( $retry_options['max_delay'] ) : $default_max_delay;

		$attempt = 0;
		$delay   = $initial_delay;

		while ( $attempt <= $max_retries ) {
			$result = call_user_func_array( $callback, $args );

			// Success - return the result.
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}

			$error_data = $result->get_error_data();

			// Check if this is a rate limit error (429).
			$is_rate_limit = false;
			if ( is_array( $error_data ) && isset( $error_data['status'] ) && 429 === (int) $error_data['status'] ) {
				$is_rate_limit = true;
			}

			// If it's not a rate limit error and not a retriable error, fail immediately.
			if ( ! $is_rate_limit && ! self::is_retriable_error( $result ) ) {
				return $result;
			}

			// Check if we've exhausted retries.
			if ( $attempt >= $max_retries ) {
				static::log_error(
					'Max retries exhausted for API request.',
					array(
						'attempts'      => $attempt + 1,
						'error_code'    => $result->get_error_code(),
						'error_message' => $result->get_error_message(),
					)
				);
				return $result;
			}

			// Calculate delay with exponential backoff.
			$retry_delay = self::calculate_retry_delay( $result, $delay, $max_delay );

			static::log_event(
				'api_retry_scheduled',
				sprintf( 'Retrying API request after %d seconds (attempt %d/%d).', $retry_delay, $attempt + 1, $max_retries + 1 ),
				array(
					'attempt'     => $attempt + 1,
					'max_retries' => $max_retries + 1,
					'delay'       => $retry_delay,
					'error_code'  => $result->get_error_code(),
				)
			);

			// Sleep for the calculated delay.
			sleep( $retry_delay );

			// Update delay for next iteration using exponential backoff.
			/**
			 * Filter the exponential backoff multiplier.
			 *
			 * @since 2.1.0
			 *
			 * @param int $backoff_multiplier Backoff multiplier. Default 2.
			 */
			$backoff_multiplier = apply_filters( 'wp_mcp_ai_rate_limit_backoff_multiplier', self::BACKOFF_MULTIPLIER );
			$delay              = min( $delay * $backoff_multiplier, $max_delay );
			++$attempt;
		}

		return new \WP_Error(
			'wp_mcp_ai_max_retries_exceeded',
			__( 'Maximum retry attempts exceeded.', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Calculate the retry delay based on Retry-After header or exponential backoff.
	 *
	 * @param \WP_Error $error      The error response.
	 * @param int       $base_delay Current base delay in seconds.
	 * @param int       $max_delay  Maximum allowed delay in seconds.
	 *
	 * @return int Delay in seconds.
	 */
	protected static function calculate_retry_delay( $error, $base_delay, $max_delay ) {
		$error_data = $error->get_error_data();

		// Check for Retry-After header from rate limit details.
		if ( is_array( $error_data ) ) {
			// Check for reset_seconds in rate limit details.
			if ( isset( $error_data['rate_limit_reset_seconds'] ) ) {
				$retry_after = absint( $error_data['rate_limit_reset_seconds'] );
				if ( $retry_after > 0 ) {
					return min( $retry_after, $max_delay );
				}
			}

			// Check for Retry-After in response headers.
			if ( isset( $error_data['headers'] ) && is_array( $error_data['headers'] ) ) {
				$headers = $error_data['headers'];

				// Normalize header keys.
				$normalized_headers = array();
				foreach ( $headers as $key => $value ) {
					$normalized_headers[ strtolower( $key ) ] = $value;
				}

				if ( isset( $normalized_headers['retry-after'] ) ) {
					$retry_after = absint( $normalized_headers['retry-after'] );
					if ( $retry_after > 0 ) {
						return min( $retry_after, $max_delay );
					}
				}
			}
		}

		// Fall back to exponential backoff.
		return min( $base_delay, $max_delay );
	}

	/**
	 * Determine if an error is retriable.
	 *
	 * @param \WP_Error $error The error to check.
	 *
	 * @return bool True if the error should be retried.
	 */
	protected static function is_retriable_error( $error ) {
		if ( ! $error instanceof \WP_Error ) {
			return false;
		}

		$error_data = $error->get_error_data();

		// Check HTTP status codes that are retriable.
		if ( is_array( $error_data ) && isset( $error_data['status'] ) ) {
			$status = (int) $error_data['status'];

			/*
			 * Retry on:
			 * - 429 (Too Many Requests)
			 * - 500 (Internal Server Error)
			 * - 502 (Bad Gateway)
			 * - 503 (Service Unavailable)
			 * - 504 (Gateway Timeout)
			 */
			$retriable_statuses = array( 429, 500, 502, 503, 504 );
			if ( in_array( $status, $retriable_statuses, true ) ) {
				return true;
			}
		}

		// Check for timeout errors.
		$error_code    = $error->get_error_code();
		$timeout_codes = array(
			'http_request_timeout',
			'wp_mcp_ai_wordpress_timeout',
		);

		if ( in_array( $error_code, $timeout_codes, true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if a service is currently rate limited.
	 *
	 * @param string $service_key Unique identifier for the service/endpoint.
	 *
	 * @return bool True if rate limited.
	 */
	public static function is_rate_limited( $service_key ) {
		$transient_key = self::RETRY_STATE_PREFIX . md5( $service_key );
		$retry_state   = get_transient( $transient_key );

		if ( false === $retry_state ) {
			return false;
		}

		if ( ! is_array( $retry_state ) || ! isset( $retry_state['retry_after'] ) ) {
			return false;
		}

		return time() < $retry_state['retry_after'];
	}

	/**
	 * Mark a service as rate limited.
	 *
	 * @param string $service_key Unique identifier for the service/endpoint.
	 * @param int    $retry_after Unix timestamp when the service can be retried.
	 *
	 * @return bool True on success.
	 */
	public static function set_rate_limit( $service_key, $retry_after ) {
		$transient_key = self::RETRY_STATE_PREFIX . md5( $service_key );
		$expiration    = max( 0, $retry_after - time() );

		$retry_state = array(
			'retry_after' => absint( $retry_after ),
			'timestamp'   => time(),
		);

		return set_transient( $transient_key, $retry_state, $expiration );
	}

	/**
	 * Clear rate limit state for a service.
	 *
	 * @param string $service_key Unique identifier for the service/endpoint.
	 *
	 * @return bool True on success.
	 */
	public static function clear_rate_limit( $service_key ) {
		$transient_key = self::RETRY_STATE_PREFIX . md5( $service_key );
		return delete_transient( $transient_key );
	}

	/**
	 * Get the retry-after timestamp for a service.
	 *
	 * @param string $service_key Unique identifier for the service/endpoint.
	 *
	 * @return int|null Unix timestamp or null if not rate limited.
	 */
	public static function get_retry_after( $service_key ) {
		$transient_key = self::RETRY_STATE_PREFIX . md5( $service_key );
		$retry_state   = get_transient( $transient_key );

		if ( false === $retry_state || ! is_array( $retry_state ) || ! isset( $retry_state['retry_after'] ) ) {
			return null;
		}

		return absint( $retry_state['retry_after'] );
	}

	// ─── Seams ──────────────────────────────────────────────────────

	/**
	 * Log a rate-limit error event.
	 *
	 * Dormant standalone until the platform's logging piece ports; the
	 * base logger is targeted in monolith installs.
	 *
	 * @param string $message Human-readable message.
	 * @param array  $data    Structured event data.
	 * @return void
	 */
	protected static function log_error( $message, $data = array() ): void {
		if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
			return;
		}

		\WP_MCP_AI_Logger::log_error( $message, $data );
	}

	/**
	 * Log a rate-limit event.
	 *
	 * Dormant standalone until the platform's logging piece ports; the
	 * base logger is targeted in monolith installs.
	 *
	 * @param string $event   Event identifier.
	 * @param string $message Human-readable message.
	 * @param array  $data    Structured event data.
	 * @return void
	 */
	protected static function log_event( $event, $message, $data = array() ): void {
		if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
			return;
		}

		\WP_MCP_AI_Logger::log_event( $event, $message, $data );
	}
}
