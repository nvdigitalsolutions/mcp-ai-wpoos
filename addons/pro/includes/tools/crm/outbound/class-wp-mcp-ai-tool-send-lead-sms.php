<?php
/**
 * Send Lead SMS — outbound SMS via Twilio or notify.lk with TCPA consent gate.
 *
 * Supports two SMS providers, selected via the CRM integrations settings:
 *   - Twilio (default): Uses Twilio Programmable SMS REST API.
 *   - notify.lk: Uses notify.lk REST API (Sri Lanka SMS gateway).
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 * @since 2.4.0 Wired to real Twilio and notify.lk APIs; stub removed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class WP_MCP_AI_Tool_Send_Lead_SMS implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Twilio API base URL.
	 */
	const TWILIO_API_BASE = 'https://api.twilio.com/2010-04-01';

	/**
	 * notify.lk API base URL.
	 */
	const NOTIFYLK_API_BASE = 'https://app.notify.lk/api/v1';

	/**
	 * Default HTTP timeout for SMS API requests.
	 */
	const HTTP_TIMEOUT = 15;

	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }

	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }

	public function get_slug() {
		return 'send_lead_sms'; }

	public function get_name() {
		return __( 'Send Lead SMS', 'mcp-ai-wpoos-pro' ); }

	public function get_description() {
		return __( 'Send an outbound SMS to a lead via Twilio or notify.lk. Requires active SMS consent. Respects TCPA quiet hours.', 'mcp-ai-wpoos-pro' ); }

	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'lead_id' => array( 'type' => 'integer' ),
				'message' => array( 'type' => 'string' ),
			),
			'required'   => array( 'lead_id', 'message' ),
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
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ); }

		$lead_id = absint( $arguments['lead_id'] );
		$phone   = get_post_meta( $lead_id, 'phone', true );
		if ( ! $phone ) {
			return new WP_Error( 'no_phone', __( 'Lead has no phone number.', 'mcp-ai-wpoos-pro' ) ); }

		// Normalise phone to E.164.
		$phone = self::normalise_e164( $phone );

		// Consent gate.
		if ( class_exists( 'WP_MCP_AI_CRM_Consent' ) && ! WP_MCP_AI_CRM_Consent::is_permitted( $lead_id, 'sms' ) ) {
			return new WP_Error( 'consent_required', __( 'No SMS consent on file.', 'mcp-ai-wpoos-pro' ) ); }

		// DNC gate.
		if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) && WP_MCP_AI_CRM_Engine::check_dnc( $phone, 'sms' ) ) {
			return new WP_Error( 'dnc_blocked', __( 'Phone is on the DNC list.', 'mcp-ai-wpoos-pro' ) ); }

		// Before-outbound-send hook.
		$veto = apply_filters( 'wp_mcp_ai_crm_before_outbound_send', null, $lead_id, 'sms', $context );
		if ( is_wp_error( $veto ) ) {
			return $veto;
		}

		// Suppression check.
		$block = apply_filters( 'wp_mcp_ai_crm_suppression_check', null, $lead_id, 'sms' );
		if ( is_wp_error( $block ) ) {
			return $block;
		}

		// Sequence step hooks.
		$sequence_id = isset( $arguments['sequence_id'] ) ? absint( $arguments['sequence_id'] ) : 0;
		$step_index  = isset( $arguments['sequence_step'] ) ? absint( $arguments['sequence_step'] ) : 0;
		if ( $sequence_id ) {
			do_action( 'wp_mcp_ai_crm_sequence_step_before_send', $lead_id, $sequence_id, $step_index, 'sms', $arguments, $context );
		}

		$message = sanitize_textarea_field( $arguments['message'] );

		// Determine SMS provider and send.
		$settings    = class_exists( 'WP_MCP_AI_CRM_Engine' )
			? WP_MCP_AI_CRM_Engine::get_toolkit_settings()
			: array();
		$provider    = sanitize_key( $settings['integrations']['sms_provider'] ?? 'twilio' );
		$send_result = null;

		if ( 'notifylk' === $provider ) {
			$send_result = $this->send_via_notifylk( $phone, $message, $settings );
		} else {
			$send_result = $this->send_via_twilio( $phone, $message, $settings );
		}

		// Log activity regardless of send outcome.
		$disposition = is_wp_error( $send_result ) ? 'sms_failed' : 'sms_sent';
		$activity_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_crm_activity',
				'post_title'  => sprintf(
					/* translators: %s: first 60 chars of SMS message */
					__( 'Sent SMS: %s', 'mcp-ai-wpoos-pro' ),
					mb_substr( $message, 0, 60 )
				),
				'post_status' => 'publish',
			),
			true
		);
		if ( ! is_wp_error( $activity_id ) ) {
			update_post_meta( $activity_id, 'activity_type', 'call' );
			update_post_meta( $activity_id, 'related_type', 'lead' );
			update_post_meta( $activity_id, 'related_id', $lead_id );
			update_post_meta( $activity_id, 'disposition', $disposition );
			if ( is_wp_error( $send_result ) ) {
				update_post_meta( $activity_id, 'error_message', $send_result->get_error_message() );
			} else {
				update_post_meta( $activity_id, 'provider_message_id', $send_result );
			}
		}

		// Audit log.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'outbound_sms_sent',
				'lead',
				$lead_id,
				array(
					'phone'    => $phone,
					'provider' => $provider,
					'success'  => ! is_wp_error( $send_result ),
				)
			);
		}

		do_action( 'wp_mcp_ai_crm_after_outbound_send', $lead_id, 'sms', array( 'activity_id' => $activity_id ), $context );
		if ( $sequence_id ) {
			do_action( 'wp_mcp_ai_crm_sequence_step_after_send', $lead_id, $sequence_id, $step_index, 'sms', $activity_id, $context );
		}

		if ( is_wp_error( $send_result ) ) {
			return $send_result;
		}

		return array(
			'success'         => true,
			'message'         => sprintf(
				/* translators: %s: SMS provider name */
				__( 'SMS sent via %s.', 'mcp-ai-wpoos-pro' ),
				'twilio' === $provider ? 'Twilio' : 'notify.lk'
			),
			'lead_id'         => $lead_id,
			'to'              => $phone,
			'activity_id'     => $activity_id,
			'provider'        => $provider,
			'provider_msg_id' => $send_result,
		);
	}

	/**
	 * Send SMS via Twilio Programmable SMS REST API.
	 *
	 * @since 2.4.0
	 *
	 * @param string $to       Recipient phone in E.164 format.
	 * @param string $message  SMS body text.
	 * @param array  $settings CRM toolkit settings.
	 * @return string|WP_Error Twilio message SID on success, WP_Error on failure.
	 */
	private function send_via_twilio( $to, $message, $settings ) {
		$account_sid = $settings['integrations']['twilio_account_sid_secret'] ?? '';
		$auth_token  = $settings['integrations']['twilio_auth_token_secret'] ?? '';
		$from_number = $settings['integrations']['twilio_from_number'] ?? '';

		if ( empty( $account_sid ) || empty( $auth_token ) || empty( $from_number ) ) {
			return new WP_Error(
				'twilio_not_configured',
				__( 'Twilio is not configured. Set twilio_account_sid_secret, twilio_auth_token_secret, and twilio_from_number in CRM integrations.', 'mcp-ai-wpoos-pro' )
			);
		}

		$endpoint = self::TWILIO_API_BASE . '/Accounts/' . rawurlencode( $account_sid ) . '/Messages.json';

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $account_sid . ':' . $auth_token ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Twilio Basic auth
				),
				'timeout' => self::HTTP_TIMEOUT,
				'body'    => array(
					'From' => $from_number,
					'To'   => $to,
					'Body' => $message,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'twilio_http_error',
				$response->get_error_message()
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$err_msg = isset( $body['message'] ) ? $body['message'] : __( 'Unknown Twilio error.', 'mcp-ai-wpoos-pro' );
			return new WP_Error( 'twilio_api_error', $err_msg, array( 'http_code' => $code ) );
		}

		return isset( $body['sid'] ) ? $body['sid'] : '';
	}

	/**
	 * Send SMS via notify.lk REST API (Sri Lanka SMS gateway).
	 *
	 * @since 2.4.0
	 *
	 * @param string $to       Recipient phone number (Sri Lanka format, e.g. 94xxxxxxxxx).
	 * @param string $message  SMS body text.
	 * @param array  $settings CRM toolkit settings.
	 * @return string|WP_Error notify.lk message ID on success, WP_Error on failure.
	 */
	private function send_via_notifylk( $to, $message, $settings ) {
		$user_id   = $settings['integrations']['notifylk_user_id'] ?? '';
		$api_key   = $settings['integrations']['notifylk_api_key'] ?? '';
		$sender_id = $settings['integrations']['notifylk_sender_id'] ?? '';

		if ( empty( $user_id ) || empty( $api_key ) || empty( $sender_id ) ) {
			return new WP_Error(
				'notifylk_not_configured',
				__( 'notify.lk is not configured. Set notifylk_user_id, notifylk_api_key, and notifylk_sender_id in CRM integrations.', 'mcp-ai-wpoos-pro' )
			);
		}

		$endpoint = self::NOTIFYLK_API_BASE . '/send';

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => self::HTTP_TIMEOUT,
				'body'    => array(
					'user_id'   => $user_id,
					'api_key'   => $api_key,
					'sender_id' => $sender_id,
					'message'   => $message,
					'to'        => $to,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'notifylk_http_error',
				$response->get_error_message()
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$err_msg = isset( $body['error'] ) ? $body['error'] : __( 'Unknown notify.lk error.', 'mcp-ai-wpoos-pro' );
			return new WP_Error( 'notifylk_api_error', $err_msg, array( 'http_code' => $code ) );
		}

		// notify.lk returns status: "ok" on success.
		$status = isset( $body['status'] ) ? $body['status'] : '';
		if ( 'ok' !== strtolower( $status ) && 'success' !== strtolower( $status ) ) {
			return new WP_Error( 'notifylk_api_error', __( 'notify.lk returned non-success status.', 'mcp-ai-wpoos-pro' ), array( 'response' => $body ) );
		}

		return isset( $body['message_id'] ) ? (string) $body['message_id'] : '';
	}

	/**
	 * Normalise a phone number to E.164 format.
	 *
	 * @param string $phone Raw phone number.
	 * @return string Normalised or original if already E.164-like.
	 */
	private static function normalise_e164( $phone ) {
		$phone = trim( (string) $phone );
		// Already has + prefix — assume valid E.164.
		if ( 0 === strpos( $phone, '+' ) ) {
			return $phone;
		}
		// Strip non-digit characters.
		$digits = preg_replace( '/\D/', '', $phone );
		// Prepend +.
		if ( ! empty( $digits ) ) {
			return '+' . $digits;
		}
		return $phone;
	}
}
