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

	// Load and register Architect Agent tools immediately.
	// These tools are loaded when the toolkit is enabled, not via action hook.
	wp_mcp_ai_load_architect_agent_tools();
}

/**
 * Load Architect Agent toolkit tools.
 *
 * Registers all 4 architect agent tools for self-editing capabilities:
 * - manage_files: Read, write, and list files
 * - execute_shell_command: Run shell commands with safety controls
 * - git_operations: Git version control operations
 * - search_codebase: Advanced code pattern search
 *
 * @since 1.1.0
 */
function wp_mcp_ai_load_architect_agent_tools() {
	$tools_dir = WP_MCP_AI_PRO_PATH . 'includes/tools/architect-agent/';

	// Core file management tool.
	require_once $tools_dir . 'class-wp-mcp-ai-tool-manage-files.php';

	// Development workflow tools (GitHub Copilot CLI-inspired).
	require_once $tools_dir . 'class-wp-mcp-ai-tool-execute-shell-command.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-git-operations.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-search-codebase.php';

	// Register all tools with the tool registry.
	$registry = wp_mcp_ai_get_tool_registry();

	if ( $registry ) {
		// File management.
		$registry->register( new WP_MCP_AI_Tool_Manage_Files() );

		// Development workflow.
		$registry->register( new WP_MCP_AI_Tool_Execute_Shell_Command() );
		$registry->register( new WP_MCP_AI_Tool_Git_Operations() );
		$registry->register( new WP_MCP_AI_Tool_Search_Codebase() );
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
