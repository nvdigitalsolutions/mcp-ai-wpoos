<?php
/**
 * Tool for deleting policies.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes an insurance policy.
 */
class WP_MCP_AI_Tool_Delete_Policy implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'delete_policy';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delete Policy', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Deletes an insurance policy. This action cannot be undone.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'policy_id' => array(
					'type'        => 'integer',
					'description' => __( 'Policy ID to delete (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'policy_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'health_wellness',
			'post_type'             => 'mcp_ai_policy',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'insurance_agent', 'admin' ),
			'risk_level'            => 'high',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'destructive' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_health_wellness_management'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'delete_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete policies.', 'mcp-ai-wpoos-pro' ) );
		}

		$policy_id = isset( $arguments['policy_id'] ) ? absint( $arguments['policy_id'] ) : 0;

		if ( ! $policy_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Policy ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$policy = get_post( $policy_id );

		if ( ! $policy || 'mcp_ai_policy' !== $policy->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_policy', __( 'Invalid policy ID.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = wp_delete_post( $policy_id, true );

		if ( ! $result ) {
			return new WP_Error( 'wp_mcp_ai_delete_failed', __( 'Failed to delete policy.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'success'   => true,
			'message'   => __( 'Policy deleted successfully.', 'mcp-ai-wpoos-pro' ),
			'policy_id' => $policy_id,
		);
	}
}
