<?php
/**
 * Backward-compatible loader for default tool registrations.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/tools-init.php';

// Phase 2 (Human-in-the-Loop): Human approval gate tool.
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-request-user-approval.php';
add_action(
	'wp_mcp_ai_register_tools',
	function ( $registry ) {
		if ( ! $registry instanceof WP_MCP_AI_Tool_Registry ) {
			return;
		}
		$registry->register_tool( new WP_MCP_AI_Tool_Request_User_Approval() );
	},
	30
);

// Phase 4 (Durable Execution): Replay workflow run tool.
add_action(
	'wp_mcp_ai_register_tools',
	function ( $registry ) {
		if ( ! $registry instanceof WP_MCP_AI_Tool_Registry ) {
			return;
		}
		if ( class_exists( 'WP_MCP_AI_Workflow_Run_CPT' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-replay-workflow-run.php';
			$registry->register_tool( new WP_MCP_AI_Tool_Replay_Workflow_Run() );
		}
	},
	31
);

// Phase 5 (Triggers/Sub-Agents): Spawn sub-agent tool.
add_action(
'wp_mcp_ai_register_tools',
function ( $registry ) {
if ( ! $registry instanceof WP_MCP_AI_Tool_Registry ) {
return;
}
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-spawn-sub-agent.php';
$registry->register_tool( new WP_MCP_AI_Tool_Spawn_Sub_Agent() );
},
32
);
