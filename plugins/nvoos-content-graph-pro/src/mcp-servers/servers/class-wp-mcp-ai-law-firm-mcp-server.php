<?php
/**
 * Toolkit MCP server (ecosystem port — Wave F2, mcp-servers batch A).
 *
 * Ported from the base Pro addon's `addons/pro/includes/mcp-servers/servers/class-wp-mcp-ai-law-firm-mcp-server.php` for the standalone
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
 * Law Firm MCP server.
 */
class WP_MCP_AI_Law_Firm_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'law-firm';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Law Firm', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Matter, billing, intake, document automation, and litigation support for law firms. Owns the Matter research surface.',
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
				'page_slug'          => 'research-law-firm',
				'entity_type'        => 'mcp_ai_lf_matter',
				'class_ref'          => 'WP_MCP_AI_Law_Firm_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Matters', 'nvoos-content-graph-pro' ),
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
		 * Filter the candidate tool slugs the Law Firm MCP server exposes.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_law_firm_candidate_tools',
			array(
				'lf_client_intake_processor',
				'lf_conflict_of_interest_checker',
				'lf_engagement_letter_generator',
				'lf_client_profile_analyzer',
				'lf_client_communication_logger',
				'lf_client_portal_manager',
				'lf_client_satisfaction_analyzer',
				'lf_client_confidentiality_auditor',
				'lf_case_status_dashboard',
				'lf_case_timeline_generator',
				'lf_case_outcome_predictor',
				'lf_case_law_analyzer',
				'lf_court_deadline_tracker',
				'lf_bar_deadline_monitor',
				'lf_calendar_rule_calculator',
				'lf_jury_instruction_drafter',
				'lf_brief_outline_generator',
				'lf_deposition_summary_generator',
				'lf_discovery_request_builder',
				'lf_evidence_catalog_manager',
				'lf_expert_witness_tracker',
				'lf_ediscovery_document_analyzer',
				'lf_document_drafter',
				'lf_document_template_manager',
				'lf_document_version_tracker',
				'lf_contract_reviewer',
				'lf_clause_library_manager',
				'lf_damages_calculator',
				'lf_invoice_generator',
				'lf_fee_calculator',
				'lf_billing_compliance_checker',
				'lf_accounts_receivable_tracker',
				'lf_expense_reimbursement_tracker',
				'lf_attorney_utilization_tracker',
				'lf_firm_performance_dashboard',
				'lf_competitive_benchmarker',
				'lf_cle_credit_tracker',
				'lf_ai_usage_disclosure_generator',
				'lf_data_privacy_compliance_checker',
				'lf_ethics_rule_checker',
			)
		);
	}
}
