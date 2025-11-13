<?php
/**
 * Cron Status Service
 *
 * Provides lightweight cron job status information for chat interfaces.
 * Follows separation of concerns by encapsulating cron status logic.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';
}

/**
 * Cron Status Service class
 *
 * Responsible for:
 * - Retrieving active/pending/completed cron job status
 * - Providing lightweight status data for UI consumption
 * - Filtering and formatting job information
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Cron_Status_Service {

	/**
	 * Get cron job status summary
	 *
	 * Returns a lightweight array of active and recently completed jobs.
	 * Only includes jobs created by the current user or accessible to admins.
	 *
	 * @param int $user_id User ID to filter jobs by (0 for all if admin).
	 * @param int $limit   Maximum number of jobs to return (default 10).
	 * @return array Array of job status objects.
	 */
	public function get_status_summary( $user_id = 0, $limit = 10 ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		// Check permissions.
		$is_admin = user_can( $user_id, 'manage_options' );

		// Prune stale jobs first.
		WP_MCP_AI_Cron_Manager::maybe_prune_jobs();

		// Get all jobs.
		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();

		if ( empty( $jobs ) ) {
			return array();
		}

		$status_data = array();
		$count       = 0;

		foreach ( $jobs as $job_id => $job ) {
			if ( $count >= $limit ) {
				break;
			}

			// Filter by user unless admin.
			$created_by = isset( $job['created_by'] ) ? absint( $job['created_by'] ) : 0;
			if ( ! $is_admin && $created_by !== $user_id ) {
				continue;
			}

			$hook            = isset( $job['hook'] ) ? (string) $job['hook'] : '';
			$args            = isset( $job['args'] ) ? $job['args'] : array();
			$args            = WP_MCP_AI_Cron_Manager::normalise_args( $args );
			$first_timestamp = isset( $job['first_timestamp'] ) ? absint( $job['first_timestamp'] ) : 0;

			// Get scheduled event from WordPress.
			$event = wp_get_scheduled_event( $hook, $args );

			// Determine status.
			$status = $this->determine_job_status( $event, $first_timestamp );

			// Format job data.
			$job_data = array(
				'job_id'     => $job_id,
				'hook'       => $hook,
				'status'     => $status,
				'next_run'   => null,
				'created_by' => $created_by,
			);

			if ( 'pending' === $status && $event ) {
				$job_data['next_run'] = array(
					'timestamp' => $event->timestamp,
					'relative'  => $this->format_relative_time( $event->timestamp ),
				);
			} elseif ( 'completed' === $status && $first_timestamp > 0 ) {
				$job_data['completed_at'] = array(
					'timestamp' => $first_timestamp,
					'relative'  => $this->format_relative_time( $first_timestamp, true ),
				);
			}

			$status_data[] = $job_data;
			++$count;
		}

		return $status_data;
	}

	/**
	 * Determine the status of a cron job
	 *
	 * @param object|false $event           Scheduled event object or false.
	 * @param int          $first_timestamp First scheduled timestamp.
	 * @return string Status: 'pending', 'processing', or 'completed'.
	 */
	private function determine_job_status( $event, $first_timestamp ) {
		// If event is scheduled, it's pending.
		if ( $event ) {
			return 'pending';
		}

		// If no event but has first_timestamp in the past, it's completed.
		if ( $first_timestamp > 0 && $first_timestamp < time() ) {
			return 'completed';
		}

		// Default to completed if not scheduled.
		return 'completed';
	}

	/**
	 * Format relative time for display
	 *
	 * @param int  $timestamp    Unix timestamp.
	 * @param bool $past         Whether the timestamp is in the past.
	 * @return string Formatted relative time string.
	 */
	private function format_relative_time( $timestamp, $past = false ) {
		$diff = abs( time() - $timestamp );

		if ( $diff < MINUTE_IN_SECONDS ) {
			return $past
				? __( 'Just now', 'wp-mcp-ai' )
				: __( 'In less than a minute', 'wp-mcp-ai' );
		}

		if ( $diff < HOUR_IN_SECONDS ) {
			$minutes = floor( $diff / MINUTE_IN_SECONDS );
			return $past
				? sprintf(
					/* translators: %d: number of minutes */
					_n( '%d minute ago', '%d minutes ago', $minutes, 'wp-mcp-ai' ),
					$minutes
				)
				: sprintf(
					/* translators: %d: number of minutes */
					_n( 'In %d minute', 'In %d minutes', $minutes, 'wp-mcp-ai' ),
					$minutes
				);
		}

		if ( $diff < DAY_IN_SECONDS ) {
			$hours = floor( $diff / HOUR_IN_SECONDS );
			return $past
				? sprintf(
					/* translators: %d: number of hours */
					_n( '%d hour ago', '%d hours ago', $hours, 'wp-mcp-ai' ),
					$hours
				)
				: sprintf(
					/* translators: %d: number of hours */
					_n( 'In %d hour', 'In %d hours', $hours, 'wp-mcp-ai' ),
					$hours
				);
		}

		$days = floor( $diff / DAY_IN_SECONDS );
		return $past
			? sprintf(
				/* translators: %d: number of days */
				_n( '%d day ago', '%d days ago', $days, 'wp-mcp-ai' ),
				$days
			)
			: sprintf(
				/* translators: %d: number of days */
				_n( 'In %d day', 'In %d days', $days, 'wp-mcp-ai' ),
				$days
			);
	}

	/**
	 * Get count of jobs by status
	 *
	 * @param int $user_id User ID to filter by.
	 * @return array Array with counts: pending, completed, total.
	 */
	public function get_status_counts( $user_id = 0 ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		$is_admin = user_can( $user_id, 'manage_options' );

		WP_MCP_AI_Cron_Manager::maybe_prune_jobs();
		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();

		$counts = array(
			'pending'   => 0,
			'completed' => 0,
			'total'     => 0,
		);

		foreach ( $jobs as $job ) {
			$created_by = isset( $job['created_by'] ) ? absint( $job['created_by'] ) : 0;
			if ( ! $is_admin && $created_by !== $user_id ) {
				continue;
			}

			$hook            = isset( $job['hook'] ) ? (string) $job['hook'] : '';
			$args            = isset( $job['args'] ) ? $job['args'] : array();
			$args            = WP_MCP_AI_Cron_Manager::normalise_args( $args );
			$first_timestamp = isset( $job['first_timestamp'] ) ? absint( $job['first_timestamp'] ) : 0;

			$event  = wp_get_scheduled_event( $hook, $args );
			$status = $this->determine_job_status( $event, $first_timestamp );

			if ( 'pending' === $status ) {
				++$counts['pending'];
			} else {
				++$counts['completed'];
			}

			++$counts['total'];
		}

		return $counts;
	}
}
