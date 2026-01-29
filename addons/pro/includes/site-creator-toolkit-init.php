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
	require_once $tools_dir . 'class-wp-mcp-ai-tool-suggest-template-patterns.php';

	// Page Building Tools.
	require_once $tools_dir . 'class-wp-mcp-ai-tool-generate-landing-page.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-create-homepage-layout.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-build-about-page.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-create-service-pages.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-generate-blog-layout.php';

	// Section Building Tools.
	require_once $tools_dir . 'class-wp-mcp-ai-tool-create-hero-section.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-generate-feature-section.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-build-testimonial-section.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-create-cta-section.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-generate-gallery-section.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-build-contact-section.php';

	// Widget Building Tools.
	require_once $tools_dir . 'class-wp-mcp-ai-tool-create-custom-widget.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-build-navigation-menu.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-generate-sidebar-widget.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-create-footer-widget.php';

	// Template Management Tools.
	require_once $tools_dir . 'class-wp-mcp-ai-tool-save-site-template.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-import-site-template.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-export-template-kit.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-manage-template-versions.php';

	// Integration Tools.
	require_once $tools_dir . 'class-wp-mcp-ai-tool-integrate-with-architect.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-scaffold-theme-structure.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-automate-development-workflow.php';

	// All tools loaded successfully.
	// require_once $tools_dir . 'class-wp-mcp-ai-tool-suggest-template-patterns.php'.

	// Get registry and register tools.
	$registry = wp_mcp_ai_get_tool_registry();

	if ( $registry ) {
		// Research & Discovery.
		$registry->register( new WP_MCP_AI_Tool_Research_Site_Best_Practices() );
		$registry->register( new WP_MCP_AI_Tool_Analyze_Competitor_Sites() );
		$registry->register( new WP_MCP_AI_Tool_Generate_Site_Plan() );

		// Page Building.
		$registry->register( new WP_MCP_AI_Tool_Generate_Landing_Page() );
		$registry->register( new WP_MCP_AI_Tool_Create_Homepage_Layout() );
		$registry->register( new WP_MCP_AI_Tool_Build_About_Page() );
		$registry->register( new WP_MCP_AI_Tool_Create_Service_Pages() );
		$registry->register( new WP_MCP_AI_Tool_Generate_Blog_Layout() );

		// Section Building.
		$registry->register( new WP_MCP_AI_Tool_Create_Hero_Section() );
		$registry->register( new WP_MCP_AI_Tool_Generate_Feature_Section() );
		$registry->register( new WP_MCP_AI_Tool_Build_Testimonial_Section() );
		$registry->register( new WP_MCP_AI_Tool_Create_CTA_Section() );
		$registry->register( new WP_MCP_AI_Tool_Generate_Gallery_Section() );
		$registry->register( new WP_MCP_AI_Tool_Build_Contact_Section() );

		// Widget Building.
		$registry->register( new WP_MCP_AI_Tool_Create_Custom_Widget() );
		$registry->register( new WP_MCP_AI_Tool_Build_Navigation_Menu() );
		$registry->register( new WP_MCP_AI_Tool_Generate_Sidebar_Widget() );
		$registry->register( new WP_MCP_AI_Tool_Create_Footer_Widget() );

		// Template Management.
		$registry->register( new WP_MCP_AI_Tool_Save_Site_Template() );
		$registry->register( new WP_MCP_AI_Tool_Import_Site_Template() );
		$registry->register( new WP_MCP_AI_Tool_Export_Template_Kit() );
		$registry->register( new WP_MCP_AI_Tool_Manage_Template_Versions() );

		// Integration Tools.
		$registry->register( new WP_MCP_AI_Tool_Integrate_With_Architect() );
		$registry->register( new WP_MCP_AI_Tool_Scaffold_Theme_Structure() );
		$registry->register( new WP_MCP_AI_Tool_Automate_Development_Workflow() );

		// All 25 tools registered successfully (26 planned - 1 research tool reserved for future).
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
 * @param string $hook Current admin page hook.
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
