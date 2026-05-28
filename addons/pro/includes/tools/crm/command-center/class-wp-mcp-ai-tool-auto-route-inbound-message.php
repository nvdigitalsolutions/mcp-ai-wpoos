<?php
/** Auto-Route Inbound Message — workload-aware routing. @package WP_MCP_AI_Pro @since 2.3.0 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class WP_MCP_AI_Tool_Auto_Route_Inbound_Message implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }
	public function get_slug() {
		return 'auto_route_inbound_message'; }
	public function get_name() {
		return __( 'Auto-Route Inbound Message', 'mcp-ai-wpoos-pro' ); }
	public function get_description() {
		return __( 'Automatically assign a new lead to the best owner using the configured routing strategy and workload.', 'mcp-ai-wpoos-pro' ); }
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'lead_id'  => array( 'type' => 'integer' ),
				'strategy' => array(
					'type'    => 'string',
					'enum'    => array( 'auto', 'round_robin', 'weighted' ),
					'default' => 'auto',
				),
			),
			'required'   => array( 'lead_id' ),
		); }
	public function get_required_capability() {
		return 'edit_posts'; }
	public function requires_base_pro() {
		return true; }
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'requires-capability' ); }
	public function execute( array $arguments = array(), array $context = array() ) {
		$lead_id = absint( $arguments['lead_id'] );
		$lp      = get_post( $lead_id );
		if ( ! $lp ) {
			return new WP_Error( 'not_found', __( 'Lead not found.', 'mcp-ai-wpoos-pro' ) ); }
		if ( ! class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			return new WP_Error( 'engine_missing', __( 'CRM Engine not available.', 'mcp-ai-wpoos-pro' ) ); }
		$owner = WP_MCP_AI_CRM_Engine::get_next_owner();
		if ( ! $owner ) {
			return new WP_Error( 'no_owner', __( 'No owner available in routing pool.', 'mcp-ai-wpoos-pro' ) ); }
		$prev = get_post_meta( $lead_id, 'contact_owner', true );
		update_post_meta( $lead_id, 'contact_owner', $owner );
		$u = get_userdata( $owner );
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'lead_auto_routed',
				'lead',
				$lead_id,
				array(
					'previous_owner' => $prev,
					'new_owner'      => $owner,
				)
			); }
		return array(
			'success'        => true,
			'message'        => sprintf( __( 'Lead routed to %s.', 'mcp-ai-wpoos-pro' ), $u ? $u->display_name : (string) $owner ),
			'lead_id'        => $lead_id,
			'owner_id'       => $owner,
			'owner_name'     => $u ? $u->display_name : '',
			'previous_owner' => $prev,
		);
	}
}
