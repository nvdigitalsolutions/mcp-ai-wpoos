<?php
/**
 * Site Creator Toolkit MCP Server
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
 * Site Creator MCP server.
 *
 * Exposes site-scaffolding, layout generation, and theme-structure tools.
 * Tools-only server — no CPT-shaped ingestion surface.
 */
class WP_MCP_AI_Site_Creator_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'site-creator';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Site Creator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Site scaffolding, layout generation, theme structure, and template management. Tools-only server.',
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
		 * Filter the candidate tool slugs the Site Creator MCP server exposes.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_site_creator_candidate_tools',
			array(
				'generate_site_plan',
				'create_homepage_layout',
				'build_about_page',
				'create_service_pages',
				'build_contact_section',
				'create_hero_section',
				'generate_feature_section',
				'generate_gallery_section',
				'create_cta_section',
				'build_navigation_menu',
				'build_testimonial_section',
				'generate_blog_layout',
				'generate_landing_page',
				'generate_sidebar_widget',
				'create_footer_widget',
				'create_custom_widget',
				'scaffold_theme_structure',
				'save_site_template',
				'import_site_template',
				'export_template_kit',
				'manage_template_versions',
				'suggest_template_patterns',
				'extract_site_design_from_mockups',
				'analyze_competitor_sites',
				'automate_development_workflow',
				'integrate_with_architect',
				'research_site_best_practices',
			)
		);
	}
}
