<?php
/**
 * Tool for updating events.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates an existing event.
 */
class WP_MCP_AI_Tool_Update_Event implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public function get_slug() {
		return 'update_event';
	}

	public function get_name() {
		return __( 'Update Event', 'wp-mcp-ai' );
	}

	public function get_description() {
		return __( 'Updates an existing calendar event. Provide only the fields you want to update.', 'wp-mcp-ai' );
	}

	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'event_id'    => array(
					'type'        => 'integer',
					'description' => __( 'Event ID to update (required)', 'wp-mcp-ai' ),
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'New event title (optional)', 'wp-mcp-ai' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'New event description (optional)', 'wp-mcp-ai' ),
				),
				'start_date'  => array(
					'type'        => 'string',
					'description' => __( 'New start date (YYYY-MM-DD) (optional)', 'wp-mcp-ai' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'start_time'  => array(
					'type'        => 'string',
					'description' => __( 'New start time (HH:MM) (optional)', 'wp-mcp-ai' ),
					'pattern'     => '^([01]\d|2[0-3]):([0-5]\d)$',
				),
				'end_date'   => array(
					'type'        => 'string',
					'description' => __( 'New end date (YYYY-MM-DD) (optional)', 'wp-mcp-ai' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'end_time'   => array(
					'type'        => 'string',
					'description' => __( 'New end time (HH:MM) (optional)', 'wp-mcp-ai' ),
					'pattern'     => '^([01]\d|2[0-3]):([0-5]\d)$',
				),
				'location'  => array(
					'type'        => 'string',
					'description' => __( 'New event location (optional)', 'wp-mcp-ai' ),
				),
				'type'      => array(
					'type'        => 'string',
					'description' => __( 'New event type (optional)', 'wp-mcp-ai' ),
					'enum'        => array( 'meeting', 'deadline', 'milestone', 'reminder', 'other' ),
				),
				'attendees' => array(
					'type'        => 'array',
					'description' => __( 'New array of attendee user IDs (optional)', 'wp-mcp-ai' ),
					'items'       => array( 'type' => 'integer' ),
				),
			),
			'required'             => array( 'event_id' ),
			'additionalProperties' => false,
		);
	}

	public function get_capability_flags() {
		return array( 'database-write' );
	}

	public static function is_available() {
		// Project management is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_project_management'] );
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update events.', 'wp-mcp-ai' ) );
		}

		$event_id = isset( $arguments['event_id'] ) ? absint( $arguments['event_id'] ) : 0;
		
		if ( ! $event_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Event ID is required.', 'wp-mcp-ai' ) );
		}

		$event = get_post( $event_id );
		if ( ! $event || 'mcp_ai_event' !== $event->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_event', __( 'Invalid event ID.', 'wp-mcp-ai' ) );
		}

		$post_data = array( 'ID' => $event_id );

		if ( isset( $arguments['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $arguments['title'] );
		}

		if ( isset( $arguments['description'] ) ) {
			$post_data['post_content'] = wp_kses_post( $arguments['description'] );
		}

		if ( count( $post_data ) > 1 ) {
			wp_update_post( $post_data );
		}

		if ( isset( $arguments['start_date'] ) ) {
			update_post_meta( $event_id, '_event_start_date', sanitize_text_field( $arguments['start_date'] ) );
		}

		if ( isset( $arguments['start_time'] ) ) {
			update_post_meta( $event_id, '_event_start_time', sanitize_text_field( $arguments['start_time'] ) );
		}

		if ( isset( $arguments['end_date'] ) ) {
			update_post_meta( $event_id, '_event_end_date', sanitize_text_field( $arguments['end_date'] ) );
		}

		if ( isset( $arguments['end_time'] ) ) {
			update_post_meta( $event_id, '_event_end_time', sanitize_text_field( $arguments['end_time'] ) );
		}

		if ( isset( $arguments['location'] ) ) {
			update_post_meta( $event_id, '_event_location', sanitize_text_field( $arguments['location'] ) );
		}

		if ( isset( $arguments['type'] ) ) {
			update_post_meta( $event_id, '_event_type', sanitize_key( $arguments['type'] ) );
		}

		if ( isset( $arguments['attendees'] ) ) {
			update_post_meta( $event_id, '_event_attendees', array_map( 'absint', $arguments['attendees'] ) );
		}

		return array(
			'success'  => true,
			'message'  => __( 'Event updated successfully.', 'wp-mcp-ai' ),
			'event_id' => $event_id,
		);
	}
}
