<?php
/**
 * Comic Creation Toolkit MCP Server
 *
 * Phase 6 Tier-2 promotion. Exposes comic creation tools
 * via the per-toolkit MCP JSON-RPC endpoint.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Comic Creation MCP server.
 *
 * Exposes AI-powered comic book creation tools: script generation,
 * panel creation, character management, layout assembly,
 * lettering/inking/coloring, style application, and CBZ/CBR export.
 */
class WP_MCP_AI_Comic_Creation_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'comic-creation';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Comic Creation', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'AI-powered comic book creation — script generation, character reference sheets, panel-by-panel image creation, speech bubbles, page layout assembly, and CBZ/CBR export.',
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
		 * Filter the candidate tool slugs the Comic Creation MCP server exposes.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_comic_creation_candidate_tools',
			array(
				'generate_comic_script',
				'breakdown_comic_panels',
				'generate_character_sheet',
				'generate_comic_panel',
				'create_comic_layout',
				'add_speech_bubbles',
				'export_comic_cbz',
				'colorize_comic_panel',
				'ink_comic_panel',
				'letter_comic_panel',
				'upscale_comic_page',
				'apply_comic_style',
			)
		);
	}
}
