<?php
/**
 * Tool for cancelling a pending background deep_research job.
 *
 * Companion to the deep_research tool: cancels a scheduled (not yet running)
 * background research job by removing its cron event and job record.
 * Completed or failed jobs cannot be cancelled and are left intact.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Research Cancel Tool
 *
 * Removes a pending background deep_research job. Only the user who
 * scheduled the job (or an administrator) may cancel it.
 */
class WP_MCP_AI_Tool_Research_Cancel implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'research_cancel';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Research Cancel', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Cancels a pending background deep_research job by job ID. Removes the scheduled cron event and the job record. Only the scheduling user or an administrator can cancel; completed or failed jobs are left intact.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Stopping a background deep_research job that is still scheduled or running before it finishes.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Checking progress — use research_status; reading a finished report — use research_result.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'deep_research', 'research_status', 'research_result' ),
			'notes'           => __( 'A job that already completed or failed cannot be cancelled; its record is simply left for research_result.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'job_id' => array(
					'type'        => 'string',
					'description' => __( 'The job ID returned by deep_research when started with run_mode=background.', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array( 'job_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'research_discovery',
			'pattern_compatibility' => array( 'sequential' ),
			'profession_tags'       => array( 'researcher', 'analyst', 'journalist' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-capability', // Checks the user's capability before running.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Check permissions.
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to cancel research jobs.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ), array( 'status' => 403 ) );
		}

		// Validate required arguments.
		if ( empty( $arguments['job_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_job_id',
				__( 'A research job ID is required.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$job_id = sanitize_text_field( $arguments['job_id'] );
		$job    = get_transient( 'wp_mcp_ai_research_job_' . $job_id );

		if ( ! is_array( $job ) ) {
			return new WP_Error(
				'wp_mcp_ai_research_job_not_found',
				sprintf(
					/* translators: %s: job ID */
					__( 'No background research job found for ID %s. Jobs expire 24 hours after scheduling.', 'mcp-ai-wpoos' ),
					$job_id
				),
				array( 'status' => 404 )
			);
		}

		// Ownership: only the scheduling user or an administrator may cancel.
		$owner_id = isset( $job['user_id'] ) ? absint( $job['user_id'] ) : 0;
		if ( $owner_id && $owner_id !== $user_id && ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'Only the user who scheduled this research job (or an administrator) can cancel it.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		$job_status = isset( $job['status'] ) ? $job['status'] : 'unknown';

		// Terminal states are left intact so research_result can still read them.
		if ( in_array( $job_status, array( 'completed', 'failed' ), true ) ) {
			return array(
				'success'   => true,
				'cancelled' => false,
				'job_id'    => $job_id,
				'status'    => $job_status,
				'message'   => sprintf(
					/* translators: 1: job ID, 2: job status */
					__( 'Research job %1$s already finished with status "%2$s" and cannot be cancelled.', 'mcp-ai-wpoos' ),
					$job_id,
					$job_status
				),
			);
		}

		// Remove the pending cron event, if it is still scheduled. Jobs are
		// scheduled as single events on wp_mcp_ai_deep_research_background
		// with a nested argument array whose first element carries the job
		// parameters (cron spreads args positionally into the handler).
		$cron = _get_cron_array();
		if ( is_array( $cron ) ) {
			foreach ( $cron as $timestamp => $events ) {
				if ( empty( $events['wp_mcp_ai_deep_research_background'] ) || ! is_array( $events['wp_mcp_ai_deep_research_background'] ) ) {
					continue;
				}
				foreach ( $events['wp_mcp_ai_deep_research_background'] as $event ) {
					$event_args = isset( $event['args'][0] ) && is_array( $event['args'][0] ) ? $event['args'][0] : array();
					if ( isset( $event_args['job_id'] ) && $job_id === $event_args['job_id'] ) {
						wp_unschedule_event( (int) $timestamp, 'wp_mcp_ai_deep_research_background', $event['args'] );
					}
				}
			}
		}

		delete_transient( 'wp_mcp_ai_research_job_' . $job_id );

		return array(
			'success'    => true,
			'cancelled'  => true,
			'job_id'     => $job_id,
			'status'     => 'cancelled',
			'was_status' => $job_status,
			'message'    => sprintf(
				/* translators: %s: job ID */
				__( 'Research job %s was cancelled.', 'mcp-ai-wpoos' ),
				$job_id
			),
		);
	}
}
