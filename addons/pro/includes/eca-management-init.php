<?php
/**
 * ECA Management System Initialization
 *
 * Loads the ECA Custom Post Type class which handles registration and management
 * of Extra-Curricular Activities (ECAs) and Students.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load ECA CPT class.
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-eca-cpt.php';

/**
 * Enqueue ECA management admin styles.
 *
 * @param string $hook Current admin page hook.
 */
function wp_mcp_ai_enqueue_eca_management_admin_styles( $hook ) {
	// Only load on ECA management edit screens.
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->post_type, array( 'mcp_ai_eca', 'mcp_ai_student' ), true ) ) {
		return;
	}

	// Check if ECA management is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_eca_management'] ) ) {
		return;
	}

	// Enqueue admin styles if available.
	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-eca-management.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-eca-management-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-eca-management.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_eca_management_admin_styles' );
