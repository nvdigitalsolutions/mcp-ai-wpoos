<?php
/** Revoke Consent — cross-channel revocation with DNC propagation. @package WP_MCP_AI_Pro @since 2.3.0 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class WP_MCP_AI_Tool_Revoke_Consent implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }
	public function get_slug() {
		return 'revoke_consent'; }
	public function get_name() {
		return __( 'Revoke Consent', 'mcp-ai-wpoos-pro' ); }
	public function get_description() {
		return __( 'Revoke consent for one or all channels. Automatically propagates to DNC list and pauses active sequences. TCPA Apr 2025 FCC compliant.', 'mcp-ai-wpoos-pro' ); }
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'contact_id' => array( 'type' => 'integer' ),
				'channel'    => array(
					'type'        => 'string',
					'default'     => 'all',
					'description' => __( "'all' revokes every channel.", 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'contact_id' ),
		); }
	public function get_required_capability() {
		return 'manage_options'; }
	public function requires_base_pro() {
		return true; }
	public function get_capability_flags() {
		return array( 'pro', 'destructive', 'requires-capability', 'pii-access' ); }
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! class_exists( 'WP_MCP_AI_CRM_Consent' ) ) {
			return new WP_Error( 'engine_missing', __( 'CRM Consent engine not available.', 'mcp-ai-wpoos-pro' ) ); }
		$contact_id = absint( $arguments['contact_id'] );
		$channel    = sanitize_key( $arguments['channel'] ?? 'all' );
		$result     = WP_MCP_AI_CRM_Consent::revoke( $contact_id, $channel );
		if ( is_wp_error( $result ) ) {
			return $result; }
		// Also pause any active sequence for this contact.
		$active_seq = get_post_meta( $contact_id, '_active_sequence_id', true );
		if ( $active_seq ) {
			update_post_meta( $contact_id, '_sequence_paused', '1' );
			update_post_meta( $contact_id, '_sequence_paused_reason', 'consent_revoked' ); }
		return array(
			'success'         => true,
			'message'         => __( 'Consent revoked. Contact removed from active sequences and added to DNC list.', 'mcp-ai-wpoos-pro' ),
			'contact_id'      => $contact_id,
			'channel'         => $channel,
			'sequence_paused' => (bool) $active_seq,
		);
	}
}
