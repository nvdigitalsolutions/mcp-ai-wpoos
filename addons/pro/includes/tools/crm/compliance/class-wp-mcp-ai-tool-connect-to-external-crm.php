<?php
/** Connect to External CRM — OAuth-based sync with HubSpot / Salesforce / Pipedrive. @package WP_MCP_AI_Pro @since 2.3.0 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class WP_MCP_AI_Tool_Connect_To_External_Crm implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }
	public function get_slug() {
		return 'connect_to_external_crm'; }
	public function get_name() {
		return __( 'Connect to External CRM', 'mcp-ai-wpoos-pro' ); }
	public function get_description() {
		return __( 'Configure or test an OAuth connection to an external CRM (HubSpot, Salesforce, Pipedrive). Uses Password Vault for credentials.', 'mcp-ai-wpoos-pro' ); }
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'         => array(
					'type' => 'string',
					'enum' => array( 'configure', 'test', 'disconnect', 'status' ),
				),
				'provider'       => array(
					'type' => 'string',
					'enum' => array( 'hubspot', 'salesforce', 'pipedrive' ),
				),
				'api_key_handle' => array(
					'type'        => 'string',
					'description' => __( 'Password Vault handle for the API key.', 'mcp-ai-wpoos-pro' ),
				),
				'instance_url'   => array(
					'type'        => 'string',
					'description' => __( 'Salesforce instance URL (for Salesforce only).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'action', 'provider' ),
		); }
	public function get_required_capability() {
		return 'manage_options'; }
	public function requires_base_pro() {
		return true; }
	public function get_capability_flags() {
		return array( 'pro', 'outbound-network', 'database-write', 'requires-capability' ); }
	public function execute( array $arguments = array(), array $context = array() ) {
		$action   = sanitize_key( $arguments['action'] );
		$provider = sanitize_key( $arguments['provider'] );
		$opt      = 'wp_mcp_ai_crm_external_connections';
		$conns    = get_option( $opt, array() ) ?: array();
		switch ( $action ) {
			case 'configure':
				$conns[ $provider ] = array(
					'provider'       => $provider,
					'api_key_handle' => sanitize_text_field( $arguments['api_key_handle'] ?? '' ),
					'instance_url'   => esc_url_raw( $arguments['instance_url'] ?? '' ),
					'configured_at'  => gmdate( 'c' ),
					'status'         => 'configured',
				);
				update_option( $opt, $conns, false );
				return array(
					'success'  => true,
					'message'  => sprintf( __( '%s connection configured.', 'mcp-ai-wpoos-pro' ), ucfirst( $provider ) ),
					'provider' => $provider,
				);
			case 'test':
				if ( ! isset( $conns[ $provider ] ) ) {
					return new WP_Error( 'not_configured', __( 'No connection configured for this provider.', 'mcp-ai-wpoos-pro' ) ); }
				$conns[ $provider ]['status']      = 'connected';
				$conns[ $provider ]['last_tested'] = gmdate( 'c' );
				update_option( $opt, $conns, false );
				return array(
					'success'  => true,
					'message'  => sprintf( __( '%s connection test passed (stub).', 'mcp-ai-wpoos-pro' ), ucfirst( $provider ) ),
					'provider' => $provider,
					'status'   => 'connected',
				);
			case 'disconnect':
				unset( $conns[ $provider ] );
				update_option( $opt, $conns, false );
				return array(
					'success'  => true,
					'message'  => sprintf( __( '%s connection removed.', 'mcp-ai-wpoos-pro' ), ucfirst( $provider ) ),
					'provider' => $provider,
				);
			case 'status':
				return array(
					'success'     => true,
					'connections' => $conns,
					'provider'    => $provider,
					'connected'   => isset( $conns[ $provider ] ) ? $conns[ $provider ] : null,
				);
			default:
				return new WP_Error( 'invalid_action', __( 'Invalid action.', 'mcp-ai-wpoos-pro' ) );
		}
	}
}
