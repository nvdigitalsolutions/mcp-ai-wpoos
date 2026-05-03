<?php
/**
 * Harness subsystem bootstrap.
 *
 * Loaded from the main plugin loader. Registers the harness-related tools
 * with the tool registry and exposes the seven harness-layer services.
 *
 * @package WP_MCP_AI
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-harness-profile.php';
require_once __DIR__ . '/class-wp-mcp-ai-pii-filter.php';
require_once __DIR__ . '/class-wp-mcp-ai-prompt-cue-library.php';
require_once __DIR__ . '/class-wp-mcp-ai-reasoning-trace.php';
require_once __DIR__ . '/class-wp-mcp-ai-tool-router-harness.php';
require_once __DIR__ . '/class-wp-mcp-ai-retrieval-harness.php';
require_once __DIR__ . '/class-wp-mcp-ai-self-refine-loop.php';

// Tools shipped with the harness subsystem.
require_once WP_MCP_AI_PATH . 'includes/tools/harness/class-wp-mcp-ai-tool-list-prompt-cues.php';
require_once WP_MCP_AI_PATH . 'includes/tools/harness/class-wp-mcp-ai-tool-select-prompt-cue.php';
require_once WP_MCP_AI_PATH . 'includes/tools/harness/class-wp-mcp-ai-tool-apply-prompt-cue.php';
require_once WP_MCP_AI_PATH . 'includes/tools/harness/class-wp-mcp-ai-tool-self-consistency-vote.php';
require_once WP_MCP_AI_PATH . 'includes/tools/harness/class-wp-mcp-ai-tool-retrieve-with-provenance.php';
require_once WP_MCP_AI_PATH . 'includes/tools/harness/class-wp-mcp-ai-tool-record-reflection.php';
require_once WP_MCP_AI_PATH . 'includes/tools/harness/class-wp-mcp-ai-tool-scope-memory.php';

add_action(
	'wp_mcp_ai_register_tools',
	function ( $registry ) {
		if ( ! $registry instanceof WP_MCP_AI_Tool_Registry ) {
			return;
		}
		$registry->register_tool( new WP_MCP_AI_Tool_List_Prompt_Cues() );
		$registry->register_tool( new WP_MCP_AI_Tool_Select_Prompt_Cue() );
		$registry->register_tool( new WP_MCP_AI_Tool_Apply_Prompt_Cue() );
		$registry->register_tool( new WP_MCP_AI_Tool_Self_Consistency_Vote() );
		$registry->register_tool( new WP_MCP_AI_Tool_Retrieve_With_Provenance() );
		$registry->register_tool( new WP_MCP_AI_Tool_Record_Reflection() );
		$registry->register_tool( new WP_MCP_AI_Tool_Scope_Memory() );
	},
	30
);
