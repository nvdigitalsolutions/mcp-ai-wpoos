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
	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-client.php';
	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-cct-manager.php';
	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-sync-engine.php';

	// Initialize sync engine.
	WP_MCP_AI_FlowHub_Sync_Engine::init();

	// Load admin page in admin context.
	if ( is_admin() ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-flowhub-toolkit-settings-page.php';
		new WP_MCP_AI_FlowHub_Toolkit_Settings_Page();
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
