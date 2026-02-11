<?php
/**
 * Document Generation Toolkit Initialization
 *
 * Loads the Document Generation Toolkit system for PDF, Word, and Excel
 * document generation with professional styling and branding.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load Document Template CPT class.
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-document-template-cpt.php';

// Load Document Generation admin pages (always load so menu items appear).
if ( is_admin() ) {
	// Load CPT-based settings page.
	require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-document-generation-cpt-settings-page.php';
	new WP_MCP_AI_Document_Generation_Settings_Page();

	// Load and initialize Research & Add page for document templates.
	require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-document-template-research-page.php';
	WP_MCP_AI_Document_Template_Research_Page::init();
}

// Check if Document Generation toolkit is enabled for advanced features.
$settings   = get_option( 'wp_mcp_ai_settings', array() );
$is_enabled = ! empty( $settings['enable_document_generation_toolkit'] );
$is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

// Only load advanced features if enabled and not in base version.
if ( $is_enabled && ! $is_base ) {
	// Load Research & Add for CCT/CPT integration.
	require_once WP_MCP_AI_PRO_PATH . 'includes/research-add/class-wp-mcp-ai-document-generation-research-add.php';
	new WP_MCP_AI_Document_Generation_Research_Add();

	// Register tools will be loaded automatically via the tools directory structure.
	// Tools are located in: addons/pro/includes/tools/document-generation/.
}

// Initialize Document Template CPT.
add_action(
	'init',
	function () {
		WP_MCP_AI_Document_Template_CPT::init();
	},
	5
);

/**
 * Enqueue document generation toolkit admin styles.
 *
 * @param string $hook Current admin page hook.
 */
function wp_mcp_ai_enqueue_document_generation_toolkit_admin_styles( $hook ) {
	// Only load if toolkit is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_document_generation_toolkit'] ) ) {
		return;
	}

	// Check if we're on document template pages.
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->post_type, array( 'mcp_ai_doc_tpl' ), true ) ) {
		// Also check for new settings page.
		if ( ! $screen || 'mcp_ai_doc_tpl_page_document-generation-settings' !== $screen->id ) {
			return;
		}
	}

	// Enqueue admin styles if available.
	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-document-generation-toolkit.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-document-generation-toolkit-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-document-generation-toolkit.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_document_generation_toolkit_admin_styles' );
