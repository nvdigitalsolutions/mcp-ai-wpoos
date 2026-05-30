<?php
/** Check DNC Status — internal + filter-extensible federal/state DNC check. @package WP_MCP_AI_Pro @since 2.3.0 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class WP_MCP_AI_Tool_Check_Dnc_Status implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }
	public function get_slug() {
		return 'check_dnc_status'; }
	public function get_name() {
		return __( 'Check DNC Status', 'mcp-ai-wpoos-pro' ); }
	public function get_description() {
		return __( 'Check whether an identifier (email or phone) is on the Do Not Contact list.', 'mcp-ai-wpoos-pro' ); }
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'identifier' => array( 'type' => 'string' ),
				'channel'    => array(
					'type'    => 'string',
					'default' => 'all',
				),
			),
			'required'   => array( 'identifier' ),
		); }
	public function get_required_capability() {
		return 'edit_posts'; }
	public function requires_base_pro() {
		return true; }
	public function get_capability_flags() {
		return array( 'pro', 'database-read', 'requires-capability' ); }
	public function execute( array $arguments = array(), array $context = array() ) {
		$id = strtolower( trim( sanitize_text_field( $arguments['identifier'] ) ) );
		$ch = sanitize_key( $arguments['channel'] ?? 'all' );
		if ( ! class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			return new WP_Error( 'engine_missing', __( 'CRM Engine not available.', 'mcp-ai-wpoos-pro' ) ); }
		$blocked = WP_MCP_AI_CRM_Engine::check_dnc( $id, $ch );
		// Allow external DNC sources via filter.
		$external = apply_filters( 'wp_mcp_ai_crm_dnc_lists', false, $id, $ch );
		return array(
			'success'    => true,
			'identifier' => $id,
			'channel'    => $ch,
			'is_blocked' => $blocked || $external,
			'source'     => $external ? 'external' : ( $blocked ? 'internal' : 'none' ),
			'message'    => ( $blocked || $external ) ? __( 'Identifier is blocked.', 'mcp-ai-wpoos-pro' ) : __( 'Identifier is not blocked.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
