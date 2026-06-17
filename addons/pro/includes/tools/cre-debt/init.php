<?php
/**
 * CRE Debt & Securitization Toolkit Initialization
 *
 * Loads the CRE Debt & Securitization Toolkit: CPTs (Loans, Properties),
 * admin pages (Settings, Dashboard, Research & Add), and admin styles.
 *
 * Follows the same initialization pattern as Financial Planner and
 * Health & Wellness Management toolkits.
 *
 * @package WP_MCP_AI
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check if CRE Debt toolkit is enabled.
$settings   = get_option( 'wp_mcp_ai_settings', array() );
$is_enabled = ! empty( $settings['enable_cre_debt_toolkit'] );
$is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

// Only load if enabled and not in base version.
if ( $is_enabled && ! $is_base ) {

	// Load CRE Debt CPTs (Loans and Properties).
	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-cre-debt-cpt.php';

	// Initialize CPTs.
	WP_MCP_AI_CRE_Debt_CPT::init();

	// Load admin pages (settings, dashboard, research).
	if ( is_admin() ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cre-debt-settings-page.php';

		// Load Research & Add page.
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cre-debt-research-page.php';
		WP_MCP_AI_CRE_Debt_Research_Page::init();

		// Load Portfolio Dashboard page.
		$cre_settings = get_option( 'wp_mcp_ai_cre_debt_settings', array() );
		$dashboard_on = isset( $cre_settings['enable_portfolio_dashboard'] ) ? (bool) $cre_settings['enable_portfolio_dashboard'] : true;
		if ( $dashboard_on ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cre-debt-dashboard-page.php';
			WP_MCP_AI_CRE_Debt_Dashboard_Page::init();
		}
	}
}

/**
 * Enqueue CRE Debt toolkit admin styles.
 *
 * @since 2.0.0
 *
 * @param string $hook Current admin page hook (unused).
 */
function wp_mcp_ai_enqueue_cre_debt_toolkit_admin_styles( $hook ) {
	// Only load if toolkit is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_cre_debt_toolkit'] ) ) {
		return;
	}

	// Only load on CRE Debt screens.
	$screen = get_current_screen();
	if ( ! $screen || ! isset( $screen->post_type ) || ! in_array( $screen->post_type, array( 'mcp_ai_cre_loan', 'mcp_ai_cre_property' ), true ) ) {
		return;
	}

	// Enqueue admin styles if available.
	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-cre-debt-toolkit.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-cre-debt-toolkit-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-cre-debt-toolkit.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_cre_debt_toolkit_admin_styles' );
