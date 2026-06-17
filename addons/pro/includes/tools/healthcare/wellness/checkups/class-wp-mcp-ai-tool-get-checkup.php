<?php
/**
 * Tool for getting a single checkup.
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
 * Get a single checkup/appointment.
 */
class WP_MCP_AI_Tool_Get_Checkup implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_checkup';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Checkup', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves detailed information about a specific checkup or appointment.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'checkup_id' => array(
					'type'        => 'integer',
					'description' => __( 'Checkup ID (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'checkup_id' ),
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
			'post_type'             => 'mcp_ai_checkup',
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view checkups.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate checkup ID.
		$checkup_id = isset( $arguments['checkup_id'] ) ? absint( $arguments['checkup_id'] ) : 0;

		if ( ! $checkup_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Checkup ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get checkup.
		$checkup = get_post( $checkup_id );

		if ( ! $checkup || 'mcp_ai_checkup' !== $checkup->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Checkup not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get member info.
		$member_id   = get_post_meta( $checkup_id, '_checkup_member_id', true );
		$member_name = '';
		if ( $member_id ) {
			$member      = get_post( $member_id );
			$member_name = $member ? $member->post_title : '';
		}

		return array(
			'success' => true,
			'checkup' => array(
				'id'          => $checkup_id,
				'title'       => $checkup->post_title,
				'member_id'   => $member_id,
				'member_name' => $member_name,
				'datetime'    => get_post_meta( $checkup_id, '_checkup_datetime', true ),
				'provider'    => get_post_meta( $checkup_id, '_checkup_provider', true ),
				'location'    => get_post_meta( $checkup_id, '_checkup_location', true ),
				'type'        => get_post_meta( $checkup_id, '_checkup_type', true ),
				'status'      => get_post_meta( $checkup_id, '_checkup_status', true ),
				'notes'       => $checkup->post_content,
				'created_at'  => $checkup->post_date,
				'modified_at' => $checkup->post_modified,
			),
		);
	}
}
