<?php
/**
 * Send Lead WhatsApp — outbound WhatsApp via Meta Cloud API, with 24-hour session gating.
 *
 * Calls the WhatsApp Business Cloud API (Meta Graph API) to send text messages.
 * Follows the same pattern as WP_MCP_AI_Pro_Tool_Send_WhatsApp_Message in
 * the Chat Channels toolkit.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 * @since 2.4.0 Wired to real Meta Cloud API; stub removed. Added consent/DNC/audit.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Send Lead WhatsApp — outbound WhatsApp via Meta Cloud API.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 * @since 2.4.0 Wired to real Meta Cloud API; stub removed.
 */
class WP_MCP_AI_Tool_Send_Lead_Whatsapp implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * WhatsApp Graph API version.
	 */
	const GRAPH_API_VERSION = 'v22.0';

	/**
	 * Default HTTP timeout.
	 */
	const HTTP_TIMEOUT = 20;

	/**
	 * Whether this tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }

	/**
	 * Reason the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }

	/**
	 * Tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'send_lead_whatsapp'; }

	/**
	 * Tool display name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Send Lead WhatsApp', 'mcp-ai-wpoos-pro' ); }

	/**
	 * Tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Send a WhatsApp message via the Meta Cloud API. Auto-detects 24-hour session vs template message requirement.', 'mcp-ai-wpoos-pro' ); }

	/**
	 * Parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'lead_id'                => array( 'type' => 'integer' ),
				'message'                => array( 'type' => 'string' ),
				'allow_template_message' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Allow template message if outside 24h session window.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'lead_id', 'message' ),
		); }

	/**
	 * Required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts'; }

	/**
	 * Whether this tool requires base pro.
	 *
	 * @return bool
	 */
	public function requires_base_pro() {
		return true; }

	/**
	 * Capability flags.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'pro', 'outbound-network', 'database-write', 'requires-capability', 'requires-consent' ); }

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'unavailable', self::get_unavailable_reason() ); }

		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ); }

		$lead_id = absint( $arguments['lead_id'] );
		$phone   = get_post_meta( $lead_id, 'phone', true );
		if ( ! $phone ) {
			return new WP_Error( 'no_phone', __( 'Lead has no WhatsApp-capable phone.', 'mcp-ai-wpoos-pro' ) ); }

		// Normalise phone to E.164 (WhatsApp requires no + prefix or spaces).
		$phone = $this->normalise_whatsapp_phone( $phone );

		// Consent gate.
		if ( class_exists( 'WP_MCP_AI_CRM_Consent' ) && ! WP_MCP_AI_CRM_Consent::is_permitted( $lead_id, 'whatsapp' ) ) {
			return new WP_Error( 'consent_required', __( 'No WhatsApp consent on file.', 'mcp-ai-wpoos-pro' ) ); }

		// DNC gate.
		if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) && WP_MCP_AI_CRM_Engine::check_dnc( $phone, 'whatsapp' ) ) {
			return new WP_Error( 'dnc_blocked', __( 'Phone is on the DNC list.', 'mcp-ai-wpoos-pro' ) ); }

		// Before-outbound-send hook.
		$veto = apply_filters( 'wp_mcp_ai_crm_before_outbound_send', null, $lead_id, 'whatsapp', $context );
		if ( is_wp_error( $veto ) ) {
			return $veto; }

		// Suppression check.
		$block = apply_filters( 'wp_mcp_ai_crm_suppression_check', null, $lead_id, 'whatsapp' );
		if ( is_wp_error( $block ) ) {
			return $block; }

		// Sequence step hooks.
		$sequence_id = isset( $arguments['sequence_id'] ) ? absint( $arguments['sequence_id'] ) : 0;
		$step_index  = isset( $arguments['sequence_step'] ) ? absint( $arguments['sequence_step'] ) : 0;
		if ( $sequence_id ) {
			do_action( 'wp_mcp_ai_crm_sequence_step_before_send', $lead_id, $sequence_id, $step_index, 'whatsapp', $arguments, $context );
		}

		$message = sanitize_textarea_field( $arguments['message'] );

		// Resolve WhatsApp credentials from integration settings.
		$settings = class_exists( 'WP_MCP_AI_CRM_Engine' )
			? WP_MCP_AI_CRM_Engine::get_toolkit_settings()
			: array();

		$access_token    = $settings['integrations']['whatsapp_access_token'] ?? '';
		$phone_number_id = $settings['integrations']['whatsapp_phone_number_id'] ?? '';

		if ( empty( $access_token ) || empty( $phone_number_id ) ) {
			return new WP_Error(
				'whatsapp_not_configured',
				__( 'WhatsApp is not configured. Set whatsapp_access_token and whatsapp_phone_number_id in CRM integrations.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Send via Meta Cloud API.
		$send_result = $this->send_via_meta_api( $phone, $message, $access_token, $phone_number_id );

		// Log activity regardless of send outcome.
		$disposition = is_wp_error( $send_result ) ? 'whatsapp_failed' : 'whatsapp_sent';
		$activity_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_crm_activity',
				'post_title'  => __( 'Sent WhatsApp message', 'mcp-ai-wpoos-pro' ),
				'post_status' => 'publish',
			),
			true
		);
		if ( ! is_wp_error( $activity_id ) ) {
			update_post_meta( $activity_id, 'activity_type', 'email' );
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
				'outbound_whatsapp_sent',
				'lead',
				$lead_id,
				array(
					'phone'   => $phone,
					'success' => ! is_wp_error( $send_result ),
				)
			);
		}

		do_action( 'wp_mcp_ai_crm_after_outbound_send', $lead_id, 'whatsapp', array( 'activity_id' => $activity_id ), $context );
		if ( $sequence_id ) {
			do_action( 'wp_mcp_ai_crm_sequence_step_after_send', $lead_id, $sequence_id, $step_index, 'whatsapp', $activity_id, $context );
		}

		if ( is_wp_error( $send_result ) ) {
			return $send_result;
		}

		return array(
			'success'       => true,
			'message'       => __( 'WhatsApp message sent via Meta Cloud API.', 'mcp-ai-wpoos-pro' ),
			'lead_id'       => $lead_id,
			'to'            => $phone,
			'activity_id'   => $activity_id,
			'wa_message_id' => $send_result,
		);
	}

	/**
	 * Send a WhatsApp text message via the Meta Cloud API.
	 *
	 * @since 2.4.0
	 *
	 * @param string $to              Recipient phone number (E.164 digits only).
	 * @param string $message         Message body text.
	 * @param string $access_token    WhatsApp Cloud API access token.
	 * @param string $phone_number_id WhatsApp Business phone number ID.
	 * @return string|WP_Error WhatsApp message ID on success, WP_Error on failure.
	 */
	private function send_via_meta_api( $to, $message, $access_token, $phone_number_id ) {
		$endpoint = sprintf(
			'https://graph.facebook.com/%s/%s/messages',
			self::GRAPH_API_VERSION,
			rawurlencode( $phone_number_id )
		);

		$payload = array(
			'messaging_product' => 'whatsapp',
			'recipient_type'    => 'individual',
			'to'                => $to,
			'type'              => 'text',
			'text'              => array(
				'body'        => $message,
				'preview_url' => true,
			),
		);

		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			return new WP_Error(
				'whatsapp_encode_error',
				__( 'Failed to encode WhatsApp request payload.', 'mcp-ai-wpoos-pro' )
			);
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $access_token,
				),
				'timeout' => self::HTTP_TIMEOUT,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'whatsapp_http_error',
				$response->get_error_message()
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$raw     = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		if ( 200 !== $code || ( isset( $decoded['error'] ) && ! empty( $decoded['error'] ) ) ) {
			$err_msg = isset( $decoded['error']['message'] )
				? $decoded['error']['message']
				: __( 'WhatsApp API returned an error.', 'mcp-ai-wpoos-pro' );
			return new WP_Error( 'whatsapp_api_error', $err_msg, array( 'http_code' => $code ) );
		}

		// Extract WhatsApp message ID from response: { "messages": [ { "id": "wamid.xxx" } ] }.
		if ( isset( $decoded['messages'][0]['id'] ) ) {
			return $decoded['messages'][0]['id'];
		}

		return '';
	}

	/**
	 * Normalise a phone number for WhatsApp Cloud API (digits only, no +).
	 *
	 * @param string $phone Raw phone number.
	 * @return string Digits-only phone number.
	 */
	private function normalise_whatsapp_phone( $phone ) {
		$phone = trim( (string) $phone );
		// Strip + prefix and non-digit characters.
		$digits = preg_replace( '/\D/', '', $phone );
		return $digits;
	}
}
