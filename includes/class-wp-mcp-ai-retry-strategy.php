<?php
/**
 * Retry Strategy with Exponential Backoff and Jitter.
 *
 * Provides a generalized retry mechanism for failed operations with
 * configurable exponential backoff and jitter to avoid thundering herd.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages retry strategies for failed operations.
 */
class WP_MCP_AI_Retry_Strategy {
	/**
	 * Default initial delay in seconds.
	 */
	const DEFAULT_INITIAL_DELAY = 5;

	/**
	 * Default multiplier for exponential backoff.
	 */
	const DEFAULT_MULTIPLIER = 2.0;

	/**
	 * Default maximum delay in seconds (5 minutes).
	 */
	const DEFAULT_MAX_DELAY = 300;

	/**
	 * Default maximum retry attempts.
	 */
	const DEFAULT_MAX_ATTEMPTS = 3;

	/**
	 * Default jitter factor (0-1, where 0.1 = ±10% randomness).
	 */
	const DEFAULT_JITTER_FACTOR = 0.1;

	/**
	 * Calculate next retry delay using exponential backoff with jitter.
	 *
	 * Formula: delay = min(initial_delay * multiplier^attempt, max_delay)
	 * Jitter: delay * (1 ± jitter_factor)
	 *
	 * @param int   $attempt         Current attempt number (0-indexed).
	 * @param array $config          Configuration options.
	 * @return int Delay in seconds before next retry.
	 */
	public static function calculate_delay( $attempt, $config = array() ) {
		$initial_delay = isset( $config['initial_delay'] ) ? absint( $config['initial_delay'] ) : self::DEFAULT_INITIAL_DELAY;
		$multiplier    = isset( $config['multiplier'] ) ? floatval( $config['multiplier'] ) : self::DEFAULT_MULTIPLIER;
		$max_delay     = isset( $config['max_delay'] ) ? absint( $config['max_delay'] ) : self::DEFAULT_MAX_DELAY;
		$jitter_factor = isset( $config['jitter_factor'] ) ? floatval( $config['jitter_factor'] ) : self::DEFAULT_JITTER_FACTOR;

		// Ensure valid values.
		$initial_delay = max( 1, $initial_delay );
		$multiplier    = max( 1.0, $multiplier );
		$max_delay     = max( $initial_delay, $max_delay );
		$jitter_factor = max( 0.0, min( 1.0, $jitter_factor ) );
		$attempt       = max( 0, absint( $attempt ) );

		// Calculate base delay with exponential backoff.
		$delay = $initial_delay * pow( $multiplier, $attempt );

		// Cap at max delay.
		$delay = min( $delay, $max_delay );

		// Apply jitter to avoid thundering herd.
		if ( $jitter_factor > 0 ) {
			$jitter = $delay * $jitter_factor;
			$min    = $delay - $jitter;
			$max    = $delay + $jitter;
			$delay  = wp_rand( (int) $min, (int) $max );
		}

		return max( 1, (int) $delay );
	}

	/**
	 * Check if an operation should be retried.
	 *
	 * @param int   $attempt    Current attempt number (0-indexed).
	 * @param array $config     Configuration options.
	 * @return bool True if should retry, false otherwise.
	 */
	public static function should_retry( $attempt, $config = array() ) {
		$max_attempts = isset( $config['max_attempts'] ) ? absint( $config['max_attempts'] ) : self::DEFAULT_MAX_ATTEMPTS;
		$attempt      = absint( $attempt );

		return $attempt < $max_attempts;
	}

	/**
	 * Get retry configuration for a specific operation type.
	 *
	 * @param string $type Operation type (webhook, cron_job, async_tool, etc.).
	 * @return array Retry configuration.
	 */
	public static function get_config( $type ) {
		$configs = array(
			'webhook'    => array(
				'initial_delay' => 10,    // 10 seconds.
				'multiplier'    => 2.0,   // Double each time.
				'max_delay'     => 300,   // 5 minutes max.
				'max_attempts'  => 3,
				'jitter_factor' => 0.2,   // ±20% randomness.
			),
			'cron_job'   => array(
				'initial_delay' => 30,    // 30 seconds.
				'multiplier'    => 2.0,
				'max_delay'     => 600,   // 10 minutes max.
				'max_attempts'  => 3,
				'jitter_factor' => 0.15,  // ±15% randomness.
			),
			'async_tool' => array(
				'initial_delay' => 15,    // 15 seconds.
				'multiplier'    => 2.0,
				'max_delay'     => 300,   // 5 minutes max.
				'max_attempts'  => 3,
				'jitter_factor' => 0.1,   // ±10% randomness.
			),
			'crawl4ai'   => array(
				'initial_delay' => 30,    // 30 seconds.
				'multiplier'    => 2.0,
				'max_delay'     => 300,   // 5 minutes max.
				'max_attempts'  => 3,
				'jitter_factor' => 0.1,   // ±10% randomness.
			),
			'default'    => array(
				'initial_delay' => self::DEFAULT_INITIAL_DELAY,
				'multiplier'    => self::DEFAULT_MULTIPLIER,
				'max_delay'     => self::DEFAULT_MAX_DELAY,
				'max_attempts'  => self::DEFAULT_MAX_ATTEMPTS,
				'jitter_factor' => self::DEFAULT_JITTER_FACTOR,
			),
		);

		$type = sanitize_key( $type );

		if ( isset( $configs[ $type ] ) ) {
			return $configs[ $type ];
		}

		return $configs['default'];
	}

