<?php
/**
 * Shopify Sync Toolkit Initialization
 *
 * Loads the Shopify Sync Toolkit for background Shopify↔WooCommerce
 * inventory synchronization with CCT-based caching. Gated on WooCommerce
 * being active, the toolkit toggle being enabled, and the existing
 * WP_MCP_AI_Shopify_Client being available.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if Shopify Sync toolkit is enabled.
 *
 * @since 1.3.0
 *
 * @return bool True if enabled.
 */
function wp_mcp_ai_is_shopify_sync_toolkit_enabled() {
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	return ! empty( $settings['enable_shopify_sync_toolkit'] );
}

// Ensure the Shopify Client class is available before checking.
// It is lazy-loaded by individual tools but not pre-loaded at init,
// so class_exists() would fail and silently skip the entire toolkit.
if ( ! class_exists( 'WP_MCP_AI_Shopify_Client' ) ) {
	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-client.php';
}

// Load Shopify Sync toolkit when enabled.
if ( wp_mcp_ai_is_shopify_sync_toolkit_enabled()
	&& class_exists( 'WooCommerce' )
	&& ! ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() )
) {
	// Load core sync classes.
	if ( ! class_exists( 'WP_MCP_AI_Shopify_Sync_CCT_Manager' ) ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-sync-cct-manager.php';
	}
	if ( ! class_exists( 'WP_MCP_AI_Shopify_Sync_Engine' ) ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-sync-engine.php';
	}

	// Initialize sync engine (schedules Action Scheduler hooks).
	WP_MCP_AI_Shopify_Sync_Engine::init();

	// Load webhook handler (REST endpoint registration).
	if ( ! class_exists( 'WP_MCP_AI_Shopify_Sync_Webhook_Handler' ) ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-sync-webhook-handler.php';
	}
	WP_MCP_AI_Shopify_Sync_Webhook_Handler::init();

	// Load admin page in admin context.
	if ( is_admin() ) {
		if ( ! class_exists( 'WP_MCP_AI_Shopify_Sync_Toolkit_Settings_Page' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-shopify-sync-toolkit-settings-page.php';
		}
		new WP_MCP_AI_Shopify_Sync_Toolkit_Settings_Page();

		// Load dashboard widget.
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-shopify-sync-dashboard-widget.php';
		WP_MCP_AI_Shopify_Sync_Dashboard_Widget::init();
	}

	// Load WP-CLI commands when running via CLI.
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-sync-cli.php';
	}
}

/**
 * Register Shopify Sync deactivation hook.
 *
 * Clears scheduled Action Scheduler actions when the Pro plugin is deactivated.
 *
 * @since 1.3.0
 */
function wp_mcp_ai_shopify_sync_deactivation_cleanup() {
	if ( class_exists( 'WP_MCP_AI_Shopify_Sync_Engine' ) ) {
		WP_MCP_AI_Shopify_Sync_Engine::clear_all_scheduled_actions();
	}
}
add_action( 'wp_mcp_ai_pro_deactivation', 'wp_mcp_ai_shopify_sync_deactivation_cleanup' );
