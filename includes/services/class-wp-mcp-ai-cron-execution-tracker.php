<?php
/**
 * Cron Execution Tracker Service
 *
 * Tracks execution of cron jobs and dispatches lifecycle events.
 * Completes the agentic loop by notifying when jobs actually execute.
 *
 * Responsibilities:
 * - Track cron job execution start/completion
 * - Dispatch execution notifications to Event Dispatcher
 * - Handle execution failures and errors
 * - Record execution duration and results
 *
 * Does NOT:
 * - Execute the cron jobs themselves
 * - Manage job scheduling
 * - Format notifications (Event Dispatcher does that)
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cron Execution Tracker Service class
 *
 * Provides execution tracking for user-created cron jobs.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Cron_Execution_Tracker {

	/**
	 * Option key for tracking executing jobs
	 *
	 * @var string
	 */
	const EXECUTING_JOBS_OPTION = 'wp_mcp_ai_executing_cron_jobs';

	/**
	 * Initialize the tracker and register hooks
	 */
	public static function init() {
		// Hook into all registered cron events to track execution.
		// Priority 1 to run before the actual job.
		add_action( 'all', array( __CLASS__, 'track_cron_execution' ), 1 );
	}

	/**
	 * Track cron execution for WP oOS-created jobs
	 *
	 * This hooks into WordPress 'all' action to catch cron executions.
	 * Only tracks jobs that were created through WP oOS tools.
	 *
	 * @param string $hook The current hook being executed.
	 */
	public static function track_cron_execution( $hook ) {
		// Ignore if not a cron context.
		if ( ! defined( 'DOING_CRON' ) || ! DOING_CRON ) {
			return;
		}

		// Get the current filter args to retrieve cron arguments.
		$args = func_get_args();
		array_shift( $args ); // Remove the hook name.

		// Check if this is a tracked WP oOS job.
		if ( ! class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
			return;
		}

		// Try to find a job with this hook and args.
		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$job  = null;

		foreach ( $jobs as $tracked_job ) {
			if ( isset( $tracked_job['hook'] ) && $tracked_job['hook'] === $hook ) {
				// Normalize both args for comparison.
				$tracked_args = isset( $tracked_job['args'] ) ? WP_MCP_AI_Cron_Manager::normalise_args( $tracked_job['args'] ) : array();
				$current_args = WP_MCP_AI_Cron_Manager::normalise_args( $args );

				// Compare args.
				if ( $tracked_args === $current_args ) {
					$job = $tracked_job;
					break;
				}
			}
		}

		// Not a tracked job, ignore.
		if ( ! $job ) {
			return;
		}

		$job_id = isset( $job['job_id'] ) ? $job['job_id'] : '';

		if ( empty( $job_id ) ) {
			return;
		}

		// Mark job as executing.
		self::mark_job_executing( $job_id, $job );

		// Register shutdown hook to track completion/failure.
		add_action( 'shutdown', array( __CLASS__, 'track_cron_completion' ), 999 );
	}

	/**
	 * Mark a job as currently executing
	 *
	 * @param string $job_id Job identifier.
	 * @param array  $job    Job data.
	 */
	protected static function mark_job_executing( $job_id, $job ) {
		$executing = get_option( self::EXECUTING_JOBS_OPTION, array() );

		$executing[ $job_id ] = array(
			'job_id'     => $job_id,
			'hook'       => isset( $job['hook'] ) ? $job['hook'] : '',
			'started_at' => microtime( true ),
			'user_id'    => isset( $job['created_by'] ) ? absint( $job['created_by'] ) : 0,
			'assistant_id' => isset( $job['assistant_id'] ) ? absint( $job['assistant_id'] ) : 0,
		);

		update_option( self::EXECUTING_JOBS_OPTION, $executing, false );
	}

	/**
	 * Track cron completion or failure on shutdown
	 */
	public static function track_cron_completion() {
		$executing = get_option( self::EXECUTING_JOBS_OPTION, array() );

		if ( empty( $executing ) ) {
			return;
		}

		$last_error = error_get_last();
		$has_error  = $last_error && in_array( $last_error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true );

		foreach ( $executing as $job_id => $job_data ) {
			$duration = microtime( true ) - $job_data['started_at'];

			if ( $has_error ) {
				// Job failed due to PHP error.
				$error_message = sprintf(
					'%s in %s on line %d',
					$last_error['message'],
					$last_error['file'],
					$last_error['line']
				);

				self::dispatch_failure( $job_id, $job_data, $error_message, $duration );
			} else {
				// Job completed successfully.
				self::dispatch_success( $job_id, $job_data, $duration );
			}
		}

		// Clear executing jobs.
		delete_option( self::EXECUTING_JOBS_OPTION );
	}

	/**
	 * Dispatch successful execution notification
	 *
	 * @param string $job_id   Job identifier.
	 * @param array  $job_data Job data.
	 * @param float  $duration Execution duration in seconds.
	 */
	protected static function dispatch_success( $job_id, $job_data, $duration ) {
		/**
		 * Fires when a cron job completes execution successfully.
		 *
		 * @since 1.0.0
		 *
		 * @param string $job_id   Job identifier.
		 * @param array  $metadata Job metadata including hook, user_id, assistant_id, duration.
		 */
		do_action(
			'wp_mcp_ai_cron_job_executed',
			$job_id,
			array(
				'hook'         => isset( $job_data['hook'] ) ? $job_data['hook'] : '',
				'duration'     => $duration,
				'user_id'      => isset( $job_data['user_id'] ) ? $job_data['user_id'] : 0,
				'assistant_id' => isset( $job_data['assistant_id'] ) ? $job_data['assistant_id'] : 0,
				'executed_at'  => time(),
			)
		);

		// Log execution for debugging.
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'cron_execution_success',
				sprintf( 'Cron job %s executed successfully', $job_id ),
				array(
					'job_id'   => $job_id,
					'hook'     => isset( $job_data['hook'] ) ? $job_data['hook'] : '',
					'duration' => $duration,
				)
			);
		}
	}

	/**
	 * Dispatch failure notification
	 *
	 * @param string $job_id       Job identifier.
	 * @param array  $job_data     Job data.
	 * @param string $error_message Error message.
	 * @param float  $duration     Execution duration before failure.
	 */
	protected static function dispatch_failure( $job_id, $job_data, $error_message, $duration ) {
		/**
		 * Fires when a cron job fails during execution.
		 *
		 * @since 1.0.0
		 *
		 * @param string $job_id       Job identifier.
		 * @param array  $metadata     Job metadata including hook, user_id, assistant_id, duration.
		 * @param string $error_message Error message.
		 */
		do_action(
			'wp_mcp_ai_cron_job_failed',
			$job_id,
			array(
				'hook'         => isset( $job_data['hook'] ) ? $job_data['hook'] : '',
				'duration'     => $duration,
				'user_id'      => isset( $job_data['user_id'] ) ? $job_data['user_id'] : 0,
				'assistant_id' => isset( $job_data['assistant_id'] ) ? $job_data['assistant_id'] : 0,
				'failed_at'    => time(),
			),
			$error_message
		);

		// Log failure for debugging.
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'cron_execution_failure',
				sprintf( 'Cron job %s failed: %s', $job_id, $error_message ),
				array(
					'job_id'   => $job_id,
					'hook'     => isset( $job_data['hook'] ) ? $job_data['hook'] : '',
					'error'    => $error_message,
					'duration' => $duration,
				)
			);
		}
	}

	/**
	 * Manually mark a job as executed (for programmatic completion)
	 *
	 * Use this when you have custom cron handlers that want to report success.
	 *
	 * @param string $job_id   Job identifier.
	 * @param array  $metadata Optional metadata (hook, user_id, assistant_id, duration).
	 */
	public static function mark_executed( $job_id, $metadata = array() ) {
		$duration = isset( $metadata['duration'] ) ? (float) $metadata['duration'] : 0;

		self::dispatch_success( $job_id, $metadata, $duration );
	}

	/**
	 * Manually mark a job as failed (for programmatic failure reporting)
	 *
	 * Use this when you have custom cron handlers that want to report failure.
	 *
	 * @param string $job_id       Job identifier.
	 * @param string $error_message Error message.
	 * @param array  $metadata     Optional metadata (hook, user_id, assistant_id, duration).
	 */
	public static function mark_failed( $job_id, $error_message, $metadata = array() ) {
		$duration = isset( $metadata['duration'] ) ? (float) $metadata['duration'] : 0;

		self::dispatch_failure( $job_id, $metadata, $error_message, $duration );
	}
}
