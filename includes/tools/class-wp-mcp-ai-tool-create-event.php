<?php
/**
 * Tool for creating events.
 *
 * Allows AI assistants to create new calendar events.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a new event.
 */
class WP_MCP_AI_Tool_Create_Event implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_event';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Event', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new calendar event. Events can be meetings, deadlines, milestones, or any time-based activities. Supports all-day events and time-specific events.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'Event title (required)', 'wp-mcp-ai' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Event description (optional)', 'wp-mcp-ai' ),
					'maxLength'   => 5000,
				),
				'project_id'  => array(
					'type'        => 'integer',
					'description' => __( 'ID of the project this event belongs to (optional)', 'wp-mcp-ai' ),
				),
				'start_date'  => array(
					'type'        => 'string',
					'description' => __( 'Event start date in ISO 8601 format (YYYY-MM-DD) (required)', 'wp-mcp-ai' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'start_time'  => array(
					'type'        => 'string',
					'description' => __( 'Event start time in 24-hour format (HH:MM) (optional, omit for all-day events)', 'wp-mcp-ai' ),
					'pattern'     => '^([01]\d|2[0-3]):([0-5]\d)$',
				),
				'end_date'    => array(
					'type'        => 'string',
					'description' => __( 'Event end date in ISO 8601 format (YYYY-MM-DD) (optional, defaults to start_date)', 'wp-mcp-ai' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'end_time'    => array(
					'type'        => 'string',
					'description' => __( 'Event end time in 24-hour format (HH:MM) (optional)', 'wp-mcp-ai' ),
					'pattern'     => '^([01]\d|2[0-3]):([0-5]\d)$',
				),
				'location'    => array(
					'type'        => 'string',
					'description' => __( 'Event location (optional)', 'wp-mcp-ai' ),
					'maxLength'   => 500,
				),
				'type'        => array(
					'type'        => 'string',
					'description' => __( 'Event type (optional)', 'wp-mcp-ai' ),
					'enum'        => array( 'meeting', 'deadline', 'milestone', 'reminder', 'other' ),
					'default'     => 'meeting',
				),
				'attendees'   => array(
					'type'        => 'array',
					'description' => __( 'Array of user IDs who will attend this event (optional)', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'integer',
					),
				),
			),
			'required'             => array( 'title', 'start_date' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'database-write' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// Project management is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		return (bool) get_option( 'wp_mcp_ai_enable_project_management', false );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create events.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		// Validate and sanitize inputs.
		$title       = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$description = isset( $arguments['description'] ) ? wp_kses_post( $arguments['description'] ) : '';
		$project_id  = isset( $arguments['project_id'] ) ? absint( $arguments['project_id'] ) : 0;
		$start_date  = isset( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : '';
		$start_time  = isset( $arguments['start_time'] ) ? sanitize_text_field( $arguments['start_time'] ) : '';
		$end_date    = isset( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : $start_date;
		$end_time    = isset( $arguments['end_time'] ) ? sanitize_text_field( $arguments['end_time'] ) : '';
		$location    = isset( $arguments['location'] ) ? sanitize_text_field( $arguments['location'] ) : '';
		$type        = isset( $arguments['type'] ) ? sanitize_key( $arguments['type'] ) : 'meeting';
		$attendees   = isset( $arguments['attendees'] ) && is_array( $arguments['attendees'] ) ? array_map( 'absint', $arguments['attendees'] ) : array();

		if ( '' === $title ) {
			return new WP_Error( 'wp_mcp_ai_missing_title', __( 'Event title is required.', 'wp-mcp-ai' ) );
		}

		if ( '' === $start_date ) {
			return new WP_Error( 'wp_mcp_ai_missing_start_date', __( 'Event start date is required.', 'wp-mcp-ai' ) );
		}

		// Validate project exists.
		if ( $project_id > 0 ) {
			$project = get_post( $project_id );
			if ( ! $project || 'mcp_ai_project' !== $project->post_type ) {
				return new WP_Error( 'wp_mcp_ai_invalid_project', __( 'Invalid project ID.', 'wp-mcp-ai' ) );
			}
		}

		// Validate dates.
		if ( ! $this->validate_date( $start_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_start_date', __( 'Invalid start date format. Use YYYY-MM-DD.', 'wp-mcp-ai' ) );
		}

		if ( $end_date && ! $this->validate_date( $end_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_end_date', __( 'Invalid end date format. Use YYYY-MM-DD.', 'wp-mcp-ai' ) );
		}

		// Validate times.
		if ( $start_time && ! $this->validate_time( $start_time ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_start_time', __( 'Invalid start time format. Use HH:MM.', 'wp-mcp-ai' ) );
		}

		if ( $end_time && ! $this->validate_time( $end_time ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_end_time', __( 'Invalid end time format. Use HH:MM.', 'wp-mcp-ai' ) );
		}

		// Validate event type.
		$valid_types = array( 'meeting', 'deadline', 'milestone', 'reminder', 'other' );
		if ( ! in_array( $type, $valid_types, true ) ) {
			$type = 'meeting';
		}

		// Validate attendees.
		foreach ( $attendees as $user_id ) {
			if ( ! get_user_by( 'id', $user_id ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_attendee', sprintf( __( 'User ID %d does not exist.', 'wp-mcp-ai' ), $user_id ) );
			}
		}

		// Determine if all-day event.
		$all_day = empty( $start_time ) && empty( $end_time );

		// Create event post.
		$post_data = array(
			'post_type'    => 'mcp_ai_event',
			'post_title'   => $title,
			'post_content' => $description,
			'post_status'  => 'publish',
			'post_author'  => $current_user_id,
		);

		$event_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $event_id ) ) {
			return $event_id;
		}

		// Save event metadata.
		update_post_meta( $event_id, '_event_start_date', $start_date );
		update_post_meta( $event_id, '_event_end_date', $end_date );
		update_post_meta( $event_id, '_event_type', $type );
		update_post_meta( $event_id, '_event_all_day', $all_day ? '1' : '0' );

		if ( $project_id > 0 ) {
			update_post_meta( $event_id, '_event_project_id', $project_id );
		}

		if ( $start_time ) {
			update_post_meta( $event_id, '_event_start_time', $start_time );
		}

		if ( $end_time ) {
			update_post_meta( $event_id, '_event_end_time', $end_time );
		}

		if ( $location ) {
			update_post_meta( $event_id, '_event_location', $location );
		}

		if ( ! empty( $attendees ) ) {
			update_post_meta( $event_id, '_event_attendees', $attendees );
		}

		return array(
			'success'  => true,
			'message'  => __( 'Event created successfully.', 'wp-mcp-ai' ),
			'event_id' => $event_id,
			'event'    => array(
				'id'          => $event_id,
				'title'       => $title,
				'description' => $description,
				'project_id'  => $project_id ?: null,
				'start_date'  => $start_date,
				'start_time'  => $start_time,
				'end_date'    => $end_date,
				'end_time'    => $end_time,
				'all_day'     => $all_day,
				'location'    => $location,
				'type'        => $type,
				'attendees'   => $attendees,
				'created_at'  => current_time( 'mysql' ),
			),
		);
	}

	/**
	 * Validate date format (YYYY-MM-DD).
	 *
	 * @param string $date Date string.
	 * @return bool
	 */
	private function validate_date( $date ) {
		$d = DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}

	/**
	 * Validate time format (HH:MM).
	 *
	 * @param string $time Time string.
	 * @return bool
	 */
	private function validate_time( $time ) {
		return (bool) preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time );
	}
}
