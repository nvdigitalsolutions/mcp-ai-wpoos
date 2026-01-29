<?php
/**
 * Site Creator Toolkit Initialization
 *
 * Loads the Site Creator Toolkit system with research tools, page/section/widget builders,
 * template management, and Architect Agent integration for automated development.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Only initialize in Pro version.
if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
	return;
}

/**
 * Check if Site Creator Toolkit should be loaded.
 *
 * Conditions:
 * 1. Must be enabled in settings
 * 2. Must not be in base version mode (unless Pro addon is active)
 */
if ( is_admin() ) {
	// Check settings.
	$settings   = get_option( 'wp_mcp_ai_settings', array() );
	$is_enabled = ! empty( $settings['enable_site_creator_toolkit'] );
	$is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

	// Load if enabled and not in base version mode.
	if ( $is_enabled && ! $is_base ) {

		// Load Site Creator admin pages.
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-site-creator-toolkit-settings-page.php';

		// Load Site Creator CPTs.
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-site-template-cpt.php';
	}
}

/**
 * Initialize site creator admin interface.
 *
 * @since 1.2.0
 */
function wp_mcp_ai_init_site_creator_admin() {
	// Skip if not in admin.
	if ( ! is_admin() ) {
		return;
	}

	// Check if site creator toolkit is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_site_creator_toolkit'] ) ) {
		return;
	}

	// Initialize CPTs if classes exist.
	if ( class_exists( 'WP_MCP_AI_Site_Template_CPT' ) ) {
		new WP_MCP_AI_Site_Template_CPT();
	}
}
add_action( 'admin_init', 'wp_mcp_ai_init_site_creator_admin' );

// Check if toolkit should be loaded for tools.
$settings   = get_option( 'wp_mcp_ai_settings', array() );
$is_enabled = ! empty( $settings['enable_site_creator_toolkit'] );

if ( $is_enabled ) {
	// Load Site Creator tools.
	add_action( 'wp_mcp_ai_load_pro_tools', 'wp_mcp_ai_load_site_creator_tools' );
}

/**
 * Load Site Creator toolkit tools.
 *
 * Registers all site creator tools including:
 * - Research & discovery tools (4)
 * - Page building tools (5)
 * - Section building tools (6)
 * - Widget building tools (4)
 * - Template management tools (4)
 * - Integration tools (3)
 *
 * @since 1.2.0
 */
function wp_mcp_ai_load_site_creator_tools() {
	$tools_dir = WP_MCP_AI_PRO_PATH . 'includes/tools/site-creator-toolkit/';

	// Research & Discovery Tools.
	require_once $tools_dir . 'class-wp-mcp-ai-tool-research-site-best-practices.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-analyze-competitor-sites.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-generate-site-plan.php';

	// Page Building Tools.
	require_once $tools_dir . 'class-wp-mcp-ai-tool-generate-landing-page.php';

	// TODO: Add more tools as they are implemented.
	// require_once $tools_dir . 'class-wp-mcp-ai-tool-suggest-template-patterns.php';

	// Get registry and register tools.
	$registry = wp_mcp_ai_get_tool_registry();

	if ( $registry ) {
		// Research & Discovery.
		$registry->register( new WP_MCP_AI_Tool_Research_Site_Best_Practices() );
		$registry->register( new WP_MCP_AI_Tool_Analyze_Competitor_Sites() );
		$registry->register( new WP_MCP_AI_Tool_Generate_Site_Plan() );

		// Page Building.
		$registry->register( new WP_MCP_AI_Tool_Generate_Landing_Page() );

		// Section Building - to be implemented.
		// Section Building - to be implemented.
		// Widget Building - to be implemented.
		// Template Management - to be implemented.
		// Integration Tools - to be implemented.
	}
}

/**
 * Enqueue site creator toolkit admin styles.
 *
 * @since 1.2.0
 *
 * @param string $hook Current admin page hook (unused).
 */
function wp_mcp_ai_enqueue_site_creator_toolkit_admin_styles( $hook ) {
	// Only load if toolkit is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_site_creator_toolkit'] ) ) {
		return;
	}

	// Enqueue admin styles if available.
	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-site-creator-toolkit.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-site-creator-toolkit-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-site-creator-toolkit.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_site_creator_toolkit_admin_styles' );
