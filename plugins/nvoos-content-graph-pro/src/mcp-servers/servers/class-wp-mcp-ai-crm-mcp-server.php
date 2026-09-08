<?php
/**
 * Toolkit MCP server (ecosystem port — Wave F2, mcp-servers batch A).
 *
 * Ported from the base Pro addon's `addons/pro/includes/mcp-servers/servers/class-wp-mcp-ai-crm-mcp-server.php` for the standalone
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
 * CRM MCP server.
 */
class WP_MCP_AI_CRM_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'crm';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'CRM & Email Marketing', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Customer relationship management — companies, leads/contacts, deals/opportunities, pipeline analytics, and multichannel outreach.',
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
				'page_slug'          => 'research-company',
				'entity_type'        => 'mcp_ai_company',
				'class_ref'          => 'WP_MCP_AI_Company_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Companies', 'nvoos-content-graph-pro' ),
			),
			array(
				'type'               => 'research_add',
				'page_slug'          => 'research-lead',
				'entity_type'        => 'mcp_ai_lead',
				'class_ref'          => 'WP_MCP_AI_Lead_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Leads', 'nvoos-content-graph-pro' ),
			),
			array(
				'type'               => 'research_add',
				'page_slug'          => 'research-deal',
				'entity_type'        => 'mcp_ai_deal',
				'class_ref'          => 'WP_MCP_AI_Deal_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Deals', 'nvoos-content-graph-pro' ),
			),
			array(
				'type'               => 'research_add',
				'page_slug'          => 'research-customer',
				'entity_type'        => 'mcp_ai_customer',
				'class_ref'          => 'WP_MCP_AI_Customer_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Customers', 'nvoos-content-graph-pro' ),
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
		 * Filter the candidate tool slugs CRM exposes through its MCP server.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_crm_candidate_tools',
			array(
				// Company tools.
				'create_company',
				'get_companies',
				'research_company',
				'manage_crm_contact',
				// Lead tools.
				'create_lead',
				'list_leads',
				'get_lead',
				'update_lead',
				'delete_lead',
				'convert_lead_to_customer',
				// Deal tools.
				'create_deal',
				'list_deals',
				'get_deal',
				'update_deal',
				'delete_deal',
				'move_deal_stage',
				// Pipeline & analytics.
				'get_pipeline_view',
				'forecast_pipeline_revenue',
				'get_conversion_funnel',
				'identify_top_customers',
				'identify_top_clients',
				// Inbound triage.
				'evaluate_inbound_message',
				'classify_message_intent',
				'extract_lead_from_message',
				'score_lead',
				'qualify_lead_bant',
				'qualify_lead_meddic',
				'detect_buying_signals',
				// Routing.
				'assign_lead_to_owner',
				'rotate_leads',
				// Outbound.
				'draft_lead_reply',
				'schedule_follow_up',
				// Activities.
				'create_crm_activity',
				'list_crm_activities',
				'get_crm_activity',
				'complete_crm_activity',
				'snooze_crm_activity',
				// Email search.
				'crm_email_search_leads',
				'crm_email_search_correspondence',
				// Compliance.
				'record_consent',
				'revoke_consent',
				'check_dnc_status',
				// Email hygiene.
				'classify_email_hygiene',
				'manage_email_hygiene',
				'prune_crm_messages',
				'repair_crm_data',
				'detect_duplicates',
				'merge_duplicates',
			)
		);
	}
}
