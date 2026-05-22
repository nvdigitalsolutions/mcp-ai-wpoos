<?php
/**
 * Healthcare Toolkit MCP Server
 *
 * R&A + C&A on `mcp_ai_member` (member-research, health-records-consolidate).
 * Acts as the canonical foreign-mount source for cross-toolkit access (Architectural
 * Design mounts `health.consolidate_add.records`).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

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
		return __( 'Health & Wellness', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Family and pet member profiles, vitals, and consolidated health records. Owns the canonical health-records consolidation surface that other toolkits can mount read-only.',
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
				'page_slug'          => 'member-research',
				'entity_type'        => 'mcp_ai_member',
				'class_ref'          => 'WP_MCP_AI_Member_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Members', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'type'               => 'consolidate_add',
				'page_slug'          => 'health-records-consolidate',
				'entity_type'        => 'mcp_ai_member',
				'class_ref'          => 'WP_MCP_AI_Health_Records_Consolidate_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Consolidate Health Records', 'mcp-ai-wpoos-pro' ),
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
