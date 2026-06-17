<?php
/** Detect Buying Signals — keyword + filter-extensible signal detection. @package WP_MCP_AI_Pro @since 2.3.0 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class WP_MCP_AI_Tool_Detect_Buying_Signals implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }
	public function get_slug() {
		return 'detect_buying_signals'; }
	public function get_name() {
		return __( 'Detect Buying Signals', 'mcp-ai-wpoos-pro' ); }
	public function get_description() {
		return __( 'Detect buying-intent keywords and phrases in a message body.', 'mcp-ai-wpoos-pro' ); }
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array( 'message_body' => array( 'type' => 'string' ) ),
			'required'   => array( 'message_body' ),
		); }
	public function get_required_capability() {
		return 'edit_posts'; }
	public function requires_base_pro() {
		return true; }
	public function get_capability_flags() {
		return array( 'pro', 'database-read', 'requires-capability' ); }
	public function execute( array $arguments = array(), array $context = array() ) {
		$body       = sanitize_textarea_field( $arguments['message_body'] );
		$lower      = mb_strtolower( $body );
		$default_kw = array( 'pricing', 'demo', 'next step', 'timeline', 'budget', 'decision maker', 'authority', 'trial', 'competing', 'competitor', 'implement', 'rollout', 'buy', 'purchase', 'sign', 'urgent', 'asap' );
		$kw         = apply_filters( 'wp_mcp_ai_crm_buying_signal_keywords', $default_kw );
		$signals    = array();
		foreach ( $kw as $k ) {
			if ( false !== strpos( $lower, $k ) ) {
				$signals[] = $k; }
		}
		$signals = array_unique( $signals );
		$hot     = count( $signals ) >= 3;
		return array(
			'success'        => true,
			'buying_signals' => $signals,
			'signal_count'   => count( $signals ),
			'is_hot'         => $hot,
			'message'        => $hot ? __( 'Multiple buying signals detected — lead appears hot.', 'mcp-ai-wpoos-pro' ) : __( 'Buying signal scan complete.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
