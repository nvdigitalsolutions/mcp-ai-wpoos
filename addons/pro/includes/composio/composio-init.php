<?php
/**
 * Composio Connect — Pro bootstrap initialization.
 *
 * Loads the Composio client, account-health engine, auth handler, trigger
 * bridge, webhook controller and the seven composio_* MCP tools. Hooks into
 * wp_mcp_ai_bootstrapped at priority 45 (after core tool interfaces are
 * guaranteed loaded).
 *
 * PHP 8.1+ only (Pro addon).
 *
 * @package WP_MCP_AI_Pro
 * @since   1.4.0
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

require_once __DIR__ . '/class-wp-mcp-ai-composio-client.php';
require_once __DIR__ . '/class-wp-mcp-ai-composio-account-health.php';
require_once __DIR__ . '/class-wp-mcp-ai-composio-auth-handler.php';
require_once __DIR__ . '/class-wp-mcp-ai-composio-trigger-bridge.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-composio-webhook-controller.php';

add_action(
	'wp_mcp_ai_bootstrapped',
	function () {
		// Tool interfaces are provided by the base plugin at this point.
		if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
			return;
		}

		// Load the shared tool helper + tool classes.
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/composio/class-wp-mcp-ai-composio-tools.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/composio/class-wp-mcp-ai-tool-composio-list-tools.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/composio/class-wp-mcp-ai-tool-composio-get-tool-schema.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/composio/class-wp-mcp-ai-tool-composio-list-connected-accounts.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/composio/class-wp-mcp-ai-tool-composio-manage-accounts.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/composio/class-wp-mcp-ai-tool-composio-create-connect-link.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/composio/class-wp-mcp-ai-tool-composio-execute-tool.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/composio/class-wp-mcp-ai-tool-composio-manage-triggers.php';

		// Register the seven composio_* tools.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( 'WP_MCP_AI_Tool_Composio_List_Tools' );
		$registry->register_tool( 'WP_MCP_AI_Tool_Composio_Get_Tool_Schema' );
		$registry->register_tool( 'WP_MCP_AI_Tool_Composio_List_Connected_Accounts' );
		$registry->register_tool( 'WP_MCP_AI_Tool_Composio_Manage_Accounts' );
		$registry->register_tool( 'WP_MCP_AI_Tool_Composio_Create_Connect_Link' );
		$registry->register_tool( 'WP_MCP_AI_Tool_Composio_Execute_Tool' );
		$registry->register_tool( 'WP_MCP_AI_Tool_Composio_Manage_Triggers' );

		// Wire the trigger bridge.
		WP_MCP_AI_Composio_Trigger_Bridge::init();
	},
	45
);
