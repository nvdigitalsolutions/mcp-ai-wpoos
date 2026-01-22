<?php
/**
 * DJ Management Toolkit Initialization
 *
 * Loads the DJ Management Toolkit system for equipment tracking, playlist
 * management, event scheduling, client management, and music library organization.
 *
 * Phase 2.7 - Planned Implementation
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check if DJ Management toolkit is enabled.
$settings   = get_option( 'wp_mcp_ai_settings', array() );
$is_enabled = ! empty( $settings['enable_dj_management_toolkit'] );
$is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

// Only load if enabled and not in base version.
if ( $is_enabled && ! $is_base ) {

	// Load DJ Management admin pages.
	if ( is_admin() ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-dj-management-settings-page.php';
	}

	// Tools will be implemented in Phase 2.7.
	// Planned location: addons/pro/includes/tools/dj-management/.
}

/**
 * Enqueue DJ management toolkit admin styles.
 *
 * @since 1.1.0
 *
 * @param string $hook Current admin page hook (unused).
 */
function wp_mcp_ai_enqueue_dj_management_toolkit_admin_styles( $hook ) {
	// Only load if toolkit is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_dj_management_toolkit'] ) ) {
		return;
	}

	// Enqueue admin styles if available.
	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-dj-management-toolkit.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-dj-management-toolkit-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-dj-management-toolkit.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_dj_management_toolkit_admin_styles' );
