<?php
/**
 * Financial Planner Toolkit MCP Server
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
 * Financial Planner MCP server.
 */
class WP_MCP_AI_Financial_Planner_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'financial-planner';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Financial Planner', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Budgeting, retirement, investment, and net-worth planning. Owns the Financial Account research surface.',
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
				'page_slug'          => 'research-financial-account',
				'entity_type'        => 'mcp_ai_fin_account',
				'class_ref'          => 'WP_MCP_AI_Financial_Account_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Financial Accounts', 'mcp-ai-wpoos-pro' ),
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
		 * Filter the candidate tool slugs the Financial Planner MCP server exposes.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_financial_planner_candidate_tools',
			array(
				'budget_planner',
				'cash_flow_analyzer',
				'expense_tracker',
				'net_worth_calculator',
				'retirement_calculator',
				'college_savings_calculator',
				'emergency_fund_calculator',
				'savings_goal_planner',
				'debt_payoff_calculator',
				'mortgage_calculator',
				'asset_allocation_planner',
				'portfolio_visualizer',
				'rebalancing_analyzer',
				'investment_return_calculator',
				'withdrawal_strategy_planner',
				'social_security_optimizer',
				'pension_analyzer',
				'ira_roth_comparison',
				'tax_estimator',
				'tax_loss_harvesting_tracker',
				'insurance_needs_analyzer',
				'credit_score_tracker',
				'financial_health_score',
				'bank_account_sync',
				'financial_report_generator',
				'financial_search',
				'financial_news_aggregator',
				'market_forecast_analyzer',
				'market_sentiment_analyzer',
				'investment_signal_tracker',
				'stock_data_fetcher',
				'financial_logic_visualizer',
			)
		);
	}
}
