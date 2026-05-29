<?php
/** Delete Outreach Sequence @package WP_MCP_AI_Pro @since 2.3.0 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class WP_MCP_AI_Tool_Delete_Outreach_Sequence implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }
	public function get_slug() {
		return 'delete_outreach_sequence'; }
	public function get_name() {
		return __( 'Delete Outreach Sequence', 'mcp-ai-wpoos-pro' ); }
	public function get_description() {
		return __( 'Permanently remove an outreach sequence and its step definitions.', 'mcp-ai-wpoos-pro' ); }
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array( 'sequence_id' => array( 'type' => 'integer' ) ),
			'required'   => array( 'sequence_id' ),
		); }
	public function get_required_capability() {
		return 'manage_options'; }
	public function requires_base_pro() {
		return true; }
	public function get_capability_flags() {
		return array( 'pro', 'destructive', 'requires-capability' ); }
	public function execute( array $arguments = array(), array $context = array() ) {
		$id = absint( $arguments['sequence_id'] );
		$p  = get_post( $id );
		if ( ! $p || 'mcp_ai_sequence' !== $p->post_type ) {
			return new WP_Error( 'not_found', __( 'Sequence not found.', 'mcp-ai-wpoos-pro' ) ); }
		wp_trash_post( $id );
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record( 'sequence_deleted', 'sequence', $id ); }
		return array(
			'success'     => true,
			'message'     => __( 'Sequence deleted.', 'mcp-ai-wpoos-pro' ),
			'sequence_id' => $id,
		);
	}
}
