<?php
/**
 * Google Drive Integration Initialization
 *
 * Loads Google Drive OAuth handler and sets up integration hooks.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load Google Drive OAuth handler.
require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-google-drive-oauth-handler.php';

/**
 * Initialize Google Drive integration.
 */
function wp_mcp_ai_init_google_drive_integration() {
	static $google_drive_handler = null;

	if ( null === $google_drive_handler ) {
		$google_drive_handler = new WP_MCP_AI_Google_Drive_OAuth_Handler();
	}

	return $google_drive_handler;
}

// Set up Google Drive OAuth hooks.
$google_drive_handler = wp_mcp_ai_init_google_drive_integration();
add_action( 'admin_post_wp_mcp_ai_google_drive_oauth_start', array( $google_drive_handler, 'handle_google_drive_oauth_start' ) );
add_action( 'admin_post_wp_mcp_ai_google_drive_oauth_callback', array( $google_drive_handler, 'handle_google_drive_oauth_callback' ) );
add_filter( 'allowed_redirect_hosts', array( $google_drive_handler, 'allow_google_drive_oauth_redirect_host' ), 10, 2 );
