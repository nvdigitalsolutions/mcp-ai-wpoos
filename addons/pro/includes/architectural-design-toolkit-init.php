<?php
/**
 * Architectural Design Toolkit Initialization
 *
 * Loads the Architectural Design Toolkit system for AI-powered floor plan generation,
 * 3D modeling, blueprint creation, code compliance, and cost estimation.
 *
 * Phase 2.10 - Implementation in Progress
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check if Architectural Design toolkit is enabled.
$settings   = get_option( 'wp_mcp_ai_settings', array() );
$is_enabled = ! empty( $settings['enable_architectural_design_toolkit'] );
$is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

// Only load if enabled and not in base version.
if ( $is_enabled && ! $is_base ) {

	// Load Architectural Design admin pages.
	if ( is_admin() ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-architectural-design-settings-page.php';
	}

	// Tools will be loaded via the standard tool loading mechanism when implemented.
	// Planned location: addons/pro/includes/tools/architectural-design/.
}

/**
 * Enqueue architectural design toolkit admin styles.
 *
 * @since 1.1.0
 *
 * @param string $hook Current admin page hook (unused).
 */
function wp_mcp_ai_enqueue_architectural_design_toolkit_admin_styles( $hook ) {
	// Only load if toolkit is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_architectural_design_toolkit'] ) ) {
		return;
	}

	// Enqueue admin styles if available.
	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-architectural-design-toolkit.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-architectural-design-toolkit-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-architectural-design-toolkit.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_architectural_design_toolkit_admin_styles' );
