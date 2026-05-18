<?php
/**
 * Architect Agent Toolkit Initialization
 *
 * Loads the Architect Agent Toolkit - AI-powered self-editing capabilities
 * with file operations, shell commands, git integration, and code search.
 * Inspired by GitHub Copilot CLI for complete development workflow support.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check if Architect Agent toolkit is enabled.
$settings   = get_option( 'wp_mcp_ai_settings', array() );
$is_enabled = ! empty( $settings['enable_architect_agent_toolkit'] );
$is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

// Only load if enabled and not in base version.
if ( $is_enabled && ! $is_base ) {

	// Load Architect Agent admin pages.
	if ( is_admin() ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-architect-agent-settings-page.php';
	}

	// Load Architect Agent tools.
	add_action( 'wp_mcp_ai_load_pro_tools', 'wp_mcp_ai_load_architect_agent_tools' );
}

/**
 * Load Architect Agent toolkit tools.
 *
 * Registers all architect agent tools for self-editing capabilities:
 * - manage_files: Read, write, and list files
 * - execute_shell_command: Run shell commands with safety controls
 * - git_inspect: Read-only git queries (status, diff, log, show, blame, branch)
 * - git_change: State-changing git operations (commit, add, checkout, stash)
 * - search_codebase: Advanced code pattern search
 *
 * Back-compat: the legacy `git_operations` slug is registered as a deprecated
 * alias pointing to `git_inspect`. Callers using write operations should
 * migrate to `git_change`. The alias expires in v1.4.0.
 *
 * @since 1.1.0  (original registration of git_operations)
 * @since 1.3.0  (split into git_inspect + git_change; alias registered)
 */
function wp_mcp_ai_load_architect_agent_tools() {
	$tools_dir = WP_MCP_AI_PRO_PATH . 'includes/tools/architect-agent/';

	// Core file management tool.
	require_once $tools_dir . 'class-wp-mcp-ai-tool-manage-files.php';

	// Development workflow tools (GitHub Copilot CLI-inspired).
	require_once $tools_dir . 'class-wp-mcp-ai-tool-execute-shell-command.php';

	// P5 Part 2: git_operations decomposed into focused read/write sub-tools.
	require_once $tools_dir . 'trait-wp-mcp-ai-tool-git-helpers.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-git-inspect.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-git-change.php';

	// Keep the legacy class loadable so existing code that does `new WP_MCP_AI_Tool_Git_Operations()`
	// does not trigger a fatal; it is no longer registered as a live tool.
	require_once $tools_dir . 'class-wp-mcp-ai-tool-git-operations.php';

	require_once $tools_dir . 'class-wp-mcp-ai-tool-search-codebase.php';

	// Register all tools with the tool registry.
	$registry = wp_mcp_ai_get_tool_registry();

	if ( $registry ) {
		// File management.
		$registry->register_tool( new WP_MCP_AI_Tool_Manage_Files() );

		// Development workflow.
		$registry->register_tool( new WP_MCP_AI_Tool_Execute_Shell_Command() );

		// Git sub-tools (P5 Part 2 decomposition).
		$registry->register_tool( new WP_MCP_AI_Tool_Git_Inspect() );
		$registry->register_tool( new WP_MCP_AI_Tool_Git_Change() );

		// Back-compat alias: git_operations → git_inspect.
		// Write-operation callers (commit/add/checkout/stash) should migrate
		// to git_change before v1.4.0 when this alias will be removed.
		$registry->register_deprecated_alias(
			'git_operations',
			'git_inspect',
			array(
				'since'   => '1.3.0',
				'remove'  => '1.4.0',
				'message' => __( 'git_operations has been split into git_inspect (read-only) and git_change (writes). Read-only callers are auto-resolved to git_inspect. Write callers (commit / add / checkout / stash) must migrate to git_change.', 'mcp-ai-wpoos-pro' ),
			)
		);

		$registry->register_tool( new WP_MCP_AI_Tool_Search_Codebase() );
	}
}

/**
 * Enqueue Architect Agent toolkit admin styles.
 *
 * @since 1.1.0
 *
 * @param string $hook Current admin page hook (unused).
 */
function wp_mcp_ai_enqueue_architect_agent_toolkit_admin_styles( $hook ) {
	// Only load if toolkit is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_architect_agent_toolkit'] ) ) {
		return;
	}

	// Enqueue admin styles if available.
	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-architect-agent-toolkit.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-architect-agent-toolkit-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-architect-agent-toolkit.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_architect_agent_toolkit_admin_styles' );
