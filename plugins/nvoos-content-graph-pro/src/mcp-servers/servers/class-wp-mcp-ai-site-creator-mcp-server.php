<?php
/**
 * Toolkit MCP server (ecosystem port — Wave F2, mcp-servers batch B).
 *
 * Ported from the base Pro addon's `addons/pro/includes/mcp-servers/servers/class-wp-mcp-ai-site-creator-mcp-server.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; no path constants — no path swaps.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


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
		return __( 'Site Creator', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Site scaffolding, layout generation, theme structure, and template management. Tools-only server.',
			'nvoos-content-graph-pro'
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
