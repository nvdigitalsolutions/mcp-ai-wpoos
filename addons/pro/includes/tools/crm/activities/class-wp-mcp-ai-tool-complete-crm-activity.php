<?php
/**
 * Complete CRM Activity Tool
 * @package WP_MCP_AI_Pro @since 2.3.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class WP_MCP_AI_Tool_Complete_CRM_Activity implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }
	public function get_slug() {
		return 'complete_crm_activity'; }
	public function get_name() {
		return __( 'Complete CRM Activity', 'mcp-ai-wpoos-pro' ); }
	public function get_description() {
		return __( 'Mark a CRM activity as completed.', 'mcp-ai-wpoos-pro' ); }
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'activity_id' => array( 'type' => 'integer' ),
				'outcome'     => array( 'type' => 'string' ),
			),
			'required'   => array( 'activity_id' ),
		);
	}
	public function get_required_capability() {
		return 'edit_posts'; }
	public function requires_base_pro() {
		return true; }
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'requires-capability' ); }

	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'unavailable', self::get_unavailable_reason() ); }
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ); }

		$id = absint( $arguments['activity_id'] );
		$p  = get_post( $id );
		if ( ! $p || 'mcp_ai_crm_activity' !== $p->post_type ) {
			return new WP_Error( 'not_found', __( 'Activity not found.', 'mcp-ai-wpoos-pro' ) ); }

		update_post_meta( $id, 'completed', '1' );
		update_post_meta( $id, 'completed_at', gmdate( 'c' ) );
		if ( ! empty( $arguments['outcome'] ) ) {
			update_post_meta( $id, 'outcome', sanitize_textarea_field( $arguments['outcome'] ) );
		}

		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record( 'activity_completed', 'activity', $id ); }

		return array(
			'success'     => true,
			'message'     => __( 'Activity marked as completed.', 'mcp-ai-wpoos-pro' ),
			'activity_id' => $id,
		);
	}
}
