<?php
/**
 * FlowHub Toolkit Initialization
 *
 * Loads the FlowHub Toolkit for FlowHub POS inventory synchronization
 * with WooCommerce. Gated on WooCommerce being active and the toolkit
 * toggle being enabled in NV oOS settings.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if FlowHub toolkit is enabled.
 *
 * @since 1.2.0
 *
 * @return bool True if enabled.
 */
function wp_mcp_ai_is_flowhub_toolkit_enabled() {
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	return ! empty( $settings['enable_flowhub_toolkit'] );
}

// Load FlowHub admin page and sync engine when toolkit is enabled.
if ( wp_mcp_ai_is_flowhub_toolkit_enabled()
	&& class_exists( 'WooCommerce' )
	&& ! ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() )
) {
	// Load core classes.
	// NOTE: The PRO FlowHub client (WP_MCP_AI_FlowHub_Client, uppercase H)
	// uses the same class name (case-insensitive) as the base client.
	// Loading it here causes PHP to resolve 'WP_MCP_AI_Flowhub_Client'
	// to the PRO class, breaking base tools that expect the base class
	// constructor and methods. Do NOT load the PRO client here.
	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-cct-manager.php';
	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-sync-engine.php';

	// Bootstrap CCT auto-registration on init.
	WP_MCP_AI_FlowHub_CCT_Manager::bootstrap();

	// Initialize sync engine.
	WP_MCP_AI_FlowHub_Sync_Engine::init();

	// Load alert manager for low-stock notifications.
	require_once WP_MCP_AI_PRO_PATH . 'includes/tools/flowhub/class-wp-mcp-ai-flowhub-alert-manager.php';
	WP_MCP_AI_FlowHub_Alert_Manager::init();

	// Load admin page in admin context.
	if ( is_admin() ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-flowhub-toolkit-settings-page.php';
		new WP_MCP_AI_FlowHub_Toolkit_Settings_Page();

		// Load dashboard widget.
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-flowhub-dashboard-widget.php';
		WP_MCP_AI_FlowHub_Dashboard_Widget::init();
	}

	// Load WP-CLI commands when running via CLI.
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-cli.php';
	}

	// Load migration helper if standalone plugin is active.
	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-migration.php';
	WP_MCP_AI_FlowHub_Migration::init();
}

/**
 * Register FlowHub deactivation hook.
 *
 * Clears scheduled Action Scheduler actions when the Pro plugin is deactivated.
 *
 * @since 1.2.0
 */
function wp_mcp_ai_flowhub_deactivation_cleanup() {
	if ( class_exists( 'WP_MCP_AI_FlowHub_Sync_Engine' ) ) {
		WP_MCP_AI_FlowHub_Sync_Engine::clear_scheduled_actions();
	}
}
add_action( 'wp_mcp_ai_pro_deactivation', 'wp_mcp_ai_flowhub_deactivation_cleanup' );
