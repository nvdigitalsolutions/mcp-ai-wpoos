<?php
/**
 * Meta Integration Initialization
 *
 * Loads Meta OAuth handler and sets up integration hooks.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load Meta OAuth handler.
require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-meta-oauth-handler.php';

/**
 * Initialize Meta integration.
 */
function wp_mcp_ai_init_meta_integration() {
	static $meta_handler = null;

	if ( null === $meta_handler ) {
		$meta_handler = new WP_MCP_AI_Meta_OAuth_Handler();
	}

	return $meta_handler;
}

// Set up Meta OAuth hooks.
$meta_handler = wp_mcp_ai_init_meta_integration();
add_action( 'admin_post_wp_mcp_ai_meta_oauth_start', array( $meta_handler, 'handle_meta_oauth_start' ) );
add_action( 'admin_post_wp_mcp_ai_meta_oauth_callback', array( $meta_handler, 'handle_meta_oauth_callback' ) );
add_action( 'admin_post_wp_mcp_ai_meta_disconnect', array( $meta_handler, 'handle_meta_disconnect' ) );
add_filter( 'allowed_redirect_hosts', array( $meta_handler, 'allow_meta_oauth_redirect_host' ), 10, 2 );
