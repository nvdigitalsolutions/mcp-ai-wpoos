<?php
/**
 * Tool for creating event bookings.
 *
 * Allows AI assistants to create DJ event bookings with client and event details.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.7
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates DJ event bookings.
 */
class WP_MCP_AI_Tool_Create_Event_Booking implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_event_booking';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Event Booking', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create a new event booking or update an existing event booking. If booking_id is provided, updates the existing event booking instead of creating a new one. Manages client details, event information, and pricing. Use this tool for both creating new event bookings and updating existing ones.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'booking_id'    => array(
					'type'        => 'integer',
					'description' => __( 'Optional booking ID. If provided, updates the existing event booking instead of creating a new one.', 'mcp-ai-wpoos-pro' ),
				),
				'event_name'    => array(
					'type'        => 'string',
					'description' => __( 'Event name (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'event_type'    => array(
					'type'        => 'string',
					'description' => __( 'Event type (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'wedding', 'corporate', 'birthday', 'club', 'private_party', 'festival', 'other' ),
				),
				'event_date'    => array(
					'type'        => 'string',
					'description' => __( 'Event date in ISO 8601 format (YYYY-MM-DD) (required)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'start_time'    => array(
					'type'        => 'string',
					'description' => __( 'Event start time in 24-hour format (HH:MM) (required)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^([01]\d|2[0-3]):([0-5]\d)$',
				),
				'end_time'      => array(
					'type'        => 'string',
					'description' => __( 'Event end time in 24-hour format (HH:MM) (required)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^([01]\d|2[0-3]):([0-5]\d)$',
				),
				'venue_name'    => array(
					'type'        => 'string',
					'description' => __( 'Venue name (required)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'venue_address' => array(
					'type'        => 'string',
					'description' => __( 'Venue address (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 500,
				),
				'client_name'   => array(
					'type'        => 'string',
					'description' => __( 'Client name (required)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'client_email'  => array(
					'type'        => 'string',
					'description' => __( 'Client email (required)', 'mcp-ai-wpoos-pro' ),
					'format'      => 'email',
					'maxLength'   => 100,
				),
				'client_phone'  => array(
					'type'        => 'string',
					'description' => __( 'Client phone number (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 20,
				),
				'guest_count'   => array(
					'type'        => 'integer',
					'description' => __( 'Expected number of guests (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'package'       => array(
					'type'        => 'string',
					'description' => __( 'Service package (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'basic', 'standard', 'premium', 'custom' ),
				),
				'total_price'   => array(
					'type'        => 'number',
					'description' => __( 'Total price (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'deposit'       => array(
					'type'        => 'number',
					'description' => __( 'Deposit amount (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'notes'         => array(
					'type'        => 'string',
					'description' => __( 'Additional notes (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 2000,
				),
			),
			'required'             => array( 'event_name', 'event_type', 'event_date', 'start_time', 'end_time', 'venue_name', 'client_name', 'client_email' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate required parameters.
		$required_fields = array( 'event_name', 'event_type', 'event_date', 'start_time', 'end_time', 'venue_name', 'client_name', 'client_email' );
		foreach ( $required_fields as $field ) {
			if ( empty( $arguments[ $field ] ) ) {
				return new WP_Error(
					'tool_error',
					sprintf(
						/* translators: %s: field name */
						__( 'Required field "%s" is missing.', 'mcp-ai-wpoos-pro' ),
						$field
					)
				);
			}
		}

		// Check if this is an update operation.
		$booking_id       = isset( $arguments['booking_id'] ) ? absint( $arguments['booking_id'] ) : 0;
		$is_update        = false;
		$existing_booking = null;

		if ( $booking_id ) {
			// Verify booking exists and user has permission to update it.
			$existing_booking = get_post( $booking_id );

			if ( ! $existing_booking || 'dj_booking' !== $existing_booking->post_type ) {
				return new WP_Error(
					'tool_error',
					__( 'Event booking not found.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Check permissions: must be author or have edit_others_posts capability.
			$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
			$is_author       = absint( $existing_booking->post_author ) === $current_user_id;
			$can_edit_others = user_can( $current_user_id, 'edit_others_posts' );

			if ( ! $is_author && ! $can_edit_others ) {
				return new WP_Error(
					'tool_error',
					__( 'You do not have permission to update this event booking.', 'mcp-ai-wpoos-pro' )
				);
			}

			$is_update = true;
		}

		// Sanitize inputs.
		$event_name    = sanitize_text_field( $arguments['event_name'] );
		$event_type    = sanitize_text_field( $arguments['event_type'] );
		$event_date    = sanitize_text_field( $arguments['event_date'] );
		$start_time    = sanitize_text_field( $arguments['start_time'] );
		$end_time      = sanitize_text_field( $arguments['end_time'] );
		$venue_name    = sanitize_text_field( $arguments['venue_name'] );
		$venue_address = ! empty( $arguments['venue_address'] ) ? sanitize_text_field( $arguments['venue_address'] ) : '';
		$client_name   = sanitize_text_field( $arguments['client_name'] );
		$client_email  = sanitize_email( $arguments['client_email'] );
		$client_phone  = ! empty( $arguments['client_phone'] ) ? sanitize_text_field( $arguments['client_phone'] ) : '';
		$guest_count   = ! empty( $arguments['guest_count'] ) ? absint( $arguments['guest_count'] ) : 0;
		$package       = ! empty( $arguments['package'] ) ? sanitize_text_field( $arguments['package'] ) : '';
		$total_price   = ! empty( $arguments['total_price'] ) ? floatval( $arguments['total_price'] ) : 0;
		$deposit       = ! empty( $arguments['deposit'] ) ? floatval( $arguments['deposit'] ) : 0;
		$notes         = ! empty( $arguments['notes'] ) ? sanitize_textarea_field( $arguments['notes'] ) : '';

		// Validate email.
		if ( ! is_email( $client_email ) ) {
			return new WP_Error(
				'tool_error',
				__( 'Invalid email address.', 'mcp-ai-wpoos-pro' )
			);
		}

		$post_title = sprintf(
			'%s - %s - %s',
			$event_date,
			$event_name,
			$client_name
		);

		if ( $is_update ) {
			// Update existing booking post.
			$post_data = array(
				'ID'           => $booking_id,
				'post_title'   => $post_title,
				'post_content' => $notes,
			);

			$result = wp_update_post( $post_data );

			if ( is_wp_error( $result ) ) {
				return new WP_Error(
					'tool_error',
					$result->get_error_message()
				);
			}
		} else {
			// Create booking post.
			$post_data = array(
				'post_title'   => $post_title,
				'post_content' => $notes,
				'post_status'  => 'publish',
				'post_type'    => 'dj_booking',
			);

			$booking_id = wp_insert_post( $post_data );

			if ( is_wp_error( $booking_id ) ) {
				return new WP_Error(
					'tool_error',
					$booking_id->get_error_message()
				);
			}
		}

		// Store booking metadata.
		update_post_meta( $booking_id, '_event_name', $event_name );
		update_post_meta( $booking_id, '_event_type', $event_type );
		update_post_meta( $booking_id, '_event_date', $event_date );
		update_post_meta( $booking_id, '_start_time', $start_time );
		update_post_meta( $booking_id, '_end_time', $end_time );
		update_post_meta( $booking_id, '_venue_name', $venue_name );
		update_post_meta( $booking_id, '_venue_address', $venue_address );
		update_post_meta( $booking_id, '_client_name', $client_name );
		update_post_meta( $booking_id, '_client_email', $client_email );
		update_post_meta( $booking_id, '_client_phone', $client_phone );
		update_post_meta( $booking_id, '_guest_count', $guest_count );
		update_post_meta( $booking_id, '_package', $package );
		update_post_meta( $booking_id, '_total_price', $total_price );
		update_post_meta( $booking_id, '_deposit', $deposit );
		if ( ! $is_update ) {
			update_post_meta( $booking_id, '_booking_status', 'pending' );
			update_post_meta( $booking_id, '_created_date', current_time( 'mysql' ) );
		}

		return array(
			'success'    => true,
			'booking_id' => $booking_id,
			'updated'    => $is_update,
			'message'    => sprintf(
				/* translators: %s: event name */
				$is_update ? __( 'Event booking "%s" updated successfully.', 'mcp-ai-wpoos-pro' ) : __( 'Event booking "%s" created successfully.', 'mcp-ai-wpoos-pro' ),
				$event_name
			),
			'booking'    => array(
				'id'           => $booking_id,
				'event_name'   => $event_name,
				'event_type'   => $event_type,
				'event_date'   => $event_date,
				'start_time'   => $start_time,
				'end_time'     => $end_time,
				'venue_name'   => $venue_name,
				'client_name'  => $client_name,
				'client_email' => $client_email,
				'total_price'  => $total_price,
				'status'       => 'pending',
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'write' );
	}
}
