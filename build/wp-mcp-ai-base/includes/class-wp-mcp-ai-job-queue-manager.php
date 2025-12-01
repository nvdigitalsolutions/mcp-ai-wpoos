<?php
/**
 * Concurrent job queue manager for API request throttling.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages concurrent API requests to prevent overwhelming the service.
 */
class WP_MCP_AI_Job_Queue_Manager {

	/**
	 * Option name for storing queue state.
	 */
	const QUEUE_STATE_OPTION = 'wp_mcp_ai_job_queue_state';

	/**
	 * Option name for storing active jobs.
	 */
	const ACTIVE_JOBS_OPTION = 'wp_mcp_ai_active_jobs';

	/**
	 * Default maximum concurrent jobs.
	 */
	const DEFAULT_MAX_CONCURRENT = 3;

	/**
	 * Default job timeout in seconds.
	 */
	const DEFAULT_JOB_TIMEOUT = 300;

	/**
	 * Job priorities.
	 */
	const PRIORITY_HIGH   = 10;
	const PRIORITY_NORMAL = 5;
	const PRIORITY_LOW    = 1;

	/**
	 * Enqueue a job for execution.
	 *
	 * @param string $job_id   Unique job identifier.
	 * @param array  $job_data Job data including callable and arguments.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function enqueue_job( $job_id, array $job_data ) {
		$job_id = sanitize_key( $job_id );

		if ( '' === $job_id ) {
			return false;
		}

		// Validate job data.
		if ( ! isset( $job_data['callable'] ) || ! is_callable( $job_data['callable'] ) ) {
			WP_MCP_AI_Logger::log_error(
				'Cannot enqueue job with invalid callable.',
				array( 'job_id' => $job_id )
			);
			return false;
		}

		$queue = self::get_queue_state();

		// Check if job already exists.
		if ( isset( $queue[ $job_id ] ) ) {
			WP_MCP_AI_Logger::log_event(
				'job_already_queued',
				'Job already exists in queue.',
				array( 'job_id' => $job_id )
			);
			return false;
		}

		// Prepare job entry.
		$priority = isset( $job_data['priority'] ) ? absint( $job_data['priority'] ) : self::PRIORITY_NORMAL;
		$timeout  = isset( $job_data['timeout'] ) ? absint( $job_data['timeout'] ) : self::DEFAULT_JOB_TIMEOUT;

		$queue[ $job_id ] = array(
			'callable'    => $job_data['callable'],
			'args'        => isset( $job_data['args'] ) ? $job_data['args'] : array(),
			'priority'    => $priority,
			'timeout'     => $timeout,
			'enqueued_at' => time(),
			'retry_count' => 0,
			'status'      => 'pending',
		);

		// Save queue state.
		$saved = self::save_queue_state( $queue );

		if ( $saved ) {
			WP_MCP_AI_Logger::log_event(
				'job_enqueued',
				'Job added to queue.',
				array(
					'job_id'   => $job_id,
					'priority' => $priority,
				)
			);
		}

		return $saved;
	}

	/**
	 * Process the job queue.
	 *
	 * @param int $max_concurrent Maximum number of concurrent jobs.
	 *
	 * @return array Processing results.
	 */
	public static function process_queue( $max_concurrent = null ) {
		if ( null === $max_concurrent ) {
			// Use resource manager setting if available.
			$resource_mgr   = WP_MCP_AI_Resource_Manager::instance();
			$max_concurrent = $resource_mgr->get_max_concurrent_requests();
		}

		$max_concurrent = max( 1, absint( $max_concurrent ) );

		// Clean up stale active jobs.
		self::cleanup_stale_jobs();

		$active_jobs  = self::get_active_jobs();
		$active_count = count( $active_jobs );

		// Check if we can process more jobs.
		if ( $active_count >= $max_concurrent ) {
			WP_MCP_AI_Logger::log_event(
				'queue_at_capacity',
				'Job queue at maximum concurrent capacity.',
				array(
					'active_count'   => $active_count,
					'max_concurrent' => $max_concurrent,
				)
			);
			return array(
				'processed' => 0,
				'active'    => $active_count,
				'reason'    => 'at_capacity',
			);
		}

		$queue           = self::get_queue_state();
		$slots_available = $max_concurrent - $active_count;

		// Get pending jobs sorted by priority.
		$pending_jobs = self::get_pending_jobs( $queue );

		if ( empty( $pending_jobs ) ) {
			return array(
				'processed' => 0,
				'active'    => $active_count,
				'reason'    => 'no_pending_jobs',
			);
		}

		$processed = 0;

		foreach ( $pending_jobs as $job_id => $job ) {
			if ( $processed >= $slots_available ) {
				break;
			}

			// Mark job as active.
			if ( self::mark_job_active( $job_id, $job ) ) {
				// Execute the job asynchronously if possible, or synchronously.
				$result = self::execute_job( $job_id, $job );

				// Update queue state based on result.
				if ( is_wp_error( $result ) ) {
					self::handle_job_failure( $job_id, $job, $result );
				} else {
					self::mark_job_complete( $job_id, $result );
				}

				++$processed;
			}
		}

		WP_MCP_AI_Logger::log_event(
			'queue_processed',
			'Job queue processing cycle completed.',
			array(
				'processed' => $processed,
				'active'    => count( self::get_active_jobs() ),
			)
		);

		return array(
			'processed' => $processed,
			'active'    => count( self::get_active_jobs() ),
			'reason'    => 'success',
		);
	}

