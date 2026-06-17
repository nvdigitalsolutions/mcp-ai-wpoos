<?php
/**
 * Tool for getting a single policy's details.
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
 * Get details of a single insurance policy.
 */
class WP_MCP_AI_Tool_Get_Policy implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_policy';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Policy', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Gets detailed information about a specific insurance policy.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Policy ID to retrieve (required)', 'mcp-ai-wpoos-pro' ),
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
			'profession_tags'       => array( 'insurance_agent', 'healthcare_provider' ),
			'risk_level'            => 'info',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read' );
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
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view policies.', 'mcp-ai-wpoos-pro' ) );
		}

		$policy_id = isset( $arguments['policy_id'] ) ? absint( $arguments['policy_id'] ) : 0;

		if ( ! $policy_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Policy ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$policy = get_post( $policy_id );

		if ( ! $policy || 'mcp_ai_policy' !== $policy->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_policy', __( 'Invalid policy ID.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get policy type.
		$types = wp_get_object_terms( $policy_id, 'mcp_ai_policy_type', array( 'fields' => 'slugs' ) );
		$type  = ! empty( $types ) && ! is_wp_error( $types ) ? $types[0] : '';

		// Get member info.
		$member_id   = get_post_meta( $policy_id, '_policy_member_id', true );
		$member_name = '';
		if ( $member_id ) {
			$member      = get_post( $member_id );
			$member_name = $member ? $member->post_title : '';
		}

		return array(
			'success' => true,
			'policy'  => array(
				'id'               => $policy_id,
				'policy_number'    => get_post_meta( $policy_id, '_policy_number', true ),
				'name'             => $policy->post_title,
				'type'             => $type,
				'member_id'        => $member_id,
				'member_name'      => $member_name,
				'provider'         => get_post_meta( $policy_id, '_policy_provider', true ),
				'status'           => get_post_meta( $policy_id, '_policy_status', true ),
				'effective_date'   => get_post_meta( $policy_id, '_policy_effective_date', true ),
				'expiration_date'  => get_post_meta( $policy_id, '_policy_expiration_date', true ),
				'premium'          => get_post_meta( $policy_id, '_policy_premium', true ),
				'coverage_details' => $policy->post_content,
				'created_at'       => $policy->post_date,
				'modified_at'      => $policy->post_modified,
			),
		);
	}
}
