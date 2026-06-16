<?php
/**
 * Tool for sending reschedule invitations to no-show clients.
 *
 * Sends reschedule invitations to clients who missed appointments (no-shows),
 * with support for custom messages and dry-run mode.
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
 * Sends reschedule invitations to clients who missed appointments (no-shows).
 *
 * Supports email and SMS delivery methods, optional custom messages,
 * and dry-run mode for previewing outreach before sending.
 *
 * @since 2.9.0
 */
class WP_MCP_AI_Tool_Send_Reschedule_Invitation implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'send_reschedule_invitation';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send Reschedule Invitation', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends reschedule invitations to clients who missed appointments (no-shows). Supports email and SMS delivery, custom messages, and dry_run mode for previewing outreach before sending.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'appointment_ids' => array(
					'type'        => 'array',
					'description' => __( 'Array of no-show appointment IDs to send reschedule invitations for.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'minItems'    => 1,
				),
				'message'         => array(
					'type'        => 'string',
					'description' => __( 'Optional custom message to include in the reschedule invitation.', 'mcp-ai-wpoos-pro' ),
				),
				'method'          => array(
					'type'        => 'string',
					'description' => __( 'Delivery method for the invitation.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'email', 'sms' ),
					'default'     => 'email',
				),
				'dry_run'         => array(
					'type'        => 'boolean',
					'description' => __( 'If true, preview what would be sent without actually sending. Default: true.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'appointment_ids' ),
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
		return __( 'The Send Reschedule Invitation tool requires the Calendar Booking toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to send reschedule invitations.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! self::is_available() ) {
			return new WP_Error( 'toolkit_not_available', self::get_unavailable_reason() );
		}

		// Parse arguments.
		$appointment_ids = isset( $arguments['appointment_ids'] ) ? array_map( 'absint', (array) $arguments['appointment_ids'] ) : array();
		$custom_message  = isset( $arguments['message'] ) ? sanitize_textarea_field( $arguments['message'] ) : '';
		$method          = isset( $arguments['method'] ) ? sanitize_text_field( $arguments['method'] ) : 'email';
		$dry_run         = isset( $arguments['dry_run'] ) ? (bool) $arguments['dry_run'] : true;

		if ( empty( $appointment_ids ) ) {
			return new WP_Error( 'missing_appointment_ids', __( 'At least one appointment ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$results = array(
			'success'    => true,
			'dry_run'    => $dry_run,
			'method'     => $method,
			'total'      => count( $appointment_ids ),
			'processed'  => 0,
			'skipped'    => 0,
			'failed'     => 0,
			'recipients' => array(),
			'errors'     => array(),
		);

		foreach ( $appointment_ids as $appointment_id ) {
			$appointment = get_post( $appointment_id );

			if ( ! $appointment || 'mcp_appointment' !== $appointment->post_type ) {
				$results['skipped']++;
				$results['errors'][] = array(
					'appointment_id' => $appointment_id,
					'error'          => __( 'Invalid appointment.', 'mcp-ai-wpoos-pro' ),
				);
				continue;
			}

			// Verify this is a no-show appointment.
			$status = get_post_meta( $appointment_id, '_appointment_status', true );
			if ( 'no_show' !== $status ) {
				$results['skipped']++;
				$results['errors'][] = array(
					'appointment_id' => $appointment_id,
					'error'          => sprintf(
						/* translators: %s: current appointment status */
						__( 'Appointment is not marked as no-show (current status: %s).', 'mcp-ai-wpoos-pro' ),
						$status ?: 'unknown'
					),
				);
				continue;
			}

			$client_email = get_post_meta( $appointment_id, '_client_email', true );
			$client_name  = get_post_meta( $appointment_id, '_client_name', true );
			$client_phone = get_post_meta( $appointment_id, '_client_phone', true );
			$start_time   = get_post_meta( $appointment_id, '_start_time', true );
			$service_type = get_post_meta( $appointment_id, '_service_type', true );

			$recipient = array(
				'appointment_id' => $appointment_id,
				'client_name'    => $client_name ?: '',
				'client_email'   => $client_email ?: '',
				'client_phone'   => $client_phone ?: '',
				'service_type'   => $service_type ?: '',
				'missed_time'    => $start_time ?: '',
				'method'         => $method,
				'sent'           => false,
			);

			if ( $dry_run ) {
				$results['recipients'][] = $recipient;
				$results['processed']++;
				continue;
			}

			$sent = false;

			if ( 'email' === $method && ! empty( $client_email ) ) {
				/* translators: %1$s: client name, %2$s: missed appointment time */
				$subject = sprintf( __( 'We Missed You — Reschedule Your Appointment', 'mcp-ai-wpoos-pro' ), $client_name );
				/* translators: %1$s: client name, %2$s: missed appointment time */
				$message = sprintf(
					__( "Hello %1\$s,\n\nWe noticed you missed your appointment on %2\$s. We'd love to help you reschedule at a more convenient time.\n\n", 'mcp-ai-wpoos-pro' ),
					$client_name,
					$start_time
				);

				if ( ! empty( $custom_message ) ) {
					$message .= $custom_message . "\n\n";
				}

				$message .= __( "Please contact us or visit our booking page to reschedule.\n\nThank you!", 'mcp-ai-wpoos-pro' );

				$sent = wp_mail( $client_email, $subject, $message );
				if ( $sent ) {
					update_post_meta( $appointment_id, '_reschedule_invitation_sent_at', current_time( 'mysql' ) );
				}
			} elseif ( 'sms' === $method && ! empty( $client_phone ) ) {
				$sms_message = sprintf(
					/* translators: %1$s: client name, %2$s: missed appointment time */
					__( 'Hi %1$s, we missed you at your appointment on %2$s. Please contact us to reschedule.', 'mcp-ai-wpoos-pro' ),
					$client_name,
					$start_time
				);

				if ( ! empty( $custom_message ) ) {
					$sms_message .= ' ' . $custom_message;
				}

				/**
				 * Filter: wp_mcp_ai_send_reschedule_sms
				 *
				 * Allows integration with SMS gateways for reschedule invitations.
				 * Return true if SMS was sent successfully.
				 *
				 * @since 2.9.0
				 *
				 * @param bool   $sent           Whether SMS was sent (default false).
				 * @param int    $appointment_id The appointment ID.
				 * @param string $client_phone   The client's phone number.
				 * @param string $message        The SMS message body.
				 */
				$sent = apply_filters(
					'wp_mcp_ai_send_reschedule_sms',
					false,
					$appointment_id,
					$client_phone,
					$sms_message
				);
			}

			$recipient['sent']       = $sent;
			$results['recipients'][] = $recipient;

			if ( $sent ) {
				$results['processed']++;
			} else {
				$results['failed']++;
				$results['errors'][] = array(
					'appointment_id' => $appointment_id,
					'error'          => __( 'Failed to send reschedule invitation.', 'mcp-ai-wpoos-pro' ),
				);
			}
		}

		if ( $dry_run ) {
			$results['message'] = sprintf(
				/* translators: %d: number of invitations previewed */
				__( 'Dry run completed. %d reschedule invitations previewed.', 'mcp-ai-wpoos-pro' ),
				$results['processed']
			);
		} else {
			$results['message'] = sprintf(
				/* translators: 1: number processed, 2: number failed, 3: number skipped */
				__( 'Reschedule invitations sent: %1$d processed, %2$d failed, %3$d skipped.', 'mcp-ai-wpoos-pro' ),
				$results['processed'],
				$results['failed'],
				$results['skipped']
			);
		}

		return $results;
	}
}
