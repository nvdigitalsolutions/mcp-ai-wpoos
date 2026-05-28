<?php
/** Classify Message Intent — delegates to WP_MCP_AI_CRM_Classifier. @package WP_MCP_AI_Pro @since 2.3.0 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class WP_MCP_AI_Tool_Classify_Message_Intent implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }
	public function get_slug() {
		return 'classify_message_intent'; }
	public function get_name() {
		return __( 'Classify Message Intent', 'mcp-ai-wpoos-pro' ); }
	public function get_description() {
		return __( 'Classify an inbound message for intent, sentiment, buying signals, and spam probability.', 'mcp-ai-wpoos-pro' ); }
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'message_body' => array( 'type' => 'string' ),
				'channel'      => array(
					'type'    => 'string',
					'default' => 'email',
				),
			),
			'required'   => array( 'message_body' ),
		); }
	public function get_required_capability() {
		return 'edit_posts'; }
	public function requires_base_pro() {
		return true; }
	public function get_capability_flags() {
		return array( 'pro', 'database-read', 'requires-capability' ); }
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'unavailable', self::get_unavailable_reason() ); }
		if ( ! class_exists( 'WP_MCP_AI_CRM_Classifier' ) ) {
			return new WP_Error( 'classifier_missing', __( 'CRM Classifier engine not available.', 'mcp-ai-wpoos-pro' ) ); }
		$body    = sanitize_textarea_field( $arguments['message_body'] );
		$channel = sanitize_key( $arguments['channel'] ?? 'email' );
		$result  = WP_MCP_AI_CRM_Classifier::classify( $body, $channel );
		if ( is_wp_error( $result ) ) {
			return $result; }
		return array(
			'success'        => true,
			'classification' => $result,
		);
	}
}
