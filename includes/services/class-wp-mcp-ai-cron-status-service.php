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
	 * Tool registry instance (lazy loaded)
	 *
	 * @var WP_MCP_AI_Tool_Registry|null
	 */
	protected $tool_registry = null;

	/**
	 * Get cron job status summary
	 *
	 * Returns a lightweight array of active and recently completed jobs.
	 * Only includes jobs created by the current user or accessible to admins.
	 * Now includes async tool execution jobs.
	 * Supports filtering by assistant_id for multi-widget isolation.
	 *
	 * @param int      $user_id User ID to filter jobs by (0 for all if admin).
	 * @param int      $limit   Maximum number of jobs to return (default 10).
	 * @param int|null $assistant_id Optional assistant ID to filter jobs for specific chat widget.
	 * @return array Array of job status objects.
	 */
	public function get_status_summary( $user_id = 0, $limit = 10, $assistant_id = null ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		// Check permissions.
		$is_admin = user_can( $user_id, 'manage_options' );

		// Prune stale jobs first.
		WP_MCP_AI_Cron_Manager::maybe_prune_jobs();

		// Get async tool jobs with optional assistant filter.
		$async_jobs = $this->get_async_tool_jobs( $user_id, $assistant_id );

		// Get video generation jobs.
		$video_jobs = $this->get_video_generation_jobs( $user_id, $assistant_id );

		// When filtering by assistant_id, only include assistant-specific jobs (async and video).
		// Regular cron jobs from WP_MCP_AI_Cron_Manager don't have assistant_id association,
		// so they should only be shown when no assistant filter is applied (e.g., admin dashboard).
		if ( null !== $assistant_id ) {
			// Multi-widget isolation: only show jobs for this specific assistant.
			$all_jobs = array_merge( $async_jobs, $video_jobs );
		} else {
			// No filter: include all jobs (regular cron + async + video).
			$jobs     = WP_MCP_AI_Cron_Manager::get_jobs();
			$all_jobs = array_merge( $jobs, $async_jobs, $video_jobs );
		}

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
					'job_id'     => $job_id,
					'hook'       => $hook,
					'status'     => $status,
					'next_run'   => null,
					'created_by' => $created_by,
					'admin_url'  => $this->get_admin_url( $job_id ),
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

			// Add user_id for consistency.
			$metadata['created_by'] = $job_user_id;

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

			// Add user_id for consistency.
			$metadata['created_by'] = $job_user_id;
			$metadata['tool_slug']  = self::VIDEO_GENERATION_TOOL_SLUG;
			$metadata['type']       = self::VIDEO_GENERATION_JOB_TYPE;

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
	 * Get tool registry instance (lazy loaded)
	 *
	 * @return WP_MCP_AI_Tool_Registry|null Tool registry instance or null if unavailable.
	 */
	protected function get_tool_registry() {
		if ( null === $this->tool_registry ) {
			if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
				$this->tool_registry = WP_MCP_AI_Tool_Registry::get_instance();
			}
		}

		return $this->tool_registry;
	}

	/**
	 * Sanitize async tool result for chat client display
	 *
	 * Applies the tool's sanitize_for_llm() method if available to ensure
	 * proper formatting (e.g., adding video_url structure for videos).
	 * This is critical for async results because the tool's sanitization
	 * is applied during synchronous execution but not when results are
	 * retrieved from the async executor's transient storage.
	 *
	 * @param array  $job_metadata Job metadata from async executor.
	 * @param string $tool_slug    Tool slug to get the tool instance.
	 * @return array Updated metadata with sanitized result.
	 */
	protected function sanitize_async_tool_result( $job_metadata, $tool_slug ) {
		// Only sanitize if result is present and completed.
		if ( ! isset( $job_metadata['result'] ) || ! isset( $job_metadata['status'] ) || 'completed' !== $job_metadata['status'] ) {
			return $job_metadata;
		}

		$registry = $this->get_tool_registry();
		if ( ! $registry ) {
			return $job_metadata;
		}

		$tool_instance = $registry->get_tool( $tool_slug );
		if ( ! $tool_instance || ! ( $tool_instance instanceof WP_MCP_AI_Tool_LLM_Sanitizer_Interface ) ) {
			return $job_metadata;
		}

		// Apply tool's sanitization to the result.
		// This adds structures like video_url, image_url that the chat client expects.
		$job_metadata['result'] = $tool_instance->sanitize_for_llm( $job_metadata['result'] );

		return $job_metadata;
	}

	/**
	 * Get count of jobs by status
	 *
	 * Now includes async tool jobs in counts.
	 * Supports filtering by assistant_id for multi-widget isolation.
	 *
	 * @param int      $user_id User ID to filter by.
	 * @param int|null $assistant_id Optional assistant ID to filter by.
	 * @return array Array with counts: pending, running, completed, failed, total.
	 */
	public function get_status_counts( $user_id = 0, $assistant_id = null ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		$is_admin = user_can( $user_id, 'manage_options' );

		WP_MCP_AI_Cron_Manager::maybe_prune_jobs();

		// Include async tool jobs and video jobs with optional assistant filter.
		$async_jobs = $this->get_async_tool_jobs( $user_id, $assistant_id );
		$video_jobs = $this->get_video_generation_jobs( $user_id, $assistant_id );

		// When filtering by assistant_id, only include assistant-specific jobs (async and video).
		// Regular cron jobs from WP_MCP_AI_Cron_Manager don't have assistant_id association,
		// so they should only be shown when no assistant filter is applied (e.g., admin dashboard).
		if ( null !== $assistant_id ) {
			// Multi-widget isolation: only show jobs for this specific assistant.
			$all_jobs = array_merge( $async_jobs, $video_jobs );
		} else {
			// No filter: include all jobs (regular cron + async + video).
			$jobs     = WP_MCP_AI_Cron_Manager::get_jobs();
			$all_jobs = array_merge( $jobs, $async_jobs, $video_jobs );
		}

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
	 * Merge Job Notifier status into result array.
	 *
	 * Checks Job Notifier cache for completion/failure status and merges
	 * the authoritative status into the result. This ensures the chat client
	 * receives completion notifications promptly, even if the transient
	 * hasn't been updated yet.
	 *
	 * @param array  $result The result array to merge status into.
	 * @param string $job_id Job identifier.
	 * @return array Modified result array with merged notifier status.
	 */
	protected function merge_notifier_status( $result, $job_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Job_Notifier' ) ) {
			return $result;
		}

		$notifier_status = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );
		if ( ! $notifier_status || ! is_array( $notifier_status ) ) {
			return $result;
		}

		// If Job Notifier shows completed or failed, use that status.
		// This is authoritative because the completion hook has already fired.
		if ( isset( $notifier_status['status'] ) ) {
			$notifier_job_status = $notifier_status['status'];

			if ( 'completed' === $notifier_job_status ) {
				$result['status'] = 'completed';

				// Merge result data from notifier if available and not already set.
				if ( isset( $notifier_status['result'] ) && is_array( $notifier_status['result'] ) ) {
					if ( ! isset( $result['result'] ) || empty( $result['result'] ) ) {
						$result['result'] = $notifier_status['result'];
					}

					// Sanitize the result before creating tool_results.
					// This is critical for tools like generate_veo_video that rely on
					// sanitize_for_llm() to add display structures (video_url, image_url, etc.).
					// Without this, notifier-driven completions would have unsanitized payloads.
					if ( isset( $result['tool_slug'] ) && ! empty( $result['tool_slug'] ) ) {
						$result = $this->sanitize_async_tool_result( $result, $result['tool_slug'] );
					}

					// Format result as tool_results array for chat client compatibility.
					// The chat client expects async tool results in the same format as sync results:
					// a tool_results array with tool messages containing the result data.
					// This ensures videos, images, and other media are properly displayed.
					if ( ! isset( $result['tool_results'] ) && isset( $result['tool_slug'] ) ) {
						$tool_name = sanitize_text_field( $result['tool_slug'] );

						// Use the original tool_call_id from context if available (stored during async queueing).
						// This ensures the async result has the same tool_call_id that the LLM provided
						// in the original tool call, allowing proper correlation in the chat client.
						// If not available, generate a fallback tool_call_id for traceability.
						$tool_call_id = '';
						if ( isset( $result['context']['tool_call_id'] ) && '' !== $result['context']['tool_call_id'] ) {
							$tool_call_id = sanitize_text_field( $result['context']['tool_call_id'] );
						} else {
							// Fallback: Generate a unique tool_call_id for async results without stored IDs.
							// Format: async_{tool_name}_{job_id} for traceability.
							$sanitized_tool_name = preg_replace( '/[^a-zA-Z0-9_]/', '_', $tool_name );
							$tool_call_id        = 'async_' . $sanitized_tool_name . '_' . sanitize_key( $job_id );
						}

						// Serialize the result for the tool message content.
						$result_content = wp_json_encode( $result['result'] );
						if ( false === $result_content ) {
							// JSON encoding failed - use a simple message instead.
							$result_content = wp_json_encode(
								array(
									'success' => true,
									'message' => __( 'Tool completed successfully but result could not be serialized.', 'wp-mcp-ai' ),
								)
							);
						}

						// Build tool message for tool_results array.
						$tool_message = array(
							'role'         => 'tool',
							'content'      => $result_content,
							'tool_call_id' => $tool_call_id,
							'name'         => $tool_name,
						);

						// Include usage data from result if available.
						// This enables cost/token badges in the chat UI.
						if ( isset( $result['result']['usage'] ) && is_array( $result['result']['usage'] ) ) {
							$tool_message['usage'] = $result['result']['usage'];
						}

						// Include cost data from result if available.
						// This enables cost badges in the chat UI.
						if ( isset( $result['result']['cost'] ) && is_array( $result['result']['cost'] ) ) {
							$tool_message['cost'] = $result['result']['cost'];
						}

						// Build tool_results array in OpenAI format.
						$result['tool_results'] = array( $tool_message );

						// Also add top-level cost for aggregated display.
						// The chat client checks both data.cost and data.tool_results[].cost.
						if ( isset( $result['result']['cost'] ) && ! isset( $result['cost'] ) ) {
							$result['cost'] = $result['result']['cost'];
						}
					}
				}
			} elseif ( 'failed' === $notifier_job_status ) {
				$result['status'] = 'failed';

				// Merge error from notifier if available.
				// Job Notifier stores errors as associative arrays (PHP) with 'message' and 'code' fields,
				// which become objects when sent to JavaScript. The chat client expects a simple string
				// in the 'error' field. Extract the message string for 'error' and keep the full
				// structure in 'error_data' for backward compatibility.
				if ( isset( $notifier_status['error'] ) ) {
					if ( is_array( $notifier_status['error'] ) && isset( $notifier_status['error']['message'] ) ) {
						// Error is a structured array - extract the message string.
						$result['error']      = $notifier_status['error']['message'];
						$result['error_data'] = $notifier_status['error'];
					} else {
						// Error is already a string or unknown format - use as-is.
						$result['error'] = $notifier_status['error'];
					}
				}
			}
		}

		// Add progress data if available.
		if ( isset( $notifier_status['progress'] ) ) {
			$result['progress'] = $notifier_status['progress'];
		}
		if ( isset( $notifier_status['metadata']['message'] ) ) {
			$result['progress_message'] = $notifier_status['metadata']['message'];
			// Also set 'message' field for delegation messages that include job IDs.
			// This ensures the chat UI can display the veo job ID to the user.
			if ( ! isset( $result['message'] ) || empty( $result['message'] ) ) {
				$result['message'] = $notifier_status['metadata']['message'];
			}
		}
		if ( isset( $notifier_status['metadata']['poll_attempt'] ) ) {
			$result['poll_attempt'] = $notifier_status['metadata']['poll_attempt'];
		}
		// Add delegated_to field if available from Job Notifier metadata.
		// This indicates the job was delegated to a nested async job (e.g., veo video generation).
		if ( isset( $notifier_status['metadata']['delegated_to'] ) && ! isset( $result['delegated_to'] ) ) {
			$result['delegated_to'] = $notifier_status['metadata']['delegated_to'];
		}

		return $result;
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

			// Merge Job Notifier status (completion/failure/progress).
			$result = $this->merge_notifier_status( $result, $job_id );

			// Add admin URL.
			$result['admin_url'] = $this->get_admin_url( $job_id );

			// Normalize result to ensure JSON serializability.
			return $this->normalize_data_recursive( $result );
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

			// Apply tool's sanitize_for_llm() to format result for chat client.
			// This is critical for tools like generate_veo_video which add video_url structure.
			// The sanitization is normally applied during sync execution but not when
			// results are retrieved from async storage.
			$tool_slug = isset( $result['tool_slug'] ) ? $result['tool_slug'] : '';
			if ( ! empty( $tool_slug ) ) {
				$result = $this->sanitize_async_tool_result( $result, $tool_slug );
			}

			// Merge Job Notifier status (completion/failure/progress).
			$result = $this->merge_notifier_status( $result, $job_id );

			// If the job is still showing as "delegated" after merging notifier status,
			// check if the delegated job has completed or failed and propagate its status.
			// This handles the case where the delegated job finished but the parent job
			// transient wasn't updated (e.g., due to timing issues or errors).
			// Note: This only checks veo_ jobs (which don't delegate further),
			// preventing infinite recursion.
			if ( 'delegated' === $result['status'] && isset( $result['delegated_to'] ) ) {
				$result = $this->handle_delegation_chain( $result, $job_id, $user_id, $tool_slug );
			}

			// Add admin URL.
			$result['admin_url'] = $this->get_admin_url( $job_id );

			// Normalize result to ensure JSON serializability.
			return $this->normalize_data_recursive( $result );
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

		// Add admin URL.
		$job['admin_url'] = $this->get_admin_url( $job_id );

		// Normalize result to ensure JSON serializability.
		return $this->normalize_data_recursive( $job );
	}

	/**
	 * Recursively normalize data structures to ensure JSON serializability.
	 *
	 * Walks through arrays and objects to convert any WP_Error instances
	 * to serializable array format. This prevents JSON encoding failures
	 * when sending data through SSE streams or REST API responses.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $data Data to normalize, can be any type.
	 * @return mixed Normalized data with all WP_Error objects converted to arrays.
	 */
	protected function normalize_data_recursive( $data ) {
		// Handle WP_Error directly.
		if ( is_wp_error( $data ) ) {
			$error_data  = $data->get_error_data();
			$error_array = array(
				'error'   => true,
				'code'    => $data->get_error_code(),
				'message' => $data->get_error_message(),
			);

			if ( ! empty( $error_data ) ) {
				$error_array['data'] = $error_data;
			}

			return $error_array;
		}

		// Handle arrays - recursively process each element.
		if ( is_array( $data ) ) {
			$normalized = array();
			foreach ( $data as $key => $value ) {
				$normalized[ $key ] = $this->normalize_data_recursive( $value );
			}
			return $normalized;
		}

		// Handle objects - convert to array and recurse.
		// Note: WP_Error is already handled above via is_wp_error() check.
		if ( is_object( $data ) ) {
			return $this->normalize_data_recursive( (array) $data );
		}

		// Scalars pass through unchanged.
		return $data;
	}

	/**
	 * Handle delegation chain for async jobs.
	 *
	 * When an async job delegates to another job (e.g., async_xxx -> veo_yyy),
	 * this method checks if the delegated job has completed or failed and
	 * propagates the status back to the parent job.
	 *
	 * This handles cases where:
	 * - The delegated job completed but the parent job transient wasn't updated
	 * - The delegated job failed and the parent should reflect the failure
	 * - Timing issues or errors prevented the normal completion callback
	 *
	 * Note: This only checks veo_ jobs (which don't delegate further),
	 * preventing infinite recursion.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $result    Parent job result from async executor.
	 * @param string $job_id    Parent job ID.
	 * @param int    $user_id   User ID for permission checks.
	 * @param string $tool_slug Tool slug for sanitization.
	 * @return array Modified result with delegated job status merged.
	 */
	protected function handle_delegation_chain( $result, $job_id, $user_id, $tool_slug ) {
		$delegated_job_id = $result['delegated_to'];

		// Only check veo_ jobs to prevent potential recursion.
		// Veo jobs complete directly and don't delegate to other jobs.
		if ( 0 !== strpos( $delegated_job_id, 'veo_' ) ) {
			return $result;
		}

		$delegated_result = $this->get_job_details( $delegated_job_id, $user_id );

		// If there was an error fetching the delegated job, return original result.
		if ( is_wp_error( $delegated_result ) ) {
			return $result;
		}

		// Check if the delegated job has a terminal status.
		if ( ! isset( $delegated_result['status'] ) ) {
			return $result;
		}

		$delegated_status = $delegated_result['status'];

		// Handle completed delegated job.
		if ( 'completed' === $delegated_status ) {
			$result['status'] = 'completed';

			// Copy result from delegated job if present.
			if ( isset( $delegated_result['result'] ) && ! empty( $delegated_result['result'] ) ) {
				$result['result'] = $delegated_result['result'];

				// Re-apply sanitization on the delegated job's result.
				if ( ! empty( $tool_slug ) ) {
					$result = $this->sanitize_async_tool_result( $result, $tool_slug );
				}

				// Build tool_results array for chat client compatibility.
				$result = $this->build_tool_results_for_delegated_job( $result, $job_id, $tool_slug );
			}

			// Copy other relevant fields from delegated result.
			if ( isset( $delegated_result['completed_at'] ) ) {
				$result['completed_at'] = $delegated_result['completed_at'];
			}

			// Note: We don't update the parent transient here to avoid race conditions
			// with the veo service's complete_parent_job(). This fallback runs on each
			// poll until complete_parent_job() updates the parent transient.
			return $result;
		}

		// Handle failed delegated job.
		if ( 'failed' === $delegated_status ) {
			$result['status'] = 'failed';

			// Copy error from delegated job if present.
			if ( isset( $delegated_result['error'] ) ) {
				$result['error'] = $delegated_result['error'];
			}

			if ( isset( $delegated_result['error_data'] ) ) {
				$result['error_data'] = $delegated_result['error_data'];
			}

			// Copy failure timestamp if available.
			if ( isset( $delegated_result['failed_at'] ) ) {
				$result['failed_at'] = $delegated_result['failed_at'];
			}

			return $result;
		}

		// For other statuses (pending, polling, running), pass through progress data.
		if ( isset( $delegated_result['progress'] ) ) {
			$result['progress'] = $delegated_result['progress'];
		}

		if ( isset( $delegated_result['progress_message'] ) ) {
			$result['progress_message'] = $delegated_result['progress_message'];
		}

		if ( isset( $delegated_result['poll_attempt'] ) ) {
			$result['poll_attempt'] = $delegated_result['poll_attempt'];
		}

		return $result;
	}

	/**
	 * Build tool_results array for a delegated job's result.
	 *
	 * Formats the result from a delegated job into the tool_results structure
	 * expected by the chat client for proper display of videos, images, etc.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $result    Job result containing the delegated job's data.
	 * @param string $job_id    Parent job ID for fallback tool_call_id.
	 * @param string $tool_slug Tool slug for the tool name.
	 * @return array Modified result with tool_results array.
	 */
	protected function build_tool_results_for_delegated_job( $result, $job_id, $tool_slug ) {
		// Skip if tool_results already exists.
		if ( isset( $result['tool_results'] ) ) {
			return $result;
		}

		// Skip if no result data.
		if ( ! isset( $result['result'] ) ) {
			return $result;
		}

		$tool_name = sanitize_text_field( $tool_slug );

		// Use the original tool_call_id from context if available.
		$tool_call_id = '';
		if ( isset( $result['context']['tool_call_id'] ) && '' !== $result['context']['tool_call_id'] ) {
			$tool_call_id = sanitize_text_field( $result['context']['tool_call_id'] );
		} else {
			// Fallback: Generate a unique tool_call_id.
			$sanitized_tool_name = preg_replace( '/[^a-zA-Z0-9_]/', '_', $tool_name );
			$tool_call_id        = 'async_' . $sanitized_tool_name . '_' . sanitize_key( $job_id );
		}

		// Serialize the result for the tool message content.
		$result_content = wp_json_encode( $result['result'] );
		if ( false === $result_content ) {
			$result_content = wp_json_encode(
				array(
					'success' => true,
					'message' => __( 'Tool completed successfully.', 'wp-mcp-ai' ),
				)
			);
		}

		// Build tool message.
		$tool_message = array(
			'role'         => 'tool',
			'content'      => $result_content,
			'tool_call_id' => $tool_call_id,
			'name'         => $tool_name,
		);

		// Include cost data if available.
		if ( isset( $result['result']['cost'] ) && is_array( $result['result']['cost'] ) ) {
			$tool_message['cost'] = $result['result']['cost'];
			$result['cost']       = $result['result']['cost'];
		}

		$result['tool_results'] = array( $tool_message );

		return $result;
	}
}
