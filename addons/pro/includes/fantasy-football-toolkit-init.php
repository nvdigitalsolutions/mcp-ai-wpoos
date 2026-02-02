<?php
/**
 * Fantasy Football Toolkit Initialization
 *
 * Initializes the Fantasy Football toolkit including CPT, settings page,
 * and research page. Only loads when FF toolkit is enabled in settings.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load Fantasy Team CPT.
require_once WP_MCP_AI_PRO_PATH . 'includes/fantasy-football/class-wp-mcp-ai-fantasy-team-cpt.php';

// Load Fantasy Football Settings page (admin only).
if ( is_admin() ) {
	require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-fantasy-football-settings.php';
	require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-fantasy-football-research-page.php';
}

/**
 * Initialize Fantasy Football toolkit.
 */
function wp_mcp_ai_init_fantasy_football_toolkit() {
	// Only initialize if fantasy football is enabled in settings.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_fantasy_football'] ) ) {
		return;
	}

	// Check if in Base Version without Pro addon.
	if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
		return;
	}

	// Initialize Fantasy Team CPT.
	WP_MCP_AI_Fantasy_Team_CPT::init();

	// Initialize settings and research pages (admin only).
	if ( is_admin() ) {
		new WP_MCP_AI_Fantasy_Football_Settings();
		WP_MCP_AI_Fantasy_Football_Research_Page::init();
	}
}

add_action( 'plugins_loaded', 'wp_mcp_ai_init_fantasy_football_toolkit', 20 );
