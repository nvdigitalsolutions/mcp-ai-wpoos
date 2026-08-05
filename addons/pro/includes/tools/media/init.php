<?php
/**
 * Media Toolkit Initialization.
 *
 * Loads and initializes the Media Toolkit system for managing
 * reusable graphic editor templates and media collections.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load Media Template CPT class.
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-media-template-cpt.php';

// Load Media Collection CPT class.
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-media-collection-cpt.php';

// Register Media meta fields with JetEngine for listing/discovery.
if ( function_exists( 'jet_engine' ) && class_exists( 'WP_MCP_AI_JetEngine_Meta_Helper' ) ) {
	WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( 'mcp_ai_media_tpl' );
	WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( 'mcp_ai_media_coll' );
}

// Load Media Template Presets class.
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-media-template-presets.php';

// Load Media Design & Add admin page.
if ( is_admin() ) {
	// Check if media toolkit is enabled and not in base version.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	$is_base  = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' );

	if ( ! $is_base && ! empty( $settings['enable_media_toolkit'] ) ) {
		// Load Media Admin Menu registry (top-level "NV Media" menu).
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-media-admin-menu.php';
		WP_MCP_AI_Media_Admin_Menu::init();

		// Load Media Command Center page (landing dashboard).
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-media-command-center-page.php';
		WP_MCP_AI_Media_Command_Center_Page::init();

		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-media-design-page.php';
		// Load new CPT-based settings page (under NV Media menu).
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-media-settings-page.php';

		// Load Research & Add for CCT/CPT integration.
		require_once WP_MCP_AI_PRO_PATH . 'includes/research-add/class-wp-mcp-ai-media-research-add.php';
		new WP_MCP_AI_Media_Research_Add();

		// Load Consolidate & Add page (under NV Media menu).
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-media-consolidate-page.php';
		WP_MCP_AI_Media_Consolidate_Page::init();

		// Load Media Blueprints page (under NV Media menu).
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-media-blueprints-page.php';
		WP_MCP_AI_Media_Blueprints_Page::init();
	}
}

// Initialize Media Toolkit system.
add_action(
	'init',
	function () {
		// Initialize Media Template CPT.
		WP_MCP_AI_Media_Template_CPT::init();

		// Initialize Media Collection CPT.
		WP_MCP_AI_Media_Collection_CPT::init();
	},
	5
);
