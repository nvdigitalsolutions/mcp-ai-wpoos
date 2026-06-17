<?php
/**
 * Tool for getting a single allergy.
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
 * Get a single allergy record.
 */
class WP_MCP_AI_Tool_Get_Allergy implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'get_allergy';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Get Allergy', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Retrieves detailed information about a specific allergy record.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'allergy_id' => array(
					'type'        => 'integer',
					'description' => __( 'Allergy ID (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'allergy_id' ),
			'additionalProperties' => false,
		);
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
			'post_type'             => 'mcp_ai_allergy',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'healthcare_provider', 'caregiver' ),
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
	 * Check if tool is available.
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
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view allergies.', 'mcp-ai-wpoos-pro' ) );
		}

		$allergy_id = isset( $arguments['allergy_id'] ) ? absint( $arguments['allergy_id'] ) : 0;

		if ( ! $allergy_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Allergy ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$allergy = get_post( $allergy_id );

		if ( ! $allergy || 'mcp_ai_allergy' !== $allergy->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Allergy not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$severities = wp_get_object_terms( $allergy_id, 'mcp_ai_allergy_severity', array( 'fields' => 'slugs' ) );
		$severity   = ! empty( $severities ) && ! is_wp_error( $severities ) ? $severities[0] : '';

		$member_id   = get_post_meta( $allergy_id, '_allergy_member_id', true );
		$member_name = '';
		if ( $member_id ) {
			$member      = get_post( $member_id );
			$member_name = $member ? $member->post_title : '';
		}

		return array(
			'success' => true,
			'allergy' => array(
				'id'             => $allergy_id,
				'allergen'       => $allergy->post_title,
				'member_id'      => $member_id,
				'member_name'    => $member_name,
				'severity'       => $severity,
				'reactions'      => get_post_meta( $allergy_id, '_allergy_reactions', true ),
				'diagnosed_date' => get_post_meta( $allergy_id, '_allergy_diagnosed_date', true ),
				'notes'          => $allergy->post_content,
				'created_at'     => $allergy->post_date,
				'modified_at'    => $allergy->post_modified,
			),
		);
	}
}
