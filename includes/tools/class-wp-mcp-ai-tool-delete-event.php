<?php
/**
 * Tool for deleting events.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes an event.
 */
class WP_MCP_AI_Tool_Delete_Event implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public function get_slug() {
		return 'delete_event';
	}

	public function get_name() {
		return __( 'Delete Event', 'wp-mcp-ai' );
	}

	public function get_description() {
		return __( 'Deletes a calendar event permanently.', 'wp-mcp-ai' );
	}

	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'event_id' => array(
					'type'        => 'integer',
					'description' => __( 'Event ID to delete (required)', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'event_id' ),
			'additionalProperties' => false,
		);
	}

	public function get_capability_flags() {
		return array( 'database-write', 'destructive' );
	}

	public static function is_available() {
		// Project management is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		return (bool) get_option( 'wp_mcp_ai_enable_project_management', false );
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'delete_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete events.', 'wp-mcp-ai' ) );
		}

		$event_id = isset( $arguments['event_id'] ) ? absint( $arguments['event_id'] ) : 0;
		
		if ( ! $event_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Event ID is required.', 'wp-mcp-ai' ) );
		}

		$event = get_post( $event_id );
		if ( ! $event || 'mcp_ai_event' !== $event->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_event', __( 'Invalid event ID.', 'wp-mcp-ai' ) );
		}

		$result = wp_delete_post( $event_id, true );

		if ( ! $result ) {
			return new WP_Error( 'wp_mcp_ai_delete_failed', __( 'Failed to delete event.', 'wp-mcp-ai' ) );
		}

		return array(
			'success' => true,
			'message' => __( 'Event deleted successfully.', 'wp-mcp-ai' ) ,
			'event_id' => $event_id,
		);
	}
}
