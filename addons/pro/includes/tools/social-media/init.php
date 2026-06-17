<?php
/**
 * Social Media Management Toolkit Initialization
 *
 * Loads the Social Media Toolkit system for multi-platform posting,
 * scheduling, analytics, and engagement management across major
 * social media platforms.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check if Social Media toolkit is enabled.
$settings   = get_option( 'wp_mcp_ai_settings', array() );
$is_enabled = ! empty( $settings['enable_social_media_toolkit'] );
$is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

// Only load if enabled and not in base version.
if ( $is_enabled && ! $is_base ) {

	// Load Social Media admin pages.
	if ( is_admin() ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-social-media-settings-page.php';
	}

	// Register tools will be loaded automatically via the tools directory structure.
	// Tools are located in: addons/pro/includes/tools/social-media/.

	// --- Performance optimization (CPT fix, cron handler, retention, autorespond cap) ---
	require_once WP_MCP_AI_PRO_PATH . 'includes/tools/social-media/class-wp-mcp-ai-social-media-optimization.php';
	WP_MCP_AI_Social_Media_Optimization::init();
}

/**
 * Enqueue social media toolkit admin styles.
 *
 * @param string $hook Current admin page hook.
 */
function wp_mcp_ai_enqueue_social_media_toolkit_admin_styles( $hook ) {
	// Only load if toolkit is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_social_media_toolkit'] ) ) {
		return;
	}

	// Enqueue admin styles if available.
	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-social-media-toolkit.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-social-media-toolkit-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-social-media-toolkit.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_social_media_toolkit_admin_styles' );
