<?php
/** Pause / Resume / Exit Sequence — manage sequence enrollment state. @package WP_MCP_AI_Pro @since 2.3.0 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class WP_MCP_AI_Tool_Manage_Sequence_State implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }
	public function get_slug() {
		return 'manage_sequence_state'; }
	public function get_name() {
		return __( 'Manage Sequence State', 'mcp-ai-wpoos-pro' ); }
	public function get_description() {
		return __( 'Pause, resume, or exit a lead from their active sequence.', 'mcp-ai-wpoos-pro' ); }
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'lead_id' => array( 'type' => 'integer' ),
				'action'  => array(
					'type' => 'string',
					'enum' => array( 'pause', 'resume', 'exit' ),
				),
			),
			'required'   => array( 'lead_id', 'action' ),
		); }
	public function get_required_capability() {
		return 'edit_posts'; }
	public function requires_base_pro() {
		return true; }
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'requires-capability' ); }
	public function execute( array $arguments = array(), array $context = array() ) {
		$lead_id = absint( $arguments['lead_id'] );
		$action  = sanitize_key( $arguments['action'] );
		$lp      = get_post( $lead_id );
		if ( ! $lp ) {
			return new WP_Error( 'not_found', __( 'Lead not found.', 'mcp-ai-wpoos-pro' ) ); }
		switch ( $action ) {
			case 'pause':
				update_post_meta( $lead_id, '_sequence_paused', '1' );
				update_post_meta( $lead_id, '_sequence_paused_at', gmdate( 'c' ) );
				$msg = __( 'Sequence paused.', 'mcp-ai-wpoos-pro' );
				break;
			case 'resume':
				delete_post_meta( $lead_id, '_sequence_paused' );
				delete_post_meta( $lead_id, '_sequence_paused_at' );
				$msg = __( 'Sequence resumed.', 'mcp-ai-wpoos-pro' );
				break;
			case 'exit':
				delete_post_meta( $lead_id, '_active_sequence_id' );
				delete_post_meta( $lead_id, '_sequence_step' );
				delete_post_meta( $lead_id, '_sequence_paused' );
				update_post_meta( $lead_id, '_sequence_exited_at', gmdate( 'c' ) );
				$msg = __( 'Sequence exited.', 'mcp-ai-wpoos-pro' );
				break;
			default:
				return new WP_Error( 'invalid_action', __( 'Invalid action.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record( 'sequence_state_changed', 'sequence_enrollment', $lead_id, array( 'action' => $action ) ); }
		return array(
			'success' => true,
			'message' => $msg,
			'lead_id' => $lead_id,
			'action'  => $action,
		);
	}
}
