<?php
/**
 * Cancel Appointment Tool
 *
 * Cancels appointments with automatic notifications to clients.
 * Supports cancellation reasons and refund processing.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Calendar_Booking_Toolkit
 * @since 2.6.0
 * @phase Phase 2.6 - Calendar Booking Toolkit
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for cancelling appointments.
 *
 * Features:
 * - Cancellation with reason tracking
 * - Automatic client notifications
 * - Refund integration support
 * - Cancellation policy enforcement
 * - Calendar sync updates
 *
 * @since 2.6.0
 */
class WP_MCP_AI_Tool_Cancel_Appointment implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if this tool is available.
	 *
	 * @since 2.6.0
	 *
	 * @return bool True if calendar booking toolkit is enabled.
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_calendar_booking_toolkit'] );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 2.6.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_calendar_booking_toolkit'] ) ) {
			return __( 'Calendar Booking toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Cancel appointment tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'cancel_appointment';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Cancel Appointment', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Cancel appointments with automatic notifications to clients. Supports cancellation reasons and refund processing.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'appointment_id'    => array(
					'type'        => 'integer',
					'description' => __( 'Appointment ID to cancel (required)', 'mcp-ai-wpoos-pro' ),
				),
				'reason'            => array(
					'type'        => 'string',
					'description' => __( 'Reason for cancellation', 'mcp-ai-wpoos-pro' ),
				),
				'cancelled_by'      => array(
					'type'        => 'string',
					'description' => __( 'Who initiated the cancellation (client, staff, system)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'client', 'staff', 'system' ),
					'default'     => 'staff',
				),
				'send_notification' => array(
					'type'        => 'boolean',
					'description' => __( 'Send cancellation notification to client', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'offer_reschedule'  => array(
					'type'        => 'boolean',
					'description' => __( 'Include reschedule link in notification', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'process_refund'    => array(
					'type'        => 'boolean',
					'description' => __( 'Automatically process refund if applicable', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'   => array( 'appointment_id' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-write',
			'phase-2.6',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check permissions.
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to cancel appointments.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'toolkit_not_available',
				self::get_unavailable_reason()
			);
		}

		// Validate appointment ID.
		if ( empty( $arguments['appointment_id'] ) ) {
			return new WP_Error(
				'missing_appointment_id',
				__( 'Appointment ID is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$appointment_id = absint( $arguments['appointment_id'] );
		$appointment    = get_post( $appointment_id );

		if ( ! $appointment || 'mcp_appointment' !== $appointment->post_type ) {
			return new WP_Error(
				'invalid_appointment',
				__( 'Invalid appointment ID.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check current status.
		$current_status = get_post_meta( $appointment_id, '_status', true );
		if ( 'cancelled' === $current_status ) {
			return new WP_Error(
				'already_cancelled',
				__( 'This appointment has already been cancelled.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Sanitize inputs.
		$reason       = ! empty( $arguments['reason'] ) ? sanitize_textarea_field( $arguments['reason'] ) : '';
		$cancelled_by = ! empty( $arguments['cancelled_by'] ) ? sanitize_text_field( $arguments['cancelled_by'] ) : 'staff';

		// Update appointment status.
		update_post_meta( $appointment_id, '_status', 'cancelled' );
		update_post_meta( $appointment_id, '_cancelled_at', current_time( 'mysql' ) );
		update_post_meta( $appointment_id, '_cancelled_by', $cancelled_by );
		update_post_meta( $appointment_id, '_cancelled_by_user_id', $current_user_id );
		update_post_meta( $appointment_id, '_cancellation_reason', $reason );

		// Get client details.
		$client_name  = get_post_meta( $appointment_id, '_client_name', true );
		$client_email = get_post_meta( $appointment_id, '_client_email', true );
		$start_time   = get_post_meta( $appointment_id, '_start_time', true );

		// Process refund if requested and applicable.
		$refund_processed = false;
		if ( ! empty( $arguments['process_refund'] ) ) {
			$refund_processed = $this->process_refund( $appointment_id );
		}

		// Send notification if requested.
		$notification_sent = false;
		if ( ! empty( $arguments['send_notification'] ) && $client_email ) {
			$offer_reschedule  = ! empty( $arguments['offer_reschedule'] );
			$notification_sent = $this->send_cancellation_email(
				$appointment_id,
				$client_email,
				$client_name,
				$start_time,
				$reason,
				$offer_reschedule
			);
		}

		// Log cancellation.
		$this->log_cancellation( $appointment_id, $current_user_id, $reason, $cancelled_by );

		return array(
			'success'           => true,
			'appointment_id'    => $appointment_id,
			'status'            => 'cancelled',
			'cancelled_at'      => current_time( 'mysql' ),
			'cancelled_by'      => $cancelled_by,
			'reason'            => $reason,
			'notification_sent' => $notification_sent,
			'refund_processed'  => $refund_processed,
			'message'           => __( 'Appointment cancelled successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Process refund for cancelled appointment.
	 *
	 * @param int $appointment_id Appointment ID.
	 * @return bool Whether refund was processed.
	 */
	private function process_refund( $appointment_id ) {
		// Check if appointment has associated payment.
		$payment_id = get_post_meta( $appointment_id, '_payment_id', true );
		if ( ! $payment_id ) {
			return false;
		}

		// Hook for payment gateway integration.
		$refund_result = apply_filters(
			'wp_mcp_ai_process_appointment_refund',
			false,
			$appointment_id,
			$payment_id
		);

		if ( $refund_result ) {
			update_post_meta( $appointment_id, '_refund_processed', 'yes' );
			update_post_meta( $appointment_id, '_refund_processed_at', current_time( 'mysql' ) );
		}

		return (bool) $refund_result;
	}

	/**
	 * Send cancellation email.
	 *
	 * @param int    $appointment_id  Appointment ID.
	 * @param string $email           Client email.
	 * @param string $name            Client name.
	 * @param string $start_time      Original start time.
	 * @param string $reason          Cancellation reason.
	 * @param bool   $offer_reschedule Whether to offer rescheduling.
	 * @return bool Whether email was sent successfully.
	 */
	private function send_cancellation_email( $appointment_id, $email, $name, $start_time, $reason, $offer_reschedule ) {
		$subject = sprintf(
			/* translators: %d: Appointment ID */
			__( 'Appointment Cancelled #%d', 'mcp-ai-wpoos-pro' ),
			$appointment_id
		);

		$message = sprintf(
			/* translators: 1: Client name, 2: Start time */
			__( "Hello %1\$s,\n\nYour appointment scheduled for %2\$s has been cancelled.\n\n", 'mcp-ai-wpoos-pro' ),
			$name,
			$start_time
		);

		if ( $reason ) {
			$message .= sprintf(
				/* translators: %s: Cancellation reason */
				__( "Reason: %s\n\n", 'mcp-ai-wpoos-pro' ),
				$reason
			);
		}

		if ( $offer_reschedule ) {
			$reschedule_link = home_url( '/book-appointment/' );
			$message        .= sprintf(
				/* translators: %s: Reschedule link */
				__( "You can reschedule your appointment here: %s\n\n", 'mcp-ai-wpoos-pro' ),
				$reschedule_link
			);
		}

		$message .= __( "We apologize for any inconvenience.\n\nThank you!", 'mcp-ai-wpoos-pro' );

		return wp_mail( $email, $subject, $message );
	}

	/**
	 * Log cancellation details.
	 *
	 * @param int    $appointment_id Appointment ID.
	 * @param int    $user_id        User who cancelled.
	 * @param string $reason         Cancellation reason.
	 * @param string $cancelled_by   Who cancelled (client/staff/system).
	 */
	private function log_cancellation( $appointment_id, $user_id, $reason, $cancelled_by ) {
		$log_entry = array(
			'action'       => 'cancelled',
			'timestamp'    => current_time( 'mysql' ),
			'user_id'      => $user_id,
			'cancelled_by' => $cancelled_by,
			'reason'       => $reason,
		);

		$activity_log = get_post_meta( $appointment_id, '_activity_log', true );
		if ( ! is_array( $activity_log ) ) {
			$activity_log = array();
		}

		$activity_log[] = $log_entry;
		update_post_meta( $appointment_id, '_activity_log', $activity_log );
	}
}
