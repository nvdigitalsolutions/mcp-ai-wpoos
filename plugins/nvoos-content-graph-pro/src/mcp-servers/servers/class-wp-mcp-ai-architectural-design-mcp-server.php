<?php
/**
 * Toolkit MCP server (ecosystem port — Wave F2, mcp-servers batch A).
 *
 * Ported from the base Pro addon's `addons/pro/includes/mcp-servers/servers/class-wp-mcp-ai-architectural-design-mcp-server.php` for the standalone
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
 * Architectural Design MCP server.
 */
class WP_MCP_AI_Architectural_Design_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'architectural-design';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Architectural Design', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Architectural drawings, projects, and specifications. Mounts the Healthcare consolidation surface read-only so accessibility and aging-in-place reviews can pull member health context.',
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
				'page_slug'          => 'architectural-drawing-research',
				'entity_type'        => 'mcp_ai_arch_drawing',
				'class_ref'          => 'WP_MCP_AI_Architectural_Drawing_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Architectural Drawings', 'nvoos-content-graph-pro' ),
			),
			array(
				'type'               => 'research_add',
				'page_slug'          => 'architectural-project-research',
				'entity_type'        => 'mcp_ai_arch_proj',
				'class_ref'          => 'WP_MCP_AI_Architectural_Project_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Architectural Projects', 'nvoos-content-graph-pro' ),
			),
			array(
				'type'               => 'research_add',
				'page_slug'          => 'architectural-specification-research',
				'entity_type'        => 'mcp_ai_arch_spec',
				'class_ref'          => 'WP_MCP_AI_Architectural_Specification_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Architectural Specifications', 'nvoos-content-graph-pro' ),
			),
		);
	}

	/**
	 * Mount the Healthcare consolidation surface read-only.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function mounted_surfaces() {
		return array(
			array(
				'type'                => 'consolidate_add',
				'page_slug'           => 'health-records-consolidate',
				'entity_type'         => 'mcp_ai_member',
				'class_ref'           => 'WP_MCP_AI_Health_Records_Consolidate_Page',
				'bound_assistant_id'  => 0,
				'label'               => __( 'Consolidated Health Records (mounted from Healthcare)', 'nvoos-content-graph-pro' ),
				'source_toolkit_slug' => 'health',
				'read_only'           => true,
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
		 * Filter the candidate tool slugs Architectural Design exposes via MCP.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_architectural_design_candidate_tools',
			array(
				'architectural_create_project',
				'architectural_create_drawing',
				'architectural_create_specification',
			)
		);
	}
}
