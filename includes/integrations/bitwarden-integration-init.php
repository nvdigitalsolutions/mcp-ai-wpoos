<?php
/**
 * Bitwarden Integration Initialization
 *
 * Loads Bitwarden OAuth handler and sets up integration hooks.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load Bitwarden OAuth handler and client.
require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-bitwarden-oauth-handler.php';
require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-bitwarden-client.php';

/**
 * Initialize Bitwarden integration.
 */
function wp_mcp_ai_init_bitwarden_integration() {
	static $bitwarden_handler = null;

	if ( null === $bitwarden_handler ) {
		$bitwarden_handler = new WP_MCP_AI_Bitwarden_OAuth_Handler();
	}

	return $bitwarden_handler;
}

// Set up Bitwarden OAuth hooks.
$bitwarden_handler = wp_mcp_ai_init_bitwarden_integration();
add_action( 'admin_post_wp_mcp_ai_bitwarden_oauth_start', array( $bitwarden_handler, 'handle_bitwarden_oauth_start' ) );
add_action( 'admin_post_wp_mcp_ai_bitwarden_oauth_callback', array( $bitwarden_handler, 'handle_bitwarden_oauth_callback' ) );
add_action( 'admin_post_wp_mcp_ai_bitwarden_disconnect', array( $bitwarden_handler, 'handle_bitwarden_disconnect' ) );
add_filter( 'allowed_redirect_hosts', array( $bitwarden_handler, 'allow_bitwarden_oauth_redirect_host' ), 10, 2 );

// Note: Bitwarden tools (Vault Access, Store Credential, Organization Management) are
// part of the Pro addon and registered via addons/pro/mcp-ai-wpoos-pro.php
