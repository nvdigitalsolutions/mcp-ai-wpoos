<?php
/**
 * Outbound Appointment Booking — booking flow.
 *
 * Renders booking offers via the [nvoos_oa_booking] shortcode and turns
 * submissions into `mcp_appointment` records linked to the prospect's lead,
 * with confirmation email and Slack notification.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Outbound_Booking_Toolkit
 * @since 2.12.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Booking flow.
 *
 * @since 2.12.0
 */
class WP_MCP_AI_OA_Booking {

	/**
	 * Initialize.
	 *
	 * @since 2.12.0
	 */
	public static function init() {
		add_shortcode( 'nvoos_oa_booking', array( __CLASS__, 'render_shortcode' ) );
	}

	/**
	 * Render the booking shortcode.
	 *
	 * @since 2.12.0
	 * @param array $atts Attributes: link (slug).
	 * @return string Markup.
	 */
	public static function render_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'link' => '' ), $atts, 'nvoos_oa_booking' );
		$post = WP_MCP_AI_OA_Booking_Link_CPT::get_by_slug( sanitize_title( $atts['link'] ) );
		if ( ! $post ) {
			return '';
		}
		if ( '1' !== get_post_meta( $post->ID, '_oa_booking_active', true ) ) {
			return '';
		}

		$headline    = get_post_meta( $post->ID, '_oa_booking_headline', true );
		$value_props = get_post_meta( $post->ID, '_oa_booking_value_props', true );
		$call_title  = get_post_meta( $post->ID, '_oa_booking_call_title', true );
		$mode        = get_post_meta( $post->ID, '_oa_booking_mode', true );
		$calendar    = get_post_meta( $post->ID, '_oa_booking_calendar_url', true );
		$duration    = (int) get_post_meta( $post->ID, '_oa_booking_duration', true );
		if ( ! $duration ) {
			$duration = 30;
		}

		$props_html = '';
		$lines      = array_filter( array_map( 'trim', explode( "\n", (string) $value_props ) ) );
		if ( ! empty( $lines ) ) {
			$props_html .= '<ul class="oa-booking-props">';
			foreach ( $lines as $line ) {
				$props_html .= '<li>' . esc_html( $line ) . '</li>';
			}
			$props_html .= '</ul>';
		}

		$lead_token = '';
		if ( isset( $_GET['lead'], $_GET['t'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Attribution tokens, verified on submit.
			$lead_id = absint( $_GET['lead'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$token   = sanitize_text_field( wp_unslash( $_GET['t'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( WP_MCP_AI_OA_Booking_Link_CPT::verify_lead_token( $lead_id, $post->ID, $token ) ) {
				$lead_token = $token;
			}
		}

		// External mode: offer + CTA to the prospect's calendar URL.
		if ( 'internal' !== $mode ) {
			$url = $calendar;
			if ( $url && $lead_token ) {
				$url = add_query_arg( array( 'name' => rawurlencode( get_post_meta( absint( $_GET['lead'] ), 'first_name', true ) ) ), $url ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
			return '<div class="oa-booking">'
				. ( $headline ? '<h2 class="oa-booking-headline">' . esc_html( $headline ) . '</h2>' : '' )
				. $props_html
				. ( $url ? '<a class="oa-booking-cta" href="' . esc_url( $url ) . '">' . esc_html( sprintf(
					/* translators: %s: call title */
					__( 'Book my %s', 'mcp-ai-wpoos-pro' ),
					$call_title
				) ) . '</a>' : '<p>' . esc_html__( 'Booking is temporarily unavailable.', 'mcp-ai-wpoos-pro' ) . '</p>' )
				. '</div>';
		}

		// Internal mode: inline form that posts to the REST endpoint.
		wp_enqueue_style(
			'wp-mcp-ai-oa-booking',
			WP_MCP_AI_PRO_URL . 'assets/css/oa-booking.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
		wp_enqueue_script(
			'wp-mcp-ai-oa-booking',
			WP_MCP_AI_PRO_URL . 'assets/js/oa-booking.js',
			array(),
			WP_MCP_AI_PRO_VERSION,
			true
		);
		wp_add_inline_script(
			'wp-mcp-ai-oa-booking',
			'window.oaBooking = ' . wp_json_encode(
				array(
					'endpoint' => rest_url( 'nvoos-outbound/v1/bookings' ),
					'linkId'   => (int) $post->ID,
					'lead'     => $lead_token ? absint( $_GET['lead'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'token'    => $lead_token,
					'duration' => $duration,
				)
			) . ';',
			'before'
		);

		$form = '<div class="oa-booking" data-oa-booking>'
			. ( $headline ? '<h2 class="oa-booking-headline">' . esc_html( $headline ) . '</h2>' : '' )
			. $props_html
			. '<form class="oa-booking-form" data-oa-form>'
			. '<p class="oa-booking-field"><label>' . esc_html__( 'Name', 'mcp-ai-wpoos-pro' ) . ' <input type="text" name="name" required /></label></p>'
			. '<p class="oa-booking-field"><label>' . esc_html__( 'Email', 'mcp-ai-wpoos-pro' ) . ' <input type="email" name="email" required /></label></p>'
			. '<p class="oa-booking-field"><label>' . esc_html__( 'Company (optional)', 'mcp-ai-wpoos-pro' ) . ' <input type="text" name="company" /></label></p>'
			. '<p class="oa-booking-field"><label>' . esc_html__( 'Preferred time', 'mcp-ai-wpoos-pro' ) . ' <input type="datetime-local" name="slot" required /></label></p>'
			. '<p class="oa-booking-field"><label>' . esc_html__( 'Anything you want to cover? (optional)', 'mcp-ai-wpoos-pro' ) . ' <textarea name="message" rows="3"></textarea></label></p>'
			. '<p class="oa-booking-field oa-booking-hp" aria-hidden="true"><label>' . esc_html__( 'Leave this field empty', 'mcp-ai-wpoos-pro' ) . ' <input type="text" name="website" tabindex="-1" autocomplete="off" /></label></p>'
			. '<p class="oa-booking-consent"><label><input type="checkbox" name="consent" required /> '
			. esc_html__( 'I agree to be contacted about this call.', 'mcp-ai-wpoos-pro' )
			. '</label></p>'
			. '<p><button type="submit" class="oa-booking-submit">' . esc_html( sprintf(
				/* translators: %s: call title */
				__( 'Book my %s', 'mcp-ai-wpoos-pro' ),
				$call_title
			) ) . '</button></p>'
			. '<p class="oa-booking-status" data-oa-status></p>'
			. '</form>'
			. '</div>';
		return $form;
	}

	/**
	 * Create a booking: appointment + lead link + notifications.
	 *
	 * @since 2.12.0
	 * @param int    $booking_link_id Booking link post ID.
	 * @param string $name            Prospect name.
	 * @param string $email           Prospect email.
	 * @param string $company         Company (optional).
	 * @param string $slot            Requested slot (datetime-local value).
	 * @param int    $lead_id         Attributed lead ID (0 = anonymous).
	 * @param string $message         Optional note.
	 * @return array|WP_Error
	 */
	public static function create_booking( $booking_link_id, $name, $email, $company, $slot, $lead_id = 0, $message = '' ) {
		$booking_link_id = absint( $booking_link_id );
		$post            = get_post( $booking_link_id );
		if ( ! $post || WP_MCP_AI_OA_Booking_Link_CPT::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'invalid_link', __( 'Invalid booking link.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( '1' !== get_post_meta( $booking_link_id, '_oa_booking_active', true ) ) {
			return new WP_Error( 'inactive_link', __( 'This booking link is not active.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( 'internal' !== get_post_meta( $booking_link_id, '_oa_booking_mode', true ) ) {
			return new WP_Error( 'external_mode', __( 'This link uses an external calendar.', 'mcp-ai-wpoos-pro' ) );
		}

		$name    = sanitize_text_field( $name );
		$email   = sanitize_email( $email );
		$company = sanitize_text_field( $company );
		$message = sanitize_textarea_field( $message );
		if ( ! $name || ! is_email( $email ) ) {
			return new WP_Error( 'invalid_fields', __( 'Please provide a valid name and email.', 'mcp-ai-wpoos-pro' ) );
		}

		// Normalise the slot and require it to be in the future.
		$slot_ts = strtotime( $slot );
		if ( ! $slot_ts || $slot_ts < time() - HOUR_IN_SECONDS ) {
			return new WP_Error( 'invalid_slot', __( 'Please pick a time in the future.', 'mcp-ai-wpoos-pro' ) );
		}
		$start = date_i18n( 'Y-m-d H:i', $slot_ts );
		$duration = (int) get_post_meta( $booking_link_id, '_oa_booking_duration', true );
		if ( ! $duration ) {
			$duration = 30;
		}
		$end = date_i18n( 'Y-m-d H:i', $slot_ts + ( $duration * MINUTE_IN_SECONDS ) );

		if ( ! post_type_exists( 'mcp_appointment' ) ) {
			return new WP_Error( 'calendar_disabled', __( 'The Calendar Booking toolkit is disabled — enable it or switch this link to an external calendar.', 'mcp-ai-wpoos-pro' ) );
		}
		$appointment_post_type = 'mcp_appointment';

		// Idempotency: the same email re-booking the same link within a day returns the existing appointment.
		$existing = get_posts(
			array(
				'post_type'      => $appointment_post_type,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => '_client_email', 'value' => $email, 'compare' => '=' ),
					array( 'key' => '_oa_booking_link_id', 'value' => $booking_link_id, 'compare' => '=' ),
				),
				'date_query'     => array( 'after' => '1 day ago' ),
			)
		);
		if ( ! empty( $existing ) ) {
			return array(
				'success'       => true,
				'duplicate'     => true,
				'appointment_id' => (int) $existing[0],
				'lead_id'       => (int) get_post_meta( $existing[0], '_oa_lead_id', true ),
			);
		}

		// Find or create the lead.
		$lead_id = self::find_or_create_lead( $name, $email, $company, $lead_id );
		if ( is_wp_error( $lead_id ) ) {
			return $lead_id;
		}

		$call_title = get_post_meta( $booking_link_id, '_oa_booking_call_title', true );
		if ( ! $call_title ) {
			$call_title = __( 'Strategy Call', 'mcp-ai-wpoos-pro' );
		}

		$appointment_id = wp_insert_post(
			array(
				'post_type'    => $appointment_post_type,
				'post_title'   => sprintf(
					/* translators: 1: name, 2: call title */
					__( '%1$s — %2$s', 'mcp-ai-wpoos-pro' ),
					$name,
					$call_title
				),
				'post_status'  => 'publish',
				'post_content' => $message,
			),
			true
		);
		if ( is_wp_error( $appointment_id ) ) {
			return $appointment_id;
		}

		update_post_meta( $appointment_id, '_client_name', $name );
		update_post_meta( $appointment_id, '_client_email', $email );
		update_post_meta( $appointment_id, '_client_company', $company );
		update_post_meta( $appointment_id, '_appointment_type', $call_title );
		update_post_meta( $appointment_id, '_start_time', $start );
		update_post_meta( $appointment_id, '_end_time', $end );
		update_post_meta( $appointment_id, '_status', 'pending' );
		update_post_meta( $appointment_id, '_oa_booking_link_id', $booking_link_id );
		update_post_meta( $appointment_id, '_oa_lead_id', $lead_id );

		// Mark the lead as booked.
		update_post_meta( $lead_id, '_oa_status', 'booked' );
		update_post_meta( $lead_id, '_oa_booked_at', current_time( 'mysql', true ) );
		update_post_meta( $lead_id, '_oa_booking_link_id', $booking_link_id );
		update_post_meta( $lead_id, '_oa_appointment_id', $appointment_id );
		update_post_meta( $lead_id, '_sequence_paused', '1' );

		WP_MCP_AI_OA_Booking_Link_CPT::record_booking( $booking_link_id );

		// Credit the last angle that touched this lead with a booking.
		$angle_id = (int) get_post_meta( $lead_id, '_oa_last_angle_id', true );
		if ( $angle_id ) {
			WP_MCP_AI_OA_Angle_CPT::record_event( $angle_id, 'booking' );
		}

		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record( 'outbound_booking_created', 'appointment', $appointment_id, array( 'lead_id' => $lead_id ) );
		}

		// Confirmation email.
		self::send_confirmation( $booking_link_id, $lead_id, $appointment_id, $start );

		WP_MCP_AI_OA_Notifications::notify_booked( $appointment_id, $lead_id, $booking_link_id );

		/**
		 * Fires after a booking has been created.
		 *
		 * @since 2.12.0
		 * @param int $appointment_id  Appointment post ID.
		 * @param int $lead_id         Lead post ID.
		 * @param int $booking_link_id Booking link post ID.
		 */
		do_action( 'wp_mcp_ai_oa_after_booking', $appointment_id, $lead_id, $booking_link_id );

		return array(
			'success'        => true,
			'appointment_id' => $appointment_id,
			'lead_id'        => $lead_id,
			'slot'           => $start,
			'confirmation'   => get_post_meta( $booking_link_id, '_oa_booking_confirmation', true ),
		);
	}

	/**
	 * Find a lead by email or create one.
	 *
	 * @since 2.12.0
	 * @param string $name    Prospect name.
	 * @param string $email   Prospect email.
	 * @param string $company Company name.
	 * @param int    $lead_id Attributed lead ID (0 = anonymous).
	 * @return int|WP_Error Lead post ID or error.
	 */
	private static function find_or_create_lead( $name, $email, $company, $lead_id = 0 ) {
		$lead_id = absint( $lead_id );
		if ( $lead_id ) {
			$attributed = get_post( $lead_id );
			if ( $attributed && 'mcp_ai_lead' === $attributed->post_type ) {
				return $lead_id;
			}
		}

		if ( post_type_exists( 'mcp_ai_lead' ) ) {
			$existing = get_posts(
				array(
					'post_type'      => 'mcp_ai_lead',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => 'email', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded email-matching lookup.
					'meta_value'     => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded email-matching lookup.
				)
			);
			if ( ! empty( $existing ) ) {
				return (int) $existing[0];
			}

			$parts     = explode( ' ', $name, 2 );
			$lead_id   = wp_insert_post(
				array(
					'post_type'   => 'mcp_ai_lead',
					'post_title'  => $name,
					'post_status' => 'publish',
				),
				true
			);
			if ( is_wp_error( $lead_id ) ) {
				return $lead_id;
			}
			update_post_meta( $lead_id, 'email', $email );
			update_post_meta( $lead_id, 'first_name', trim( $parts[0] ) );
			update_post_meta( $lead_id, 'last_name', isset( $parts[1] ) ? trim( $parts[1] ) : '' );
			if ( $company ) {
				update_post_meta( $lead_id, 'company_name', $company );
				update_post_meta( $lead_id, 'company', $company );
			}
			update_post_meta( $lead_id, 'source', 'cold_outreach' );
			update_post_meta( $lead_id, 'lead_status', 'new' );
			update_post_meta( $lead_id, 'lifecycle_stage', 'sql' );
			update_post_meta( $lead_id, '_oa_email_consent', '1' );
			return $lead_id;
		}

		return new WP_Error( 'no_lead_cpt', __( 'CRM toolkit is disabled — cannot create lead records.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Send the prospect a confirmation email.
	 *
	 * @since 2.12.0
	 * @param int    $booking_link_id Booking link post ID.
	 * @param int    $lead_id         Lead post ID.
	 * @param int    $appointment_id  Appointment post ID.
	 * @param string $start           Start time string.
	 * @return void
	 */
	private static function send_confirmation( $booking_link_id, $lead_id, $appointment_id, $start ) {
		if ( 'off' === WP_MCP_AI_OA_Settings::get_setting( 'email_mode', 'approval' ) ) {
			return;
		}
		$to      = get_post_meta( $lead_id, 'email', true );
		$subject = get_post_meta( $booking_link_id, '_oa_booking_call_title', true );
		$body    = get_post_meta( $booking_link_id, '_oa_booking_confirmation', true );
		if ( ! $body ) {
			/* translators: %s: booked slot */
			$body = __( "Thanks for booking! Your call is scheduled for %s. We'll send a calendar invite shortly.", 'mcp-ai-wpoos-pro' );
		}
		$body = sprintf( $body, $start );
		if ( $to ) {
			wp_mail( $to, $subject ? $subject : __( 'Your call is booked', 'mcp-ai-wpoos-pro' ), wp_kses_post( $body ), array( 'Content-Type: text/html; charset=UTF-8' ) );
		}
	}
}