	/**
	 * Execute a job.
	 *
	 * @param string $job_id Job identifier.
	 * @param array  $job    Job data.
	 *
	 * @return mixed|WP_Error Job result or error.
	 */
	protected static function execute_job( $job_id, array $job ) {
		WP_MCP_AI_Logger::log_event(
			'job_executing',
			'Executing job.',
			array( 'job_id' => $job_id )
		);

		try {
			$result = call_user_func_array( $job['callable'], $job['args'] );
			return $result;
		} catch ( Exception $e ) {
			return new WP_Error(
				'wp_mcp_ai_job_exception',
				$e->getMessage(),
				array(
					'job_id'    => $job_id,
					'exception' => $e,
				)
			);
		}
	}

	/**
	 * Handle job failure.
	 *
	 * @param string   $job_id Job identifier.
	 * @param array    $job    Job data.
	 * @param WP_Error $error  Error object.
	 *
	 * @return bool True on success.
	 */
	protected static function handle_job_failure( $job_id, array $job, $error ) {
		$queue = self::get_queue_state();

		if ( ! isset( $queue[ $job_id ] ) ) {
			return false;
		}

		$retry_count = isset( $queue[ $job_id ]['retry_count'] ) ? absint( $queue[ $job_id ]['retry_count'] ) : 0;
		$max_retries = 3;

		WP_MCP_AI_Logger::log_error(
			'Job execution failed.',
			array(
				'job_id'        => $job_id,
				'retry_count'   => $retry_count,
				'error_code'    => $error->get_error_code(),
				'error_message' => $error->get_error_message(),
			)
		);

		// Check if we should retry.
		if ( $retry_count < $max_retries ) {
			$queue[ $job_id ]['retry_count'] = $retry_count + 1;
			$queue[ $job_id ]['status']      = 'pending';
			$queue[ $job_id ]['last_error']  = $error->get_error_message();

			// Remove from active jobs.
			self::remove_active_job( $job_id );

			return self::save_queue_state( $queue );
		}

		// Max retries exceeded - mark as failed.
		$queue[ $job_id ]['status']     = 'failed';
		$queue[ $job_id ]['failed_at']  = time();
		$queue[ $job_id ]['last_error'] = $error->get_error_message();

		// Remove from active jobs.
		self::remove_active_job( $job_id );

		return self::save_queue_state( $queue );
	}

	/**
	 * Mark a job as complete.
	 *
	 * @param string $job_id Job identifier.
	 * @param mixed  $result Job result.
	 *
	 * @return bool True on success.
	 */
	protected static function mark_job_complete( $job_id, $result ) {
		$queue = self::get_queue_state();

		if ( isset( $queue[ $job_id ] ) ) {
			unset( $queue[ $job_id ] );
		}

		// Remove from active jobs.
		self::remove_active_job( $job_id );

		WP_MCP_AI_Logger::log_event(
			'job_completed',
			'Job completed successfully.',
			array( 'job_id' => $job_id )
		);

		return self::save_queue_state( $queue );
	}

	/**
	 * Mark a job as active.
	 *
	 * @param string $job_id Job identifier.
	 * @param array  $job    Job data.
	 *
	 * @return bool True on success.
	 */
	protected static function mark_job_active( $job_id, array $job ) {
		$active_jobs = self::get_active_jobs();

		$active_jobs[ $job_id ] = array(
			'started_at' => time(),
			'timeout'    => isset( $job['timeout'] ) ? absint( $job['timeout'] ) : self::DEFAULT_JOB_TIMEOUT,
		);

		return self::save_active_jobs( $active_jobs );
	}

	/**
	 * Remove a job from active jobs.
	 *
	 * @param string $job_id Job identifier.
	 *
	 * @return bool True on success.
	 */
	protected static function remove_active_job( $job_id ) {
		$active_jobs = self::get_active_jobs();

		if ( isset( $active_jobs[ $job_id ] ) ) {
			unset( $active_jobs[ $job_id ] );
			return self::save_active_jobs( $active_jobs );
		}

		return false;
	}

