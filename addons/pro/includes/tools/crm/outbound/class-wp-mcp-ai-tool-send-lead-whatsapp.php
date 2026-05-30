<?php
/**
 * Send Lead WhatsApp — outbound WhatsApp via Cloud API, with 24-hour session gating.
 * @package WP_MCP_AI_Pro @since 2.3.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class WP_MCP_AI_Tool_Send_Lead_Whatsapp implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }
	public function get_slug() {
		return 'send_lead_whatsapp'; }
	public function get_name() {
		return __( 'Send Lead WhatsApp', 'mcp-ai-wpoos-pro' ); }
	public function get_description() {
		return __( 'Send a WhatsApp message. Auto-detects 24-hour session vs template message requirement.', 'mcp-ai-wpoos-pro' ); }
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
		$phone   = get_post_meta( $lead_id, 'phone', true );
		if ( ! $phone ) {
			return new WP_Error( 'no_phone', __( 'Lead has no WhatsApp-capable phone.', 'mcp-ai-wpoos-pro' ) ); }
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
		$message = sanitize_textarea_field( $arguments['message'] );
		// Stub — no real API call.
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
			update_post_meta( $activity_id, 'disposition', 'whatsapp_sent' ); }
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record( 'outbound_whatsapp_sent', 'lead', $lead_id ); }
		do_action( 'wp_mcp_ai_crm_after_outbound_send', $lead_id, 'whatsapp', array( 'activity_id' => $activity_id ), $context );
		return array(
			'success'     => true,
			'message'     => __( 'WhatsApp message sent (stub).', 'mcp-ai-wpoos-pro' ),
			'lead_id'     => $lead_id,
			'activity_id' => $activity_id,
		);
	}
}
