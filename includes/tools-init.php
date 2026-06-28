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

/*
 * Phase 6 of the Memory Layer 2026 Enhancements — register the
 * read-only `trace_memory_provenance` tool. The registry's main load_default_tools()
 * array is the canonical home for tool registration; we hook the
 * `wp_mcp_ai_default_tools` filter from this side-loader so the
 * registration is co-located with the rest of the memory layer's
 * additive phases (which all live outside `class-wp-mcp-ai-tool-registry.php`)
 * and so this branch's disjoint-files contract is preserved.
 */
add_filter(
	'wp_mcp_ai_default_tools',
	static function ( $default_tools ) {
		if ( ! is_array( $default_tools ) ) {
			return $default_tools;
		}
		$default_tools['WP_MCP_AI_Tool_Trace_Memory_Provenance'] = WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-trace-memory-provenance.php';
		$default_tools['WP_MCP_AI_Tool_Wait_For_User']           = WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-wait-for-user.php';
		return $default_tools;
	}
);
