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
	require_once __DIR__ . '/class-wp-mcp-ai-fnb-tenant-profile.php';
	require_once __DIR__ . '/class-wp-mcp-ai-fnb-table-reader.php';
	require_once __DIR__ . '/class-wp-mcp-ai-fnb-data-source.php';
	require_once __DIR__ . '/class-wp-mcp-ai-fnb-universal-reader.php';
	require_once __DIR__ . '/class-wp-mcp-ai-fnb-benchmarks.php';
	require_once __DIR__ . '/class-wp-mcp-ai-fnb-metrics.php';
	require_once __DIR__ . '/class-wp-mcp-ai-fnb-report-builder.php';
	require_once __DIR__ . '/class-wp-mcp-ai-fnb-report-exporter.php';
	require_once __DIR__ . '/class-wp-mcp-ai-fnb-draft-writer.php';
	require_once __DIR__ . '/class-wp-mcp-ai-fnb-csv-exporter.php';

	// Phase 9 native storage: CPTs register only when enable_fnb_native_storage
	// is on (checked inside register(), hooked on `init` at priority 12).
	require_once __DIR__ . '/trait-wp-mcp-ai-fnb-native-cpt-storage.php';
	require_once __DIR__ . '/class-wp-mcp-ai-fnb-cpt-reader.php';
	require_once __DIR__ . '/class-wp-mcp-ai-fnb-sale-cpt.php';
	require_once __DIR__ . '/class-wp-mcp-ai-fnb-purchase-cpt.php';
	require_once __DIR__ . '/class-wp-mcp-ai-fnb-stock-count-cpt.php';
	require_once __DIR__ . '/class-wp-mcp-ai-fnb-waste-cpt.php';
	require_once __DIR__ . '/class-wp-mcp-ai-fnb-booking-cpt.php';
	WP_MCP_AI_Fnb_Sale_CPT::init();
	WP_MCP_AI_Fnb_Purchase_CPT::init();
	WP_MCP_AI_Fnb_Stock_Count_CPT::init();
	WP_MCP_AI_Fnb_Waste_CPT::init();
	WP_MCP_AI_Fnb_Booking_CPT::init();

	// JetEngine field schemas for the native CPTs (no-op without JetEngine;
	// mirrors the CRM company-CPT registration).
	if ( function_exists( 'jet_engine' ) && class_exists( 'WP_MCP_AI_JetEngine_Meta_Helper' ) ) {
		WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( WP_MCP_AI_Fnb_Sale_CPT::POST_TYPE );
		WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( WP_MCP_AI_Fnb_Purchase_CPT::POST_TYPE );
		WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( WP_MCP_AI_Fnb_Stock_Count_CPT::POST_TYPE );
		WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( WP_MCP_AI_Fnb_Waste_CPT::POST_TYPE );
		WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( WP_MCP_AI_Fnb_Booking_CPT::POST_TYPE );
	}

	// Phase 9b purchase-request lifecycle (Appendix A): QMS-backed PR
	// service + the precondition gates that layer onto the QMS state machine
	// when QMS compliance is enabled.
	require_once __DIR__ . '/pr/class-wp-mcp-ai-fnb-pr-service.php';
	require_once __DIR__ . '/pr/class-wp-mcp-ai-fnb-pr-gates.php';
	WP_MCP_AI_Fnb_Pr_Gates::init();

	// Shared assistant-pack seeder service (used by the WP-CLI command and
	// the admin settings-page button).
	require_once __DIR__ . '/class-wp-mcp-ai-fnb-assistant-seeder.php';

	// Load the admin settings page (registers the "Food & Beverage" submenu
	// under NV oOS Pro) and the F&B Management top-level admin menu.
	if ( is_admin() ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-fnb-settings-page.php';
		WP_MCP_AI_Fnb_Settings_Page::init();

		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-fnb-admin-menu.php';
		WP_MCP_AI_Fnb_Admin_Menu::init();

		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-fnb-dashboard-page.php';
	}
}
