<?php
/**
 * Tool for deleting places.
 *
 * Allows AI assistants to delete places.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes a place.
 */
class WP_MCP_AI_Tool_Delete_Place implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return __( 'Delete Place', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Deletes a place permanently.', 'wp-mcp-ai' );
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
					'description' => __( 'Place ID to delete (required)', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'place_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro', 'database-write', 'requires-capability' );
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
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'delete_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete places.', 'wp-mcp-ai' ) );
		}

		$place_id = isset( $arguments['place_id'] ) ? absint( $arguments['place_id'] ) : 0;

		if ( ! $place_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Place ID is required.', 'wp-mcp-ai' ) );
		}

		$place = get_post( $place_id );

		if ( ! $place || 'mcp_ai_place' !== $place->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Place not found.', 'wp-mcp-ai' ) );
		}

		$result = wp_delete_post( $place_id, true );

		if ( ! $result ) {
			return new WP_Error( 'wp_mcp_ai_delete_failed', __( 'Failed to delete place.', 'wp-mcp-ai' ) );
		}

		return array(
			'success' => true,
			'message' => __( 'Place deleted successfully.', 'wp-mcp-ai' ),
		);
	}
}
