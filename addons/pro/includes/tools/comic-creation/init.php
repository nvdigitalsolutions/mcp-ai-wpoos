<?php
/**
 * Comic Creation Toolkit Initialization
 *
 * Loads the Comic Creation Toolkit system for AI-powered comic book
 * creation including script generation, panel-by-panel image creation,
 * character consistency management, layout assembly, and CBZ export.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load Comic CPT class.
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-comic-cpt.php';

// Load Comic Panel CPT class.
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-comic-panel-cpt.php';

// Load Comic Character CPT class.
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-comic-character-cpt.php';

// Load Comic Script CPT class.
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-comic-script-cpt.php';

// Load Comic Creation admin pages (always load so menu items appear).
if ( is_admin() ) {
	// Load CPT-based settings page.
	require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-comic-settings-page.php';
	new WP_MCP_AI_Comic_Settings_Page();

	// Load and initialize Research & Add page for comics.
	require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-comic-research-page.php';
	WP_MCP_AI_Comic_Research_Page::init();
}

// Check if Comic Creation toolkit is enabled for advanced features.
$settings   = get_option( 'wp_mcp_ai_settings', array() );
$is_enabled = ! empty( $settings['enable_comic_creation_toolkit'] );
$is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

// Only load advanced features if enabled and not in base version.
if ( $is_enabled && ! $is_base ) {
	// Load Research & Add for CCT/CPT integration.
	require_once WP_MCP_AI_PRO_PATH . 'includes/research-add/class-wp-mcp-ai-comic-research-add.php';
	new WP_MCP_AI_Comic_Research_Add();

	// Load Consolidate & Add page.
	require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-comic-consolidate-page.php';
	WP_MCP_AI_Comic_Consolidate_Page::init();

	// Register tools will be loaded automatically via the tools directory structure.
	// Tools are located in: addons/pro/includes/tools/comic-creation/.
}

// Initialize CPTs (always register so admin menu items are visible).
add_action(
	'init',
	function () {
		WP_MCP_AI_Comic_CPT::init();
		WP_MCP_AI_Comic_Panel_CPT::init();
		WP_MCP_AI_Comic_Character_CPT::init();
		WP_MCP_AI_Comic_Script_CPT::init();
	},
	5
);

/**
 * Enqueue comic creation toolkit admin styles.
 *
 * @since 2.0.0
 *
 * @param string $hook Current admin page hook (unused).
 */
function wp_mcp_ai_enqueue_comic_creation_toolkit_admin_styles( $hook ) {
	// Only load if toolkit is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_comic_creation_toolkit'] ) ) {
		return;
	}

	// Enqueue admin styles if available.
	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-comic-creation-toolkit.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-comic-creation-toolkit-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-comic-creation-toolkit.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_comic_creation_toolkit_admin_styles' );
