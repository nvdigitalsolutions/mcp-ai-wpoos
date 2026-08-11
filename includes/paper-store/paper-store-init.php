<?php
/**
 * Paper Store — Bootstrap initialization.
 *
 * Loads all Paper Store core classes and registers MCP tools.
 * Hooked into wp_mcp_ai_bootstrapped at priority 30 (after default tools).
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load core classes in dependency order.
require_once __DIR__ . '/interface-wp-mcp-ai-paper-driver.php';
require_once __DIR__ . '/class-wp-mcp-ai-paper-json-driver.php';
require_once __DIR__ . '/class-wp-mcp-ai-paper-index.php';
require_once __DIR__ . '/class-wp-mcp-ai-paper-repository.php';
require_once __DIR__ . '/class-wp-mcp-ai-paper-query.php';
require_once __DIR__ . '/class-wp-mcp-ai-paper-store-manager.php';
require_once __DIR__ . '/trait-wp-mcp-ai-paper-store-remote.php';

// Load tool classes.
require_once WP_MCP_AI_PATH . 'includes/tools/paper-store/class-wp-mcp-ai-tool-paper-store-list.php';
require_once WP_MCP_AI_PATH . 'includes/tools/paper-store/class-wp-mcp-ai-tool-paper-store-read.php';
require_once WP_MCP_AI_PATH . 'includes/tools/paper-store/class-wp-mcp-ai-tool-paper-store-search.php';
require_once WP_MCP_AI_PATH . 'includes/tools/paper-store/class-wp-mcp-ai-tool-paper-store-write.php';
require_once WP_MCP_AI_PATH . 'includes/tools/paper-store/class-wp-mcp-ai-tool-paper-store-update.php';
require_once WP_MCP_AI_PATH . 'includes/tools/paper-store/class-wp-mcp-ai-tool-paper-store-delete.php';

// Load Paper Store REST API controller (for remote connection support).
require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-paper-store-rest.php';

/**
 * Register Paper Store tools with the tool registry.
 *
 * Hooked at priority 30 — after the default tool init at priority 20
 * and the wp_mcp_ai_register_tools action.
 */
add_action(
	'wp_mcp_ai_bootstrapped',
	function () {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Phase 1 — Read tools.
		$registry->register_tool( 'WP_MCP_AI_Tool_Paper_Store_List' );
		$registry->register_tool( 'WP_MCP_AI_Tool_Paper_Store_Read' );
		$registry->register_tool( 'WP_MCP_AI_Tool_Paper_Store_Search' );

		// Phase 2 — Write tools.
		$registry->register_tool( 'WP_MCP_AI_Tool_Paper_Store_Write' );
		$registry->register_tool( 'WP_MCP_AI_Tool_Paper_Store_Update' );
		$registry->register_tool( 'WP_MCP_AI_Tool_Paper_Store_Delete' );
	},
	30
);
