<?php
/**
 * Tool for deleting WordPress cron jobs.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';
}

/**
 * Allows users to delete scheduled WordPress cron jobs.
 */
class WP_MCP_AI_Tool_Delete_Cron_Job implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'delete_cron_job';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delete Cron Job', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Deletes a scheduled WordPress cron job and removes it from the system.', 'wp-mcp-ai' );
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
					'description' => __( 'The unique identifier of the cron job to delete.', 'wp-mcp-ai' ),
					'minLength'   => 1,
				),
			),
			'required'             => array( 'job_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if cron orchestration is enabled.
		if ( class_exists( 'WP_MCP_AI_Orchestration_Budget_Enforcement_Service' ) ) {
			if ( ! WP_MCP_AI_Orchestration_Budget_Enforcement_Service::is_cron_orchestration_enabled() ) {
				return new WP_Error( 'wp_mcp_ai_cron_disabled', __( 'Cron-based task orchestration is currently disabled. Enable it in Settings → Orchestration Layer → Settings.', 'wp-mcp-ai' ) );
			}
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete cron jobs.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$job_id = isset( $arguments['job_id'] ) ? sanitize_text_field( (string) $arguments['job_id'] ) : '';

		if ( '' === $job_id ) {
			return new WP_Error( 'wp_mcp_ai_invalid_job_id', __( 'A valid job ID is required.', 'wp-mcp-ai' ) );
		}

		// Get the job details before deleting.
		$job = WP_MCP_AI_Cron_Manager::get_job( $job_id );

		if ( ! $job ) {
			return new WP_Error( 'wp_mcp_ai_job_not_found', __( 'The specified cron job was not found.', 'wp-mcp-ai' ) );
		}

		$deleted = WP_MCP_AI_Cron_Manager::remove_job( $job_id );

		if ( ! $deleted ) {
			return new WP_Error( 'wp_mcp_ai_delete_failed', __( 'Failed to delete the cron job. It may have already been removed.', 'wp-mcp-ai' ) );
		}

		return array(
			'job_id'  => $job_id,
			'hook'    => isset( $job['hook'] ) ? $job['hook'] : '',
			'message' => __( 'Cron job deleted successfully.', 'wp-mcp-ai' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',                // Deletes scheduled tasks.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
			'state-changing',       // Modifies scheduled tasks.
		);
	}
}
