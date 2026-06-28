<?php
/**
 * Tool for creating events.
 *
 * Allows AI assistants to create new calendar events.
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
		return __( 'Create Event', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new calendar event or updates an existing one if event_id is provided. Events can be meetings, deadlines, milestones, or any time-based activities. Supports all-day events and time-specific events.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'event_id'    => array(
					'type'        => 'integer',
					'description' => __( 'Optional event ID. If provided, updates the existing event instead of creating a new one.', 'mcp-ai-wpoos-pro' ),
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'Event title (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Event description (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 5000,
				),
				'project_id'  => array(
					'type'        => 'integer',
					'description' => __( 'ID of the project this event belongs to (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'start_date'  => array(
					'type'        => 'string',
					'description' => __( 'Event start date in ISO 8601 format (YYYY-MM-DD) (required)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'start_time'  => array(
					'type'        => 'string',
					'description' => __( 'Event start time in 24-hour format (HH:MM) (optional, omit for all-day events)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^([01]\d|2[0-3]):([0-5]\d)$',
				),
				'end_date'    => array(
					'type'        => 'string',
					'description' => __( 'Event end date in ISO 8601 format (YYYY-MM-DD) (optional, defaults to start_date)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'end_time'    => array(
					'type'        => 'string',
					'description' => __( 'Event end time in 24-hour format (HH:MM) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^([01]\d|2[0-3]):([0-5]\d)$',
				),
				'location'    => array(
					'type'        => 'string',
					'description' => __( 'Event location (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 500,
				),
				'type'        => array(
					'type'        => 'string',
					'description' => __( 'Event type (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'meeting', 'deadline', 'milestone', 'reminder', 'other' ),
					'default'     => 'meeting',
				),
				'attendees'   => array(
					'type'        => 'array',
					'description' => __( 'Array of user IDs who will attend this event (optional)', 'mcp-ai-wpoos-pro' ),
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
			'toolkit'               => 'event_management',
			'post_type'             => 'mcp_ai_event',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'event_manager', 'project_manager', 'coordinator' ),
			'risk_level'            => 'standard',
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
		);
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
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_project_management'] );
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create events.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check if this is an update operation.
		$event_id       = isset( $arguments['event_id'] ) ? absint( $arguments['event_id'] ) : 0;
		$is_update      = false;
		$existing_event = null;

		if ( $event_id ) {
			// Verify event exists and user has permission to update it.
			$existing_event = get_post( $event_id );

			if ( ! $existing_event || 'mcp_ai_event' !== $existing_event->post_type ) {
				return new WP_Error( 'wp_mcp_ai_event_not_found', __( 'Event not found.', 'mcp-ai-wpoos-pro' ) );
			}

			// Check permissions: must be author or have edit_others_posts capability.
			$is_author       = absint( $existing_event->post_author ) === $current_user_id;
			$can_edit_others = user_can( $current_user_id, 'edit_others_posts' );

			if ( ! $is_author && ! $can_edit_others ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update this event.', 'mcp-ai-wpoos-pro' ) );
			}

			$is_update = true;
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
			return new WP_Error( 'wp_mcp_ai_missing_title', __( 'Event title is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( '' === $start_date ) {
			return new WP_Error( 'wp_mcp_ai_missing_start_date', __( 'Event start date is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate project exists.
		if ( $project_id > 0 ) {
			$project = get_post( $project_id );
			if ( ! $project || 'mcp_ai_project' !== $project->post_type ) {
				return new WP_Error( 'wp_mcp_ai_invalid_project', __( 'Invalid project ID.', 'mcp-ai-wpoos-pro' ) );
			}
		}

		// Validate dates.
		if ( ! $this->validate_date( $start_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_start_date', __( 'Invalid start date format. Use YYYY-MM-DD.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $end_date && ! $this->validate_date( $end_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_end_date', __( 'Invalid end date format. Use YYYY-MM-DD.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate times.
		if ( $start_time && ! $this->validate_time( $start_time ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_start_time', __( 'Invalid start time format. Use HH:MM.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $end_time && ! $this->validate_time( $end_time ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_end_time', __( 'Invalid end time format. Use HH:MM.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate event type.
		$valid_types = array( 'meeting', 'deadline', 'milestone', 'reminder', 'other' );
		if ( ! in_array( $type, $valid_types, true ) ) {
			$type = 'meeting';
		}

		// Validate attendees.
		foreach ( $attendees as $user_id ) {
			if ( ! get_user_by( 'id', $user_id ) ) {
				/* translators: %d: user ID */
				return new WP_Error( 'wp_mcp_ai_invalid_attendee', sprintf( __( 'User ID %d does not exist.', 'mcp-ai-wpoos-pro' ), $user_id ) );
			}
		}

		// Determine if all-day event.
		$all_day = empty( $start_time ) && empty( $end_time );

		if ( $is_update ) {
			// Update existing event.
			$post_data = array(
				'ID'           => $event_id,
				'post_title'   => $title,
				'post_content' => $description,
			);

			$result = wp_update_post( $post_data, true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Update event metadata.
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

			$event = get_post( $event_id );

			return array(
				'success'  => true,
				'message'  => sprintf(
					/* translators: %s: event title */
					__( 'Event updated: %s', 'mcp-ai-wpoos-pro' ),
					$title
				),
				'event_id' => $event_id,
				'event'    => array(
					'id'          => $event_id,
					'title'       => $title,
					'description' => $description,
					'project_id'  => $project_id ? $project_id : null,
					'start_date'  => $start_date,
					'start_time'  => $start_time,
					'end_date'    => $end_date,
					'end_time'    => $end_time,
					'all_day'     => $all_day,
					'location'    => $location,
					'type'        => $type,
					'attendees'   => $attendees,
					'updated_at'  => $event->post_modified,
				),
				'updated'  => true,
			);
		} else {
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
				'message'  => __( 'Event created successfully.', 'mcp-ai-wpoos-pro' ),
				'event_id' => $event_id,
				'event'    => array(
					'id'          => $event_id,
					'title'       => $title,
					'description' => $description,
					'project_id'  => $project_id ? $project_id : null,
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
				'updated'  => false,
			);
		}
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
