<?php
/**
 * tools/cre-debt/init.php (ecosystem port — Wave F4, cre-debt data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/cre-debt/init.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps; the CPT require resolves from the addon's `src/`
 * copy; the three admin-page requires are file-gated until the cre-debt admin slice lands; the
 * standalone copy adds `is_admin()`-gated loads keyed to the same `enable_portfolio_dashboard`
 * sub-setting; NEW standalone-only wiring (deviation, same as the CRM init): a
 * `wp_mcp_ai_pro_tools` filter plus `wp_mcp_ai_pro_register_cre_debt_ecosystem_tools()` — both
 * carry the eleven originations batch-1 tools and fill further as the cre-debt tool batches land;
 * local vars prefixed
 * `$nvoos_content_graph_pro_*`; full-body `! defined( 'WP_MCP_AI_PATH' )` guard (the global
 * enqueue helper would collide compile-time with the base copy in the monorepo test matrix).
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

if ( ! defined( 'WP_MCP_AI_PATH' ) ) {

	// Check if CRE Debt toolkit is enabled.
	$nvoos_content_graph_pro_settings   = get_option( 'wp_mcp_ai_settings', array() );
	$nvoos_content_graph_pro_is_enabled = ! empty( $nvoos_content_graph_pro_settings['enable_cre_debt_toolkit'] );
	$nvoos_content_graph_pro_is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

	// Only load if enabled and not in base version.
	if ( $nvoos_content_graph_pro_is_enabled && ! $nvoos_content_graph_pro_is_base ) {

		// Load CRE Debt CPTs (Loans and Properties).
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-cre-debt-cpt.php';

		// Initialize CPTs.
		WP_MCP_AI_CRE_Debt_CPT::init();

		// Load admin pages (settings, dashboard, research).
		if ( is_admin() ) {
			$nvoos_content_graph_pro_cre_settings_page = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-cre-debt-settings-page.php';
			if ( file_exists( $nvoos_content_graph_pro_cre_settings_page ) ) {
				require_once $nvoos_content_graph_pro_cre_settings_page;
			}

			// Load Research & Add page.
			$nvoos_content_graph_pro_cre_research_page = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-cre-debt-research-page.php';
			if ( file_exists( $nvoos_content_graph_pro_cre_research_page ) ) {
				require_once $nvoos_content_graph_pro_cre_research_page;
				WP_MCP_AI_CRE_Debt_Research_Page::init();
			}

			// Load Portfolio Dashboard page.
			$nvoos_content_graph_pro_cre_settings = get_option( 'wp_mcp_ai_cre_debt_settings', array() );
			$nvoos_content_graph_pro_dashboard_on = isset( $nvoos_content_graph_pro_cre_settings['enable_portfolio_dashboard'] ) ? (bool) $nvoos_content_graph_pro_cre_settings['enable_portfolio_dashboard'] : true;
			if ( $nvoos_content_graph_pro_dashboard_on ) {
				$nvoos_content_graph_pro_cre_dashboard_page = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-cre-debt-dashboard-page.php';
				if ( file_exists( $nvoos_content_graph_pro_cre_dashboard_page ) ) {
					require_once $nvoos_content_graph_pro_cre_dashboard_page;
					WP_MCP_AI_CRE_Debt_Dashboard_Page::init();
				}
			}

			unset(
				$nvoos_content_graph_pro_cre_settings,
				$nvoos_content_graph_pro_dashboard_on
			);
		}
	}

	unset(
		$nvoos_content_graph_pro_settings,
		$nvoos_content_graph_pro_is_enabled,
		$nvoos_content_graph_pro_is_base
	);

	/**
	 * Enqueue CRE Debt toolkit admin styles.
	 *
	 * @since 2.0.0
	 *
	 * @param string $hook Current admin page hook (unused).
	 */
	function wp_mcp_ai_enqueue_cre_debt_toolkit_admin_styles( $hook ) {
		// Only load if toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_cre_debt_toolkit'] ) ) {
			return;
		}

		// Only load on CRE Debt screens.
		$screen = get_current_screen();
		if ( ! $screen || ! isset( $screen->post_type ) || ! in_array( $screen->post_type, array( 'mcp_ai_cre_loan', 'mcp_ai_cre_property' ), true ) ) {
			return;
		}

		// Enqueue admin styles if available.
		$css_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'assets/css/admin-cre-debt-toolkit.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'wp-mcp-ai-cre-debt-toolkit-admin',
				NVOOS_CONTENT_GRAPH_PRO_URL . 'assets/css/admin-cre-debt-toolkit.css',
				array(),
				NVOOS_CONTENT_GRAPH_PRO_VERSION
			);
		}
	}
	add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_cre_debt_toolkit_admin_styles' );

	// ---- Standalone-only tool wiring (deviation). ----
	add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_cre_debt_tools', 10 );

	if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_cre_debt_ecosystem_tools();
	}
} // End monolith guard (deviation).