	/**
	 * Clean up stale active jobs.
	 *
	 * @return int Number of jobs cleaned up.
	 */
	protected static function cleanup_stale_jobs() {
		$active_jobs  = self::get_active_jobs();
		$current_time = time();
		$cleaned      = 0;

		foreach ( $active_jobs as $job_id => $job_data ) {
			$started_at = isset( $job_data['started_at'] ) ? absint( $job_data['started_at'] ) : 0;
			$timeout    = isset( $job_data['timeout'] ) ? absint( $job_data['timeout'] ) : self::DEFAULT_JOB_TIMEOUT;

			// Check if job has timed out.
			if ( $current_time - $started_at > $timeout ) {
				unset( $active_jobs[ $job_id ] );

				WP_MCP_AI_Logger::log_event(
					'job_timeout',
					'Job timed out and was removed from active queue.',
					array(
						'job_id'     => $job_id,
						'started_at' => $started_at,
						'timeout'    => $timeout,
					)
				);

				++$cleaned;
			}
		}

		if ( $cleaned > 0 ) {
			self::save_active_jobs( $active_jobs );
		}

		return $cleaned;
	}

	/**
	 * Get pending jobs sorted by priority.
	 *
	 * @param array $queue Queue state.
	 *
	 * @return array Pending jobs sorted by priority.
	 */
	protected static function get_pending_jobs( array $queue ) {
		$pending = array();

		foreach ( $queue as $job_id => $job ) {
			if ( isset( $job['status'] ) && 'pending' === $job['status'] ) {
				$pending[ $job_id ] = $job;
			}
		}

		// Sort by priority (higher first) then by enqueue time.
		uasort(
			$pending,
			function ( $a, $b ) {
				$priority_a = isset( $a['priority'] ) ? absint( $a['priority'] ) : self::PRIORITY_NORMAL;
				$priority_b = isset( $b['priority'] ) ? absint( $b['priority'] ) : self::PRIORITY_NORMAL;

				if ( $priority_a !== $priority_b ) {
					return $priority_b - $priority_a;
				}

				$time_a = isset( $a['enqueued_at'] ) ? absint( $a['enqueued_at'] ) : 0;
				$time_b = isset( $b['enqueued_at'] ) ? absint( $b['enqueued_at'] ) : 0;

				return $time_a - $time_b;
			}
		);

		return $pending;
	}

	/**
	 * Get the current queue state.
	 *
	 * @return array Queue state.
	 */
	protected static function get_queue_state() {
		$queue = get_option( self::QUEUE_STATE_OPTION, array() );
		return is_array( $queue ) ? $queue : array();
	}

	/**
	 * Save the queue state.
	 *
	 * @param array $queue Queue state.
	 *
	 * @return bool True on success.
	 */
	protected static function save_queue_state( array $queue ) {
		return update_option( self::QUEUE_STATE_OPTION, $queue, false );
	}

	/**
	 * Get active jobs.
	 *
	 * @return array Active jobs.
	 */
	protected static function get_active_jobs() {
		$active = get_option( self::ACTIVE_JOBS_OPTION, array() );
		return is_array( $active ) ? $active : array();
	}

	/**
	 * Save active jobs.
	 *
	 * @param array $active_jobs Active jobs.
	 *
	 * @return bool True on success.
	 */
	protected static function save_active_jobs( array $active_jobs ) {
		return update_option( self::ACTIVE_JOBS_OPTION, $active_jobs, false );
	}

	/**
	 * Get queue statistics.
	 *
	 * @return array Queue statistics.
	 */
	public static function get_queue_stats() {
		$queue       = self::get_queue_state();
		$active_jobs = self::get_active_jobs();

		$stats = array(
			'total'   => count( $queue ),
			'active'  => count( $active_jobs ),
			'pending' => 0,
			'failed'  => 0,
		);

		foreach ( $queue as $job ) {
			$status = isset( $job['status'] ) ? $job['status'] : 'pending';

			if ( 'pending' === $status ) {
				++$stats['pending'];
			} elseif ( 'failed' === $status ) {
				++$stats['failed'];
			}
		}

		return $stats;
	}

	/**
	 * Clear all jobs from the queue.
	 *
	 * @return bool True on success.
	 */
	public static function clear_queue() {
		delete_option( self::QUEUE_STATE_OPTION );
		delete_option( self::ACTIVE_JOBS_OPTION );

		WP_MCP_AI_Logger::log_event( 'queue_cleared', 'Job queue cleared.' );

		return true;
	}
}
