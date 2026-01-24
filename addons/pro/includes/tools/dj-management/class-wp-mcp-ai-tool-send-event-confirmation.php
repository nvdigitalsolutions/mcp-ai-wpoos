<?php
/**
 * Tool for sending event confirmations.
 *
 * Allows AI assistants to send booking confirmation emails to clients.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends event confirmation emails.
 */
class WP_MCP_AI_Tool_Send_Event_Confirmation implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'send_event_confirmation';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send Event Confirmation', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends a booking confirmation email to the client with event details, timeline, and next steps.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'booking_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Booking ID to send confirmation for (required)', 'mcp-ai-wpoos-pro' ),
				),
				'custom_message'   => array(
					'type'        => 'string',
					'description' => __( 'Custom message to include in email (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 1000,
				),
				'include_timeline' => array(
					'type'        => 'boolean',
					'description' => __( 'Include event timeline in email (optional, defaults to true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'             => array( 'booking_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments, array $context = array() ) {
		if ( empty( $arguments['booking_id'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Booking ID is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$booking_id = absint( $arguments['booking_id'] );

		if ( ! get_post( $booking_id ) || get_post_type( $booking_id ) !== 'dj_booking' ) {
			return array(
				'success' => false,
				'error'   => __( 'Invalid booking ID.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Get booking details.
		$event_name    = get_post_meta( $booking_id, '_event_name', true );
		$event_date    = get_post_meta( $booking_id, '_event_date', true );
		$start_time    = get_post_meta( $booking_id, '_start_time', true );
		$end_time      = get_post_meta( $booking_id, '_end_time', true );
		$venue_name    = get_post_meta( $booking_id, '_venue_name', true );
		$venue_address = get_post_meta( $booking_id, '_venue_address', true );
		$client_name   = get_post_meta( $booking_id, '_client_name', true );
		$client_email  = get_post_meta( $booking_id, '_client_email', true );
		$total_price   = get_post_meta( $booking_id, '_total_price', true );
		$deposit       = get_post_meta( $booking_id, '_deposit', true );

		$custom_message   = ! empty( $arguments['custom_message'] ) ? sanitize_textarea_field( $arguments['custom_message'] ) : '';
		$include_timeline = isset( $arguments['include_timeline'] ) ? (bool) $arguments['include_timeline'] : true;

		// Build email.
		$subject = sprintf(
		/* translators: %s: event name */
			__( 'Booking Confirmation: %s', 'mcp-ai-wpoos-pro' ),
			$event_name
		);

		/* translators: %s: Client name */
		$message = sprintf(
			__( "Dear %s,\n\nThank you for booking our DJ services! We are excited to be part of your special event.\n\n", 'mcp-ai-wpoos-pro' ),
			$client_name
		);

		$message .= "**Event Details:**\n";
		$message .= sprintf( "Event: %s\n", $event_name );
		$message .= sprintf( "Date: %s\n", gmdate( 'F j, Y', strtotime( $event_date ) ) );
		$message .= sprintf( "Time: %s - %s\n", $start_time, $end_time );
		$message .= sprintf( "Venue: %s\n", $venue_name );
		if ( $venue_address ) {
			$message .= sprintf( "Address: %s\n", $venue_address );
		}

		if ( $total_price ) {
			$message .= sprintf( "\nTotal: $%.2f\n", $total_price );
			if ( $deposit ) {
				$message .= sprintf( "Deposit: $%.2f\n", $deposit );
				$message .= sprintf( "Balance: $%.2f\n", $total_price - $deposit );
			}
		}

		if ( $custom_message ) {
			$message .= "\n" . $custom_message . "\n";
		}

		if ( $include_timeline ) {
			$timeline = get_post_meta( $booking_id, '_event_timeline', true );
			if ( $timeline && is_array( $timeline ) ) {
				$message .= "\n**Event Timeline:**\n";
				foreach ( $timeline as $item ) {
					$message .= sprintf( "%s - %s\n", $item['time'], $item['activity'] );
				}
			}
		}

		$message .= __( "\nIf you have any questions, please don't hesitate to contact us.\n\nBest regards,\nYour DJ Service", 'mcp-ai-wpoos-pro' );

		// Send email.
		$sent = wp_mail( $client_email, $subject, $message );

		if ( $sent ) {
			update_post_meta( $booking_id, '_booking_status', 'confirmed' );
			update_post_meta( $booking_id, '_confirmation_sent', current_time( 'mysql' ) );

			return array(
				'success'    => true,
				'message'    => __( 'Confirmation email sent successfully.', 'mcp-ai-wpoos-pro' ),
				'sent_to'    => $client_email,
				'booking_id' => $booking_id,
			);
		} else {
			return array(
				'success' => false,
				'error'   => __( 'Failed to send confirmation email.', 'mcp-ai-wpoos-pro' ),
			);
		}
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
	public function get_flag_capabilities() {
		return array( 'write' );
	}
}
