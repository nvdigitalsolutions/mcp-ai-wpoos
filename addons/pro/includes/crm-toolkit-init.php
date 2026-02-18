<?php
/**
 * CRM & Email Marketing Toolkit Initialization
 *
 * Loads the CRM & Email Marketing Toolkit system for contact management,
 * email campaigns, lead tracking, and customer relationship management.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check if CRM toolkit is enabled.
$settings   = get_option( 'wp_mcp_ai_settings', array() );
$is_enabled = ! empty( $settings['enable_crm_toolkit'] );
$is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

// Only load if enabled and not in base version.
if ( $is_enabled && ! $is_base ) {

	// Load Company CPT.
	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-company-cpt.php';
	WP_MCP_AI_Company_CPT::init();

	// Load CRM admin pages.
	if ( is_admin() ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-crm-settings-page.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-company-research-page.php';
		WP_MCP_AI_Company_Research_Page::init();
	}

	// Load Research & Add for CCT/CPT integration.
	require_once WP_MCP_AI_PRO_PATH . 'includes/research-add/class-wp-mcp-ai-crm-research-add.php';
	new WP_MCP_AI_CRM_Research_Add();

	// Register tools will be loaded automatically via the tools directory structure.
	// Tools are located in: addons/pro/includes/tools/crm/.
}

/**
 * Enqueue CRM toolkit admin styles.
 *
 * @param string $hook Current admin page hook.
 */
function wp_mcp_ai_enqueue_crm_toolkit_admin_styles( $hook ) {
	// Only load if toolkit is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_crm_toolkit'] ) ) {
		return;
	}

	// Check if we're on a relevant admin page.
	$screen = get_current_screen();
	if ( ! $screen ) {
		return;
	}

	// Enqueue admin styles if available.
	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-crm-toolkit.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-crm-toolkit-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-crm-toolkit.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_crm_toolkit_admin_styles' );
