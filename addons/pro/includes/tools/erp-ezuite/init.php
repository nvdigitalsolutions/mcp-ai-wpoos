<?php
/**
 * EZuite Inventory Sync Pro Toolkit Initialization
 *
 * Loads the EZuite Inventory Sync Pro Toolkit for EZuite ERP inventory
 * synchronization with WooCommerce. Gated on WooCommerce being active
 * and the toolkit toggle being enabled in NV oOS settings.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if EZuite toolkit is enabled.
 *
 * @since 1.9.0
 *
 * @return bool True if enabled.
 */
function wp_mcp_ai_is_ezuite_toolkit_enabled() {
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	return ! empty( $settings['enable_ezuite_toolkit'] );
}

// Load EZuite sync engine and related classes when toolkit is enabled.
if ( wp_mcp_ai_is_ezuite_toolkit_enabled()
	&& class_exists( 'WooCommerce' )
	&& ! ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() )
) {
	// Load core classes.
	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-ezuite-cct-manager.php';
	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-ezuite-sync-engine.php';

	// Bootstrap CCT auto-registration on init.
	WP_MCP_AI_EZuite_CCT_Manager::bootstrap();

	// Initialize sync engine.
	WP_MCP_AI_EZuite_Sync_Engine::init();

	// Load alert manager for low-stock notifications.
	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-ezuite-alert-manager.php';
	WP_MCP_AI_EZuite_Alert_Manager::init();

	// Load admin page in admin context.
	if ( is_admin() ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-ezuite-toolkit-settings-page.php';
		new WP_MCP_AI_EZuite_Toolkit_Settings_Page();

		// Load dashboard widget.
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-ezuite-dashboard-widget.php';
		WP_MCP_AI_EZuite_Dashboard_Widget::init();
	}

	// Load WP-CLI commands when running via CLI.
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-ezuite-cli.php';
	}

	// Load migration helper if standalone plugin is active.
	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-ezuite-migration.php';
	WP_MCP_AI_EZuite_Migration::init();
}

/**
 * Register EZuite deactivation hook.
 *
 * Clears scheduled Action Scheduler actions when the Pro plugin is deactivated.
 *
 * @since 1.9.0
 */
function wp_mcp_ai_ezuite_deactivation_cleanup() {
	if ( class_exists( 'WP_MCP_AI_EZuite_Sync_Engine' ) ) {
		WP_MCP_AI_EZuite_Sync_Engine::clear_scheduled_actions();
	}
}
add_action( 'wp_mcp_ai_pro_deactivation', 'wp_mcp_ai_ezuite_deactivation_cleanup' );
