<?php
/**
 * Quiz Management System Initialization
 *
 * Loads the Quiz Custom Post Type class which handles registration and management
 * of Quizzes and Submissions.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load Quiz CPT class.
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-quiz-cpt.php';

// Load JetEngine quiz CCT if JetEngine is active.
if ( function_exists( 'jet_engine' ) ) {
	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-jetengine-quizzes-cct.php';
}

// Load Quiz admin pages (always load so menu items appear when CPT is registered).
if ( is_admin() ) {
	require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-quiz-research-page.php';
	require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-quiz-settings-page.php';
}

/**
 * Enqueue Quiz management admin styles.
 *
 * @param string $hook Current admin page hook.
 */
function wp_mcp_ai_enqueue_quiz_management_admin_styles( $hook ) {
	// Only load on Quiz management edit screens.
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->post_type, array( 'mcp_ai_quiz', 'mcp_ai_submission' ), true ) ) {
		return;
	}

	// Check if quiz system is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_quiz_system'] ) ) {
		return;
	}

	// Enqueue admin styles if available.
	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-quiz-management.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-quiz-management-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-quiz-management.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_quiz_management_admin_styles' );
