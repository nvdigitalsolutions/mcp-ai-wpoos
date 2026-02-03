<?php
/**
 * Financial Planner Toolkit Initialization
 *
 * Loads the Financial Planner Toolkit system for retirement planning,
 * budgeting, investment tracking, debt management, and financial goal planning.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check if Financial Planner toolkit is enabled.
$settings   = get_option( 'wp_mcp_ai_settings', array() );
$is_enabled = ! empty( $settings['enable_financial_planner_toolkit'] );
$is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

// Only load if enabled and not in base version.
if ( $is_enabled && ! $is_base ) {

	// Load Financial Account CPT (works independently, no API required).
	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-financial-account-cpt.php';

	// Load Financial Planner admin pages.
	if ( is_admin() ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-financial-planner-settings-page.php';
	}

	// Register tools will be loaded automatically via the tools directory structure.
	// Tools are located in: addons/pro/includes/tools/financial-planning/.
	// Note: All tools work independently. Only bank_account_sync requires optional API.
}

/**
 * Enqueue financial planner toolkit admin styles.
 *
 * @since 1.1.0
 *
 * @param string $hook Current admin page hook (unused).
 */
function wp_mcp_ai_enqueue_financial_planner_toolkit_admin_styles( $hook ) {
	// Only load if toolkit is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_financial_planner_toolkit'] ) ) {
		return;
	}

	// Enqueue admin styles if available.
	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-financial-planner-toolkit.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-financial-planner-toolkit-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-financial-planner-toolkit.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_financial_planner_toolkit_admin_styles' );
