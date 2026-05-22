<?php
/**
 * Architect Agent Toolkit MCP Server
 *
 * Phase 6 Tier-2 promotion. See docs/ADR_002_toolkit_mcp_servers.md.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Architect Agent MCP server.
 *
 * Exposes code-generation and filesystem tools used by the Architect Agent
 * (shell commands, git operations, file management, codebase search).
 * Tools-only server — workflow plumbing without a CPT-shaped ingestion surface.
 */
class WP_MCP_AI_Architect_Agent_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'architect-agent';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Architect Agent', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Code-generation, shell execution, git operations, file management, and codebase search for the Architect Agent workflow. Tools-only server.',
			'mcp-ai-wpoos-pro'
		);
	}

	/**
	 * Get the ingestion surfaces for this server.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function ingestion_surfaces() {
		return array();
	}

	/**
	 * Get the candidate tool slugs for this server.
	 *
	 * @return string[]
	 */
	public function candidate_tool_slugs() {
		/**
		 * Filter the candidate tool slugs the Architect Agent MCP server exposes.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_architect_agent_candidate_tools',
			array(
				'execute_shell_command',
				'git_operations',
				'manage_files',
				'search_codebase',
			)
		);
	}
}
