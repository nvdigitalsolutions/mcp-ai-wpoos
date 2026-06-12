<?php
/**
 * Calendar Booking Toolkit Initialization
 *
 * Loads the Calendar Booking Toolkit system for appointment scheduling,
 * availability management, calendar synchronization, and booking management.
 *
 * Phase 2.6 - Planned Implementation
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load Calendar Booking Custom Post Types (always load for CPT registration).
require_once WP_MCP_AI_PRO_PATH . 'includes/calendar-booking/class-wp-mcp-ai-appointment-cpt.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/calendar-booking/class-wp-mcp-ai-service-cpt.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/calendar-booking/class-wp-mcp-ai-staff-cpt.php';

// Load Calendar Booking admin pages only when the toolkit is enabled.
if ( is_admin() ) {
	$settings      = get_option( 'wp_mcp_ai_settings', array() );
	$is_enabled    = ! empty( $settings['enable_calendar_booking_toolkit'] );
	$is_base       = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
	$is_pro_active = defined( 'WP_MCP_AI_PRO_VERSION' );

	if ( $is_enabled && ( ! $is_base || $is_pro_active ) ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-calendar-booking-research-page.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-calendar-booking-settings-page.php';
	}
}

// Tools will be implemented in Phase 2.6.
// Planned location: addons/pro/includes/tools/calendar-booking/.

// --- Performance optimization (business hours autoload, appointment retention, schedule cap, orphan detection) ---
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/calendar-booking/class-wp-mcp-ai-cal-orch-optimization.php';
WP_MCP_AI_Calendar_Orchestration_Optimization::init();

/**
 * Enqueue calendar booking toolkit admin styles.
 *
 * @since 1.1.0
 *
 * @param string $hook Current admin page hook (unused).
 */
function wp_mcp_ai_enqueue_calendar_booking_toolkit_admin_styles( $hook ) {
	// Only load if toolkit is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_calendar_booking_toolkit'] ) ) {
		return;
	}

	// Enqueue admin styles if available.
	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-calendar-booking-toolkit.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-calendar-booking-toolkit-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-calendar-booking-toolkit.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_calendar_booking_toolkit_admin_styles' );
