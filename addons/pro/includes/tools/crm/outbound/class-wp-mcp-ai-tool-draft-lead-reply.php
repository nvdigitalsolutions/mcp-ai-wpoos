<?php
/** Draft Lead Reply — AI-assisted reply draft (does NOT send). @package WP_MCP_AI_Pro @since 2.3.0 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class WP_MCP_AI_Tool_Draft_Lead_Reply implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public static function is_available() { $s = get_option( 'wp_mcp_ai_settings', array() ); return ! empty( $s['enable_crm_toolkit'] ); }
	public static function get_unavailable_reason() { return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }
	public function get_slug() { return 'draft_lead_reply'; }
	public function get_name() { return __( 'Draft Lead Reply', 'mcp-ai-wpoos-pro' ); }
	public function get_description() { return __( 'Generate an AI-assisted reply draft for a lead message. Does NOT send — returns the draft for review.', 'mcp-ai-wpoos-pro' ); }
	public function get_parameters_schema() { return array( 'type' => 'object', 'properties' => array( 'lead_id' => array( 'type' => 'integer' ), 'incoming_message' => array( 'type' => 'string', 'description' => __( 'The message you are replying to.', 'mcp-ai-wpoos-pro' ) ), 'channel' => array( 'type' => 'string', 'default' => 'email' ), 'tone' => array( 'type' => 'string', 'enum' => array( 'friendly', 'professional', 'concise', 'urgent' ), 'default' => 'professional' ) ), 'required' => array( 'incoming_message' ) ); }
	public function get_required_capability() { return 'edit_posts'; }
	public function requires_base_pro() { return true; }
	public function get_capability_flags() { return array( 'pro', 'database-read', 'requires-capability' ); }
	public function execute( array $arguments = array(), array $context = array() ) {
		$incoming = sanitize_textarea_field( $arguments['incoming_message'] );
		$tone     = sanitize_key( $arguments['tone'] ?? 'professional' );
		$channel  = sanitize_key( $arguments['channel'] ?? 'email' );
		// Template-based draft — production would use LLM provider.
		$templates = array(
			'friendly'     => __( "Hi there!\n\nThanks so much for reaching out — we really appreciate it. I'd love to help with what you're looking for.\n\nLet's set up a quick call this week to discuss. What time works best for you?\n\nBest,\n[Your Name]", 'mcp-ai-wpoos-pro' ),
			'professional' => __( "Hello,\n\nThank you for your inquiry. I would be happy to provide more information and address any questions you may have.\n\nWould you be available for a brief call this week to discuss further?\n\nKind regards,\n[Your Name]", 'mcp-ai-wpoos-pro' ),
			'concise'      => __( "Thanks for reaching out. Happy to help — when would be a good time to connect?\n\nBest,\n[Your Name]", 'mcp-ai-wpoos-pro' ),
			'urgent'       => __( "Hi,\n\nI saw your message and want to make sure we respond quickly. Let's connect ASAP — are you available today?\n\nBest,\n[Your Name]", 'mcp-ai-wpoos-pro' ),
		);
		$draft = $templates[ $tone ] ?? $templates['professional'];
		return array( 'success' => true, 'draft' => $draft, 'tone' => $tone, 'channel' => $channel, 'message' => __( 'Reply draft generated. Review before sending.', 'mcp-ai-wpoos-pro' ) );
	}
}
