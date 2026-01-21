<?php
/**
 * Advanced Analytics Toolkit Initialization
 *
 * Loads the Advanced Analytics Toolkit system for business intelligence,
 * predictive analytics, custom dashboards, and data export capabilities.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check if Advanced Analytics toolkit is enabled.
$settings   = get_option( 'wp_mcp_ai_settings', array() );
$is_enabled = ! empty( $settings['enable_analytics_toolkit'] );
$is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

// Only load if enabled and not in base version.
if ( $is_enabled && ! $is_base ) {

	// Load Analytics admin pages.
	if ( is_admin() ) {
		// @TODO: Create admin pages in future phase.
		// require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-analytics-settings-page.php';
	}

	// Register tools will be loaded automatically via the tools directory structure.
	// Tools are located in: addons/pro/includes/tools/analytics/
}

/**
 * Enqueue advanced analytics toolkit admin styles.
 *
 * @param string $hook Current admin page hook.
 */
function wp_mcp_ai_enqueue_analytics_toolkit_admin_styles( $hook ) {
	// Only load if toolkit is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_analytics_toolkit'] ) ) {
		return;
	}

	// Enqueue admin styles if available.
	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-analytics-toolkit.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-analytics-toolkit-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-analytics-toolkit.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_analytics_toolkit_admin_styles' );
