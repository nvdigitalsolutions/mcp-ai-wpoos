<?php
/**
 * Tool for deleting allergies.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes an allergy record.
 */
class WP_MCP_AI_Tool_Delete_Allergy implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public function get_slug() {
		return 'delete_allergy';
	}

	public function get_name() {
		return __( 'Delete Allergy', 'mcp-ai-wpoos-pro' );
	}

	public function get_description() {
		return __( 'Permanently deletes an allergy record from the system.', 'mcp-ai-wpoos-pro' );
	}

	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'allergy_id' => array(
					'type'        => 'integer',
					'description' => __( 'Allergy ID to delete (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'allergy_id' ),
			'additionalProperties' => false,
		);
	}

	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'destructive' );
	}

	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_health_wellness_management'] );
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'delete_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete allergies.', 'mcp-ai-wpoos-pro' ) );
		}

		$allergy_id = isset( $arguments['allergy_id'] ) ? absint( $arguments['allergy_id'] ) : 0;

		if ( ! $allergy_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Allergy ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$allergy = get_post( $allergy_id );

		if ( ! $allergy || 'mcp_ai_allergy' !== $allergy->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Allergy not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = wp_delete_post( $allergy_id, true );

		if ( ! $result ) {
			return new WP_Error( 'wp_mcp_ai_delete_failed', __( 'Failed to delete allergy.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'success' => true,
			'message' => __( 'Allergy deleted successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
