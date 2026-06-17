<?php
/**
 * AI Tool Builder Toolkit MCP Server
 *
 * Phase 2 Tier-1 promotion. See docs/ADR_002_toolkit_mcp_servers.md.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI Tool Builder MCP server.
 */
class WP_MCP_AI_AI_Tool_Builder_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'ai-tool-builder';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'AI Tool Builder', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Meta-toolkit that scaffolds, validates, and benchmarks NV oOS tools themselves. Tools-only server.',
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
		 * Filter the candidate tool slugs the AI Tool Builder MCP server exposes.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_ai_tool_builder_candidate_tools',
			array(
				'generate_tool_scaffold',
				'generate_tool_logic',
				'generate_tool_parameters',
				'generate_tool_documentation',
				'generate_tool_tests',
				'validate_tool_schema',
				'analyze_tool_security',
				'check_tool_compliance',
				'benchmark_tool_performance',
				'refactor_tool_code',
			)
		);
	}
}
