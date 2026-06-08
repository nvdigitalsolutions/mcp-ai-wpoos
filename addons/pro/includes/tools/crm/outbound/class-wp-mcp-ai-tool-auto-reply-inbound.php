<?php
/**
 * Auto-Reply Inbound — rule-driven auto-reply that actually sends on the same channel.
 *
 * Previously only logged an activity (stub). Now dispatches via:
 *   - email    → wp_mail (with CAN-SPAM footer if configured)
 *   - sms      → send_lead_sms tool (Twilio or notify.lk)
 *   - whatsapp → send_lead_whatsapp tool (Meta Cloud API)
 *   - other    → logged as activity only (no transport)
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 * @since 2.4.0 Wired to real transport; no longer just a stub logger.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class WP_MCP_AI_Tool_Auto_Reply_Inbound implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }

	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }

	public function get_slug() {
		return 'auto_reply_inbound'; }

	public function get_name() {
		return __( 'Auto-Reply Inbound', 'mcp-ai-wpoos-pro' ); }

	public function get_description() {
		return __( 'Send an automated reply on the same channel (email/SMS/WhatsApp) based on matched intent rules.', 'mcp-ai-wpoos-pro' ); }

	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'lead_id'        => array( 'type' => 'integer' ),
				'intent'         => array(
					'type' => 'string',
					'enum' => WP_MCP_AI_CRM_Codes::INQUIRY_TYPES,
				),
				'channel'        => array(
					'type'    => 'string',
					'default' => 'email',
				),
				'custom_message' => array(
					'type'        => 'string',
					'description' => __( 'Overrides the template-based reply.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'lead_id', 'intent' ),
		); }

	public function get_required_capability() {
		return 'edit_posts'; }

	public function requires_base_pro() {
		return true; }

	public function get_capability_flags() {
		return array( 'pro', 'outbound-network', 'database-write', 'requires-capability', 'requires-consent' ); }

	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'unavailable', self::get_unavailable_reason() ); }

		$lead_id = absint( $arguments['lead_id'] );
		$intent  = sanitize_key( $arguments['intent'] );
		$channel = sanitize_key( $arguments['channel'] ?? 'email' );

		// Consent + DNC.
		if ( class_exists( 'WP_MCP_AI_CRM_Consent' ) && ! WP_MCP_AI_CRM_Consent::is_permitted( $lead_id, $channel ) ) {
			return new WP_Error( 'consent_required', __( 'Consent required.', 'mcp-ai-wpoos-pro' ) ); }

		$email = get_post_meta( $lead_id, 'email', true );
		$phone = get_post_meta( $lead_id, 'phone', true );
		if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			if ( ( $email && WP_MCP_AI_CRM_Engine::check_dnc( $email, $channel ) )
				|| ( $phone && WP_MCP_AI_CRM_Engine::check_dnc( $phone, $channel ) ) ) {
				return new WP_Error( 'dnc_blocked', __( 'DNC blocked.', 'mcp-ai-wpoos-pro' ) ); }
		}

		// Before-outbound-send hook.
		$veto = apply_filters( 'wp_mcp_ai_crm_before_outbound_send', null, $lead_id, $channel, $context );
		if ( is_wp_error( $veto ) ) {
			return $veto;
		}

		// Suppression check.
		$block = apply_filters( 'wp_mcp_ai_crm_suppression_check', null, $lead_id, $channel );
		if ( is_wp_error( $block ) ) {
			return $block;
		}

		// Default templates per intent.
		$templates = array(
			'new_inquiry'     => __( "Thanks for reaching out! We've received your inquiry and our team will get back to you within 24 hours.", 'mcp-ai-wpoos-pro' ),
			'demo_request'    => __( 'Thanks for requesting a demo! Someone from our team will reach out shortly to schedule a time that works for you.', 'mcp-ai-wpoos-pro' ),
			'pricing_inquiry' => __( "Thanks for your interest in our pricing. We'll send over the relevant details within the next business day.", 'mcp-ai-wpoos-pro' ),
			'support'         => __( "We've received your support request. Our team typically responds within 4 business hours.", 'mcp-ai-wpoos-pro' ),
			'complaint'       => __( "We're sorry to hear about your experience. A member of our team will personally follow up with you within 24 hours.", 'mcp-ai-wpoos-pro' ),
			'general'         => __( "Thanks for getting in touch! We'll respond to your message shortly.", 'mcp-ai-wpoos-pro' ),
		);
		$msg       = ! empty( $arguments['custom_message'] )
			? sanitize_textarea_field( $arguments['custom_message'] )
			: ( $templates[ $intent ] ?? $templates['general'] );

		// ---- Dispatch via actual transport based on channel ----
		$send_result = self::dispatch_reply( $lead_id, $channel, $msg, $context, $arguments );

		// Log activity.
		$disposition = is_wp_error( $send_result ) ? 'auto_reply_failed' : 'auto_replied';
		$activity_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_crm_activity',
				'post_title'   => __( 'Auto-reply sent', 'mcp-ai-wpoos-pro' ),
				'post_content' => $msg,
				'post_status'  => 'publish',
			),
			true
		);
		if ( ! is_wp_error( $activity_id ) ) {
			update_post_meta( $activity_id, 'activity_type', 'email' );
			update_post_meta( $activity_id, 'related_type', 'lead' );
			update_post_meta( $activity_id, 'related_id', $lead_id );
			update_post_meta( $activity_id, 'disposition', $disposition );
			update_post_meta( $activity_id, 'channel', $channel );
			if ( is_wp_error( $send_result ) ) {
				update_post_meta( $activity_id, 'error_message', $send_result->get_error_message() );
			}
		}

		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'auto_reply_sent',
				'lead',
				$lead_id,
				array(
					'intent'  => $intent,
					'channel' => $channel,
					'success' => ! is_wp_error( $send_result ),
				)
			);
		}

		do_action( 'wp_mcp_ai_crm_after_outbound_send', $lead_id, $channel, array( 'activity_id' => $activity_id ), $context );

		if ( is_wp_error( $send_result ) ) {
			return $send_result;
		}

		return array(
			'success'     => true,
			'message'     => sprintf(
				/* translators: %s: channel name (email, sms, whatsapp) */
				__( 'Auto-reply sent via %s.', 'mcp-ai-wpoos-pro' ),
				$channel
			),
			'lead_id'     => $lead_id,
			'reply'       => $msg,
			'activity_id' => $activity_id,
			'channel'     => $channel,
		);
	}

	/**
	 * Dispatch the auto-reply message via the appropriate transport.
	 *
	 * @since 2.4.0
	 *
	 * @param int    $lead_id   Lead post ID.
	 * @param string $channel   Channel slug (email, sms, whatsapp).
	 * @param string $message   Message body.
	 * @param array  $context   Execution context.
	 * @param array  $arguments Original tool arguments.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private static function dispatch_reply( $lead_id, $channel, $message, $context, $arguments ) {
		switch ( $channel ) {
			case 'email':
				return self::dispatch_email( $lead_id, $message );

			case 'sms':
				return self::dispatch_sms( $lead_id, $message, $context, $arguments );

			case 'whatsapp':
				return self::dispatch_whatsapp( $lead_id, $message, $context, $arguments );

			default:
				// Channels without a real transport (webchat, telegram, etc.)
				// are logged as activity only — no error, just not dispatched.
				return true;
		}
	}

	/**
	 * Send auto-reply via email (wp_mail).
	 *
	 * @param int    $lead_id Lead post ID.
	 * @param string $message Email body.
	 * @return true|WP_Error
	 */
	private static function dispatch_email( $lead_id, $message ) {
		$email   = get_post_meta( $lead_id, 'email', true );
		$name    = get_post_meta( $lead_id, 'first_name', true );
		$subject = __( 'Thanks for reaching out!', 'mcp-ai-wpoos-pro' );

		if ( ! $email ) {
			return new WP_Error( 'no_email', __( 'Lead has no email address.', 'mcp-ai-wpoos-pro' ) );
		}

		// Append CAN-SPAM footer if configured.
		if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			$settings = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
			$unsub    = $settings['consent']['unsubscribe_footer_text'] ?? '';
			$address  = $settings['consent']['physical_address'] ?? '';
			if ( $unsub || $address ) {
				$message .= "\n\n--\n" . $unsub;
				if ( $address ) {
					$message .= "\n" . $address;
				}
			}
		}

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		$sent = wp_mail( $email, $subject, $message, $headers );
		if ( ! $sent ) {
			return new WP_Error( 'wp_mail_failed', __( 'wp_mail failed to send the auto-reply.', 'mcp-ai-wpoos-pro' ) );
		}

		return true;
	}

	/**
	 * Send auto-reply via SMS (delegates to send_lead_sms tool).
	 *
	 * @param int    $lead_id   Lead post ID.
	 * @param string $message   SMS body.
	 * @param array  $context   Execution context.
	 * @param array  $arguments Original tool arguments.
	 * @return true|WP_Error
	 */
	private static function dispatch_sms( $lead_id, $message, $context, $arguments ) {
		$_tool_file = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/outbound/class-wp-mcp-ai-tool-send-lead-sms.php';
		if ( ! file_exists( $_tool_file ) ) {
			return new WP_Error( 'tool_missing', __( 'send_lead_sms tool not available.', 'mcp-ai-wpoos-pro' ) );
		}
		require_once $_tool_file;

		if ( ! class_exists( 'WP_MCP_AI_Tool_Send_Lead_SMS' ) ) {
			return new WP_Error( 'tool_missing', __( 'send_lead_sms tool not loaded.', 'mcp-ai-wpoos-pro' ) );
		}

		$tool   = new WP_MCP_AI_Tool_Send_Lead_SMS();
		$result = $tool->execute(
			array(
				'lead_id' => $lead_id,
				'message' => $message,
			),
			$context
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Send auto-reply via WhatsApp (delegates to send_lead_whatsapp tool).
	 *
	 * @param int    $lead_id   Lead post ID.
	 * @param string $message   WhatsApp message body.
	 * @param array  $context   Execution context.
	 * @param array  $arguments Original tool arguments.
	 * @return true|WP_Error
	 */
	private static function dispatch_whatsapp( $lead_id, $message, $context, $arguments ) {
		$_tool_file = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/outbound/class-wp-mcp-ai-tool-send-lead-whatsapp.php';
		if ( ! file_exists( $_tool_file ) ) {
			return new WP_Error( 'tool_missing', __( 'send_lead_whatsapp tool not available.', 'mcp-ai-wpoos-pro' ) );
		}
		require_once $_tool_file;

		if ( ! class_exists( 'WP_MCP_AI_Tool_Send_Lead_Whatsapp' ) ) {
			return new WP_Error( 'tool_missing', __( 'send_lead_whatsapp tool not loaded.', 'mcp-ai-wpoos-pro' ) );
		}

		$tool   = new WP_MCP_AI_Tool_Send_Lead_Whatsapp();
		$result = $tool->execute(
			array(
				'lead_id' => $lead_id,
				'message' => $message,
			),
			$context
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}
}
