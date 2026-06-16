<?php
/**
 * Tool: send_appointment_followup
 *
 * Sends follow-up messages to patients/members after appointments.
 * Supports dry_run mode for preview without sending.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Send appointment follow-up tool.
 */
class WP_MCP_AI_Tool_Send_Appointment_Followup implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Whether the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_health_wellness_management'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Send Appointment Follow-up tool requires the Health & Wellness Management toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'send_appointment_followup';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send Appointment Follow-up', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends follow-up messages to patients/members after appointments. Supports dry_run mode for preview without sending.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'appointment_ids'  => array(
					'type'        => 'array',
					'description' => __( 'Array of appointment (checkup) post IDs to send follow-ups for.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'minItems'    => 1,
				),
				'message_template' => array(
					'type'        => 'string',
					'description' => __( 'Custom message template. Use {{name}}, {{appointment_date}}, {{appointment_type}} as placeholders.', 'mcp-ai-wpoos-pro' ),
				),
				'method'           => array(
					'type'        => 'string',
					'description' => __( 'Delivery method: email or sms.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'email', 'sms' ),
					'default'     => 'email',
				),
				'dry_run'          => array(
					'type'        => 'boolean',
					'description' => __( 'If true, preview what would be sent without actually sending. Default: true for safety.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'appointment_ids' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'write',
			'requires-capability',
			'pii-data',
			'idempotent',
			'local-only',
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
			'toolkit'               => 'health_wellness_management',
			'pattern_compatibility' => array( 'orchestrator', 'sequential', 'standalone' ),
			'profession_tags'       => array( 'healthcare', 'administrator' ),
			'risk_level'            => 'action',
		);
	}

	/**
	 * Get the default follow-up template for email.
	 *
	 * @return string
	 */
	private function get_default_email_template() {
		return __( "Dear {{name}},\n\nThank you for your recent {{appointment_type}} appointment on {{appointment_date}}. We hope you are feeling well.\n\nIf you have any questions or concerns, please don't hesitate to contact us.\n\nBest regards,\n{{site_name}}", 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the default follow-up template for SMS.
	 *
	 * @return string
	 */
	private function get_default_sms_template() {
		return __( "Hi {{name}}, thanks for your {{appointment_type}} visit on {{appointment_date}}. Contact us with any questions. - {{site_name}}", 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Follow-up results.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Parse arguments with sanitization.
		$appointment_ids  = isset( $arguments['appointment_ids'] ) ? (array) $arguments['appointment_ids'] : array();
		$message_template = isset( $arguments['message_template'] ) ? sanitize_textarea_field( $arguments['message_template'] ) : '';
		$method           = isset( $arguments['method'] ) ? sanitize_text_field( $arguments['method'] ) : 'email';
		$dry_run          = isset( $arguments['dry_run'] ) ? (bool) $arguments['dry_run'] : true;

		// Validate method.
		if ( ! in_array( $method, array( 'email', 'sms' ), true ) ) {
			$method = 'email';
		}

		// Sanitize appointment IDs.
		$appointment_ids = array_map( 'absint', $appointment_ids );
		$appointment_ids = array_filter( $appointment_ids, function ( $id ) {
			return $id > 0;
		} );

		if ( empty( $appointment_ids ) ) {
			return array(
				'success' => false,
				'error'   => __( 'No valid appointment IDs provided.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Set default template if none provided.
		if ( '' === $message_template ) {
			$message_template = 'email' === $method
				? $this->get_default_email_template()
				: $this->get_default_sms_template();
		}

		$site_name = get_bloginfo( 'name' );

		$results    = array();
		$sent_count = 0;
		$errors     = array();

		foreach ( $appointment_ids as $appointment_id ) {
			$post = get_post( $appointment_id );

			if ( ! $post || 'mcp_ai_checkup' !== $post->post_type ) {
				$errors[] = array(
					'id'    => $appointment_id,
					'error' => __( 'Appointment not found or invalid post type.', 'mcp-ai-wpoos-pro' ),
				);
				continue;
			}

			// Get member info.
			$member_id = get_post_meta( $appointment_id, '_member_id', true );
			$member_name = '';
			$member_email = '';
			$member_phone = '';

			if ( $member_id ) {
				$member_post = get_post( absint( $member_id ) );
				$member_name = $member_post ? $member_post->post_title : '';
				$member_email = get_post_meta( absint( $member_id ), '_email', true );
				$member_phone = get_post_meta( absint( $member_id ), '_phone', true );
			}

			// Get appointment details.
			$appointment_date = get_post_meta( $appointment_id, '_appointment_date', true );
			$appointment_type = get_post_meta( $appointment_id, '_appointment_type', true );

			// Build message with replacements.
			$replacements = array(
				'{{name}}'             => $member_name,
				'{{appointment_date}}' => $appointment_date,
				'{{appointment_type}}' => $appointment_type,
				'{{site_name}}'        => $site_name,
			);
			$message_body = str_replace( array_keys( $replacements ), array_values( $replacements ), $message_template );

			$entry = array(
				'id'               => $appointment_id,
				'member_name'      => esc_html( $member_name ),
				'appointment_type' => esc_html( $appointment_type ),
				'appointment_date' => esc_html( $appointment_date ),
				'method'           => $method,
				'dry_run'          => $dry_run,
			);

			if ( $dry_run ) {
				// Preview mode - log what would be sent.
				$entry['status']  = 'preview';
				$entry['message'] = $message_body;
				if ( 'email' === $method ) {
					$entry['recipient'] = $member_email ? esc_html( $member_email ) : __( '(no email on file)', 'mcp-ai-wpoos-pro' );
				} else {
					$entry['recipient'] = $member_phone ? esc_html( $member_phone ) : __( '(no phone on file)', 'mcp-ai-wpoos-pro' );
				}
				$sent_count++;
			} else {
				// Actually send the follow-up.
				$send_result = $this->send_followup( $member_email, $member_phone, $message_body, $method );
				if ( is_wp_error( $send_result ) ) {
					$entry['status'] = 'error';
					$entry['error']  = $send_result->get_error_message();
					$errors[]        = $entry;
				} else {
					$entry['status'] = 'sent';
					// Mark follow-up as sent on the appointment.
					update_post_meta( $appointment_id, '_followup_sent', current_time( 'mysql' ) );
					update_post_meta( $appointment_id, '_followup_method', $method );
					$sent_count++;

					/**
					 * Fires after a follow-up message is sent for an appointment.
					 *
					 * @since 1.6.0
					 *
					 * @param int    $appointment_id The appointment post ID.
					 * @param string $method         The delivery method (email or sms).
					 * @param string $message_body   The message that was sent.
					 */
					do_action( 'wp_mcp_ai_healthcare_after_followup_sent', $appointment_id, $method, $message_body );
				}
			}

			$results[] = $entry;
		}

		$response = array(
			'success'      => true,
			'message'      => $dry_run
				? sprintf(
					/* translators: %d: number of appointments previewed */
					__( 'Previewed follow-ups for %d appointment(s). No messages were actually sent.', 'mcp-ai-wpoos-pro' ),
					$sent_count
				)
				: sprintf(
					/* translators: %d: number of follow-ups sent */
					__( 'Sent follow-ups for %d appointment(s).', 'mcp-ai-wpoos-pro' ),
					$sent_count
				),
			'dry_run'      => $dry_run,
			'method'       => $method,
			'total'        => count( $appointment_ids ),
			'sent'         => $sent_count,
			'errors_count' => count( $errors ),
			'results'      => $results,
		);

		if ( ! empty( $errors ) ) {
			$response['errors'] = $errors;
		}

		return $response;
	}

	/**
	 * Send a follow-up message via email or SMS.
	 *
	 * @param string $email   Recipient email address.
	 * @param string $phone   Recipient phone number.
	 * @param string $message Message body.
	 * @param string $method  Delivery method (email or sms).
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private function send_followup( $email, $phone, $message, $method ) {
		if ( 'email' === $method ) {
			if ( empty( $email ) ) {
				return new WP_Error(
					'wp_mcp_ai_no_email',
					__( 'No email address on file for this member.', 'mcp-ai-wpoos-pro' )
				);
			}

			$subject = sprintf(
				/* translators: %s: site name */
				__( 'Appointment Follow-up - %s', 'mcp-ai-wpoos-pro' ),
				get_bloginfo( 'name' )
			);

			$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
			$sent    = wp_mail( $email, $subject, $message, $headers );

			if ( ! $sent ) {
				return new WP_Error(
					'wp_mcp_ai_email_failed',
					__( 'Failed to send email follow-up.', 'mcp-ai-wpoos-pro' )
				);
			}

			return true;
		}

		if ( 'sms' === $method ) {
			if ( empty( $phone ) ) {
				return new WP_Error(
					'wp_mcp_ai_no_phone',
					__( 'No phone number on file for this member.', 'mcp-ai-wpoos-pro' )
				);
			}

			/**
			 * Filter to allow custom SMS sending implementation.
			 *
			 * @since 1.6.0
			 *
			 * @param bool   $sent    Whether the SMS was sent. Default false (not configured).
			 * @param string $phone   Recipient phone number.
			 * @param string $message Message body.
			 * @return bool|WP_Error True if sent, false if not configured, WP_Error on failure.
			 */
			$sms_result = apply_filters( 'wp_mcp_ai_healthcare_send_sms_followup', false, $phone, $message );

			if ( is_wp_error( $sms_result ) ) {
				return $sms_result;
			}

			if ( ! $sms_result ) {
				return new WP_Error(
					'wp_mcp_ai_sms_not_configured',
					__( 'SMS sending is not configured. Use a plugin or custom code hooked to wp_mcp_ai_healthcare_send_sms_followup.', 'mcp-ai-wpoos-pro' )
				);
			}

			return true;
		}

		return new WP_Error(
			'wp_mcp_ai_invalid_method',
			__( 'Invalid delivery method.', 'mcp-ai-wpoos-pro' )
		);
	}
}
