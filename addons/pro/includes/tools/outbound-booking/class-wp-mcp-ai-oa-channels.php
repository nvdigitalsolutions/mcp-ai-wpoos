<?php
/**
 * Outbound Appointment Booking — channel dispatcher.
 *
 * Sends approved outbox messages on their target channel. Email goes through
 * wp_mail (so any SMTP plugin is honoured). LinkedIn and Instagram have no
 * native send APIs, so those channels are either pushed to a webhook
 * automation endpoint (Make/n8n/Instantly-style) or left for a human to send
 * and mark complete.
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
 * Channel dispatcher.
 *
 * @since 2.12.0
 */
class WP_MCP_AI_OA_Channels {

	/**
	 * Channels the toolkit dispatches.
	 *
	 * @var string[]
	 */
	const CHANNELS = array( 'email', 'linkedin_dm', 'instagram_dm' );

	/**
	 * Get the configured mode for a channel.
	 *
	 * @since 2.12.0
	 * @param string $channel Channel slug.
	 * @return string auto|approval|webhook|off.
	 */
	public static function mode_for( $channel ) {
		$s = WP_MCP_AI_OA_Settings::get();
		switch ( $channel ) {
			case 'email':
				return $s['email_mode'];
			case 'linkedin_dm':
				return $s['linkedin_mode'];
			case 'instagram_dm':
				return $s['instagram_mode'];
		}
		return 'off';
	}

	/**
	 * Whether a channel is auto-sending.
	 *
	 * @since 2.12.0
	 * @param string $channel Channel slug.
	 * @return bool
	 */
	public static function is_auto( $channel ) {
		return 'auto' === self::mode_for( $channel );
	}

