<?php
/**
 * Tool for getting single policy details.
 *
 * Allows AI assistants to retrieve detailed information about a specific policy.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gets detailed information for a single policy.
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
		return __( 'Retrieves detailed information about a specific insurance policy, including all coverage details and member information.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Policy ID (required)', 'mcp-ai-wpoos-pro' ),
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

		// Validate inputs.
		$policy_id = isset( $arguments['policy_id'] ) ? absint( $arguments['policy_id'] ) : 0;

		if ( ! $policy_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_policy_id', __( 'Policy ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify policy exists.
		$policy = get_post( $policy_id );
		if ( ! $policy || 'mcp_ai_policy' !== $policy->post_type ) {
			return new WP_Error( 'wp_mcp_ai_policy_not_found', __( 'Policy not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get policy type.
		$types       = wp_get_object_terms( $policy_id, 'mcp_ai_policy_type', array( 'fields' => 'slugs' ) );
		$policy_type = ! empty( $types ) && ! is_wp_error( $types ) ? $types[0] : '';

		// Get member info.
		$member_id   = get_post_meta( $policy_id, '_policy_member_id', true );
		$member_name = '';
		if ( $member_id ) {
			$member = get_post( $member_id );
			$member_name = $member ? $member->post_title : '';
		}

		// Build policy data.
		$policy_data = array(
			'id'               => $policy_id,
			'policy_number'    => get_post_meta( $policy_id, '_policy_number', true ),
			'name'             => $policy->post_title,
			'type'             => $policy_type,
			'member_id'        => $member_id,
			'member_name'      => $member_name,
			'provider'         => get_post_meta( $policy_id, '_policy_provider', true ),
			'status'           => get_post_meta( $policy_id, '_policy_status', true ),
			'effective_date'   => get_post_meta( $policy_id, '_policy_effective_date', true ),
			'expiration_date'  => get_post_meta( $policy_id, '_policy_expiration_date', true ),
			'premium'          => get_post_meta( $policy_id, '_policy_premium', true ),
			'deductible'       => get_post_meta( $policy_id, '_policy_deductible', true ),
			'group_number'     => get_post_meta( $policy_id, '_policy_group_number', true ),
			'phone'            => get_post_meta( $policy_id, '_policy_phone', true ),
			'coverage_details' => $policy->post_content,
			'created_at'       => $policy->post_date,
			'modified_at'      => $policy->post_modified,
			'author_id'        => absint( $policy->post_author ),
		);

		return array(
			'success' => true,
			'policy'  => $policy_data,
		);
	}
}
