<?php
/**
 * Tool for deleting places.
 *
 * Allows AI assistants to delete places.
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
 * Deletes a place.
 */
class WP_MCP_AI_Tool_Delete_Place implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'delete_place';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delete Place', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Deletes a place permanently.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Permanently removing a place record the user explicitly wants gone.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Reversible edits; use update_place to change data, and confirm before deleting since this cannot be undone.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'get_place', 'update_place', 'list_places' ),
			'notes'           => __( 'Deletion is permanent and ignores the trash; verify the place via get_place first.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'place_id' => array(
					'type'        => 'integer',
					'description' => __( 'Place ID to delete (required)', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'place_id' ),
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
			'toolkit'               => 'places',
			'post_type'             => 'mcp_ai_place',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'travel_agent', 'content_creator' ),
			'risk_level'            => 'high',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-write',
			'requires-capability',
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_places_management'] );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete places.', 'mcp-ai-wpoos-pro' ) );
		}

		$place_id = isset( $arguments['place_id'] ) ? absint( $arguments['place_id'] ) : 0;

		if ( ! $place_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Place ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$place = get_post( $place_id );

		if ( ! $place || 'mcp_ai_place' !== $place->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Place not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = wp_delete_post( $place_id, true );

		if ( ! $result ) {
			return new WP_Error( 'wp_mcp_ai_delete_failed', __( 'Failed to delete place.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'success' => true,
			'message' => __( 'Place deleted successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
