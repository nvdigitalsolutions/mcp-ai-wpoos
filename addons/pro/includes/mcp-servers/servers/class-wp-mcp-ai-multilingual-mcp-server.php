<?php
/**
 * Multilingual Toolkit MCP Server
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
 * Multilingual MCP server.
 */
class WP_MCP_AI_Multilingual_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * @return string
	 */
	public function get_slug() {
		return 'multilingual';
	}

	/**
	 * @return string
	 */
	public function get_name() {
		return __( 'Multilingual', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * @return string
	 */
	public function get_description() {
		return __(
			'Translation memory, content localization, and multilingual SEO tooling. Tools-only server.',
			'mcp-ai-wpoos-pro'
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function ingestion_surfaces() {
		return array();
	}

	/**
	 * @return string[]
	 */
	public function candidate_tool_slugs() {
		/**
		 * Filter the candidate tool slugs the Multilingual MCP server exposes.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_multilingual_candidate_tools',
			array(
				'auto_translate_content',
				'detect_content_language',
				'find_untranslated_strings',
				'translation_memory_search',
				'translation_quality_check',
				'export_import_translations',
				'localize_dates_currencies',
				'rtl_content_optimization',
				'multilingual_seo_audit',
				'translate_woocommerce_products',
			)
		);
	}
}
