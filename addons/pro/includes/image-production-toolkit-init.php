<?php
/**
 * Image Production Toolkit Initialization
 *
 * Loads the Image Production Toolkit system for AI-powered image generation,
 * editing, enhancement, and optimization.
 *
 * Phase 2.8 - Implementation Complete
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load Image Template CPT class.
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-image-template-cpt.php';

// Check if Image Production toolkit is enabled.
$settings   = get_option( 'wp_mcp_ai_settings', array() );
$is_enabled = ! empty( $settings['enable_image_production_toolkit'] );
$is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

// Only load if enabled and not in base version.
if ( $is_enabled && ! $is_base ) {

	// Load Image Production admin pages.
	if ( is_admin() ) {
		// Load new CPT-based settings page (under Media menu).
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-image-production-cpt-settings-page.php';
		
		// Load Research & Add page for AI-assisted template creation.
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-image-template-research-page.php';
	}

	// Load Research & Add for CCT/CPT integration.
	require_once WP_MCP_AI_PRO_PATH . 'includes/research-add/class-wp-mcp-ai-image-production-research-add.php';
	new WP_MCP_AI_Image_Production_Research_Add();

	// Tools are loaded via the standard tool loading mechanism.
	// Location: addons/pro/includes/tools/image-production/.
}

// Initialize Image Template CPT.
add_action(
	'init',
	function () {
		WP_MCP_AI_Image_Template_CPT::init();
	},
	5
);

/**
 * Enqueue image production toolkit admin styles.
 *
 * @since 1.1.0
 *
 * @param string $hook Current admin page hook (unused).
 */
function wp_mcp_ai_enqueue_image_production_toolkit_admin_styles( $hook ) {
	// Only load if toolkit is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_image_production_toolkit'] ) ) {
		return;
	}

	// Enqueue admin styles if available.
	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-image-production-toolkit.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-image-production-toolkit-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-image-production-toolkit.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_image_production_toolkit_admin_styles' );
