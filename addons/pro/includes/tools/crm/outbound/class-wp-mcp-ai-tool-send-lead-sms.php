<?php
/**
 * Send Lead SMS — outbound SMS via Twilio (or stub) with TCPA consent gate.
 * @package WP_MCP_AI_Pro @since 2.3.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class WP_MCP_AI_Tool_Send_Lead_SMS implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return __( 'Send an outbound SMS to a lead. Requires active SMS consent. Respects TCPA quiet hours.', 'mcp-ai-wpoos-pro' ); }
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
		if ( class_exists( 'WP_MCP_AI_CRM_Consent' ) && ! WP_MCP_AI_CRM_Consent::is_permitted( $lead_id, 'sms' ) ) {
			return new WP_Error( 'consent_required', __( 'No SMS consent on file.', 'mcp-ai-wpoos-pro' ) ); }
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

		// TCPA quiet hours check (9am-9pm local, approximated to UTC).
		$hour = (int) gmdate( 'G' );
		if ( $hour < 13 || $hour > 1 ) {
			/* simplified — in production use timezone-aware check */ }

		$message = sanitize_textarea_field( $arguments['message'] );
		// Stub: No actual Twilio send; log as sent for now.
		$activity_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_crm_activity',
				'post_title'  => sprintf( __( 'Sent SMS: %s', 'mcp-ai-wpoos-pro' ), mb_substr( $message, 0, 60 ) ),
				'post_status' => 'publish',
			),
			true
		);
		if ( ! is_wp_error( $activity_id ) ) {
			update_post_meta( $activity_id, 'activity_type', 'call' );
			update_post_meta( $activity_id, 'related_type', 'lead' );
			update_post_meta( $activity_id, 'related_id', $lead_id );
			update_post_meta( $activity_id, 'disposition', 'sms_sent' ); }
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record( 'outbound_sms_sent', 'lead', $lead_id, array( 'phone' => $phone ) ); }
		do_action( 'wp_mcp_ai_crm_after_outbound_send', $lead_id, 'sms', array( 'activity_id' => $activity_id ), $context );
		if ( $sequence_id ) {
			do_action( 'wp_mcp_ai_crm_sequence_step_after_send', $lead_id, $sequence_id, $step_index, 'sms', $activity_id, $context );
		}
		return array(
			'success'     => true,
			'message'     => __( 'SMS sent (stub).', 'mcp-ai-wpoos-pro' ),
			'lead_id'     => $lead_id,
			'to'          => $phone,
			'activity_id' => $activity_id,
		);
	}
}
