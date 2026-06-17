<?php
/**
 * CRE Debt Toolkit MCP Server
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
 * CRE Debt MCP server.
 */
class WP_MCP_AI_CRE_Debt_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'cre-debt';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'CRE Debt', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Commercial real-estate debt origination, underwriting, asset management, CMBS, and debt-fund analytics. Owns the CRE Loan research surface.',
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
				'page_slug'          => 'research-cre-debt',
				'entity_type'        => 'mcp_ai_cre_loan',
				'class_ref'          => 'WP_MCP_AI_CRE_Debt_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add CRE Loans', 'mcp-ai-wpoos-pro' ),
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
		 * Filter the candidate tool slugs the CRE Debt MCP server exposes.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_cre_debt_candidate_tools',
			array(
				'cre_loan_sizer',
				'cre_loan_quote_generator',
				'cre_deal_screening_calculator',
				'cre_deal_pipeline_manager',
				'cre_borrower_profile_analyzer',
				'cre_credit_risk_scorer',
				'cre_environmental_risk_scorer',
				'cre_dcf_modeler',
				'cre_cap_rate_sensitivity',
				'cre_leverage_return_analyzer',
				'cre_debt_yield_analyzer',
				'cre_debt_waterfall_modeler',
				'cre_amortization_scheduler',
				'cre_loan_modification_calculator',
				'cre_closing_checklist_manager',
				'cre_execution_strategy_advisor',
				'cre_covenant_compliance_checker',
				'cre_concentration_limit_monitor',
				'cre_loan_surveillance_dashboard',
				'cre_lease_expiration_manager',
				'cre_capex_reserve_planner',
				'cre_asset_disposition_analyzer',
				'cre_hold_sell_analyzer',
				'cre_broker_relationship_tracker',
				'cre_fund_capital_call_calculator',
				'cre_fund_liquidity_analyzer',
				'cre_fund_portfolio_dashboard',
				'cre_fund_return_calculator',
				'cre_fund_scenario_modeler',
				'cre_lp_report_generator',
				'cre_clo_modeler',
				'cmbs_pool_analyzer',
				'cmbs_deal_structurer',
				'cmbs_bond_cash_flow_modeler',
				'cmbs_defeasance_calculator',
				'cmbs_investor_reporting_generator',
				'cmbs_maturity_risk_analyzer',
				'cmbs_rating_agency_analyzer',
				'cmbs_special_servicing_tracker',
				'cmbs_surveillance_monitor',
			)
		);
	}
}
