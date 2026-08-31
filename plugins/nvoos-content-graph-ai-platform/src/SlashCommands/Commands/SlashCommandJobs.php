<?php
/**
 * /jobs slash command.
 *
 * Lists and cancels async jobs for the current user or (with
 * manage_options) for all users.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since   2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SlashCommands\Commands;

use NvoosContentGraphAiPlatform\SlashCommands\SlashCommandHandler;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SlashCommandJobs
 *
 * @since 2.1.0
 */
class SlashCommandJobs {
	/**
	 * Log an event through the base plugin's logger when present
	 * (monolith mode), falling back to error_log in standalone mode.
	 *
	 * @since 2.0.0
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 */
	private static function log_event( $level, $message ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $level, $message );
			return;
		}
		error_log( sprintf( '[nvoos-content-graph-ai-platform] %s: %s', $level, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Standalone fallback when the base logger is absent.
	}

	/**
	 * Execute the /jobs command.
	 *
	 * @param array $args    Positional arguments (unused).
	 * @param array $flags   Parsed flag map.
	 * @param array $context Execution context.
	 * @return array|WP_Error Command response or error.
	 */
	public function execute( $args, $flags, $context ) {
		// Block guest requests.
		if ( ! empty( $context['guest_request'] ) ) {
			return new \WP_Error( 'guest_forbidden', __( 'This command requires authentication.', 'nvoos-content-graph-ai-platform' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Capability gate.
		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new \WP_Error( 'insufficient_capability', __( 'You do not have permission to use /jobs.', 'nvoos-content-graph-ai-platform' ) );
		}

		$as_json   = isset( $flags['json'] );
		$cancel_id = isset( $flags['cancel'] ) ? sanitize_text_field( $flags['cancel'] ) : '';
		$show_all  = isset( $flags['all'] );
		$limit     = isset( $flags['limit'] ) ? absint( $flags['limit'] ) : 10;
		$status    = isset( $flags['status'] ) ? sanitize_key( $flags['status'] ) : '';

		// Validate status allowlist.
		$valid_statuses = array( 'queued', 'running', 'completed', 'failed', 'paused' );
		if ( $status && ! in_array( $status, $valid_statuses, true ) ) {
			return new \WP_Error(
				'invalid_status',
				sprintf(
					/* translators: %s: provided status value */
					__( 'Invalid status "%s". Valid values: queued, running, completed, failed, paused.', 'nvoos-content-graph-ai-platform' ),
					esc_html( $status )
				)
			);
		}

		// Enforce manage_options for --all.
		if ( $show_all && ! user_can( $user_id, 'manage_options' ) ) {
			return new \WP_Error( 'insufficient_capability', __( 'manage_options capability is required for --all.', 'nvoos-content-graph-ai-platform' ) );
		}

		// Handle --cancel.
		if ( $cancel_id ) {
			return $this->cancel_job( $cancel_id, $user_id );
		}

		// Determine which user to query.
		$query_user_id = ( $show_all && user_can( $user_id, 'manage_options' ) ) ? 0 : $user_id;

		// List jobs.
		$jobs = $this->fetch_jobs( $query_user_id, $status, $limit );

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => wp_json_encode( $jobs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
				'data'    => $jobs,
			);
		}

		return array(
			'success' => true,
			'message' => $this->render_table( $jobs ),
			'data'    => $jobs,
		);
	}

	/**
	 * Cancel a job by ID.
	 *
	 * @param string $job_id  Job identifier.
	 * @param int    $user_id Requesting user ID.
	 * @return array|WP_Error
	 */
	private function cancel_job( $job_id, $user_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- reserved for future ownership check
		if ( ! defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Async_Job_Queue' ) ) {
			return new \WP_Error( 'service_unavailable', __( 'Async job queue service is not available.', 'nvoos-content-graph-ai-platform' ) );
		}

		$result = \WP_MCP_AI_Async_Job_Queue::cancel_job( $job_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: job ID */
				__( 'Job `%s` has been cancelled.', 'nvoos-content-graph-ai-platform' ),
				esc_html( $job_id )
			),
			'data'    => array( 'job_id' => $job_id ),
		);
	}

	/**
	 * Fetch jobs from available service.
	 *
	 * @param int    $user_id Target user ID (0 = all users).
	 * @param string $status  Status filter.
	 * @param int    $limit   Max rows.
	 * @return array
	 */
	private function fetch_jobs( $user_id, $status, $limit ) {
		// Prefer cron status service.
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Cron_Status_Service' ) ) {
			$service = new \WP_MCP_AI_Cron_Status_Service();
			$summary = $service->get_status_summary( $user_id, $limit );
			if ( is_array( $summary ) ) {
				return $summary;
			}
		}

		// Fall back to async job queue.
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Async_Job_Queue' ) ) {
			$filter  = $status ? $status : 'running';
			$results = \WP_MCP_AI_Async_Job_Queue::get_jobs_by_status( $filter, $limit );
			if ( is_array( $results ) ) {
				return $results;
			}
		}

		return array();
	}

	/**
	 * Render jobs as a Markdown table.
	 *
	 * @param array $jobs Jobs list.
	 * @return string
	 */
	private function render_table( array $jobs ) {
		if ( empty( $jobs ) ) {
			return __( '_No jobs found._', 'nvoos-content-graph-ai-platform' );
		}

		$out  = "## Jobs\n\n";
		$out .= "| ID | Status | Type | Created |\n";
		$out .= "|----|--------|------|---------|\n";

		foreach ( $jobs as $job ) {
			$job_id      = isset( $job['id'] ) ? esc_html( (string) $job['id'] ) : '—';
			$job_status  = isset( $job['status'] ) ? esc_html( (string) $job['status'] ) : '—';
			$job_type    = isset( $job['type'] ) ? esc_html( (string) $job['type'] ) : '—';
			$job_created = isset( $job['created'] ) ? esc_html( (string) $job['created'] ) : '—';
			$out        .= "| `{$job_id}` | {$job_status} | {$job_type} | {$job_created} |\n";
		}

		return $out;
	}
}
