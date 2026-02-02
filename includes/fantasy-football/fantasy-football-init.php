<?php
/**
 * Fantasy Football Module Initialization
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load Fantasy Team CPT.
require_once WP_MCP_AI_PATH . 'includes/fantasy-football/class-wp-mcp-ai-fantasy-team-cpt.php';

// Load Fantasy Football Settings (admin only).
if ( is_admin() ) {
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-fantasy-football-settings.php';
}

/**
 * Initialize Fantasy Football module.
 */
function wp_mcp_ai_init_fantasy_football() {
	// Initialize Fantasy Team CPT.
	new WP_MCP_AI_Fantasy_Team_CPT();

	// Initialize settings page (admin only).
	if ( is_admin() ) {
		new WP_MCP_AI_Fantasy_Football_Settings();
	}
}

add_action( 'plugins_loaded', 'wp_mcp_ai_init_fantasy_football' );
