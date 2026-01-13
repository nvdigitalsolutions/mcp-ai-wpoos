<?php
/**
 * Tool for getting single allergy details.
 *
 * Allows AI assistants to retrieve detailed information about a specific allergy.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gets detailed information for a single allergy.
 */
class WP_MCP_AI_Tool_Get_Allergy implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_allergy';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Allergy', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves detailed information about a specific allergy record, including allergen, type, severity, reactions, and management notes.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view allergy records.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate inputs.
		$allergy_id = isset( $arguments['allergy_id'] ) ? absint( $arguments['allergy_id'] ) : 0;

		if ( ! $allergy_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_allergy_id', __( 'Allergy ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify allergy exists.
		$allergy = get_post( $allergy_id );
		if ( ! $allergy || 'mcp_ai_allergy' !== $allergy->post_type ) {
			return new WP_Error( 'wp_mcp_ai_allergy_not_found', __( 'Allergy record not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get member info.
		$member_id   = get_post_meta( $allergy_id, '_allergy_member_id', true );
		$member_name = '';
		if ( $member_id ) {
			$member      = get_post( $member_id );
			$member_name = $member ? $member->post_title : '';
		}

		// Build allergy data.
		$allergy_data = array(
			'id'             => $allergy_id,
			'allergen'       => $allergy->post_title,
			'member_id'      => $member_id,
			'member_name'    => $member_name,
			'type'           => get_post_meta( $allergy_id, '_allergy_type', true ),
			'severity'       => get_post_meta( $allergy_id, '_allergy_severity', true ),
			'reactions'      => get_post_meta( $allergy_id, '_allergy_reactions', true ),
			'diagnosed_date' => get_post_meta( $allergy_id, '_allergy_diagnosed_date', true ),
			'notes'          => $allergy->post_content,
			'created_at'     => $allergy->post_date,
			'modified_at'    => $allergy->post_modified,
			'author_id'      => absint( $allergy->post_author ),
		);

		return array(
			'success' => true,
			'allergy' => $allergy_data,
		);
	}
}
