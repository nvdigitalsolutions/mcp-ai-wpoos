<?php
/**
 * Async Process Health Monitor Service
 *
 * Monitors async processes and cron jobs to detect and prevent hanging operations.
 * Provides health checks and safety mechanisms for long-running operations.
 *
 * Separation of Concerns:
 * - Monitors health of async processes
 * - Does NOT execute processes
 * - Does NOT manage cron scheduling
 * - Provides metrics and diagnostics only
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Async Process Health Monitor Service class
 *
 * Monitors async task execution health and detects potential issues.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Async_Health_Monitor {

	/**
	 * Maximum reasonable execution time for async tasks (seconds).
	 *
	 * @var int
	 */
	const MAX_REASONABLE_DURATION = 600; // 10 minutes.

	/**
	 * Time threshold to consider a pending job as potentially stuck (seconds).
	 *
	 * @var int
	 */
	const STUCK_JOB_THRESHOLD = 300; // 5 minutes.

	/**
	 * Check health of async task system.
	 *
	 * @return array Health status information.
	 */
	public static function check_async_health() {
		$health = array(
			'status'         => 'healthy',
			'issues'         => array(),
			'stuck_jobs'     => 0,
			'long_running'   => 0,
			'pending_jobs'   => 0,
			'failed_jobs'    => 0,
			'cron_scheduled' => false,
		);

		// Check if async executor cron is scheduled.
		$health['cron_scheduled'] = (bool) wp_next_scheduled( WP_MCP_AI_Tool_Async_Executor::CRON_HOOK );

		if ( ! $health['cron_scheduled'] ) {
			$health['status']   = 'warning';
			$health['issues'][] = 'Async executor cron hook not scheduled';
		}

		// Check for stuck jobs.
		$stuck_jobs           = self::get_stuck_jobs();
		$health['stuck_jobs'] = count( $stuck_jobs );

		if ( $health['stuck_jobs'] > 0 ) {
			$health['status']   = 'warning';
			$health['issues'][] = sprintf( '%d potentially stuck jobs detected', $health['stuck_jobs'] );
		}

		// Check for long-running jobs.
		$long_running           = self::get_long_running_jobs();
		$health['long_running'] = count( $long_running );

		if ( $health['long_running'] > 3 ) {
			$health['status']   = 'warning';
			$health['issues'][] = sprintf( '%d long-running jobs detected', $health['long_running'] );
		}

		// Check cleanup cron.
		if ( ! wp_next_scheduled( 'wp_mcp_ai_cleanup_async_results' ) ) {
			$health['status']   = 'warning';
			$health['issues'][] = 'Cleanup cron job not scheduled';
		}

		return $health;
	}

	/**
	 * Get potentially stuck jobs.
	 *
	 * Jobs are considered stuck if they've been in pending/running state
	 * for longer than the threshold.
	 *
	 * @return array Array of stuck job IDs.
	 */
	protected static function get_stuck_jobs() {
		global $wpdb;

		$stuck_jobs = array();
		$threshold  = time() - self::STUCK_JOB_THRESHOLD;

		// Query transients for async jobs.
		$transient_prefix = WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$transients = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value 
				FROM {$wpdb->options} 
				WHERE option_name LIKE %s
				AND option_name NOT LIKE %s
				LIMIT 100",
				$wpdb->esc_like( '_transient_' . $transient_prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' ) . '%'
			)
		);

		foreach ( $transients as $transient ) {
			$metadata = maybe_unserialize( $transient->option_value );

			if ( ! is_array( $metadata ) || ! isset( $metadata['status'], $metadata['queued_at'] ) ) {
				continue;
			}

			// Check if job is stuck.
			if ( in_array( $metadata['status'], array( 'pending', 'running' ), true ) ) {
				$job_age = time() - absint( $metadata['queued_at'] );

				if ( $job_age > self::STUCK_JOB_THRESHOLD ) {
					$stuck_jobs[] = array(
						'job_id'    => $metadata['job_id'] ?? 'unknown',
						'tool_slug' => $metadata['tool_slug'] ?? 'unknown',
						'status'    => $metadata['status'],
						'age'       => $job_age,
					);
				}
			}
		}

		return $stuck_jobs;
	}

	/**
	 * Get long-running jobs.
	 *
	 * @return array Array of long-running job IDs.
	 */
	protected static function get_long_running_jobs() {
		global $wpdb;

		$long_running     = array();
		$transient_prefix = WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$transients = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value 
				FROM {$wpdb->options} 
				WHERE option_name LIKE %s
				AND option_name NOT LIKE %s
				LIMIT 100",
				$wpdb->esc_like( '_transient_' . $transient_prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' ) . '%'
			)
		);

		foreach ( $transients as $transient ) {
			$metadata = maybe_unserialize( $transient->option_value );

			if ( ! is_array( $metadata ) || ! isset( $metadata['status'] ) ) {
				continue;
			}

			// Check if job is long-running.
			if ( 'running' === $metadata['status'] && isset( $metadata['started_at'] ) ) {
				$duration = time() - absint( $metadata['started_at'] );

				if ( $duration > 120 ) { // Longer than 2 minutes.
					$long_running[] = array(
						'job_id'    => $metadata['job_id'] ?? 'unknown',
						'tool_slug' => $metadata['tool_slug'] ?? 'unknown',
						'duration'  => $duration,
					);
				}
			}
		}

		return $long_running;
	}

	/**
	 * Get health metrics for monitoring.
	 *
	 * @return array Metrics array.
	 */
	public static function get_health_metrics() {
		$health = self::check_async_health();

		return array(
			'async_system_healthy' => ( 'healthy' === $health['status'] ),
			'stuck_job_count'      => $health['stuck_jobs'],
			'long_running_count'   => $health['long_running'],
			'issues'               => $health['issues'],
			'timestamp'            => time(),
		);
	}

	/**
	 * Check if a specific job appears to be stuck.
	 *
	 * @param string $job_id Job identifier.
	 * @return bool True if job appears stuck.
	 */
	public static function is_job_stuck( $job_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Async_Executor' ) ) {
			return false;
		}

		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$executor->init();

		$result = $executor->get_result( $job_id );

		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			return false;
		}

		// Job is stuck if pending/running for too long.
		if ( ! in_array( $result['status'], array( 'pending', 'running' ), true ) ) {
			return false;
		}

		$queued_at = isset( $result['queued_at'] ) ? absint( $result['queued_at'] ) : 0;

		if ( 0 === $queued_at ) {
			return false;
		}

		$age = time() - $queued_at;

		return $age > self::STUCK_JOB_THRESHOLD;
	}

	/**
	 * Get recommended action for stuck job.
	 *
	 * @param string $job_id Job identifier.
	 * @return string|null Recommended action or null.
	 */
	public static function get_stuck_job_recommendation( $job_id ) {
		if ( ! self::is_job_stuck( $job_id ) ) {
			return null;
		}

		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$executor->init();
		$result = $executor->get_result( $job_id );

		if ( is_wp_error( $result ) || ! isset( $result['tool_slug'] ) ) {
			return 'Unable to determine recommendation';
		}

		$tool_slug = $result['tool_slug'];

		return sprintf(
			'Job %s (tool: %s) has been %s for over %d minutes. Consider manually executing or canceling this job.',
			$job_id,
			$tool_slug,
			$result['status'],
			floor( self::STUCK_JOB_THRESHOLD / 60 )
		);
	}

	/**
	 * Log health check results.
	 *
	 * @param array $health Health check results.
	 */
	protected static function log_health_status( $health ) {
		if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
			return;
		}

		if ( 'healthy' !== $health['status'] ) {
			WP_MCP_AI_Logger::log_event(
				'async_health_warning',
				'Async task system health check detected issues',
				$health
			);
		}
	}
}
