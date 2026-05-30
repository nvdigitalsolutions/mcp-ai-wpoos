<?php
/**
 * Video Production Toolkit Initialization
 *
 * Loads the Video Production Toolkit system for video creation,
 * editing, processing, and optimization for content creators
 * and marketers.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check if Video Production toolkit is enabled.
$settings   = get_option( 'wp_mcp_ai_settings', array() );
$is_enabled = ! empty( $settings['enable_video_production_toolkit'] );
$is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

// Only load if enabled and not in base version.
if ( $is_enabled && ! $is_base ) {

	// Load Video Production admin pages.
	if ( is_admin() ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-video-production-settings-page.php';
	}

	// Load Research & Add for CCT/CPT integration.
	require_once WP_MCP_AI_PRO_PATH . 'includes/research-add/class-wp-mcp-ai-video-production-research-add.php';
	new WP_MCP_AI_Video_Production_Research_Add();

	// Register tools will be loaded automatically via the tools directory structure.
	// Tools are located in: addons/pro/includes/tools/video-production/.
}

/**
 * Enqueue video production toolkit admin styles.
 *
 * @param string $hook Current admin page hook.
 */
function wp_mcp_ai_enqueue_video_production_toolkit_admin_styles( $hook ) {
	// Only load if toolkit is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_video_production_toolkit'] ) ) {
		return;
	}

	// Enqueue admin styles if available.
	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-video-production-toolkit.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-video-production-toolkit-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-video-production-toolkit.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_video_production_toolkit_admin_styles' );
