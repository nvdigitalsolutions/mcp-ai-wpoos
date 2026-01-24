<?php
/**
 * Site Kit Integration Initialization
 *
 * Initializes the Google Site Kit integration.
 *
 * @package    WP_MCP_AI
 * @subpackage Integrations
 * @since      1.2.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize Site Kit integration
 *
 * This function is called during plugin initialization to set up
 * the Google Site Kit integration.
 *
 * @since 1.2.0
 */
function wp_mcp_ai_init_sitekit_integration() {
	// Check if Site Kit plugin is active.
	if ( ! class_exists( 'Google\\Site_Kit\\Plugin' ) ) {
		return;
	}

	// Load integration class if not already loaded.
	if ( ! class_exists( 'WP_MCP_AI_SiteKit_Integration' ) ) {
		require_once WP_MCP_AI_PLUGIN_DIR . 'includes/integrations/class-wp-mcp-ai-sitekit-integration.php';
	}

	// Initialize integration.
	WP_MCP_AI_SiteKit_Integration::get_instance();
}

// Hook into plugin initialization.
add_action( 'wp_mcp_ai_init', 'wp_mcp_ai_init_sitekit_integration', 20 );
