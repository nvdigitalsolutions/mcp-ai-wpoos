<?php
/**
 * Tool for updating policies.
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
 * Updates an existing policy.
 */
class WP_MCP_AI_Tool_Update_Policy implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_policy';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Policy', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates an existing insurance policy. Provide only the fields you want to update.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'policy_id'        => array(
					'type'        => 'integer',
					'description' => __( 'Policy ID to update (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'policy_number'    => array(
					'type'        => 'string',
					'description' => __( 'New policy number (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'name'             => array(
					'type'        => 'string',
					'description' => __( 'New policy name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'policy_type'      => array(
					'type'        => 'string',
					'description' => __( 'New policy type (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'health-insurance', 'dental-insurance', 'vision-insurance', 'pet-insurance', 'life-insurance' ),
				),
				'provider'         => array(
					'type'        => 'string',
					'description' => __( 'New provider (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'status'           => array(
					'type'        => 'string',
					'description' => __( 'New status (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'active', 'expired', 'pending', 'cancelled' ),
				),
				'effective_date'   => array(
					'type'        => 'string',
					'description' => __( 'New effective date (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'expiration_date'  => array(
					'type'        => 'string',
					'description' => __( 'New expiration date (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'premium'          => array(
					'type'        => 'string',
					'description' => __( 'New premium amount (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 50,
				),
				'coverage_details' => array(
					'type'        => 'string',
					'description' => __( 'New coverage details (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 5000,
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
			'risk_level'            => 'standard',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write' );
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update policies.', 'mcp-ai-wpoos-pro' ) );
		}

		$policy_id = isset( $arguments['policy_id'] ) ? absint( $arguments['policy_id'] ) : 0;

		if ( ! $policy_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Policy ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$policy = get_post( $policy_id );

		if ( ! $policy || 'mcp_ai_policy' !== $policy->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_policy', __( 'Invalid policy ID.', 'mcp-ai-wpoos-pro' ) );
		}

		// Update post data if provided.
		$post_data = array( 'ID' => $policy_id );

		if ( isset( $arguments['name'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $arguments['name'] );
		}

		if ( isset( $arguments['coverage_details'] ) ) {
			$post_data['post_content'] = wp_kses_post( $arguments['coverage_details'] );
		}

		if ( count( $post_data ) > 1 ) {
			$result = wp_update_post( $post_data, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Update taxonomy if provided.
		if ( isset( $arguments['policy_type'] ) ) {
			$type = sanitize_key( $arguments['policy_type'] );
			wp_set_object_terms( $policy_id, $type, 'mcp_ai_policy_type' );
		}

		// Update metadata.
		$meta_fields = array(
			'policy_number'   => '_policy_number',
			'provider'        => '_policy_provider',
			'status'          => '_policy_status',
			'effective_date'  => '_policy_effective_date',
			'expiration_date' => '_policy_expiration_date',
			'premium'         => '_policy_premium',
		);

		foreach ( $meta_fields as $arg_key => $meta_key ) {
			if ( isset( $arguments[ $arg_key ] ) ) {
				update_post_meta( $policy_id, $meta_key, sanitize_text_field( $arguments[ $arg_key ] ) );
			}
		}

		return array(
			'success'   => true,
			'message'   => __( 'Policy updated successfully.', 'mcp-ai-wpoos-pro' ),
			'policy_id' => $policy_id,
		);
	}
}
