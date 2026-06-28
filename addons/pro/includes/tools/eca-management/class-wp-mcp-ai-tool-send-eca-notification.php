<?php
/**
 * Tool for sending ECA notifications.
 *
 * Sends email notifications to students and parents about ECA events.
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
 * Sends email notifications to students and parents about ECA events.
 */
class WP_MCP_AI_Tool_Send_ECA_Notification implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'send_eca_notification';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send ECA Notification', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends email notifications to students and parents about ECA events including enrollment confirmations, waitlist updates, schedule changes, cancellations, reminders, and payment notices.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'eca_id'            => array(
					'type'        => 'integer',
					'description' => __( 'WordPress post ID of the ECA (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'notification_type' => array(
					'type'        => 'string',
					'description' => __( 'Type of notification to send (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'enrollment_confirmed', 'waitlist_update', 'schedule_change', 'cancellation', 'reminder', 'payment_due' ),
				),
				'recipients'        => array(
					'type'        => 'string',
					'description' => __( 'Recipient group for the notification (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'all_enrolled', 'waitlisted', 'specific_students' ),
				),
				'student_ids'       => array(
					'type'        => 'array',
					'description' => __( 'Array of student post IDs when recipients is specific_students', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'custom_message'    => array(
					'type'        => 'string',
					'description' => __( 'Optional custom message to include in the notification', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 2000,
				),
				'include_parent'    => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to also send notification to parent email addresses', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'eca_id', 'notification_type', 'recipients' ),
			'additionalProperties' => false,
		);
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
			'toolkit'               => 'education',
			'post_type'             => 'mcp_ai_eca',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'educator', 'school_admin', 'activities_coordinator' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'email' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_eca_management'] );
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
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to send ECA notifications.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate ECA.
		$eca_id = isset( $arguments['eca_id'] ) ? absint( $arguments['eca_id'] ) : 0;
		if ( ! $eca_id ) {
			return new WP_Error(
				'wp_mcp_ai_missing_id',
				__( 'ECA ID is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$eca = get_post( $eca_id );
		if ( ! $eca || 'mcp_ai_eca' !== $eca->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_eca',
				__( 'Invalid ECA ID.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate notification type.
		$valid_types       = array( 'enrollment_confirmed', 'waitlist_update', 'schedule_change', 'cancellation', 'reminder', 'payment_due' );
		$notification_type = isset( $arguments['notification_type'] ) ? sanitize_key( $arguments['notification_type'] ) : '';
		if ( ! in_array( $notification_type, $valid_types, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_type',
				__( 'Invalid notification type.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate recipients group.
		$valid_recipients = array( 'all_enrolled', 'waitlisted', 'specific_students' );
		$recipients       = isset( $arguments['recipients'] ) ? sanitize_key( $arguments['recipients'] ) : '';
		if ( ! in_array( $recipients, $valid_recipients, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_recipients',
				__( 'Invalid recipients group.', 'mcp-ai-wpoos-pro' )
			);
		}

		$custom_message = isset( $arguments['custom_message'] ) ? sanitize_textarea_field( $arguments['custom_message'] ) : '';
		$include_parent = isset( $arguments['include_parent'] ) ? (bool) $arguments['include_parent'] : false;

		// Determine recipient student IDs.
		$student_ids = $this->resolve_recipients( $eca_id, $recipients, $arguments );
		if ( is_wp_error( $student_ids ) ) {
			return $student_ids;
		}

		if ( empty( $student_ids ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_recipients',
				__( 'No recipients found for the selected group.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Build email subject and body.
		$eca_name = sanitize_text_field( $eca->post_title );
		$subject  = $this->build_subject( $notification_type, $eca_name );
		$body     = $this->build_email_body( $notification_type, $eca, $custom_message );

		// Send emails.
		$sent_count   = 0;
		$failed_count = 0;

		foreach ( $student_ids as $student_id ) {
			$student_id = absint( $student_id );
			$student    = get_post( $student_id );
			if ( ! $student || 'mcp_ai_student' !== $student->post_type ) {
				++$failed_count;
				continue;
			}

			$student_email = sanitize_email( get_post_meta( $student_id, '_student_email', true ) );
			if ( $student_email && is_email( $student_email ) ) {
				$headers = array( 'Content-Type: text/html; charset=UTF-8' );
				if ( wp_mail( $student_email, $subject, $body, $headers ) ) {
					++$sent_count;
				} else {
					++$failed_count;
				}
			} else {
				++$failed_count;
			}

			// Send to parent if requested.
			if ( $include_parent ) {
				$parent_email = sanitize_email( get_post_meta( $student_id, '_student_parent_email', true ) );
				if ( $parent_email && is_email( $parent_email ) && $parent_email !== $student_email ) {
					$headers = array( 'Content-Type: text/html; charset=UTF-8' );
					if ( wp_mail( $parent_email, $subject, $body, $headers ) ) {
						++$sent_count;
					} else {
						++$failed_count;
					}
				}
			}
		}

		// Log notification.
		$notification_log = get_post_meta( $eca_id, '_eca_notification_log', true );
		if ( ! is_array( $notification_log ) ) {
			$notification_log = array();
		}
		$notification_log[] = array(
			'type'             => $notification_type,
			'recipients_count' => $sent_count,
			'sent_at'          => current_time( 'mysql' ),
			'sent_by'          => $current_user_id,
		);
		update_post_meta( $eca_id, '_eca_notification_log', $notification_log );

		return array(
			'success'           => true,
			'eca_id'            => $eca_id,
			'eca_name'          => $eca_name,
			'notification_type' => $notification_type,
			'sent_count'        => $sent_count,
			'failed_count'      => $failed_count,
			'message'           => sprintf(
				/* translators: 1: notification type, 2: ECA name, 3: sent count */
				__( '%1$s notification sent for %2$s to %3$d recipient(s).', 'mcp-ai-wpoos-pro' ),
				ucwords( str_replace( '_', ' ', $notification_type ) ),
				$eca_name,
				$sent_count
			),
		);
	}

	/**
	 * Resolve the list of student IDs based on recipient group.
	 *
	 * @param int    $eca_id     ECA post ID.
	 * @param string $recipients Recipient group key.
	 * @param array  $arguments  Original tool arguments.
	 * @return array|WP_Error Array of student IDs or error.
	 */
	private function resolve_recipients( $eca_id, $recipients, $arguments ) {
		$enrollments = get_post_meta( $eca_id, '_eca_student_enrollments', true );
		if ( ! is_array( $enrollments ) ) {
			$enrollments = array();
		}

		switch ( $recipients ) {
			case 'all_enrolled':
				$student_ids = array();
				foreach ( $enrollments as $student_id => $enrollment ) {
					if ( isset( $enrollment['enrollment_type'] ) && 'confirmed' === $enrollment['enrollment_type'] ) {
						$student_ids[] = absint( $student_id );
					}
				}
				return $student_ids;

			case 'waitlisted':
				$student_ids = array();
				foreach ( $enrollments as $student_id => $enrollment ) {
					if ( isset( $enrollment['enrollment_type'] ) && 'waitlist' === $enrollment['enrollment_type'] ) {
						$student_ids[] = absint( $student_id );
					}
				}
				return $student_ids;

			case 'specific_students':
				if ( ! isset( $arguments['student_ids'] ) || ! is_array( $arguments['student_ids'] ) || empty( $arguments['student_ids'] ) ) {
					return new WP_Error(
						'wp_mcp_ai_missing_student_ids',
						__( 'student_ids array is required when recipients is specific_students.', 'mcp-ai-wpoos-pro' )
					);
				}
				return array_map( 'absint', $arguments['student_ids'] );

			default:
				return new WP_Error(
					'wp_mcp_ai_invalid_recipients',
					__( 'Invalid recipients group.', 'mcp-ai-wpoos-pro' )
				);
		}
	}

	/**
	 * Build the email subject line based on notification type.
	 *
	 * @param string $notification_type Notification type key.
	 * @param string $eca_name          ECA display name.
	 * @return string Email subject line.
	 */
	private function build_subject( $notification_type, $eca_name ) {
		$subjects = array(
			'enrollment_confirmed' => sprintf(
				/* translators: %s: ECA name */
				__( 'ECA Enrollment Confirmed: %s', 'mcp-ai-wpoos-pro' ),
				$eca_name
			),
			'waitlist_update'      => sprintf(
				/* translators: %s: ECA name */
				__( 'ECA Waitlist Update: %s', 'mcp-ai-wpoos-pro' ),
				$eca_name
			),
			'schedule_change'      => sprintf(
				/* translators: %s: ECA name */
				__( 'ECA Schedule Change: %s', 'mcp-ai-wpoos-pro' ),
				$eca_name
			),
			'cancellation'         => sprintf(
				/* translators: %s: ECA name */
				__( 'ECA Cancellation Notice: %s', 'mcp-ai-wpoos-pro' ),
				$eca_name
			),
			'reminder'             => sprintf(
				/* translators: %s: ECA name */
				__( 'ECA Reminder: %s', 'mcp-ai-wpoos-pro' ),
				$eca_name
			),
			'payment_due'          => sprintf(
				/* translators: %s: ECA name */
				__( 'ECA Payment Due: %s', 'mcp-ai-wpoos-pro' ),
				$eca_name
			),
		);

		return isset( $subjects[ $notification_type ] ) ? $subjects[ $notification_type ] : $eca_name;
	}

	/**
	 * Build the HTML email body.
	 *
	 * @param string  $notification_type Notification type key.
	 * @param WP_Post $eca               ECA post object.
	 * @param string  $custom_message    Optional custom message.
	 * @return string HTML email body.
	 */
	private function build_email_body( $notification_type, $eca, $custom_message ) {
		$eca_name  = esc_html( $eca->post_title );
		$eca_day   = esc_html( get_post_meta( $eca->ID, '_eca_day', true ) );
		$eca_time  = esc_html( get_post_meta( $eca->ID, '_eca_time', true ) );
		$eca_venue = esc_html( get_post_meta( $eca->ID, '_eca_venue', true ) );

		$type_messages = array(
			'enrollment_confirmed' => __( 'Your enrollment has been confirmed.', 'mcp-ai-wpoos-pro' ),
			'waitlist_update'      => __( 'There has been an update to the waitlist status.', 'mcp-ai-wpoos-pro' ),
			'schedule_change'      => __( 'The schedule for this activity has changed. Please review the updated details below.', 'mcp-ai-wpoos-pro' ),
			'cancellation'         => __( 'This activity session has been cancelled. Please check for further updates.', 'mcp-ai-wpoos-pro' ),
			'reminder'             => __( 'This is a reminder about your upcoming activity session.', 'mcp-ai-wpoos-pro' ),
			'payment_due'          => __( 'A payment is due for this activity. Please arrange payment at your earliest convenience.', 'mcp-ai-wpoos-pro' ),
		);

		$type_content = isset( $type_messages[ $notification_type ] ) ? $type_messages[ $notification_type ] : '';

		$html  = '<html><body>';
		$html .= '<h2>' . $eca_name . '</h2>';
		$html .= '<p>' . esc_html( $type_content ) . '</p>';
		$html .= '<table style="border-collapse:collapse;width:100%;max-width:600px;">';
		if ( $eca_day ) {
			$html .= '<tr><td style="padding:4px 8px;font-weight:bold;">' . esc_html__( 'Day', 'mcp-ai-wpoos-pro' ) . '</td><td style="padding:4px 8px;">' . $eca_day . '</td></tr>';
		}
		if ( $eca_time ) {
			$html .= '<tr><td style="padding:4px 8px;font-weight:bold;">' . esc_html__( 'Time', 'mcp-ai-wpoos-pro' ) . '</td><td style="padding:4px 8px;">' . $eca_time . '</td></tr>';
		}
		if ( $eca_venue ) {
			$html .= '<tr><td style="padding:4px 8px;font-weight:bold;">' . esc_html__( 'Venue', 'mcp-ai-wpoos-pro' ) . '</td><td style="padding:4px 8px;">' . $eca_venue . '</td></tr>';
		}
		$html .= '</table>';

		if ( $custom_message ) {
			$html .= '<hr style="margin:16px 0;" />';
			$html .= '<p>' . nl2br( esc_html( $custom_message ) ) . '</p>';
		}

		$html .= '</body></html>';

		return $html;
	}
}
