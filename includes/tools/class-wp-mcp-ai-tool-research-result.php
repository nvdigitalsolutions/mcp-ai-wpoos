<?php
/**
 * Tool for retrieving the finished report of a background deep_research job.
 *
 * Companion to the deep_research tool: once research_status reports a
 * background job as completed, this tool returns the full research report
 * (findings, sources, and diagnostics) stored in the job transient.
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
 * Research Result Tool
 *
 * Reads the result payload of a completed background deep_research job.
 */
class WP_MCP_AI_Tool_Research_Result implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'research_result';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Research Result', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns the finished report of a background deep_research job by job ID, including findings, sources, and diagnostics. Use after research_status reports completed; an unfinished or missing job returns an explicit error.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Fetching the completed report of a background deep_research job after polling research_status.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Checking whether the job is done — use research_status; stopping a pending job — use research_cancel.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'deep_research', 'research_status', 'research_cancel' ),
			'notes'           => __( 'Completed reports live for 24 hours after the job was scheduled.', 'mcp-ai-wpoos' ),
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
			'read-only',           // Only reads job state.
			'requires-capability', // Checks the user's capability before running.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read';
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
		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to read research results.', 'mcp-ai-wpoos' ),
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

		$job_status = isset( $job['status'] ) ? $job['status'] : 'unknown';

		if ( 'completed' !== $job_status ) {
			return new WP_Error(
				'wp_mcp_ai_research_job_not_complete',
				sprintf(
					/* translators: 1: job ID, 2: current status */
					__( 'Research job %1$s is not complete yet (current status: %2$s). Poll research_status until it completes.', 'mcp-ai-wpoos' ),
					$job_id,
					$job_status
				),
				array(
					'status'     => 409,
					'job_status' => $job_status,
				)
			);
		}

		if ( empty( $job['result'] ) || ! is_array( $job['result'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_research_result_missing',
				__( 'The research job completed without a stored result.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		$result = $job['result'];

		return array(
			'success' => true,
			'job_id'  => $job_id,
			'status'  => 'completed',
			'partial' => ! empty( $job['partial'] ),
			'result'  => $result,
			'report'  => isset( $result['report'] ) ? $result['report'] : '',
			'sources' => isset( $result['sources'] ) ? $result['sources'] : array(),
			'message' => sprintf(
				/* translators: %s: topic */
				__( 'Research on "%s" completed. The full report is in the result field.', 'mcp-ai-wpoos' ),
				isset( $result['topic'] ) ? $result['topic'] : ''
			),
		);
	}
}
