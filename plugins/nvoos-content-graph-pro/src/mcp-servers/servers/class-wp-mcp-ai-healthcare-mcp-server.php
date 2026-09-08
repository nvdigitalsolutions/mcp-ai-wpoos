<?php
/**
 * Toolkit MCP server (ecosystem port — Wave F2, mcp-servers batch A).
 *
 * Ported from the base Pro addon's `addons/pro/includes/mcp-servers/servers/class-wp-mcp-ai-healthcare-mcp-server.php` for the standalone
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
 * Healthcare MCP server.
 */
class WP_MCP_AI_Healthcare_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'health';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Health & Wellness', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Family and pet member profiles, vitals, and consolidated health records. Owns the canonical health-records consolidation surface that other toolkits can mount read-only.',
			'nvoos-content-graph-pro'
		);
	}

	/**
	 * Get the ingestion surfaces for this server.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function ingestion_surfaces() {
		return array(
			array(
				'type'               => 'research_add',
				'page_slug'          => 'member-research',
				'entity_type'        => 'mcp_ai_member',
				'class_ref'          => 'WP_MCP_AI_Member_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Members', 'nvoos-content-graph-pro' ),
			),
			array(
				'type'               => 'consolidate_add',
				'page_slug'          => 'health-records-consolidate',
				'entity_type'        => 'mcp_ai_member',
				'class_ref'          => 'WP_MCP_AI_Health_Records_Consolidate_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Consolidate Health Records', 'nvoos-content-graph-pro' ),
			),
		);
	}

	/**
	 * Get the candidate tool slugs for this server.
	 *
	 * @return string[]
	 */
	public function candidate_tool_slugs() {
		/**
		 * Filter the candidate tool slugs Healthcare exposes through its MCP server.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_health_candidate_tools',
			array(
				'health_create_member',
				'health_update_member',
				'health_log_vitals',
				'health_consolidate_records',
			)
		);
	}
}
