<?php
/**
 * Tool for sending event confirmations.
 *
 * Allows AI assistants to send booking confirmation emails to clients.
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

		$subject = sprintf(
			/* translators: %s: event name */
			__( 'Booking Confirmation: %s', 'mcp-ai-wpoos-pro' ),
			$event_name
		);

		$timeline_rows = '';
		if ( $include_timeline ) {
			$timeline = get_post_meta( $booking_id, '_event_timeline', true );
			if ( $timeline && is_array( $timeline ) ) {
				foreach ( $timeline as $item ) {
					$timeline_rows .= '<tr><td style="padding:4px 10px;color:#555;">' . esc_html( $item['time'] ) . '</td>'
						. '<td style="padding:4px 10px;">' . esc_html( $item['activity'] ) . '</td></tr>';
				}
			}
		}

		$price_rows = '';
		if ( $total_price ) {
			$price_rows .= '<tr><td style="padding:6px 10px;font-weight:bold;">' . esc_html__( 'Total', 'mcp-ai-wpoos-pro' ) . '</td>'
				. '<td style="padding:6px 10px;">$' . esc_html( number_format( (float) $total_price, 2 ) ) . '</td></tr>';
			if ( $deposit ) {
				$price_rows .= '<tr><td style="padding:6px 10px;">' . esc_html__( 'Deposit', 'mcp-ai-wpoos-pro' ) . '</td>'
					. '<td style="padding:6px 10px;">$' . esc_html( number_format( (float) $deposit, 2 ) ) . '</td></tr>';
				$price_rows .= '<tr><td style="padding:6px 10px;">' . esc_html__( 'Balance Due', 'mcp-ai-wpoos-pro' ) . '</td>'
					. '<td style="padding:6px 10px;">$' . esc_html( number_format( (float) $total_price - (float) $deposit, 2 ) ) . '</td></tr>';
			}
		}

		// ── Try MJML responsive HTML email ────────────────────────────────────
		$html_body   = '';
		$send_method = 'wp_mail';

		$mjml_service = class_exists( 'WP_MCP_AI_MJML_Service' ) ? new WP_MCP_AI_MJML_Service() : null;
		if ( $mjml_service && $mjml_service->is_available() ) {
			$venue_line = $venue_address
				? esc_html( $venue_name ) . ', ' . esc_html( $venue_address )
				: esc_html( $venue_name );

			$mjml  = '<mjml><mj-head>';
			$mjml .= '<mj-attributes><mj-all font-family="Arial,sans-serif" /></mj-attributes>';
			$mjml .= '</mj-head><mj-body background-color="#f4f4f4">';

			// Header.
			$mjml .= '<mj-section background-color="#2271b1" padding="20px 24px">';
			$mjml .= '<mj-column><mj-text font-size="20px" color="#ffffff" font-weight="bold">';
			$mjml .= esc_html__( 'Booking Confirmed!', 'mcp-ai-wpoos-pro' );
			$mjml .= '</mj-text></mj-column></mj-section>';

			// Greeting.
			$mjml .= '<mj-section background-color="#ffffff" padding="20px 24px">';
			$mjml .= '<mj-column>';
			$mjml .= '<mj-text font-size="15px">';
			$mjml .= esc_html(
				sprintf(
				/* translators: %s: client name */
					__( 'Dear %s,', 'mcp-ai-wpoos-pro' ),
					$client_name
				)
			);
			$mjml .= '<br/><br/>';
			$mjml .= esc_html__( 'Thank you for booking our DJ services! We are excited to be part of your special event.', 'mcp-ai-wpoos-pro' );
			$mjml .= '</mj-text>';

			// Event details table.
			$mjml .= '<mj-table font-size="14px" cell-padding="6px 10px">';
			$mjml .= '<tr style="background:#f0f6fc"><td colspan="2" style="font-weight:bold;padding:8px 10px;">' . esc_html__( 'Event Details', 'mcp-ai-wpoos-pro' ) . '</td></tr>';
			$mjml .= '<tr><td style="color:#555;padding:6px 10px;">' . esc_html__( 'Event', 'mcp-ai-wpoos-pro' ) . '</td><td style="padding:6px 10px;">' . esc_html( $event_name ) . '</td></tr>';
			$mjml .= '<tr style="background:#f9f9f9"><td style="color:#555;padding:6px 10px;">' . esc_html__( 'Date', 'mcp-ai-wpoos-pro' ) . '</td><td style="padding:6px 10px;">' . esc_html( gmdate( 'F j, Y', strtotime( $event_date ) ) ) . '</td></tr>';
			$mjml .= '<tr><td style="color:#555;padding:6px 10px;">' . esc_html__( 'Time', 'mcp-ai-wpoos-pro' ) . '</td><td style="padding:6px 10px;">' . esc_html( $start_time . ' – ' . $end_time ) . '</td></tr>';
			$mjml .= '<tr style="background:#f9f9f9"><td style="color:#555;padding:6px 10px;">' . esc_html__( 'Venue', 'mcp-ai-wpoos-pro' ) . '</td><td style="padding:6px 10px;">' . esc_html( $venue_line ) . '</td></tr>';
			if ( $price_rows ) {
				$mjml .= $price_rows;
			}
			$mjml .= '</mj-table>';

			if ( $custom_message ) {
				$mjml .= '<mj-text font-size="14px" color="#555" padding-top="12px">' . esc_html( $custom_message ) . '</mj-text>';
			}

			if ( $timeline_rows ) {
				$mjml .= '<mj-table font-size="13px" cell-padding="4px 10px" padding-top="16px">';
				$mjml .= '<tr style="background:#f0f6fc"><td colspan="2" style="font-weight:bold;padding:8px 10px;">' . esc_html__( 'Event Timeline', 'mcp-ai-wpoos-pro' ) . '</td></tr>';
				$mjml .= $timeline_rows;
				$mjml .= '</mj-table>';
			}

			$mjml .= '<mj-text font-size="13px" color="#777" padding-top="16px">';
			$mjml .= esc_html__( "If you have any questions, please don't hesitate to contact us.", 'mcp-ai-wpoos-pro' );
			$mjml .= '</mj-text>';
			$mjml .= '</mj-column></mj-section>';
			$mjml .= '</mj-body></mjml>';

			$compiled = $mjml_service->compile( $mjml, array( 'minify' => true ) );
			if ( ! is_wp_error( $compiled ) ) {
				$html_body = $compiled;
			}
		}

		// ── Try Nodemailer for enhanced SMTP delivery ─────────────────────────
		$sent               = false;
		$nodemailer_service = class_exists( 'WP_MCP_AI_Nodemailer_Service' ) ? new WP_MCP_AI_Nodemailer_Service() : null;

		if ( $nodemailer_service && $nodemailer_service->is_available() && '' !== $html_body ) {
			$plain_text = sprintf(
				/* translators: %s: client name */
				__( "Dear %1\$s,\n\nThank you for booking our DJ services!\n\nEvent: %2\$s\nDate: %3\$s\nTime: %4\$s - %5\$s\nVenue: %6\$s\n", 'mcp-ai-wpoos-pro' ),
				$client_name,
				$event_name,
				gmdate( 'F j, Y', strtotime( $event_date ) ),
				$start_time,
				$end_time,
				$venue_name
			);
			$plain_text .= __( "\nIf you have any questions, please contact us.\n\nBest regards,\nYour DJ Service", 'mcp-ai-wpoos-pro' );

			$nm_result = $nodemailer_service->send_email(
				array(
					'to'      => $client_email,
					'subject' => $subject,
					'html'    => $html_body,
					'text'    => $plain_text,
				)
			);

			if ( ! is_wp_error( $nm_result ) && ! empty( $nm_result['success'] ) ) {
				$sent        = true;
				$send_method = 'nodemailer';
			}
		}

		// ── Fallback: wp_mail ─────────────────────────────────────────────────
		if ( ! $sent ) {
			if ( '' !== $html_body ) {
				// Send as HTML via wp_mail.
				add_filter(
					'wp_mail_content_type',
					static function () {
						return 'text/html';
					}
				);
				$sent = wp_mail( $client_email, $subject, $html_body );
				remove_filter(
					'wp_mail_content_type',
					static function () {
						return 'text/html';
					}
				);
			} else {
				// Plain-text fallback.
				$plain = sprintf(
					/* translators: %s: client name */
					__( "Dear %s,\n\nThank you for booking our DJ services! We are excited to be part of your special event.\n\n", 'mcp-ai-wpoos-pro' ),
					$client_name
				);
				$plain .= "Event: {$event_name}\n";
				$plain .= 'Date: ' . gmdate( 'F j, Y', strtotime( $event_date ) ) . "\n";
				$plain .= "Time: {$start_time} - {$end_time}\n";
				$plain .= "Venue: {$venue_name}\n";
				if ( $venue_address ) {
					$plain .= "Address: {$venue_address}\n";
				}
				if ( $total_price ) {
					$plain .= sprintf( "\nTotal: $%.2f\n", $total_price );
					if ( $deposit ) {
						$plain .= sprintf( "Deposit: $%.2f\n", $deposit );
						$plain .= sprintf( "Balance: $%.2f\n", (float) $total_price - (float) $deposit );
					}
				}
				if ( $custom_message ) {
					$plain .= "\n{$custom_message}\n";
				}
				if ( $include_timeline ) {
					$timeline = get_post_meta( $booking_id, '_event_timeline', true );
					if ( $timeline && is_array( $timeline ) ) {
						$plain .= "\nEvent Timeline:\n";
						foreach ( $timeline as $item ) {
							$plain .= "{$item['time']} - {$item['activity']}\n";
						}
					}
				}
				$plain .= __( "\nIf you have any questions, please don't hesitate to contact us.\n\nBest regards,\nYour DJ Service", 'mcp-ai-wpoos-pro' );
				$sent   = wp_mail( $client_email, $subject, $plain );
			}
		}

		if ( $sent ) {
			update_post_meta( $booking_id, '_booking_status', 'confirmed' );
			update_post_meta( $booking_id, '_confirmation_sent', current_time( 'mysql' ) );

			return array(
				'success'     => true,
				'message'     => __( 'Confirmation email sent successfully.', 'mcp-ai-wpoos-pro' ),
				'sent_to'     => $client_email,
				'booking_id'  => $booking_id,
				'send_method' => $send_method,
			);
		}

		return array(
			'success' => false,
			'error'   => __( 'Failed to send confirmation email.', 'mcp-ai-wpoos-pro' ),
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
	public function get_flag_capabilities() {
		return array( 'write' );
	}
}
