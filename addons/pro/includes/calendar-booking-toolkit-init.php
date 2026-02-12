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
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load Calendar Booking Custom Post Types (always load for CPT registration).
require_once WP_MCP_AI_PRO_PATH . 'includes/calendar-booking/class-wp-mcp-ai-appointment-cpt.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/calendar-booking/class-wp-mcp-ai-service-cpt.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/calendar-booking/class-wp-mcp-ai-staff-cpt.php';

// Load Calendar Booking admin pages (always load so menu items appear when CPT is registered).
if ( is_admin() ) {
	require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-calendar-booking-research-page.php';
	require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-calendar-booking-settings-page.php';
}

// Tools will be implemented in Phase 2.6.
// Planned location: addons/pro/includes/tools/calendar-booking/.

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
