<?php
/**
 * Paper Store Pro — Bootstrap initialization.
 *
 * Loads Pro Paper Store features: Markdown+YAML driver, Git sync,
 * admin UI, and import/export tools. Hooks into wp_mcp_ai_bootstrapped
 * at priority 35 (after base Paper Store init at 30).
 *
 * PHP 8.1+ only (Pro addon).
 *
 * @package WP_MCP_AI_Pro
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	return;
}

/**
 * Register Pro Paper Store features.
 *
 * Hooked at priority 35 — after base Paper Store init at priority 30.
 * Gracefully degrades if the base Paper Store is not loaded.
 */
add_action(
	'wp_mcp_ai_bootstrapped',
	function () {
		// Bail if base Paper Store manager is not available.
		if ( ! class_exists( 'WP_MCP_AI_Paper_Store_Manager' ) ) {
			return;
		}

		// Load Pro core classes now that the base interface is guaranteed loaded.
		// These must be loaded inside the hook because the Markdown+YAML driver
		// implements WP_MCP_AI_Paper_Driver_Interface, which is only loaded by
		// the base Paper Store init at priority 30.
		require_once __DIR__ . '/class-wp-mcp-ai-paper-markdown-yaml-driver.php';
		require_once __DIR__ . '/class-wp-mcp-ai-paper-git-sync.php';
		require_once __DIR__ . '/class-wp-mcp-ai-paper-admin-ui.php';

		// Load Pro tool classes.
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/paper-store/class-wp-mcp-ai-tool-paper-store-import.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/paper-store/class-wp-mcp-ai-tool-paper-store-export.php';

		$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();

		// Register the Markdown + YAML driver for .md files.
		$manager->register_driver( '.md', new WP_MCP_AI_Paper_Markdown_Yaml_Driver() );

		// Register Pro tools.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( 'WP_MCP_AI_Tool_Paper_Store_Import' );
		$registry->register_tool( 'WP_MCP_AI_Tool_Paper_Store_Export' );

		// Initialize admin UI.
		if ( class_exists( 'WP_MCP_AI_Paper_Admin_UI' ) ) {
			WP_MCP_AI_Paper_Admin_UI::init();
		}

		// Initialize Git sync (opt-in via filter).
		if ( class_exists( 'WP_MCP_AI_Paper_Git_Sync' ) ) {
			WP_MCP_AI_Paper_Git_Sync::init();
		}
	},
	35
);