	/**
	 * Calculate all retry delays for a sequence of attempts.
	 *
	 * Useful for planning or visualization.
	 *
	 * @param array $config Configuration options.
	 * @return array Array of delays indexed by attempt number.
	 */
	public static function get_retry_schedule( $config = array() ) {
		$max_attempts = isset( $config['max_attempts'] ) ? absint( $config['max_attempts'] ) : self::DEFAULT_MAX_ATTEMPTS;
		$schedule     = array();

		for ( $i = 0; $i < $max_attempts; $i++ ) {
			$schedule[ $i ] = self::calculate_delay( $i, $config );
		}

		return $schedule;
	}

	/**
	 * Schedule a retry for an operation using WordPress cron.
	 *
	 * @param string $hook      Cron hook to call.
	 * @param array  $args      Arguments to pass to hook.
	 * @param int    $attempt   Current attempt number (0-indexed).
	 * @param array  $config    Configuration options.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function schedule_retry( $hook, $args, $attempt, $config = array() ) {
		if ( ! self::should_retry( $attempt, $config ) ) {
			return new WP_Error(
				'max_retries_exceeded',
				__( 'Maximum retry attempts exceeded.', 'mcp-ai-wpoos' ),
				array(
					'attempt'      => $attempt,
					'max_attempts' => isset( $config['max_attempts'] ) ? $config['max_attempts'] : self::DEFAULT_MAX_ATTEMPTS,
				)
			);
		}

		$delay     = self::calculate_delay( $attempt, $config );
		$timestamp = time() + $delay;

		$scheduled = wp_schedule_single_event( $timestamp, $hook, $args );

		if ( false === $scheduled ) {
			return new WP_Error(
				'schedule_failed',
				__( 'Failed to schedule retry event.', 'mcp-ai-wpoos' ),
				array(
					'hook'      => $hook,
					'timestamp' => $timestamp,
					'delay'     => $delay,
				)
			);
		}

		WP_MCP_AI_Logger::log_event(
			'retry_scheduled',
			'Retry scheduled with exponential backoff',
			array(
				'hook'      => $hook,
				'attempt'   => $attempt,
				'delay'     => $delay,
				'timestamp' => gmdate( 'Y-m-d H:i:s', $timestamp ),
			)
		);

		return true;
	}

	/**
	 * Get retry statistics for an operation.
	 *
	 * @param int   $attempt Current attempt number.
	 * @param array $config  Configuration options.
	 * @return array Statistics.
	 */
	public static function get_stats( $attempt, $config = array() ) {
		$max_attempts = isset( $config['max_attempts'] ) ? absint( $config['max_attempts'] ) : self::DEFAULT_MAX_ATTEMPTS;
		$schedule     = self::get_retry_schedule( $config );

		$total_delay    = 0;
		$loop_max_index = min( $attempt, $max_attempts - 1 );
		for ( $i = 0; $i <= $loop_max_index; $i++ ) {
			if ( isset( $schedule[ $i ] ) ) {
				$total_delay += $schedule[ $i ];
			}
		}

		$remaining_attempts = max( 0, $max_attempts - $attempt - 1 );

		return array(
			'current_attempt'    => $attempt,
			'max_attempts'       => $max_attempts,
			'remaining_attempts' => $remaining_attempts,
			'next_delay'         => isset( $schedule[ $attempt ] ) ? $schedule[ $attempt ] : null,
			'total_delay_so_far' => $total_delay,
			'schedule'           => $schedule,
		);
	}

	/**
	 * Apply retry strategy to a failed operation.
	 *
	 * This is a high-level helper that encapsulates the full retry logic.
	 *
	 * @param string   $operation_id Unique identifier for the operation.
	 * @param callable $operation    The operation to retry.
	 * @param array    $context      Context data for the operation.
	 * @param array    $config       Retry configuration.
	 * @return mixed|WP_Error Operation result or error.
	 */
	public static function execute_with_retry( $operation_id, callable $operation, array $context = array(), array $config = array() ) {
		$attempt = isset( $context['attempt'] ) ? absint( $context['attempt'] ) : 0;

		// Check if we should even attempt this.
		if ( ! self::should_retry( $attempt, $config ) ) {
			return new WP_Error(
				'max_retries_exceeded',
				__( 'Maximum retry attempts exceeded before execution.', 'mcp-ai-wpoos' )
			);
		}

		try {
			// Execute the operation.
			$result = call_user_func( $operation, $context );

			// If result is WP_Error, treat as failure.
			if ( is_wp_error( $result ) ) {
				// Check if we should retry.
				if ( self::should_retry( $attempt + 1, $config ) ) {
					// Log failure and return retry indicator.
					WP_MCP_AI_Logger::log_error(
						'Operation failed, will retry',
						array(
							'operation_id' => $operation_id,
							'attempt'      => $attempt,
							'error'        => $result->get_error_message(),
						)
					);

					return new WP_Error(
						'retry_needed',
						__( 'Operation failed, retry required.', 'mcp-ai-wpoos' ),
						array(
							'attempt'    => $attempt,
							'next_delay' => self::calculate_delay( $attempt + 1, $config ),
						)
					);
				}

				// Max retries exhausted.
				return $result;
			}

			// Success!
			return $result;

		} catch ( Exception $e ) {
			return new WP_Error(
				'operation_exception',
				$e->getMessage(),
				array(
					'operation_id' => $operation_id,
					'attempt'      => $attempt,
					'exception'    => $e,
				)
			);
		}
	}
}
