<?php
/**
 * Financial Planner Toolkit Initialization (ecosystem port — Wave F2,
 * financial-planning data layer).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/financial-planning/init.php` for the standalone
 * `nvoos-content-graph-pro` addon. Loads the financial-account CPT (which
 * self-boots at file load) behind the `enable_financial_planner_toolkit`
 * gate.
 *
 * Documented deviations from the monolith copy:
 *
 * 1. `declare(strict_types=1)` added.
 * 2. File paths resolve from `NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/'`.
 * 3. The JetEngine meta-helper guard stays byte-identical (base-owned
 *    `WP_MCP_AI_JetEngine_Meta_Helper` — classmap-served, dormant standalone
 *    unless JetEngine is present).
 * 4. The admin pages (CPT settings + research) stay file-gated — they land
 *    with the financial admin slice.
 * 5. Monolith guard — this init declares the global helper
 *    `wp_mcp_ai_enqueue_financial_planner_toolkit_admin_styles()` that the
 *    base financial init also declares; the collision is a compile-time
 *    fatal, so the ENTIRE body is wrapped in a runtime
 *    `! defined( 'WP_MCP_AI_PATH' )` block (the declarations register only
 *    when the block executes — same pattern as the calendar init deviation
 *    5). NOTE: the base tree ships this init but nothing loads it monolith
 *    (no registry module — byte-identical dormancy); the standalone registry
 *    gains a standalone-only `toolkit_financial_planning` module that boots
 *    it (toolkit_data_store/vector_storage precedent).
 * 6. New standalone-only tool wiring (same pattern as the calendar init
 *    deviation 6): a `wp_mcp_ai_pro_tools` filter carrying the ported
 *    financial tool subset (fills as the tool batches land) plus
 *    `wp_mcp_ai_pro_register_financial_ecosystem_tools()` registering the
 *    ported tools into the ecosystem graph ToolRegistry.
 *
 * @package NvoosContentGraphPro
 * @since   1.1.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Monolith guard (deviation 5): full-body runtime wrap — see the header.
if ( ! defined( 'WP_MCP_AI_PATH' ) ) {

	// Check if Financial Planner toolkit is enabled.
	$nvoos_content_graph_pro_fin_settings   = get_option( 'wp_mcp_ai_settings', array() );
	$nvoos_content_graph_pro_fin_is_enabled = ! empty( $nvoos_content_graph_pro_fin_settings['enable_financial_planner_toolkit'] );
	$nvoos_content_graph_pro_fin_is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

	// Only load if enabled and not in base version.
	if ( $nvoos_content_graph_pro_fin_is_enabled && ! $nvoos_content_graph_pro_fin_is_base ) {

		// Load Financial Account CPT (works independently, no API required).
		// CPT creates its own menu automatically.
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-financial-account-cpt.php';

		// Register Financial Account meta fields with JetEngine for listing/discovery.
		if ( function_exists( 'jet_engine' ) && class_exists( 'WP_MCP_AI_JetEngine_Meta_Helper' ) ) {
			WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( 'mcp_ai_fin_account' );
		}

		// Load Financial Planner settings page (appears under CPT menu).
		// Uses CPT-based pattern like Quiz, Project, and other toolkits.
		if ( is_admin() ) {
			// Deferred — file-gated until the financial admin slice lands.
			$nvoos_content_graph_pro_fin_cpt_settings = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-financial-planner-cpt-settings-page.php';
			if ( file_exists( $nvoos_content_graph_pro_fin_cpt_settings ) ) {
				require_once $nvoos_content_graph_pro_fin_cpt_settings;
			}

			// Load Research & Add page for financial account research and creation.
			$nvoos_content_graph_pro_fin_research = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-financial-account-research-page.php';
			if ( file_exists( $nvoos_content_graph_pro_fin_research ) ) {
				require_once $nvoos_content_graph_pro_fin_research;
			}
		}

		// Register tools will be loaded automatically via the tools directory structure.
		// Tools are located in: plugins/nvoos-content-graph-pro/src/tools/financial-planning/.
		// Note: All tools work independently. Only bank_account_sync requires optional API.
	}

	/**
	 * Enqueue financial planner toolkit admin styles.
	 *
	 * @since 1.1.0
	 *
	 * @param string $hook Current admin page hook (unused).
	 */
	function wp_mcp_ai_enqueue_financial_planner_toolkit_admin_styles( $hook ) {
		// Only load if toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_financial_planner_toolkit'] ) ) {
			return;
		}

		// Enqueue admin styles if available.
		$css_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'assets/css/admin-financial-planner-toolkit.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'wp-mcp-ai-financial-planner-toolkit-admin',
				NVOOS_CONTENT_GRAPH_PRO_URL . 'assets/css/admin-financial-planner-toolkit.css',
				array(),
				NVOOS_CONTENT_GRAPH_PRO_VERSION
			);
		}
	}
	add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_financial_planner_toolkit_admin_styles' );

	/**
	 * Standalone-only tool filter — carries the ported financial tool subset
	 * (inert standalone, consumed by the base plugin monolith). The map
	 * fills as the financial tool batches land.
	 *
	 * @param array $tools Existing tool map (class => file).
	 * @return array Extended tool map.
	 */
	function wp_mcp_ai_pro_register_financial_tools( $tools ) {
		$nvoos_content_graph_pro_fin_tools = array(
			'WP_MCP_AI_Tool_Retirement_Calculator'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-retirement-calculator.php',
			'WP_MCP_AI_Tool_IRA_Roth_Comparison'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-ira-roth-comparison.php',
			'WP_MCP_AI_Tool_Withdrawal_Strategy_Planner'  => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-withdrawal-strategy-planner.php',
			'WP_MCP_AI_Tool_Social_Security_Optimizer'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-social-security-optimizer.php',
			'WP_MCP_AI_Tool_Pension_Analyzer'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-pension-analyzer.php',
			'WP_MCP_AI_Tool_Budget_Planner'               => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-budget-planner.php',
			'WP_MCP_AI_Tool_Expense_Tracker'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-expense-tracker.php',
			'WP_MCP_AI_Tool_Net_Worth_Calculator'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-net-worth-calculator.php',
			'WP_MCP_AI_Tool_Cash_Flow_Analyzer'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-cash-flow-analyzer.php',
			'WP_MCP_AI_Tool_Bank_Account_Sync'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-bank-account-sync.php',
			'WP_MCP_AI_Tool_Portfolio_Visualizer'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-portfolio-visualizer.php',
			'WP_MCP_AI_Tool_Asset_Allocation_Planner'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-asset-allocation-planner.php',
			'WP_MCP_AI_Tool_Investment_Return_Calculator' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-investment-return-calculator.php',
			'WP_MCP_AI_Tool_Rebalancing_Analyzer'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-rebalancing-analyzer.php',
			'WP_MCP_AI_Tool_Tax_Loss_Harvesting_Tracker'  => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-tax-loss-harvesting-tracker.php',
			'WP_MCP_AI_Tool_Debt_Payoff_Calculator'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-debt-payoff-calculator.php',
			'WP_MCP_AI_Tool_Mortgage_Calculator'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-mortgage-calculator.php',
			'WP_MCP_AI_Tool_Credit_Score_Tracker'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-credit-score-tracker.php',
			'WP_MCP_AI_Tool_Savings_Goal_Planner'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-savings-goal-planner.php',
			'WP_MCP_AI_Tool_Emergency_Fund_Calculator'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-emergency-fund-calculator.php',
			'WP_MCP_AI_Tool_Financial_Health_Score'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-financial-health-score.php',
			'WP_MCP_AI_Tool_Tax_Estimator'                => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-tax-estimator.php',
			'WP_MCP_AI_Tool_College_Savings_Calculator'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-college-savings-calculator.php',
			'WP_MCP_AI_Tool_Insurance_Needs_Analyzer'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-insurance-needs-analyzer.php',
			'WP_MCP_AI_Tool_Financial_News_Aggregator'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-financial-news-aggregator.php',
			'WP_MCP_AI_Tool_Stock_Data_Fetcher'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-stock-data-fetcher.php',
			'WP_MCP_AI_Tool_Market_Sentiment_Analyzer'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-market-sentiment-analyzer.php',
			'WP_MCP_AI_Tool_Market_Forecast_Analyzer'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-market-forecast-analyzer.php',
			'WP_MCP_AI_Tool_Investment_Signal_Tracker'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-investment-signal-tracker.php',
			'WP_MCP_AI_Tool_Financial_Logic_Visualizer'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-financial-logic-visualizer.php',
			'WP_MCP_AI_Tool_Financial_Report_Generator'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-financial-report-generator.php',
			'WP_MCP_AI_Tool_Financial_Search'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-financial-search.php',
			'WP_MCP_AI_Tool_Get_Uncategorised_Transactions' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-get-uncategorised-transactions.php',
			'WP_MCP_AI_Tool_Categorise_Transactions'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/class-wp-mcp-ai-tool-categorise-transactions.php',
			'WP_MCP_AI_Tool_Import_Financial_Planning_Blueprint' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/examples/class-wp-mcp-ai-tool-import-financial-planning-blueprint.php',
		);

		return array_merge( $tools, $nvoos_content_graph_pro_fin_tools );
	}

	/**
	 * Standalone-only ecosystem registration — registers the ported financial
	 * tools into the ecosystem graph ToolRegistry and the nvoos/core
	 * registry via `WP_MCP_AI_Pro_Tool_Adapter` (same wiring as the
	 * calendar/CRM/PM inits). The list fills as the financial tool batches
	 * land.
	 *
	 * @return void
	 */
	function wp_mcp_ai_pro_register_financial_ecosystem_tools() {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-tool-adapter.php';

		$nvoos_content_graph_pro_parent_registry = nvoos_content_graph_get_tool_registry();
		if ( ! $nvoos_content_graph_pro_parent_registry instanceof \NvoosContentGraph\ToolRegistry ) {
			return;
		}

		foreach (
			array(
				'WP_MCP_AI_Tool_Retirement_Calculator',
				'WP_MCP_AI_Tool_IRA_Roth_Comparison',
				'WP_MCP_AI_Tool_Withdrawal_Strategy_Planner',
				'WP_MCP_AI_Tool_Social_Security_Optimizer',
				'WP_MCP_AI_Tool_Pension_Analyzer',
				'WP_MCP_AI_Tool_Budget_Planner',
				'WP_MCP_AI_Tool_Expense_Tracker',
				'WP_MCP_AI_Tool_Net_Worth_Calculator',
				'WP_MCP_AI_Tool_Cash_Flow_Analyzer',
				'WP_MCP_AI_Tool_Bank_Account_Sync',
				'WP_MCP_AI_Tool_Portfolio_Visualizer',
				'WP_MCP_AI_Tool_Asset_Allocation_Planner',
				'WP_MCP_AI_Tool_Investment_Return_Calculator',
				'WP_MCP_AI_Tool_Rebalancing_Analyzer',
				'WP_MCP_AI_Tool_Tax_Loss_Harvesting_Tracker',
				'WP_MCP_AI_Tool_Debt_Payoff_Calculator',
				'WP_MCP_AI_Tool_Mortgage_Calculator',
				'WP_MCP_AI_Tool_Credit_Score_Tracker',
				'WP_MCP_AI_Tool_Savings_Goal_Planner',
				'WP_MCP_AI_Tool_Emergency_Fund_Calculator',
				'WP_MCP_AI_Tool_Financial_Health_Score',
				'WP_MCP_AI_Tool_Tax_Estimator',
				'WP_MCP_AI_Tool_College_Savings_Calculator',
				'WP_MCP_AI_Tool_Insurance_Needs_Analyzer',
				'WP_MCP_AI_Tool_Financial_News_Aggregator',
				'WP_MCP_AI_Tool_Stock_Data_Fetcher',
				'WP_MCP_AI_Tool_Market_Sentiment_Analyzer',
				'WP_MCP_AI_Tool_Market_Forecast_Analyzer',
				'WP_MCP_AI_Tool_Investment_Signal_Tracker',
				'WP_MCP_AI_Tool_Financial_Logic_Visualizer',
				'WP_MCP_AI_Tool_Financial_Report_Generator',
				'WP_MCP_AI_Tool_Financial_Search',
				'WP_MCP_AI_Tool_Get_Uncategorised_Transactions',
				'WP_MCP_AI_Tool_Categorise_Transactions',
				'WP_MCP_AI_Tool_Import_Financial_Planning_Blueprint',
			) as $nvoos_content_graph_pro_tool_class
		) {
			$nvoos_content_graph_pro_adapter = new WP_MCP_AI_Pro_Tool_Adapter( new $nvoos_content_graph_pro_tool_class() );
			try {
				$nvoos_content_graph_pro_parent_registry->register( $nvoos_content_graph_pro_adapter );
			} catch ( \RuntimeException $nvoos_content_graph_pro_e ) {
				unset( $nvoos_content_graph_pro_e ); // Duplicate slug — non-fatal.
			}

			// Wrap into the nvoos/core registry so the agentic chat loop can
			// resolve and execute the tool (same path the AI addon uses).
			if ( class_exists( 'NvoosContentGraphAi\CoreBridge' ) ) {
				$nvoos_content_graph_pro_core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
				try {
					$nvoos_content_graph_pro_core_tools->register( new \NvoosContentGraphAi\Adapter\GraphToolAdapter( $nvoos_content_graph_pro_adapter ) );
				} catch ( \RuntimeException $nvoos_content_graph_pro_e ) {
					unset( $nvoos_content_graph_pro_e ); // Duplicate slug — non-fatal.
				}
			}
		}
	}

	// ---- Standalone-only tool wiring (deviation 6). ----
	add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_financial_tools', 10 );

	if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_financial_ecosystem_tools();
	}
} // End monolith guard (deviation 5).
