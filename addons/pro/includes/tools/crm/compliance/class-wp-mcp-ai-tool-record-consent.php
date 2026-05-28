<?php
/** Record Consent — channel-specific consent with legal basis and evidence. @package WP_MCP_AI_Pro @since 2.3.0 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class WP_MCP_AI_Tool_Record_Consent implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }
	public function get_slug() {
		return 'record_consent'; }
	public function get_name() {
		return __( 'Record Consent', 'mcp-ai-wpoos-pro' ); }
	public function get_description() {
		return __( 'Record a consent event for a contact on a specific channel with legal basis and evidence.', 'mcp-ai-wpoos-pro' ); }
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'contact_id'   => array( 'type' => 'integer' ),
				'channel'      => array(
					'type' => 'string',
					'enum' => WP_MCP_AI_CRM_Codes::CHANNELS,
				),
				'legal_basis'  => array(
					'type'    => 'string',
					'enum'    => array( 'consent', 'legitimate_interest', 'contractual_necessity', 'legal_obligation' ),
					'default' => 'consent',
				),
				'source'       => array(
					'type'    => 'string',
					'default' => 'web_form',
				),
				'evidence_url' => array( 'type' => 'string' ),
			),
			'required'   => array( 'contact_id', 'channel' ),
		); }
	public function get_required_capability() {
		return 'edit_posts'; }
	public function requires_base_pro() {
		return true; }
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'requires-capability', 'pii-access' ); }
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! class_exists( 'WP_MCP_AI_CRM_Consent' ) ) {
			return new WP_Error( 'engine_missing', __( 'CRM Consent engine not available.', 'mcp-ai-wpoos-pro' ) ); }
		$result = WP_MCP_AI_CRM_Consent::record( absint( $arguments['contact_id'] ), sanitize_key( $arguments['channel'] ), sanitize_key( $arguments['legal_basis'] ?? 'consent' ), sanitize_key( $arguments['source'] ?? 'web_form' ), esc_url_raw( $arguments['evidence_url'] ?? '' ) );
		if ( is_wp_error( $result ) ) {
			return $result; }
		return array(
			'success'    => true,
			'message'    => __( 'Consent recorded successfully.', 'mcp-ai-wpoos-pro' ),
			'contact_id' => absint( $arguments['contact_id'] ),
			'channel'    => sanitize_key( $arguments['channel'] ),
		);
	}
}
