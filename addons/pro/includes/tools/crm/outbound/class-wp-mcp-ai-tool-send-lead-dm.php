<?php
/**
 * Send Lead DM — direct message via LinkedIn or chat-channels. @package WP_MCP_AI_Pro @since 2.3.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class WP_MCP_AI_Tool_Send_Lead_Dm implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public static function is_available() { $s = get_option( 'wp_mcp_ai_settings', array() ); return ! empty( $s['enable_crm_toolkit'] ); }
	public static function get_unavailable_reason() { return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }
	public function get_slug() { return 'send_lead_dm'; }
	public function get_name() { return __( 'Send Lead DM', 'mcp-ai-wpoos-pro' ); }
	public function get_description() { return __( 'Send a direct message via LinkedIn or existing chat-channels integration. Stub — returns instructions.', 'mcp-ai-wpoos-pro' ); }
	public function get_parameters_schema() { return array( 'type' => 'object', 'properties' => array( 'lead_id' => array( 'type' => 'integer' ), 'message' => array( 'type' => 'string' ), 'platform' => array( 'type' => 'string', 'enum' => array( 'linkedin', 'telegram', 'webchat' ), 'default' => 'linkedin' ) ), 'required' => array( 'lead_id', 'message' ) ); }
	public function get_required_capability() { return 'edit_posts'; }
	public function requires_base_pro() { return true; }
	public function get_capability_flags() { return array( 'pro', 'outbound-network', 'database-write', 'requires-capability' ); }
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) { return new WP_Error( 'unavailable', self::get_unavailable_reason() ); }
		$platform = sanitize_key( $arguments['platform'] ?? 'linkedin' );
		$lead_id  = absint( $arguments['lead_id'] );
		$activity_id = wp_insert_post( array( 'post_type' => 'mcp_ai_crm_activity', 'post_title' => sprintf( __( 'Sent %s DM', 'mcp-ai-wpoos-pro' ), ucfirst( $platform ) ), 'post_status' => 'publish' ), true );
		if ( ! is_wp_error( $activity_id ) ) { update_post_meta( $activity_id, 'activity_type', 'email' ); update_post_meta( $activity_id, 'related_type', 'lead' ); update_post_meta( $activity_id, 'related_id', $lead_id ); update_post_meta( $activity_id, 'disposition', 'dm_sent' ); }
		return array( 'success' => true, 'message' => sprintf( __( 'DM logged as activity (stub). Use the Chat Channels toolkit for %s delivery.', 'mcp-ai-wpoos-pro' ), $platform ), 'lead_id' => $lead_id, 'activity_id' => $activity_id );
	}
}
