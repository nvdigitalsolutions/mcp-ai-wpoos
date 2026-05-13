<?php
/**
 * CRM Toolkit MCP Server
 *
 * Multi-page R&A pilot: company-research, post-research, page-research, place-research.
 * No consolidate-add surfaces.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRM MCP server.
 */
class WP_MCP_AI_CRM_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * @return string
	 */
	public function get_slug() {
		return 'crm';
	}

	/**
	 * @return string
	 */
	public function get_name() {
		return __( 'CRM & Email Marketing', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * @return string
	 */
	public function get_description() {
		return __(
			'Customer relationship management — companies, contacts, places, and outbound posts/pages. Supports four AI-powered Research & Add ingestion surfaces.',
			'mcp-ai-wpoos-pro'
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function ingestion_surfaces() {
		return array(
			array(
				'type'               => 'research_add',
				'page_slug'          => 'company-research',
				'entity_type'        => 'mcp_ai_company',
				'class_ref'          => 'WP_MCP_AI_Company_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Companies', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'type'               => 'research_add',
				'page_slug'          => 'post-research',
				'entity_type'        => 'post',
				'class_ref'          => 'WP_MCP_AI_Post_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Posts', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'type'               => 'research_add',
				'page_slug'          => 'page-research',
				'entity_type'        => 'page',
				'class_ref'          => 'WP_MCP_AI_Page_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Pages', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'type'               => 'research_add',
				'page_slug'          => 'place-research',
				'entity_type'        => 'mcp_ai_place',
				'class_ref'          => 'WP_MCP_AI_Place_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Places', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * @return string[]
	 */
	public function candidate_tool_slugs() {
		/**
		 * Filter the candidate tool slugs CRM exposes through its MCP server.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_crm_candidate_tools',
			array(
				'crm_create_contact',
				'crm_update_contact',
				'crm_search_contacts',
				'crm_create_company',
				'crm_search_companies',
				'crm_create_email_campaign',
				'crm_send_email',
				'crm_validate_email',
			)
		);
	}
}
