<?php
/**
 * Law Firm Toolkit Initialization
 *
 * Loads the Law Firm Toolkit: CPTs (Matters, Clients, Documents, Time Entries,
 * Trust Transactions), admin pages (Settings, Dashboard, Research & Add), and admin styles.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings   = get_option( 'wp_mcp_ai_settings', array() );
$is_enabled = ! empty( $settings['enable_law_firm_toolkit'] );
$is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

if ( $is_enabled && ! $is_base ) {

	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-law-firm-cpt.php';
	WP_MCP_AI_Law_Firm_CPT::init();

	if ( is_admin() ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-law-firm-settings-page.php';

		$lf_settings = get_option( 'wp_mcp_ai_law_firm_settings', array() );

		// Load Research & Add page if enabled (defaults to true).
		$research_on = isset( $lf_settings['enable_research'] ) ? (bool) $lf_settings['enable_research'] : true;
		if ( $research_on ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-law-firm-research-page.php';
			WP_MCP_AI_Law_Firm_Research_Page::init();
		}

		// Load Firm Dashboard page if enabled (defaults to true).
		$dashboard_on = isset( $lf_settings['enable_firm_dashboard'] ) ? (bool) $lf_settings['enable_firm_dashboard'] : true;
		if ( $dashboard_on ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-law-firm-dashboard-page.php';
			WP_MCP_AI_Law_Firm_Dashboard_Page::init();
		}
	}
}

/**
 * Enqueue Law Firm toolkit admin styles.
 *
 * @since 2.0.0
 *
 * @param string $hook Current admin page hook.
 */
function wp_mcp_ai_enqueue_law_firm_toolkit_admin_styles( $hook ) {
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_law_firm_toolkit'] ) ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || ! isset( $screen->post_type ) || ! in_array( $screen->post_type, array( 'mcp_ai_lf_matter', 'mcp_ai_lf_client', 'mcp_ai_lf_document', 'mcp_ai_lf_time_entry', 'mcp_ai_lf_trust_txn' ), true ) ) {
		return;
	}

	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-law-firm-toolkit.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-law-firm-toolkit-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-law-firm-toolkit.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_law_firm_toolkit_admin_styles' );
