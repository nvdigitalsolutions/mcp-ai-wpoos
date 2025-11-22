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
 * - Including async tool execution results
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Cron_Status_Service {

	/**
	 * Video generation tool slug constant
	 *
	 * @var string
	 */
	const VIDEO_GENERATION_TOOL_SLUG = 'generate_veo_video';

	/**
	 * Video generation job type constant
	 *
	 * @var string
	 */
	const VIDEO_GENERATION_JOB_TYPE = 'video_generation';

	/**
	 * Async tool executor instance (lazy loaded)
	 *
	 * @var WP_MCP_AI_Tool_Async_Executor|null
	 */
	protected $async_executor = null;

	/**
	 * Get cron job status summary
	 *
	 * Returns a lightweight array of active and recently completed jobs.
	 * Only includes jobs created by the current user or accessible to admins.
	 * Now includes async tool execution jobs.
	 * Supports filtering by assistant_id for multi-widget isolation.
	 * Supports filtering by context to hide internal jobs from chat clients.
	 *
	 * @param int      $user_id User ID to filter jobs by (0 for all if admin).
	 * @param int      $limit   Maximum number of jobs to return (default 10).
	 * @param int|null $assistant_id Optional assistant ID to filter jobs for specific chat widget.
	 * @param string   $context Context for filtering: 'chat' excludes internal async jobs, 'admin' shows all.
	 * @return array Array of job status objects.
	 */
	public function get_status_summary( $user_id = 0, $limit = 10, $assistant_id = null, $context = 'admin' ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		// Check permissions.
		$is_admin = user_can( $user_id, 'manage_options' );

		// Prune stale jobs first.
		WP_MCP_AI_Cron_Manager::maybe_prune_jobs();

		// Get all jobs from three sources.
		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();

		// When context is 'chat', filter out internal infrastructure jobs.
		// Internal jobs are those with specific system hooks that are not user-initiated.
		// User-created jobs (via create_cron_job tool, etc.) should always be visible.
		if ( 'chat' === $context ) {
			$jobs = $this->filter_internal_jobs( $jobs );
		}

		// Get async tool jobs and video jobs with optional assistant filter.
		// Include async tool jobs in both admin and chat contexts so jobs created via chat are visible.
		$async_jobs = $this->get_async_tool_jobs( $user_id, $assistant_id );
		$video_jobs = $this->get_video_generation_jobs( $user_id, $assistant_id );

		// Log job counts for debugging.
		WP_MCP_AI_Logger::log_event(
			'cron_status_summary_requested',
			'Cron status summary retrieved',
			array(
				'user_id'          => $user_id,
				'is_admin'         => $is_admin,
				'assistant_id'     => $assistant_id,
				'context'          => $context,
				'regular_jobs'     => count( $jobs ),
				'async_tool_jobs'  => count( $async_jobs ),
				'video_jobs'       => count( $video_jobs ),
			)
		);

		// Merge async tool jobs, video jobs, and regular cron jobs.
		$all_jobs = array_merge( $jobs, $async_jobs, $video_jobs );

		if ( empty( $all_jobs ) ) {
			return array();
		}

		$status_data = array();
		$count       = 0;

		foreach ( $all_jobs as $job_id => $job ) {
			if ( $count >= $limit ) {
				break;
			}

			// Filter by user unless admin.
			$created_by = isset( $job['created_by'] ) ? absint( $job['created_by'] ) : 0;
			if ( ! $is_admin && $created_by !== $user_id ) {
				continue;
			}

			// Filter by assistant_id if specified (for multi-widget isolation).
			if ( null !== $assistant_id ) {
				$job_assistant_id = isset( $job['assistant_id'] ) ? absint( $job['assistant_id'] ) : 0;
				if ( $job_assistant_id !== $assistant_id ) {
					continue;
				}
			}

			// Check if this is an async tool job.
			$is_async_tool = isset( $job['tool_slug'] );

			if ( $is_async_tool ) {
				// Format async tool job data.
				$job_data = $this->format_async_tool_job( $job );
			} else {
				// Format regular cron job data.
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
					'job_id'       => $job_id,
					'hook'         => $hook,
					'status'       => $status,
					'next_run'     => null,
					'created_by'   => $created_by,
					'assistant_id' => isset( $job['assistant_id'] ) ? absint( $job['assistant_id'] ) : 0,
					'admin_url'    => $this->get_admin_url( $job_id ),
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

					// Check for stored result.
					$result = WP_MCP_AI_Cron_Manager::get_job_result( $job_id );
					if ( $result ) {
						$job_data['has_result'] = true;
						$job_data['result']     = $result['result'];
						if ( isset( $result['status'] ) ) {
							$job_data['status'] = $result['status'];
						}
					}
				}
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
	 * Get async tool jobs for a user
	 *
	 * Retrieves async tool execution jobs and formats them for status display.
	 * Follows SOC by delegating actual job retrieval to async executor.
	 * Supports filtering by assistant_id for multi-widget isolation.
	 *
	 * @param int      $user_id User ID to filter by.
	 * @param int|null $assistant_id Optional assistant ID to filter by.
	 * @return array Array of async tool jobs formatted like cron jobs.
	 */
	protected function get_async_tool_jobs( $user_id, $assistant_id = null ) {
		// Lazy load async executor.
		$executor = $this->get_async_executor();

		if ( ! $executor ) {
			return array();
		}

		// Get async tool jobs from transients.
		// This is a lightweight operation that doesn't hit the main jobs table.
		global $wpdb;

		$prefix = WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$transient_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT REPLACE(option_name, '_transient_', '') as transient_key 
				FROM {$wpdb->options} 
				WHERE option_name LIKE %s 
				LIMIT 50",
				$wpdb->esc_like( '_transient_' . $prefix ) . '%'
			)
		);

		if ( empty( $transient_keys ) ) {
			return array();
		}

		$is_admin = user_can( $user_id, 'manage_options' );
		$jobs     = array();

		foreach ( $transient_keys as $transient_key ) {
			$metadata = get_transient( $transient_key );

			if ( ! $metadata || ! is_array( $metadata ) ) {
				continue;
			}

			// Filter by user unless admin.
			$job_user_id = isset( $metadata['context']['user_id'] ) ? absint( $metadata['context']['user_id'] ) : 0;

			if ( ! $is_admin && $job_user_id !== $user_id ) {
				continue;
			}

			// Filter by assistant_id if specified (for multi-widget isolation).
			if ( null !== $assistant_id ) {
				$job_assistant_id = isset( $metadata['context']['assistant_id'] ) ? absint( $metadata['context']['assistant_id'] ) : 0;

				if ( $job_assistant_id !== $assistant_id ) {
					continue;
				}
			}

			// Add user_id and assistant_id for consistency.
			$metadata['created_by']   = $job_user_id;
			$metadata['assistant_id'] = isset( $metadata['context']['assistant_id'] ) ? absint( $metadata['context']['assistant_id'] ) : 0;

			$jobs[ $metadata['job_id'] ] = $metadata;
		}

		return $jobs;
	}

	/**
	 * Get video generation jobs for a user
	 *
	 * Retrieves Veo video generation jobs and formats them for status display.
	 * Supports filtering by assistant_id for multi-widget isolation.
	 *
	 * Note: This uses a LIKE query on the options table similar to get_async_tool_jobs.
	 * Performance impact is limited by the LIMIT 50 clause and transient expiration (24h).
	 * For better performance, consider implementing a dedicated job tracking table if
	 * the number of concurrent jobs becomes significant.
	 *
	 * @param int      $user_id User ID to filter by.
	 * @param int|null $assistant_id Optional assistant ID to filter by.
	 * @return array Array of video generation jobs formatted like cron jobs.
	 */
	protected function get_video_generation_jobs( $user_id, $assistant_id = null ) {
		global $wpdb;

		$prefix = 'wp_mcp_ai_veo_async_';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$transient_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT REPLACE(option_name, '_transient_', '') as transient_key 
				FROM {$wpdb->options} 
				WHERE option_name LIKE %s 
				LIMIT 50",
				$wpdb->esc_like( '_transient_' . $prefix ) . '%'
			)
		);

		if ( empty( $transient_keys ) ) {
			return array();
		}

		$is_admin = user_can( $user_id, 'manage_options' );
		$jobs     = array();

		foreach ( $transient_keys as $transient_key ) {
			$metadata = get_transient( $transient_key );

			if ( ! $metadata || ! is_array( $metadata ) ) {
				continue;
			}

			// Filter by user unless admin.
			$job_user_id = isset( $metadata['args']['user_id'] ) ? absint( $metadata['args']['user_id'] ) : 0;

			if ( ! $is_admin && $job_user_id !== $user_id ) {
				continue;
			}

			// Filter by assistant_id if specified (for multi-widget isolation).
			if ( null !== $assistant_id ) {
				$job_assistant_id = isset( $metadata['args']['assistant_id'] ) ? absint( $metadata['args']['assistant_id'] ) : 0;

				if ( $job_assistant_id !== $assistant_id ) {
					continue;
				}
			}

			// Add user_id and assistant_id for consistency.
			$metadata['created_by']   = $job_user_id;
			$metadata['assistant_id'] = isset( $metadata['args']['assistant_id'] ) ? absint( $metadata['args']['assistant_id'] ) : 0;
			$metadata['tool_slug']    = self::VIDEO_GENERATION_TOOL_SLUG;
			$metadata['type']         = self::VIDEO_GENERATION_JOB_TYPE;

			$jobs[ $metadata['job_id'] ] = $metadata;
		}

		return $jobs;
	}

	/**
	 * Format async tool job for status display
	 *
	 * Transforms async tool job metadata into consistent status format.
	 * Includes full result data for completed jobs to support agentic workflows.
	 * Follows SOC by only formatting, not executing or managing jobs.
	 *
	 * @param array $job Async tool job metadata.
	 * @return array Formatted job data for UI with result data when available.
	 */
	protected function format_async_tool_job( $job ) {
		$job_id     = isset( $job['job_id'] ) ? $job['job_id'] : '';
		$tool_slug  = isset( $job['tool_slug'] ) ? $job['tool_slug'] : 'unknown';
		$status     = isset( $job['status'] ) ? $job['status'] : 'unknown';
		$created_by = isset( $job['created_by'] ) ? absint( $job['created_by'] ) : 0;

		$job_data = array(
			'job_id'     => $job_id,
			'hook'       => 'wp_mcp_ai_async_tool_execution',
			'tool_slug'  => $tool_slug,
			'status'     => $status,
			'type'       => 'async_tool',
			'created_by' => $created_by,
			'admin_url'  => $this->get_admin_url( $job_id ),
		);

		// Add timing information based on status.
		if ( 'pending' === $status && isset( $job['queued_at'] ) ) {
			$job_data['queued_at'] = array(
				'timestamp' => $job['queued_at'],
				'relative'  => $this->format_relative_time( $job['queued_at'], true ),
			);
		} elseif ( 'running' === $status && isset( $job['started_at'] ) ) {
			$job_data['started_at'] = array(
				'timestamp' => $job['started_at'],
				'relative'  => $this->format_relative_time( $job['started_at'], true ),
			);
		} elseif ( 'completed' === $status && isset( $job['completed_at'] ) ) {
			$job_data['completed_at'] = array(
				'timestamp' => $job['completed_at'],
				'relative'  => $this->format_relative_time( $job['completed_at'], true ),
			);

			// Include full result data for agentic workflow.
			if ( isset( $job['result'] ) ) {
				$job_data['has_result'] = true;
				$job_data['result']     = $job['result'];
			}

			if ( isset( $job['duration'] ) ) {
				$job_data['duration'] = round( $job['duration'], 2 );
			}
		} elseif ( 'failed' === $status ) {
			if ( isset( $job['completed_at'] ) ) {
				$job_data['failed_at'] = array(
					'timestamp' => $job['completed_at'],
					'relative'  => $this->format_relative_time( $job['completed_at'], true ),
				);
			}

			if ( isset( $job['error'] ) ) {
				$job_data['error'] = sanitize_text_field( $job['error'] );
			}
		}

		return $job_data;
	}

	/**
	 * Get async executor instance (lazy loaded)
	 *
	 * @return WP_MCP_AI_Tool_Async_Executor|null
	 */
	protected function get_async_executor() {
		if ( null === $this->async_executor ) {
			if ( class_exists( 'WP_MCP_AI_Tool_Async_Executor' ) ) {
				$this->async_executor = new WP_MCP_AI_Tool_Async_Executor();
			}
		}

		return $this->async_executor;
	}

	/**
	 * Get count of jobs by status
	 *
	 * Now includes async tool jobs in counts.
	 * Supports filtering by assistant_id for multi-widget isolation.
	 * Supports filtering by context to hide internal jobs from chat clients.
	 *
	 * @param int      $user_id User ID to filter by.
	 * @param int|null $assistant_id Optional assistant ID to filter by.
	 * @param string   $context Context for filtering: 'chat' excludes internal async jobs, 'admin' shows all.
	 * @return array Array with counts: pending, running, completed, failed, total.
	 */
	public function get_status_counts( $user_id = 0, $assistant_id = null, $context = 'admin' ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		$is_admin = user_can( $user_id, 'manage_options' );

		WP_MCP_AI_Cron_Manager::maybe_prune_jobs();
		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();

		// When context is 'chat', filter out internal infrastructure jobs.
		if ( 'chat' === $context ) {
			$jobs = $this->filter_internal_jobs( $jobs );
		}

		// Include async tool jobs and video jobs with optional assistant filter.
		// Include async tool jobs in both admin and chat contexts so jobs created via chat are visible.
		$async_jobs = $this->get_async_tool_jobs( $user_id, $assistant_id );
		$video_jobs = $this->get_video_generation_jobs( $user_id, $assistant_id );
		$all_jobs           = array_merge( $jobs, $async_jobs, $video_jobs );

		$counts = array(
			'pending'   => 0,
			'running'   => 0,
			'completed' => 0,
			'failed'    => 0,
			'total'     => 0,
		);

		foreach ( $all_jobs as $job ) {
			$created_by = isset( $job['created_by'] ) ? absint( $job['created_by'] ) : 0;
			if ( ! $is_admin && $created_by !== $user_id ) {
				continue;
			}

			// Filter by assistant_id if specified (for multi-widget isolation).
			if ( null !== $assistant_id ) {
				$job_assistant_id = isset( $job['assistant_id'] ) ? absint( $job['assistant_id'] ) : 0;
				if ( $job_assistant_id !== $assistant_id ) {
					continue;
				}
			}

			// Check if async tool job.
			if ( isset( $job['tool_slug'] ) && isset( $job['status'] ) ) {
				$status = $job['status'];
			} else {
				$hook            = isset( $job['hook'] ) ? (string) $job['hook'] : '';
				$args            = isset( $job['args'] ) ? $job['args'] : array();
				$args            = WP_MCP_AI_Cron_Manager::normalise_args( $args );
				$first_timestamp = isset( $job['first_timestamp'] ) ? absint( $job['first_timestamp'] ) : 0;

				$event  = wp_get_scheduled_event( $hook, $args );
				$status = $this->determine_job_status( $event, $first_timestamp );
			}

			// Count by status.
			if ( 'pending' === $status ) {
				++$counts['pending'];
			} elseif ( 'running' === $status || 'polling' === $status ) {
				++$counts['running'];
			} elseif ( 'failed' === $status ) {
				++$counts['failed'];
			} else {
				++$counts['completed'];
			}

			++$counts['total'];
		}

		return $counts;
	}

	/**
	 * Filter out internal infrastructure jobs from the jobs array
	 *
	 * Internal jobs are those created by the system for async tool execution
	 * and cleanup. User-initiated jobs should always be visible:
	 * - User-created cron jobs (via create_cron_job tool)
	 * - Video generation jobs (user explicitly requested)
	 *
	 * @param array $jobs Array of jobs to filter.
	 * @return array Filtered array with internal jobs removed.
	 */
	protected function filter_internal_jobs( $jobs ) {
		if ( empty( $jobs ) ) {
			return $jobs;
		}

		// List of internal system hooks that should be hidden from chat clients.
		// Note: wp_mcp_ai_poll_veo_video is NOT included because video generation
		// is a user-initiated action, not internal infrastructure.
		$internal_hooks = array(
			'wp_mcp_ai_async_tool_execution', // Async tool executor (internal infrastructure).
			'wp_mcp_ai_cleanup_async_results', // Cleanup job (internal maintenance).
		);

		$filtered_jobs = array();

		foreach ( $jobs as $job_id => $job ) {
			$hook = isset( $job['hook'] ) ? $job['hook'] : '';

			// Skip internal infrastructure jobs.
			if ( in_array( $hook, $internal_hooks, true ) ) {
				continue;
			}

			$filtered_jobs[ $job_id ] = $job;
		}

		return $filtered_jobs;
	}

	/**
	 * Get admin URL for viewing cron manager page
	 *
	 * Returns the URL to the admin cron manager page.
	 * Follows SOC by keeping URL generation logic in one place.
	 *
	 * @param string|null $job_id Optional job ID to highlight in admin.
	 * @return string Admin URL for cron manager.
	 */
	public function get_admin_url( $job_id = null ) {
		$url = admin_url( 'admin.php?page=wp-mcp-ai-cron-manager' );

		if ( $job_id ) {
			$url = add_query_arg( 'highlight', sanitize_key( $job_id ), $url );
		}

		return $url;
	}

	/**
	 * Get details for a specific cron job
	 *
	 * Returns detailed information about a single cron job.
	 * Checks permissions before returning data.
	 * Follows SOC by delegating to appropriate services.
	 *
	 * @param string $job_id Job identifier.
	 * @param int    $user_id User requesting the job details.
	 * @return array|WP_Error Job details or error.
	 */
	public function get_job_details( $job_id, $user_id = 0 ) {
		// Sanitize job_id preserving dots (used in veo_ job IDs from uniqid).
		// This logic matches the sanitization in REST Tools Controller to ensure consistency.
		// Remove any characters that aren't alphanumeric, underscore, dot, or hyphen.
		$job_id = preg_replace( '/[^a-zA-Z0-9_.\-]/', '', $job_id );
		// Remove path traversal attempts (consecutive dots).
		$job_id = preg_replace( '/\.\.+/', '', $job_id );

		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		$is_admin = user_can( $user_id, 'manage_options' );

		// Check if it's a video generation job.
		if ( 0 === strpos( $job_id, 'veo_' ) ) {
			if ( ! class_exists( 'WP_MCP_AI_Gemini_Video_Generation_Service' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
			}

			$video_service = new WP_MCP_AI_Gemini_Video_Generation_Service();
			$result        = $video_service->get_async_status( $job_id );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Check permissions - video jobs store user_id in args.
			$job_user_id = 0;
			if ( isset( $result['args']['user_id'] ) ) {
				$job_user_id = absint( $result['args']['user_id'] );
			}

			if ( ! $is_admin && $job_user_id !== $user_id ) {
				return new WP_Error(
					'wp_mcp_ai_forbidden',
					__( 'You do not have permission to view this job.', 'wp-mcp-ai' )
				);
			}

			// Add admin URL.
			$result['admin_url'] = $this->get_admin_url( $job_id );

			return $result;
		}

		// Check if it's an async tool job.
		if ( 0 === strpos( $job_id, 'async_' ) ) {
			$executor = $this->get_async_executor();
			if ( ! $executor ) {
				return new WP_Error(
					'wp_mcp_ai_service_unavailable',
					__( 'Async executor service is not available.', 'wp-mcp-ai' )
				);
			}

			$result = $executor->get_result( $job_id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Check permissions.
			$created_by = isset( $result['context']['user_id'] ) ? absint( $result['context']['user_id'] ) : 0;
			if ( ! $is_admin && $created_by !== $user_id ) {
				return new WP_Error(
					'wp_mcp_ai_forbidden',
					__( 'You do not have permission to view this job.', 'wp-mcp-ai' )
				);
			}

			// Add admin URL.
			$result['admin_url'] = $this->get_admin_url( $job_id );

			return $result;
		}

		// Regular cron job.
		$job = WP_MCP_AI_Cron_Manager::get_job( $job_id );

		if ( ! $job ) {
			return new WP_Error(
				'wp_mcp_ai_job_not_found',
				__( 'Job not found or has been removed.', 'wp-mcp-ai' )
			);
		}

		// Check permissions.
		$created_by = isset( $job['created_by'] ) ? absint( $job['created_by'] ) : 0;
		if ( ! $is_admin && $created_by !== $user_id ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to view this job.', 'wp-mcp-ai' )
			);
		}

		// Add runtime status.
		$hook            = isset( $job['hook'] ) ? (string) $job['hook'] : '';
		$args            = isset( $job['args'] ) ? $job['args'] : array();
		$args            = WP_MCP_AI_Cron_Manager::normalise_args( $args );
		$first_timestamp = isset( $job['first_timestamp'] ) ? absint( $job['first_timestamp'] ) : 0;

		$event           = wp_get_scheduled_event( $hook, $args );
		$job['status']   = $this->determine_job_status( $event, $first_timestamp );
		$job['next_run'] = $event ? $event->timestamp : null;

		// Check for stored result.
		$result = WP_MCP_AI_Cron_Manager::get_job_result( $job_id );
		if ( $result ) {
			$job['has_result'] = true;
			$job['result']     = $result['result'];
			if ( isset( $result['completed_at'] ) ) {
				$job['completed_at'] = $result['completed_at'];
			}
			if ( isset( $result['status'] ) ) {
				$job['status'] = $result['status'];
			}
		}

		// Add admin URL.
		$job['admin_url'] = $this->get_admin_url( $job_id );

		return $job;
	}
}
