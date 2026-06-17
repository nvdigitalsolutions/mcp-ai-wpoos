<?php
/**
 * Cloudways Pro Toolkit Initialization
 *
 * Conditional loader for the Cloudways server/application management toolkit.
 * Gated behind the `enable_cloudways_toolkit` setting.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage Cloudways_Toolkit
 * @since      1.1.15
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the API v2 client and helpers (always needed when toolkit is enabled).
if ( ! class_exists( 'WP_MCP_AI_Cloudways_Client' ) ) {
	require_once WP_MCP_AI_PRO_PATH . 'includes/cloudways/class-wp-mcp-ai-cloudways-client.php';
}

// Register Cloudways disconnect admin-post handler.
add_action(
	'admin_post_wp_mcp_ai_cloudways_disconnect',
	function () {
		if ( class_exists( 'WP_MCP_AI_Cloudways_Client' ) ) {
			WP_MCP_AI_Cloudways_Client::instance()->handle_cloudways_disconnect();
		}
	}
);
if ( ! class_exists( 'WP_MCP_AI_Cloudways_Helpers' ) && ! function_exists( 'wp_mcp_ai_is_cloudways_toolkit_enabled' ) ) {
	require_once WP_MCP_AI_PRO_PATH . 'includes/cloudways/class-wp-mcp-ai-cloudways-helpers.php';
}

// Load the abstract tool base (required by all Cloudways tool classes).
if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Base' ) ) {
	require_once WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-base.php';
}

// Load Cloudways admin settings page when in admin area.
if ( is_admin() ) {
	if ( ! class_exists( 'WP_MCP_AI_Cloudways_Settings_Page' ) ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cloudways-settings-page.php';
		new WP_MCP_AI_Cloudways_Settings_Page();
	}
}

/**
 * Enqueue Cloudways toolkit admin styles.
 */
function wp_mcp_ai_enqueue_cloudways_toolkit_admin_styles() {
	if ( ! wp_mcp_ai_is_cloudways_toolkit_enabled() ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || false === strpos( $screen->id, 'cloudways' ) ) {
		return;
	}

	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-cloudways-toolkit.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-cloudways-toolkit-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-cloudways-toolkit.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_cloudways_toolkit_admin_styles' );
