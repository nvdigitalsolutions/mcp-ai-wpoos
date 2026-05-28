<?php
/** Auto-Reply Inbound — rule-driven auto-reply on the same channel. @package WP_MCP_AI_Pro @since 2.3.0 */
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
		return __( 'Send an automated reply on the same channel based on matched intent rules.', 'mcp-ai-wpoos-pro' ); }
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
			if ( ( $email && WP_MCP_AI_CRM_Engine::check_dnc( $email, $channel ) ) || ( $phone && WP_MCP_AI_CRM_Engine::check_dnc( $phone, $channel ) ) ) {
				return new WP_Error( 'dnc_blocked', __( 'DNC blocked.', 'mcp-ai-wpoos-pro' ) ); }
		}
		// Default templates per intent.
		$templates   = array(
			'new_inquiry'     => __( "Thanks for reaching out! We've received your inquiry and our team will get back to you within 24 hours.", 'mcp-ai-wpoos-pro' ),
			'demo_request'    => __( 'Thanks for requesting a demo! Someone from our team will reach out shortly to schedule a time that works for you.', 'mcp-ai-wpoos-pro' ),
			'pricing_inquiry' => __( "Thanks for your interest in our pricing. We'll send over the relevant details within the next business day.", 'mcp-ai-wpoos-pro' ),
			'support'         => __( "We've received your support request. Our team typically responds within 4 business hours.", 'mcp-ai-wpoos-pro' ),
			'complaint'       => __( "We're sorry to hear about your experience. A member of our team will personally follow up with you within 24 hours.", 'mcp-ai-wpoos-pro' ),
			'general'         => __( "Thanks for getting in touch! We'll respond to your message shortly.", 'mcp-ai-wpoos-pro' ),
		);
		$msg         = ! empty( $arguments['custom_message'] ) ? sanitize_textarea_field( $arguments['custom_message'] ) : ( $templates[ $intent ] ?? $templates['general'] );
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
			update_post_meta( $activity_id, 'disposition', 'auto_replied' ); }
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'auto_reply_sent',
				'lead',
				$lead_id,
				array(
					'intent'  => $intent,
					'channel' => $channel,
				)
			); }
		return array(
			'success'     => true,
			'message'     => __( 'Auto-reply sent.', 'mcp-ai-wpoos-pro' ),
			'lead_id'     => $lead_id,
			'reply'       => $msg,
			'activity_id' => $activity_id,
		);
	}
}
