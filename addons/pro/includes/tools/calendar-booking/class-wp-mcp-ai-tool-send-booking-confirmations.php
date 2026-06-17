<?php
/**
 * Tool for sending booking confirmations in bulk.
 *
 * Sends confirmation emails/notifications for specified bookings,
 * with support for dry-run mode to preview without sending.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Calendar_Booking_Toolkit
 * @since 2.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends confirmation emails/notifications for specified bookings.
 *
 * Supports email, SMS, or both delivery methods. Dry-run mode
 * allows previewing recipients and messages without actually
 * sending anything. Useful for batch-confirming pending bookings.
 *
 * @since 2.9.0
 */
class WP_MCP_AI_Tool_Send_Booking_Confirmations implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'send_booking_confirmations';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send Booking Confirmations', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends confirmation emails/notifications for specified bookings. Supports email, SMS, or both delivery methods. Use dry_run mode to preview without sending.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'booking_ids' => array(
					'type'        => 'array',
					'description' => __( 'Array of booking (appointment) IDs to send confirmations for.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'minItems'    => 1,
				),
				'method'      => array(
					'type'        => 'string',
					'description' => __( 'Delivery method for confirmations.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'email', 'sms', 'both' ),
					'default'     => 'email',
				),
				'dry_run'     => array(
					'type'        => 'boolean',
					'description' => __( 'If true, preview what would be sent without actually sending. Default: true.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'booking_ids' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
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
			'toolkit'               => 'calendar_booking',
			'post_type'             => 'mcp_appointment',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'administrator', 'coordinator', 'receptionist' ),
			'risk_level'            => 'moderate',
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
			'email',
			'requires-capability',
			'phase-2.9',
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * Requires the Calendar Booking toolkit to be enabled in plugin settings.
	 *
	 * @since 2.9.0
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_calendar_booking_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.9.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Send Booking Confirmations tool requires the Calendar Booking toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to send booking confirmations.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! self::is_available() ) {
			return new WP_Error( 'toolkit_not_available', self::get_unavailable_reason() );
		}

		// Parse arguments.
		$booking_ids = isset( $arguments['booking_ids'] ) ? array_map( 'absint', (array) $arguments['booking_ids'] ) : array();
		$method      = isset( $arguments['method'] ) ? sanitize_text_field( $arguments['method'] ) : 'email';
		$dry_run     = isset( $arguments['dry_run'] ) ? (bool) $arguments['dry_run'] : true;

		if ( empty( $booking_ids ) ) {
			return new WP_Error( 'missing_booking_ids', __( 'At least one booking ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$results = array(
			'success'    => true,
			'dry_run'    => $dry_run,
			'method'     => $method,
			'total'      => count( $booking_ids ),
			'processed'  => 0,
			'skipped'    => 0,
			'failed'     => 0,
			'recipients' => array(),
			'errors'     => array(),
		);

		foreach ( $booking_ids as $booking_id ) {
			$appointment = get_post( $booking_id );

			if ( ! $appointment || 'mcp_appointment' !== $appointment->post_type ) {
				++$results['skipped'];
				$results['errors'][] = array(
					'booking_id' => $booking_id,
					'error'      => __( 'Invalid appointment.', 'mcp-ai-wpoos-pro' ),
				);
				continue;
			}

			$client_email = get_post_meta( $booking_id, '_client_email', true );
			$client_name  = get_post_meta( $booking_id, '_client_name', true );
			$client_phone = get_post_meta( $booking_id, '_client_phone', true );
			$start_time   = get_post_meta( $booking_id, '_start_time', true );
			$end_time     = get_post_meta( $booking_id, '_end_time', true );
			$service_type = get_post_meta( $booking_id, '_service_type', true );

			$recipient = array(
				'booking_id'   => $booking_id,
				'client_name'  => $client_name ? $client_name : '',
				'client_email' => $client_email ? $client_email : '',
				'client_phone' => $client_phone ? $client_phone : '',
				'service_type' => $service_type ? $service_type : '',
				'start_time'   => $start_time ? $start_time : '',
				'end_time'     => $end_time ? $end_time : '',
				'methods_used' => array(),
				'sent'         => false,
			);

			if ( $dry_run ) {
				$recipient['methods_used'][] = 'dry_run_preview';
				$results['recipients'][]     = $recipient;
				++$results['processed'];
				continue;
			}

			$email_sent = false;
			$sms_sent   = false;

			// Send email confirmation.
			if ( in_array( $method, array( 'email', 'both' ), true ) && ! empty( $client_email ) ) {
				/* translators: %d: booking ID */
				$subject = sprintf( __( 'Appointment Confirmation #%d', 'mcp-ai-wpoos-pro' ), $booking_id );
				/* translators: %1$s: client name, %2$s: start time, %3$s: end time */
				$message = sprintf(
					__( "Hello %1\$s,\n\nYour appointment is confirmed.\nTime: %2\$s to %3\$s\n\nThank you for booking with us!", 'mcp-ai-wpoos-pro' ),
					$client_name,
					$start_time,
					$end_time
				);

				$email_sent = wp_mail( $client_email, $subject, $message );
				if ( $email_sent ) {
					$recipient['methods_used'][] = 'email';
					update_post_meta( $booking_id, '_confirmation_sent_at', current_time( 'mysql' ) );
					update_post_meta( $booking_id, '_confirmation_method', 'email' );
				}
			}

			// Send SMS confirmation (placeholder — requires SMS gateway integration).
			if ( in_array( $method, array( 'sms', 'both' ), true ) && ! empty( $client_phone ) ) {
				/**
				 * Filter: wp_mcp_ai_send_booking_sms_confirmation
				 *
				 * Allows integration with SMS gateways for booking confirmations.
				 * Return true if SMS was sent successfully.
				 *
				 * @since 2.9.0
				 *
				 * @param bool   $sent         Whether SMS was sent (default false).
				 * @param int    $booking_id   The appointment/booking ID.
				 * @param string $client_phone The client's phone number.
				 * @param string $message      The SMS message body.
				 */
				$sms_sent = apply_filters(
					'wp_mcp_ai_send_booking_sms_confirmation',
					false,
					$booking_id,
					$client_phone,
					sprintf(
						/* translators: %1$s: client name, %2$s: start time */
						__( 'Hi %1$s, your appointment is confirmed for %2$s.', 'mcp-ai-wpoos-pro' ),
						$client_name,
						$start_time
					)
				);
				if ( $sms_sent ) {
					$recipient['methods_used'][] = 'sms';
				}
			}

			$recipient['sent']       = $email_sent || $sms_sent;
			$results['recipients'][] = $recipient;

			if ( $recipient['sent'] ) {
				++$results['processed'];
			} else {
				++$results['failed'];
				if ( empty( $recipient['methods_used'] ) ) {
					$results['errors'][] = array(
						'booking_id' => $booking_id,
						'error'      => __( 'No delivery method succeeded.', 'mcp-ai-wpoos-pro' ),
					);
				}
			}
		}

		if ( $dry_run ) {
			$results['message'] = sprintf(
				/* translators: %d: number of bookings previewed */
				__( 'Dry run completed. %d bookings previewed.', 'mcp-ai-wpoos-pro' ),
				$results['processed']
			);
		} else {
			$results['message'] = sprintf(
				/* translators: 1: number processed, 2: number failed, 3: number skipped */
				__( 'Confirmations sent: %1$d processed, %2$d failed, %3$d skipped.', 'mcp-ai-wpoos-pro' ),
				$results['processed'],
				$results['failed'],
				$results['skipped']
			);
		}

		return $results;
	}
}