	/**
	 * Dispatch an outbox message according to its channel mode.
	 *
	 * @since 2.12.0
	 * @param int $outbox_id Outbox post ID.
	 * @return array|WP_Error Result.
	 */
	public static function dispatch( $outbox_id ) {
		$post = get_post( $outbox_id );
		if ( ! $post || WP_MCP_AI_OA_Outbox::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Message not found.', 'mcp-ai-wpoos-pro' ) );
		}
		$status = get_post_meta( $outbox_id, '_oa_ob_status', true );
		if ( 'sent' === $status ) {
			return array(
				'success'   => true,
				'message'   => __( 'Message already sent.', 'mcp-ai-wpoos-pro' ),
				'outbox_id' => $outbox_id,
			);
		}
		if ( 'rejected' === $status ) {
			return new WP_Error( 'rejected', __( 'Message was rejected.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( 'held' === $status ) {
			return new WP_Error( 'held', __( 'Message is held for human review.', 'mcp-ai-wpoos-pro' ) );
		}

		$channel = get_post_meta( $outbox_id, '_oa_ob_channel', true );
		$mode    = self::mode_for( $channel );
		if ( 'off' === $mode ) {
			return new WP_Error( 'channel_off', __( 'Channel is disabled in settings.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( 'approval' === $mode ) {
			return array(
				'success'           => true,
				'message'           => __( 'Message awaits manual send — mark it sent after sending it yourself.', 'mcp-ai-wpoos-pro' ),
				'outbox_id'         => $outbox_id,
				'needs_manual_send' => true,
			);
		}

		/**
		 * Fires before a message is dispatched. Return a WP_Error to veto.
		 *
		 * @since 2.12.0
		 * @param null  $veto      Veto placeholder.
		 * @param int   $outbox_id Outbox post ID.
		 * @param string $channel  Channel slug.
		 */
		$veto = apply_filters( 'wp_mcp_ai_oa_before_dispatch', null, $outbox_id, $channel );
		if ( is_wp_error( $veto ) ) {
			WP_MCP_AI_OA_Outbox::set_status( $outbox_id, 'failed', $veto->get_error_message() );
			return $veto;
		}

		// Deterministic pre-send gate. Auto-send never bypasses it.
		if ( class_exists( 'WP_MCP_AI_CRM_Pre_Send_Gate' ) ) {
			$lead_id = (int) get_post_meta( $outbox_id, '_oa_ob_lead_id', true );
			$to      = '';
			if ( 'email' === $channel ) {
				$to = get_post_meta( $lead_id, 'email', true );
			} elseif ( 'linkedin_dm' === $channel ) {
				$to = get_post_meta( $lead_id, '_oa_linkedin', true );
			} elseif ( 'instagram_dm' === $channel ) {
				$to = get_post_meta( $lead_id, '_oa_instagram', true );
			}
			$kind = (string) get_post_meta( $outbox_id, '_oa_ob_kind', true );
			if ( '' === $kind ) {
				$kind = 'sequence';
			}
			$gate = WP_MCP_AI_CRM_Pre_Send_Gate::check(
				array(
					'lead_id' => $lead_id,
					'channel' => $channel,
					'kind'    => $kind,
					'to'      => $to,
					'subject' => get_post_meta( $outbox_id, '_oa_ob_subject', true ),
					'body'    => get_post_meta( $outbox_id, '_oa_ob_body', true ),
					'step'    => (int) get_post_meta( $outbox_id, '_oa_ob_step', true ),
				)
			);
			if ( is_wp_error( $gate ) ) {
				$data = $gate->get_error_data();
				WP_MCP_AI_OA_Outbox::hold( $outbox_id, is_array( $data ) && isset( $data['reasons'] ) ? (array) $data['reasons'] : array() );
				return $gate;
			}
		}

		// Agent output guard: foreign links / instruction echoes in AI copy.
		if ( class_exists( 'WP_MCP_AI_CRM_Agent_Guard' ) && '1' === (string) get_post_meta( $outbox_id, '_oa_ob_ai_generated', true ) ) {
			$review = WP_MCP_AI_CRM_Agent_Guard::review(
				get_post_meta( $outbox_id, '_oa_ob_subject', true ),
				get_post_meta( $outbox_id, '_oa_ob_body', true ),
				array(
					'lead_id' => (int) get_post_meta( $outbox_id, '_oa_ob_lead_id', true ),
					'kind'    => get_post_meta( $outbox_id, '_oa_ob_kind', true ),
				)
			);
			if ( empty( $review['ok'] ) ) {
				WP_MCP_AI_OA_Outbox::hold( $outbox_id, $review['reasons'] );
				return new WP_Error( 'wp_mcp_ai_crm_agent_guard_held', __( 'Generated message is held for human review.', 'mcp-ai-wpoos-pro' ), array( 'reasons' => $review['reasons'] ) );
			}
		}

		if ( 'email' === $channel ) {
			$result = self::send_email( $outbox_id );
		} elseif ( 'webhook' === $mode ) {
			$result = self::send_webhook( $outbox_id, $channel );
		} else {
			$result = new WP_Error( 'unsupported', __( 'Unsupported channel mode.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_wp_error( $result ) ) {
			WP_MCP_AI_OA_Outbox::set_status( $outbox_id, 'failed', $result->get_error_message() );
			return $result;
		}

		self::after_sent( $outbox_id, $channel, isset( $result['message_id'] ) ? $result['message_id'] : '' );
		return array(
			'success'   => true,
			'message'   => __( 'Message sent.', 'mcp-ai-wpoos-pro' ),
			'outbox_id' => $outbox_id,
			'channel'   => $channel,
		);
	}

	/**
	 * Send an email from an outbox message.
	 *
	 * @since 2.12.0
	 * @param int $outbox_id Outbox post ID.
	 * @return array|WP_Error
	 */
	private static function send_email( $outbox_id ) {
		$lead_id = (int) get_post_meta( $outbox_id, '_oa_ob_lead_id', true );
		$to      = get_post_meta( $lead_id, 'email', true );
		if ( ! $to ) {
			return new WP_Error( 'no_email', __( 'Lead has no email address.', 'mcp-ai-wpoos-pro' ) );
		}
		$subject = get_post_meta( $outbox_id, '_oa_ob_subject', true );
		$body    = get_post_meta( $outbox_id, '_oa_ob_body', true );
		if ( ! $subject ) {
			$subject = __( 'Quick question', 'mcp-ai-wpoos-pro' );
		}
		$settings = WP_MCP_AI_OA_Settings::get();
		$headers  = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( class_exists( 'WP_MCP_AI_CRM_Unsubscribe' ) ) {
			$kind    = get_post_meta( $outbox_id, '_oa_ob_kind', true );
			$headers = array_merge( $headers, WP_MCP_AI_CRM_Unsubscribe::headers_for_lead( $lead_id, 'email', $kind ? $kind : 'sequence' ) );
		}
		if ( $settings['from_name'] && $settings['from_email'] ) {
			$headers[] = 'From: ' . $settings['from_name'] . ' <' . $settings['from_email'] . '>';
		}
		if ( $settings['sender_signature'] ) {
			$body .= '<br><br>' . nl2br( esc_html( $settings['sender_signature'] ) );
		}
		$sent = wp_mail( $to, $subject, $body, $headers );
		if ( ! $sent ) {
			return new WP_Error( 'send_failed', __( 'Email failed to send.', 'mcp-ai-wpoos-pro' ) );
		}
		return array( 'message_id' => 'email-' . $outbox_id . '-' . time() );
	}

	/**
	 * Push a DM to the configured webhook automation endpoint.
	 *
	 * @since 2.12.0
	 * @param int    $outbox_id Outbox post ID.
	 * @param string $channel   Channel slug.
	 * @return array|WP_Error
	 */
	private static function send_webhook( $outbox_id, $channel ) {
		$settings = WP_MCP_AI_OA_Settings::get();
		$url      = $settings['webhook_url'];
		if ( ! $url ) {
			return new WP_Error( 'no_webhook', __( 'No automation webhook URL configured.', 'mcp-ai-wpoos-pro' ) );
		}
		$lead_id = (int) get_post_meta( $outbox_id, '_oa_ob_lead_id', true );

		$payload = array(
			'channel'   => $channel,
			'lead_id'   => $lead_id,
			'lead_name' => get_the_title( $lead_id ),
			'email'     => get_post_meta( $lead_id, 'email', true ),
			'linkedin'  => get_post_meta( $lead_id, '_oa_linkedin', true ),
			'instagram' => get_post_meta( $lead_id, '_oa_instagram', true ),
			'subject'   => get_post_meta( $outbox_id, '_oa_ob_subject', true ),
			'body'      => get_post_meta( $outbox_id, '_oa_ob_body', true ),
			'outbox_id' => $outbox_id,
		);

		/**
		 * Filter the webhook payload before sending.
		 *
		 * @since 2.12.0
		 * @param array  $payload   Payload.
		 * @param int    $outbox_id Outbox post ID.
		 * @param string $channel   Channel slug.
		 */
		$payload = apply_filters( 'wp_mcp_ai_oa_webhook_payload', $payload, $outbox_id, $channel );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'webhook_failed', sprintf( __( 'Automation webhook returned HTTP %d.', 'mcp-ai-wpoos-pro' ), $code ) );
		}
		return array( 'message_id' => 'webhook-' . $outbox_id . '-' . time() );
	}

	/**
	 * Mark a manually-sent DM as complete (human clicked "Mark Sent").
	 *
	 * @since 2.12.0
	 * @param int $outbox_id Outbox post ID.
	 * @return array|WP_Error
	 */
	public static function mark_manually_sent( $outbox_id ) {
		$post = get_post( $outbox_id );
		if ( ! $post || WP_MCP_AI_OA_Outbox::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Message not found.', 'mcp-ai-wpoos-pro' ) );
		}
		$channel = get_post_meta( $outbox_id, '_oa_ob_channel', true );
		self::after_sent( $outbox_id, $channel, 'manual-' . $outbox_id . '-' . time() );
		return array(
			'success'   => true,
			'message'   => __( 'Message marked as sent.', 'mcp-ai-wpoos-pro' ),
			'outbox_id' => $outbox_id,
		);
	}

	/**
	 * Shared post-send bookkeeping: status, stats, lead meta, activity, audit.
	 *
	 * @since 2.12.0
	 * @param int    $outbox_id  Outbox post ID.
	 * @param string $channel    Channel slug.
	 * @param string $message_id Platform-side message ID.
	 * @return void
	 */
	private static function after_sent( $outbox_id, $channel, $message_id = '' ) {
		WP_MCP_AI_OA_Outbox::set_status( $outbox_id, 'sent' );
		if ( $message_id ) {
			update_post_meta( $outbox_id, '_oa_ob_message_id', sanitize_text_field( $message_id ) );
		}

		$lead_id  = (int) get_post_meta( $outbox_id, '_oa_ob_lead_id', true );
		$angle_id = (int) get_post_meta( $outbox_id, '_oa_ob_angle_id', true );
		if ( 'email' === $channel && class_exists( 'WP_MCP_AI_CRM_Deliverability' ) ) {
			WP_MCP_AI_CRM_Deliverability::record_sent();
			$from = (string) WP_MCP_AI_OA_Settings::get_setting( 'from_email', '' );
			if ( $from ) {
				WP_MCP_AI_CRM_Deliverability::increment_sent_today( $from );
			}
		}
		if ( $angle_id ) {
			WP_MCP_AI_OA_Angle_CPT::record_event( $angle_id, 'send' );
		}
		if ( $lead_id ) {
			update_post_meta( $lead_id, '_oa_last_message_at', current_time( 'mysql', true ) );
		}

		// CRM activity trail (only when the CRM activity CPT is available).
		if ( post_type_exists( 'mcp_ai_crm_activity' ) ) {
			$subject     = get_post_meta( $outbox_id, '_oa_ob_subject', true );
			$activity_id = wp_insert_post(
				array(
					'post_type'   => 'mcp_ai_crm_activity',
					'post_title'  => sprintf(
						/* translators: 1: channel, 2: subject */
						__( 'Outbound %1$s sent: %2$s', 'mcp-ai-wpoos-pro' ),
						ucfirst( str_replace( '_', ' ', $channel ) ),
						$subject ? $subject : get_the_title( $outbox_id )
					),
					'post_status' => 'publish',
				),
				true
			);
			if ( ! is_wp_error( $activity_id ) && $lead_id ) {
				update_post_meta( $activity_id, 'activity_type', $channel );
				update_post_meta( $activity_id, 'related_type', 'lead' );
				update_post_meta( $activity_id, 'related_id', $lead_id );
				update_post_meta( $activity_id, 'disposition', 'sent' );
			}
		}

		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'outbound_message_sent',
				'outbox',
				$outbox_id,
				array(
					'channel'    => $channel,
					'lead_id'    => $lead_id,
					'message_id' => $message_id,
				)
			);
		}

		/**
		 * Fires after an outbound message has been sent.
		 *
		 * @since 2.12.0
		 * @param int    $outbox_id  Outbox post ID.
		 * @param int    $lead_id    Lead post ID.
		 * @param string $channel    Channel slug.
		 * @param string $message_id Platform-side message ID.
		 */
		do_action( 'wp_mcp_ai_oa_after_dispatch', $outbox_id, $lead_id, $channel, $message_id );
	}
}
