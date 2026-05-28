<?php
/** Import CRM Blueprint — installs a curated assistant blueprint. @package WP_MCP_AI_Pro @since 2.3.0 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class WP_MCP_AI_Tool_Import_CRM_Blueprint implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public static function is_available() { $s = get_option( 'wp_mcp_ai_settings', array() ); return ! empty( $s['enable_crm_toolkit'] ); }
	public static function get_unavailable_reason() { return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }
	public function get_slug() { return 'import_crm_blueprint'; }
	public function get_name() { return __( 'Import CRM Blueprint', 'mcp-ai-wpoos-pro' ); }
	public function get_description() { return __( 'Install a curated CRM assistant blueprint for B2B SaaS, agency, real estate, or wholesale distribution workflows.', 'mcp-ai-wpoos-pro' ); }
	public function get_parameters_schema() { return array( 'type' => 'object', 'properties' => array( 'blueprint' => array( 'type' => 'string', 'enum' => array( 'b2b-saas-sdr', 'agency-account-manager', 'real-estate-buyer-agent', 'wholesale-distributor' ), 'description' => __( 'Blueprint to import.', 'mcp-ai-wpoos-pro' ) ) ), 'required' => array( 'blueprint' ) ); }
	public function get_required_capability() { return 'edit_posts'; }
	public function requires_base_pro() { return true; }
	public function get_capability_flags() { return array( 'pro', 'database-write', 'requires-capability' ); }
	public function execute( array $arguments = array(), array $context = array() ) {
		$bp = sanitize_key( $arguments['blueprint'] );
		$file = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/examples/' . $bp . '.json';
		if ( ! file_exists( $file ) ) { return new WP_Error( 'not_found', __( 'Blueprint file not found.', 'mcp-ai-wpoos-pro' ) ); }
		$json = file_get_contents( $file );
		$data = json_decode( $json, true );
		if ( ! $data ) { return new WP_Error( 'invalid_json', __( 'Blueprint file is invalid JSON.', 'mcp-ai-wpoos-pro' ) ); }
		$name = $data['name'] ?? ucwords( str_replace( '-', ' ', $bp ) );
		$existing = get_page_by_title( $name, OBJECT, 'mcp_ai_assistant' );
		if ( $existing && empty( $arguments['overwrite'] ) ) { return new WP_Error( 'duplicate', sprintf( __( 'An assistant named "%s" already exists. Set overwrite=true to replace.', 'mcp-ai-wpoos-pro' ), $name ) ); }
		$assistant_id = wp_insert_post( array( 'post_type' => 'mcp_ai_assistant', 'post_title' => $name, 'post_status' => 'publish', 'post_content' => $data['description'] ?? '' ), true );
		if ( is_wp_error( $assistant_id ) ) { return $assistant_id; }
		if ( ! empty( $data['meta'] ) ) { foreach ( $data['meta'] as $k => $v ) { update_post_meta( $assistant_id, sanitize_key( $k ), $v ); } }
		update_post_meta( $assistant_id, '_blueprint_source', $bp );
		return array( 'success' => true, 'message' => sprintf( __( 'Blueprint "%s" imported as assistant #%d.', 'mcp-ai-wpoos-pro' ), $name, $assistant_id ), 'blueprint' => $bp, 'assistant_id' => $assistant_id );
	}
}