/**
 * Standalone-only tool filter — mirrors the monolith's inline cre-debt map
 * (the `enable_cre_debt_toolkit` gate in `mcp-ai-wpoos-pro.php`). The map
 * fills as the cre-debt tool batches land.
 *
 * @param array $tools Existing tool map.
 * @return array Extended tool map.
 */
function wp_mcp_ai_pro_register_cre_debt_tools( $tools ) {
	$nvoos_content_graph_pro_cre_tools = array(
		'WP_MCP_AI_Tool_CRE_Deal_Pipeline_Manager'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-deal-pipeline-manager.php',
		'WP_MCP_AI_Tool_CRE_Borrower_Profile_Analyzer'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-borrower-profile-analyzer.php',
		'WP_MCP_AI_Tool_CRE_Loan_Quote_Generator'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-loan-quote-generator.php',
		'WP_MCP_AI_Tool_CRE_Market_Comp_Analyzer'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-market-comp-analyzer.php',
		'WP_MCP_AI_Tool_CRE_Deal_Screening_Calculator'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-deal-screening-calculator.php',
		'WP_MCP_AI_Tool_CRE_Origination_Volume_Tracker'  => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-origination-volume-tracker.php',
		'WP_MCP_AI_Tool_CRE_Rate_Lock_Manager'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-rate-lock-manager.php',
		'WP_MCP_AI_Tool_CRE_Broker_Relationship_Tracker' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-broker-relationship-tracker.php',
		'WP_MCP_AI_Tool_CRE_Term_Sheet_Comparator'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-term-sheet-comparator.php',
		'WP_MCP_AI_Tool_CRE_Execution_Strategy_Advisor'  => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-execution-strategy-advisor.php',
		'WP_MCP_AI_Tool_CRE_Closing_Checklist_Manager'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cre-debt/originations/class-wp-mcp-ai-tool-cre-closing-checklist-manager.php',
	);

	return array_merge( $tools, $nvoos_content_graph_pro_cre_tools );
}

/**
 * Standalone-only ecosystem registration — registers the ported cre-debt
 * tools into the ecosystem graph ToolRegistry and the nvoos/core registry
 * via `WP_MCP_AI_Pro_Tool_Adapter` (same wiring as the image-production
 * inits). The list fills as the cre-debt tool batches land.
 *
 * @return void
 */
function wp_mcp_ai_pro_register_cre_debt_ecosystem_tools() {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-tool-adapter.php';

	$nvoos_content_graph_pro_parent_registry = nvoos_content_graph_get_tool_registry();
	if ( ! $nvoos_content_graph_pro_parent_registry instanceof \NvoosContentGraph\ToolRegistry ) {
		return;
	}

	foreach (
		array(
			'WP_MCP_AI_Tool_CRE_Deal_Pipeline_Manager',
			'WP_MCP_AI_Tool_CRE_Borrower_Profile_Analyzer',
			'WP_MCP_AI_Tool_CRE_Loan_Quote_Generator',
			'WP_MCP_AI_Tool_CRE_Market_Comp_Analyzer',
			'WP_MCP_AI_Tool_CRE_Deal_Screening_Calculator',
			'WP_MCP_AI_Tool_CRE_Origination_Volume_Tracker',
			'WP_MCP_AI_Tool_CRE_Rate_Lock_Manager',
			'WP_MCP_AI_Tool_CRE_Broker_Relationship_Tracker',
			'WP_MCP_AI_Tool_CRE_Term_Sheet_Comparator',
			'WP_MCP_AI_Tool_CRE_Execution_Strategy_Advisor',
			'WP_MCP_AI_Tool_CRE_Closing_Checklist_Manager',
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
