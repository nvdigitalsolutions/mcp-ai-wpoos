<?php
/**
 * Food & Beverage Management Toolkit — Initialization.
 *
 * Loads the F&B toolkit services and admin settings page, gated on the
 * `enable_fnb_toolkit` toggle. Tools register through the central Pro tool
 * registry in mcp-ai-wpoos-pro.php, gated on the same setting.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the `enable_fnb_toolkit` toggle in the plugin settings schema.
 *
 * @param array $settings Existing settings defaults.
 * @return array
 */
function wp_mcp_ai_fnb_settings_defaults( $settings ) {
	if ( is_array( $settings ) ) {
		$settings['enable_fnb_toolkit'] = false;
	}

	return $settings;
}
add_filter( 'wp_mcp_ai_settings_defaults', 'wp_mcp_ai_fnb_settings_defaults' );

// Check if the F&B toolkit is enabled.
$settings      = get_option( 'wp_mcp_ai_settings', array() );
$is_enabled    = ! empty( $settings['enable_fnb_toolkit'] );
$is_base       = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
$is_pro_active = defined( 'WP_MCP_AI_PRO_VERSION' );

// Only load the toolkit when enabled (and not in base version or the Pro addon is active).
if ( $is_enabled && ( ! $is_base || $is_pro_active ) ) {
	require_once __DIR__ . '/class-wp-mcp-ai-fnb-settings.php';
	require_once __DIR__ . '/class-wp-mcp-ai-fnb-data-source.php';
	require_once __DIR__ . '/class-wp-mcp-ai-fnb-metrics.php';
	require_once __DIR__ . '/class-wp-mcp-ai-fnb-report-builder.php';
	require_once __DIR__ . '/class-wp-mcp-ai-fnb-draft-writer.php';

	// Load the admin settings page (registers the "Food & Beverage" submenu under NV oOS).
	if ( is_admin() ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-fnb-settings-page.php';
		WP_MCP_AI_Fnb_Settings_Page::init();
	}
}
