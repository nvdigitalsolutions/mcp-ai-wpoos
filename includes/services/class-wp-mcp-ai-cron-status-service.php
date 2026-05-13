<?php
/**
 * Cron Status Service
 *
 * Provides lightweight cron job status information for chat interfaces.
 * Follows separation of concerns by encapsulating cron status logic.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
	 * @param int             $user_id User ID to filter jobs by (0 for all if admin).
	 * @param int             $limit   Maximum number of jobs to return (default 10).
	 * @param int|string|null $assistant_id Optional assistant ID to filter jobs for specific chat widget. Can be int or string (e.g., "unified_team_123").
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

		// Phase 1 (job-source registry): collect extra jobs contributed via the
		// `wp_mcp_ai_cron_status_job_sources` filter. These are merged into the
		// status-summary pipeline alongside the built-in async/video collectors
		// and respect the same assistant_id scoping. See
		// docs/features/chat/cron-status-tasks-drawer-plan.md.
		$external_jobs = $this->collect_registered_source_jobs( $user_id, $assistant_id );

		// When filtering by assistant_id, only include assistant-specific jobs (async and video).
		// Regular cron jobs from WP_MCP_AI_Cron_Manager don't have assistant_id association,.
		// so they should only be shown when no assistant filter is applied (e.g., admin dashboard).
		//
		// Async/video jobs are merged BEFORE regular cron entries so that, when the shared
		// $limit is small (e.g. the chat UI default of 10), assistant-scoped jobs always win
		// the slot ordering and are never starved by a backlog of generic cron entries.
		if ( null !== $assistant_id ) {
			// Multi-widget isolation: only show jobs for this specific assistant.
			$all_jobs = array_merge( $async_jobs, $video_jobs, $external_jobs );
		} else {
			// No filter: include all jobs (regular cron + async + video + external)
			// but put assistant jobs first so they win the limited window.
			$jobs     = WP_MCP_AI_Cron_Manager::get_jobs();
			$all_jobs = array_merge( $async_jobs, $video_jobs, $external_jobs, $jobs );
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

			// Phase 1 (job-source registry): records contributed via the
			// `wp_mcp_ai_cron_status_job_sources` filter are tagged with
			// `_is_source_record` so we can route them to the dedicated formatter
			// instead of mis-dispatching to async/cron formatting.
			$is_source_record = ! empty( $job['_is_source_record'] );
			// Check if this is an async tool job.
			$is_async_tool = isset( $job['tool_slug'] );

			if ( $is_source_record ) {
				$job_data = $this->format_source_record( $job );
			} elseif ( $is_async_tool ) {
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
				? __( 'Just now', 'mcp-ai-wpoos' )
				: __( 'In less than a minute', 'mcp-ai-wpoos' );
		}

		if ( $diff < HOUR_IN_SECONDS ) {
			$minutes = floor( $diff / MINUTE_IN_SECONDS );
			return $past
				? sprintf(
					/* translators: %d: number of minutes */
					_n( '%d minute ago', '%d minutes ago', $minutes, 'mcp-ai-wpoos' ),
					$minutes
				)
				: sprintf(
					/* translators: %d: number of minutes */
					_n( 'In %d minute', 'In %d minutes', $minutes, 'mcp-ai-wpoos' ),
					$minutes
				);
		}

		if ( $diff < DAY_IN_SECONDS ) {
			$hours = floor( $diff / HOUR_IN_SECONDS );
			return $past
				? sprintf(
					/* translators: %d: number of hours */
					_n( '%d hour ago', '%d hours ago', $hours, 'mcp-ai-wpoos' ),
					$hours
				)
				: sprintf(
					/* translators: %d: number of hours */
					_n( 'In %d hour', 'In %d hours', $hours, 'mcp-ai-wpoos' ),
					$hours
				);
		}

		$days = floor( $diff / DAY_IN_SECONDS );
		return $past
			? sprintf(
				/* translators: %d: number of days */
				_n( '%d day ago', '%d days ago', $days, 'mcp-ai-wpoos' ),
				$days
			)
			: sprintf(
				/* translators: %d: number of days */
				_n( 'In %d day', 'In %d days', $days, 'mcp-ai-wpoos' ),
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
	 * @param int             $user_id User ID to filter by.
	 * @param int|string|null $assistant_id Optional assistant ID to filter by. Can be int or string (e.g., "unified_team_123").
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for performance-critical aggregation on custom plugin table; WP_Query does not support custom table queries of this type.
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
				$job_assistant_id = isset( $metadata['context']['assistant_id'] ) ? $metadata['context']['assistant_id'] : null;

				// Normalize job assistant ID to match the filter type (string or int).
				$job_assistant_id = $this->normalize_assistant_id_for_comparison( $job_assistant_id, $assistant_id );

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
	 * @param int             $user_id User ID to filter by.
	 * @param int|string|null $assistant_id Optional assistant ID to filter by. Can be int or string (e.g., "unified_team_123").
	 * @return array Array of video generation jobs formatted like cron jobs.
	 */
	protected function get_video_generation_jobs( $user_id, $assistant_id = null ) {
		global $wpdb;

		$prefix = 'wp_mcp_ai_veo_async_';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for performance-critical aggregation on custom plugin table; WP_Query does not support custom table queries of this type.
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
				$job_assistant_id = isset( $metadata['args']['assistant_id'] ) ? $metadata['args']['assistant_id'] : null;

				// Normalize job assistant ID to match the filter type (string or int).
				$job_assistant_id = $this->normalize_assistant_id_for_comparison( $job_assistant_id, $assistant_id );

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
	 * @param int             $user_id User ID to filter by.
	 * @param int|string|null $assistant_id Optional assistant ID to filter by. Can be int or string (e.g., "unified_team_123").
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

		// Phase 1 (job-source registry): include jobs contributed via the
		// `wp_mcp_ai_cron_status_job_sources` filter so they participate in
		// the running/pending/completed/failed tally.
		$external_jobs = $this->collect_registered_source_jobs( $user_id, $assistant_id );

		// When filtering by assistant_id, only include assistant-specific jobs (async and video).
		// Regular cron jobs from WP_MCP_AI_Cron_Manager don't have assistant_id association,.
		// so they should only be shown when no assistant filter is applied (e.g., admin dashboard).
		if ( null !== $assistant_id ) {
			// Multi-widget isolation: only show jobs for this specific assistant.
			$all_jobs = array_merge( $async_jobs, $video_jobs, $external_jobs );
		} else {
			// No filter: include all jobs (regular cron + async + video + external).
			$jobs     = WP_MCP_AI_Cron_Manager::get_jobs();
			$all_jobs = array_merge( $jobs, $async_jobs, $video_jobs, $external_jobs );
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
			if ( ! empty( $job['_is_source_record'] ) ) {
				// Phase 1 normalized source record carries its own status.
				$status = isset( $job['status'] ) ? (string) $job['status'] : 'pending';
			} elseif ( isset( $job['tool_slug'] ) && isset( $job['status'] ) ) {
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
	 * Phase 1: Resolve the registered job-source adapters.
	 *
	 * Fires the `wp_mcp_ai_cron_status_job_sources` filter and returns the
	 * filtered map of `slug => Interface_WP_MCP_AI_Cron_Status_Job_Source`.
	 * Non-conforming entries are dropped (defensive; we never trust a third-party
	 * filter to return well-shaped data).
	 *
	 * @since 1.9.2
	 * @return array<string,Interface_WP_MCP_AI_Cron_Status_Job_Source>
	 */
	public function get_registered_sources() {
		/**
		 * Filter: register job sources for the cron-status Tasks drawer.
		 *
		 * @since 1.9.2
		 *
		 * @param array<string,Interface_WP_MCP_AI_Cron_Status_Job_Source> $sources Existing map.
		 */
		$sources = apply_filters( 'wp_mcp_ai_cron_status_job_sources', array() );

		if ( ! is_array( $sources ) ) {
			return array();
		}

		$resolved = array();
		foreach ( $sources as $key => $source ) {
			if ( ! is_object( $source ) || ! ( $source instanceof Interface_WP_MCP_AI_Cron_Status_Job_Source ) ) {
				continue;
			}

			$slug = is_string( $key ) && '' !== $key ? $key : $source->get_slug();
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug ) {
				continue;
			}

			$resolved[ $slug ] = $source;
		}

		return $resolved;
	}

	/**
	 * Phase 1: Collect normalized job records from every registered source.
	 *
	 * Each source is invoked inside a try/catch so a single misbehaving
	 * adapter cannot break the chat-status REST response. Invalid records
	 * (missing job_id, non-array, etc.) are silently dropped.
	 *
	 * The returned map is keyed by `<slug>:<job_id>` to guarantee global
	 * uniqueness across heterogeneous sources, and each record is tagged
	 * with `_is_source_record => true` so the dispatch loop can route it
	 * to {@see format_source_record()}.
	 *
	 * @since 1.9.2
	 *
	 * @param int             $user_id      Requesting user.
	 * @param int|string|null $assistant_id Optional assistant scope.
	 * @return array<string,array<string,mixed>>
	 */
	public function collect_registered_source_jobs( $user_id = 0, $assistant_id = null ) {
		$sources = $this->get_registered_sources();
		if ( empty( $sources ) ) {
			return array();
		}

		$collected = array();

		foreach ( $sources as $slug => $source ) {
			$jobs = array();
			try {
				$jobs = $source->get_jobs( $user_id, $assistant_id );
			} catch ( \Throwable $e ) {
				/**
				 * Fires when a registered job source throws while listing jobs.
				 *
				 * Useful for surfacing broken adapters in observability without
				 * letting them break the chat-status REST response.
				 *
				 * @since 1.9.2
				 *
				 * @param string     $slug Registered source slug.
				 * @param \Throwable $e    The thrown exception.
				 */
				do_action( 'wp_mcp_ai_cron_status_source_error', $slug, $e );
				continue;
			}

			if ( ! is_array( $jobs ) ) {
				continue;
			}

			foreach ( $jobs as $raw ) {
				$record = $this->normalize_source_record( $raw, $slug );
				if ( null === $record ) {
					continue;
				}

				// Assistant-scope filtering: drop records that don't match when scope is set.
				if ( null !== $assistant_id && '' !== (string) $record['assistant_id']
					&& (string) $record['assistant_id'] !== (string) $assistant_id ) {
					continue;
				}

				$key               = $slug . ':' . $record['job_id'];
				$collected[ $key ] = $record;
			}
		}

		return $collected;
	}

	/**
	 * Phase 1: Normalize a raw record returned by a job source.
	 *
	 * Enforces the documented contract from
	 * `docs/features/chat/cron-status-tasks-drawer-plan.md` and tags the
	 * record with `_is_source_record => true` so the formatter loop in
	 * {@see get_status_summary()} can dispatch to {@see format_source_record()}.
	 *
	 * Returns `null` for records that are missing required keys
	 * (job_id, status) — callers should treat that as "drop silently".
	 *
	 * @since 1.9.2
	 *
	 * @param mixed  $raw  Raw record from the source.
	 * @param string $slug Source slug (used as fallback for `source`).
	 * @return array<string,mixed>|null
	 */
	public function normalize_source_record( $raw, $slug ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$job_id = isset( $raw['job_id'] ) ? (string) $raw['job_id'] : '';
		$job_id = sanitize_text_field( $job_id );
		if ( '' === $job_id ) {
			return null;
		}

		$status = isset( $raw['status'] ) ? (string) $raw['status'] : '';
		$status = sanitize_key( $status );
		$allowed_statuses = array( 'queued', 'pending', 'running', 'polling', 'completed', 'failed', 'cancelled' );
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			// Map unknown legacy values defensively rather than dropping the record.
			$status = 'pending';
		}

		$kind = isset( $raw['kind'] ) ? sanitize_key( (string) $raw['kind'] ) : '';
		if ( '' === $kind ) {
			$kind = sanitize_key( $slug );
		}

		// Assistant ID may be int or string (e.g. "unified_team_123").
		$assistant_id = '';
		if ( isset( $raw['assistant_id'] ) ) {
			$assistant_id = is_int( $raw['assistant_id'] )
				? (string) $raw['assistant_id']
				: sanitize_text_field( (string) $raw['assistant_id'] );
		}

		$record = array(
			'job_id'            => $job_id,
			'kind'              => $kind,
			'status'            => $status,
			'created_by'        => isset( $raw['created_by'] ) ? absint( $raw['created_by'] ) : 0,
			'assistant_id'      => $assistant_id,
			'started_at'        => isset( $raw['started_at'] ) ? absint( $raw['started_at'] ) : 0,
			'updated_at'        => isset( $raw['updated_at'] ) ? absint( $raw['updated_at'] ) : 0,
			'eta'               => isset( $raw['eta'] ) && null !== $raw['eta'] ? absint( $raw['eta'] ) : null,
			'progress'          => isset( $raw['progress'] ) && null !== $raw['progress']
				? max( 0, min( 100, (int) $raw['progress'] ) )
				: null,
			'message'           => isset( $raw['message'] ) ? sanitize_text_field( (string) $raw['message'] ) : '',
			'cancellable'       => ! empty( $raw['cancellable'] ),
			'retryable'         => ! empty( $raw['retryable'] ),
			'source'            => isset( $raw['source'] ) && is_string( $raw['source'] ) && '' !== $raw['source']
				? sanitize_key( $raw['source'] )
				: sanitize_key( $slug ),
			'_is_source_record' => true,
		);

		return $record;
	}

	/**
	 * Phase 1: Format a normalized source record for the REST response.
	 *
	 * Mirrors the shape used by {@see format_async_tool_job()} so the chat
	 * client doesn't need to know which subsystem produced the row.
	 *
	 * @since 1.9.2
	 *
	 * @param array<string,mixed> $record Normalized record (must carry `_is_source_record`).
	 * @return array<string,mixed>
	 */
	protected function format_source_record( $record ) {
		$status = isset( $record['status'] ) ? (string) $record['status'] : 'pending';

		$job_data = array(
			'job_id'       => isset( $record['job_id'] ) ? (string) $record['job_id'] : '',
			'kind'         => isset( $record['kind'] ) ? (string) $record['kind'] : '',
			'source'       => isset( $record['source'] ) ? (string) $record['source'] : '',
			'status'       => $status,
			'created_by'   => isset( $record['created_by'] ) ? (int) $record['created_by'] : 0,
			'assistant_id' => isset( $record['assistant_id'] ) ? (string) $record['assistant_id'] : '',
			'started_at'   => isset( $record['started_at'] ) ? (int) $record['started_at'] : 0,
			'updated_at'   => isset( $record['updated_at'] ) ? (int) $record['updated_at'] : 0,
			'eta'          => array_key_exists( 'eta', $record ) ? $record['eta'] : null,
			'progress'     => array_key_exists( 'progress', $record ) ? $record['progress'] : null,
			'message'      => isset( $record['message'] ) ? (string) $record['message'] : '',
			'cancellable'  => ! empty( $record['cancellable'] ),
			'retryable'    => ! empty( $record['retryable'] ),
		);

		return $job_data;
	}

	/**
	 * Classify a job-status diff into a typed `job:*` SSE event name.
	 *
	 * Implements the canonical contract documented in
	 * `docs/features/chat/cron-status-tasks-drawer-plan.md` Phase 2.
	 *
	 * Valid event names: `job:queued`, `job:started`, `job:progress`,
	 * `job:completed`, `job:failed`, `job:cancelled`, `job:retried`.
	 * Returns an empty string when the diff produces no meaningful event
	 * (e.g. unchanged record).
	 *
	 * Note: `job:step` frames are emitted directly by
	 * {@see WP_MCP_AI_Job_Notifier::record_step()} and are not classified
	 * here — this method handles only the list-endpoint diff loop.
	 *
	 * @since 1.9.3
	 *
	 * @param array<string,mixed>|null $prev Previous snapshot record (or null for new jobs).
	 * @param array<string,mixed>      $next Current snapshot record.
	 * @return string Typed event name, or empty string for "no event".
	 */
	public function classify_job_diff_event( $prev, array $next ) {
		$next_status = isset( $next['status'] ) ? (string) $next['status'] : '';
		if ( '' === $next_status ) {
			return '';
		}

		$running_statuses  = array( 'running', 'polling', 'in_progress' );
		$pending_statuses  = array( 'pending', 'queued' );
		$terminal_complete = 'completed';
		$terminal_failed   = 'failed';
		$terminal_cancel   = 'cancelled';

		// New job (no previous record).
		if ( null === $prev || ! is_array( $prev ) ) {
			if ( $terminal_complete === $next_status ) {
				return 'job:completed';
			}
			if ( $terminal_failed === $next_status ) {
				return 'job:failed';
			}
			if ( $terminal_cancel === $next_status ) {
				return 'job:cancelled';
			}
			if ( in_array( $next_status, $running_statuses, true ) ) {
				return 'job:started';
			}
			return 'job:queued';
		}

		$prev_status = isset( $prev['status'] ) ? (string) $prev['status'] : '';

		if ( $prev_status !== $next_status ) {
			if ( $terminal_complete === $next_status ) {
				return 'job:completed';
			}
			if ( $terminal_failed === $next_status ) {
				return 'job:failed';
			}
			if ( $terminal_cancel === $next_status ) {
				return 'job:cancelled';
			}
			if ( in_array( $next_status, $running_statuses, true )
				&& in_array( $prev_status, $pending_statuses, true ) ) {
				return 'job:started';
			}
			if ( in_array( $next_status, $pending_statuses, true )
				&& in_array( $prev_status, array( $terminal_failed, $terminal_cancel ), true ) ) {
				return 'job:retried';
			}
			// Generic transition (e.g. running → polling) is a progress signal.
			return 'job:progress';
		}

		// Same status — look for progress/updated_at changes.
		$prev_progress = array_key_exists( 'progress', $prev ) ? $prev['progress'] : null;
		$next_progress = array_key_exists( 'progress', $next ) ? $next['progress'] : null;
		if ( $prev_progress !== $next_progress ) {
			return 'job:progress';
		}

		$prev_updated = isset( $prev['updated_at'] ) ? (int) $prev['updated_at'] : 0;
		$next_updated = isset( $next['updated_at'] ) ? (int) $next['updated_at'] : 0;
		if ( $prev_updated !== $next_updated && in_array( $next_status, $running_statuses, true ) ) {
			return 'job:progress';
		}

		return '';
	}

	/**
	 * Get lightweight system status for chat-client display.
	 *
	 * Surfaces async-health and orchestration-health signals so the chat UI
	 * can render a health pill alongside the job counters. Failures from
	 * either subsystem are swallowed so a misbehaving health probe never
	 * breaks the cron-status REST response.
	 *
	 * @since 1.9.2
	 * @return array {
	 *     @type array $async  { status, stuck_jobs, long_running }.
	 *     @type array $health { status, label }.
	 * }
	 */
	public function get_system_status() {
		$status = array(
			'async'  => array(
				'status'       => 'unknown',
				'stuck_jobs'   => 0,
				'long_running' => 0,
			),
			'health' => array(
				'status' => 'unknown',
				'label'  => 'Unknown',
			),
		);

		if ( class_exists( 'WP_MCP_AI_Async_Health_Monitor' ) ) {
			try {
				$async_health    = WP_MCP_AI_Async_Health_Monitor::check_async_health();
				$status['async'] = array(
					'status'       => isset( $async_health['status'] ) ? (string) $async_health['status'] : 'unknown',
					'stuck_jobs'   => isset( $async_health['stuck_jobs'] ) ? absint( $async_health['stuck_jobs'] ) : 0,
					'long_running' => isset( $async_health['long_running'] ) ? absint( $async_health['long_running'] ) : 0,
				);
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Async health is best-effort; never break the chat surface.
				// Silently fall back to the 'unknown' default initialised above.
			}
		}

		if ( class_exists( 'WP_MCP_AI_Orchestration_Health_Service' ) ) {
			try {
				$health_status    = WP_MCP_AI_Orchestration_Health_Service::get_health_status();
				$status['health'] = array(
					'status' => isset( $health_status['status'] ) ? (string) $health_status['status'] : 'unknown',
					'label'  => isset( $health_status['label'] ) ? (string) $health_status['label'] : 'Unknown',
				);
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Orchestration health is best-effort; never break the chat surface.
				// Silently fall back to the 'unknown' default initialised above.
			}
		}

		return $status;
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
					// This is critical for tools like generate_veo_video that rely on.
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
						// This ensures the async result has the same tool_call_id that the LLM provided.
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
									'message' => __( 'Tool completed successfully but result could not be serialized.', 'mcp-ai-wpoos' ),
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
				// Job Notifier stores errors as associative arrays (PHP) with 'message' and 'code' fields,.
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

		// Note: user_id can be 0 for guest users authenticated with guest tokens.
		// The REST endpoint (handle_cron_job_details_request) already resolves the user_id.
		// from auth context or get_current_user_id(), so we don't need to re-resolve it here.
		// Allowing user_id = 0 enables guest users to view their own async jobs.

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

			// Check permissions - video jobs store user_id in args or context.
			// When a job reuses parent ID (async_xxx), the context field from async executor.
			// contains the user_id. Otherwise, the args field contains the user_id.
			$job_user_id = 0;
			if ( isset( $result['args']['user_id'] ) ) {
				$job_user_id = absint( $result['args']['user_id'] );
			} elseif ( isset( $result['context']['user_id'] ) ) {
				$job_user_id = absint( $result['context']['user_id'] );
			}

			if ( ! $is_admin && $job_user_id !== $user_id ) {
				return new WP_Error(
					'wp_mcp_ai_forbidden',
					__( 'You do not have permission to view this job.', 'mcp-ai-wpoos' )
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
					__( 'Async executor service is not available.', 'mcp-ai-wpoos' )
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
					__( 'You do not have permission to view this job.', 'mcp-ai-wpoos' )
				);
			}

			// Apply tool's sanitize_for_llm() to format result for chat client.
			// This is critical for tools like generate_veo_video which add video_url structure.
			// The sanitization is normally applied during sync execution but not when.
			// results are retrieved from async storage.
			$tool_slug = isset( $result['tool_slug'] ) ? $result['tool_slug'] : '';
			if ( ! empty( $tool_slug ) ) {
				$result = $this->sanitize_async_tool_result( $result, $tool_slug );
			}

			// Merge Job Notifier status (completion/failure/progress).
			$result = $this->merge_notifier_status( $result, $job_id );

			// If the job is still showing as "delegated" after merging notifier status,.
			// check if the delegated job has completed or failed and propagate its status.
			// This handles the case where the delegated job finished but the parent job.
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
				__( 'Job not found or has been removed.', 'mcp-ai-wpoos' )
			);
		}

		// Check permissions.
		$created_by = isset( $job['created_by'] ) ? absint( $job['created_by'] ) : 0;
		if ( ! $is_admin && $created_by !== $user_id ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to view this job.', 'mcp-ai-wpoos' )
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
	 * and WordPress objects (WP_Post, WP_Query, etc.) to serializable array format.
	 * This prevents JSON encoding failures when sending data through SSE streams
	 * or REST API responses.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $data Data to normalize, can be any type.
	 * @param int   $depth Current recursion depth (internal parameter for preventing infinite loops).
	 * @return mixed Normalized data with all non-serializable objects converted to arrays.
	 */
	protected function normalize_data_recursive( $data, $depth = 0 ) {
		// Prevent infinite recursion - limit depth to 20 levels.
		if ( $depth > 20 ) {
			return '[max recursion depth reached]';
		}

		// Handle WP_Error directly.
		if ( is_wp_error( $data ) ) {
			$error_data  = $data->get_error_data();
			$error_array = array(
				'error'   => true,
				'code'    => $data->get_error_code(),
				'message' => $data->get_error_message(),
			);

			if ( ! empty( $error_data ) ) {
				// Recursively normalize error data in case it contains objects.
				$error_array['data'] = $this->normalize_data_recursive( $error_data, $depth + 1 );
			}

			return $error_array;
		}

		// Handle arrays - recursively process each element.
		if ( is_array( $data ) ) {
			$normalized = array();
			foreach ( $data as $key => $value ) {
				$normalized[ $key ] = $this->normalize_data_recursive( $value, $depth + 1 );
			}
			return $normalized;
		}

		// Handle resources (file handles, database connections, etc.).
		// Resources cannot be JSON encoded and should be excluded.
		if ( is_resource( $data ) ) {
			return '[resource]';
		}

		// Handle objects - special handling for common WordPress types.
		if ( is_object( $data ) ) {
			// Handle WP_Post objects - extract only essential data.
			if ( $data instanceof WP_Post ) {
				return array(
					'ID'          => $data->ID,
					'post_title'  => $data->post_title,
					'post_type'   => $data->post_type,
					'post_status' => $data->post_status,
				);
			}

			// Handle WP_Query objects - don't serialize the entire query, just reference it.
			if ( $data instanceof WP_Query ) {
				return array(
					'query_type' => 'WP_Query',
					'post_count' => isset( $data->post_count ) ? $data->post_count : 0,
				);
			}

			// Handle WP_User objects.
			if ( $data instanceof WP_User ) {
				return array(
					'ID'           => $data->ID,
					'user_login'   => $data->user_login,
					'display_name' => $data->display_name,
				);
			}

			// For other objects, use get_object_vars() to avoid exposing private/protected properties.
			// This provides only public properties and avoids mangled property names like '\0ClassName\0propertyName'.
			// that can occur when casting objects with private/protected properties to arrays.
			// For stdClass and simple objects, this works well. For complex objects with magic methods
			// or ArrayAccess, they should be handled in specific cases above.
			$object_vars = get_object_vars( $data );
			return $this->normalize_data_recursive( $object_vars, $depth + 1 );
		}

		// Scalars pass through unchanged (strings, ints, floats, booleans, null).
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
	 * preventing infinite recursion. Future changes should maintain this
	 * constraint or add explicit recursion depth limits.
	 *
	 * @since 1.1.0
	 *
	 * @param array       $result    Parent job result from async executor.
	 * @param string      $job_id    Parent job ID.
	 * @param int         $user_id   User ID for permission checks.
	 * @param string|null $tool_slug Tool slug for sanitization. May be empty.
	 * @return array Modified result with delegated job status merged.
	 */
	protected function handle_delegation_chain( $result, $job_id, $user_id, $tool_slug ) {
		$delegated_job_id = $result['delegated_to'];

		// Only check veo_ jobs to prevent potential recursion.
		// Veo jobs complete directly and don't delegate to other jobs.
		// This is a critical safeguard - if delegation chains are extended.
		// in the future, explicit recursion depth limits should be added.
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
					'message' => __( 'Tool completed successfully.', 'mcp-ai-wpoos' ),
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

	/**
	 * Normalize assistant ID for comparison.
	 *
	 * Handles both integer and string-based assistant IDs (e.g., "unified_team_123").
	 * When the filter is a string, jobs are compared as strings.
	 * When the filter is an integer, jobs are compared as integers.
	 *
	 * @param mixed      $job_assistant_id The assistant ID from the job metadata.
	 * @param int|string $filter_assistant_id The assistant ID filter to match against.
	 * @return int|string Normalized assistant ID for comparison.
	 */
	private function normalize_assistant_id_for_comparison( $job_assistant_id, $filter_assistant_id ) {
		// If the filter is a string, normalize the job ID as a string.
		if ( is_string( $filter_assistant_id ) ) {
			return $job_assistant_id ? sanitize_text_field( $job_assistant_id ) : '';
		}

		// Otherwise, normalize as integer.
		return $job_assistant_id ? absint( $job_assistant_id ) : 0;
	}
}
