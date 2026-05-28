<?php
/** Get Consent Audit — full audit trail for regulator inspection or DSAR. @package WP_MCP_AI_Pro @since 2.3.0 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class WP_MCP_AI_Tool_Get_Consent_Audit implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }
	public function get_slug() {
		return 'get_consent_audit'; }
	public function get_name() {
		return __( 'Get Consent Audit', 'mcp-ai-wpoos-pro' ); }
	public function get_description() {
		return __( 'Retrieve full consent audit trail for a contact — consent records + audit log entries. Suitable for DSAR / regulator inspection.', 'mcp-ai-wpoos-pro' ); }
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array( 'contact_id' => array( 'type' => 'integer' ) ),
			'required'   => array( 'contact_id' ),
		); }
	public function get_required_capability() {
		return 'manage_options'; }
	public function requires_base_pro() {
		return true; }
	public function get_capability_flags() {
		return array( 'pro', 'database-read', 'requires-capability', 'pii-access' ); }
	public function execute( array $arguments = array(), array $context = array() ) {
		$cid = absint( $arguments['contact_id'] );
		if ( ! class_exists( 'WP_MCP_AI_CRM_Consent' ) ) {
			return new WP_Error( 'engine_missing', __( 'CRM Consent engine not available.', 'mcp-ai-wpoos-pro' ) ); }
		$audit = WP_MCP_AI_CRM_Consent::get_consent_audit( $cid );
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record( 'consent_audit_viewed', 'contact', $cid ); }
		return array(
			'success'         => true,
			'contact_id'      => $cid,
			'consent_records' => $audit['consent_records'],
			'audit_entries'   => $audit['audit_entries'],
			'message'         => sprintf( __( 'Audit trail retrieved: %1$d consent records, %2$d audit entries.', 'mcp-ai-wpoos-pro' ), count( $audit['consent_records'] ), count( $audit['audit_entries'] ) ),
		);
	}
}
