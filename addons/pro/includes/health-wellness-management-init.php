<?php
/**
 * Health and Wellness Management System Initialization
 *
 * Loads the Health and Wellness Custom Post Type class which handles registration
 * and management of health-related CPTs for managing members (people & pets),
 * policies, medical records, checkups, prescriptions, and allergies.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load Health and Wellness CPT class.
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-health-wellness-cpt.php';

// Load Policy Research & Add page.
if ( is_admin() ) {
	// Check if health and wellness management is enabled and not in base version (unless Pro addon is active).
	$settings      = get_option( 'wp_mcp_ai_settings', array() );
	$is_enabled    = ! empty( $settings['enable_health_wellness_management'] );
	$is_base       = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
	$is_pro_active = defined( 'WP_MCP_AI_PRO_VERSION' );

	if ( $is_enabled && ( ! $is_base || $is_pro_active ) ) {
		// Load Member (primary CPT) settings and research pages.
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-member-settings-page.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-member-research-page.php';

		// Load Policy settings and research pages.
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-policy-research-page.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-policy-settings-page.php';
	}
}

/**
 * Enqueue health and wellness management admin styles.
 *
 * @param string $hook Current admin page hook.
 */
function wp_mcp_ai_enqueue_health_wellness_management_admin_styles( $hook ) {
	// Only load on health and wellness management edit screens.
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->post_type, array( 'mcp_ai_member', 'mcp_ai_policy', 'mcp_ai_med_record', 'mcp_ai_checkup', 'mcp_ai_prescription', 'mcp_ai_allergy' ), true ) ) {
		return;
	}

	// Check if health and wellness management is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_health_wellness_management'] ) ) {
		return;
	}

	// Enqueue admin styles if available.
	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-health-wellness-management.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-health-wellness-management-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-health-wellness-management.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_health_wellness_management_admin_styles' );
