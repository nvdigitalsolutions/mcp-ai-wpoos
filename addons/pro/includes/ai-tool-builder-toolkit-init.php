<?php
/**
 * AI Tool Builder Toolkit Initialization
 *
 * Loads the AI Tool Builder Toolkit - a meta-toolkit for creating custom AI tools
 * with scaffolding, code generation, testing, and documentation capabilities.
 *
 * Phase 2.9 - Planned Implementation
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check if AI Tool Builder toolkit is enabled.
$settings   = get_option( 'wp_mcp_ai_settings', array() );
$is_enabled = ! empty( $settings['enable_ai_tool_builder_toolkit'] );
$is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

// Only load if enabled and not in base version.
if ( $is_enabled && ! $is_base ) {

	// Load AI Tool Builder admin pages.
	if ( is_admin() ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-ai-tool-builder-settings-page.php';
	}

	// Tools will be implemented in Phase 2.9.
	// Planned location: addons/pro/includes/tools/ai-tool-builder/.
}

/**
 * Enqueue AI tool builder toolkit admin styles.
 *
 * @since 1.1.0
 *
 * @param string $hook Current admin page hook (unused).
 */
function wp_mcp_ai_enqueue_ai_tool_builder_toolkit_admin_styles( $hook ) {
	// Only load if toolkit is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_ai_tool_builder_toolkit'] ) ) {
		return;
	}

	// Enqueue admin styles if available.
	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-ai-tool-builder-toolkit.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-ai-tool-builder-toolkit-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-ai-tool-builder-toolkit.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_ai_tool_builder_toolkit_admin_styles' );
