<?php
/**
 * Architectural Design Toolkit MCP Server
 *
 * Multi-page R&A (architectural-drawing-research, architectural-project-research,
 * architectural-specification-research), no native C&A.
 *
 * Acts as the canonical foreign-mount consumer: mounts the health-records-consolidate
 * surface read-only because its three R&A pages explicitly link to it for context.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

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
		return __( 'Architectural Design', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Architectural drawings, projects, and specifications. Mounts the Healthcare consolidation surface read-only so accessibility and aging-in-place reviews can pull member health context.',
			'mcp-ai-wpoos-pro'
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
				'label'              => __( 'Research & Add Architectural Drawings', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'type'               => 'research_add',
				'page_slug'          => 'architectural-project-research',
				'entity_type'        => 'mcp_ai_arch_proj',
				'class_ref'          => 'WP_MCP_AI_Architectural_Project_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Architectural Projects', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'type'               => 'research_add',
				'page_slug'          => 'architectural-specification-research',
				'entity_type'        => 'mcp_ai_arch_spec',
				'class_ref'          => 'WP_MCP_AI_Architectural_Specification_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Architectural Specifications', 'mcp-ai-wpoos-pro' ),
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
				'label'               => __( 'Consolidated Health Records (mounted from Healthcare)', 'mcp-ai-wpoos-pro' ),
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
