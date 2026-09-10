<?php
/**
 * Law Firm Toolkit Initialization (ecosystem port — Wave F4, law-firm data
 * layer).
 *
 * Slimmed standalone init for the `nvoos-content-graph-pro` addon. The base
 * Pro addon owns the same init monolith — the addon boots nothing when
 * `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps; the
 * CPT require resolves from the addon's `src/` copy; the three admin-page
 * requires are file-gated until the law-firm admin slice lands; the
 * standalone copy adds `is_admin()`-gated loads keyed to the same
 * `enable_research`/`enable_firm_dashboard` sub-settings; NEW
 * standalone-only wiring (deviation, same as the CRM init): a
 * `wp_mcp_ai_pro_tools` filter plus
 * `wp_mcp_ai_pro_register_law_firm_ecosystem_tools()` — both carry the ten
 * matter-management batch-1 tools plus the ten billing-trust tools plus the
 * eight intake-management tools plus the eight litigation-support tools plus
 * the eight compliance-ethics tools plus the ten document-automation tools
 * plus the eight research-analytics tools plus the tree-only import-blueprint
 * tool and fill further as the law-firm tool batches land; local vars
 * prefixed `$nvoos_content_graph_pro_*`; full-body
 * `! defined( 'WP_MCP_AI_PATH' )` guard (the global enqueue helper would
 * collide compile-time with the base copy in the monorepo test matrix).
 *
 * @package NvoosContentGraphPro
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_MCP_AI_PATH' ) ) {

	$nvoos_content_graph_pro_settings   = get_option( 'wp_mcp_ai_settings', array() );
	$nvoos_content_graph_pro_is_enabled = ! empty( $nvoos_content_graph_pro_settings['enable_law_firm_toolkit'] );
	$nvoos_content_graph_pro_is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

	if ( $nvoos_content_graph_pro_is_enabled && ! $nvoos_content_graph_pro_is_base ) {

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-law-firm-cpt.php';
		WP_MCP_AI_Law_Firm_CPT::init();

		if ( is_admin() ) {
			$nvoos_content_graph_pro_lf_settings_page = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-law-firm-settings-page.php';
			if ( file_exists( $nvoos_content_graph_pro_lf_settings_page ) ) {
				require_once $nvoos_content_graph_pro_lf_settings_page;
			}

			$nvoos_content_graph_pro_lf_settings = get_option( 'wp_mcp_ai_law_firm_settings', array() );

			// Load Research & Add page if enabled (defaults to true) — file-gated.
			$nvoos_content_graph_pro_research_on = isset( $nvoos_content_graph_pro_lf_settings['enable_research'] ) ? (bool) $nvoos_content_graph_pro_lf_settings['enable_research'] : true;
			if ( $nvoos_content_graph_pro_research_on ) {
				$nvoos_content_graph_pro_lf_research_page = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-law-firm-research-page.php';
				if ( file_exists( $nvoos_content_graph_pro_lf_research_page ) ) {
					require_once $nvoos_content_graph_pro_lf_research_page;
					WP_MCP_AI_Law_Firm_Research_Page::init();
				}
			}

			// Load Firm Dashboard page if enabled (defaults to true) — file-gated.
			$nvoos_content_graph_pro_dashboard_on = isset( $nvoos_content_graph_pro_lf_settings['enable_firm_dashboard'] ) ? (bool) $nvoos_content_graph_pro_lf_settings['enable_firm_dashboard'] : true;
			if ( $nvoos_content_graph_pro_dashboard_on ) {
				$nvoos_content_graph_pro_lf_dashboard_page = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-law-firm-dashboard-page.php';
				if ( file_exists( $nvoos_content_graph_pro_lf_dashboard_page ) ) {
					require_once $nvoos_content_graph_pro_lf_dashboard_page;
					WP_MCP_AI_Law_Firm_Dashboard_Page::init();
				}
			}

			unset(
				$nvoos_content_graph_pro_lf_settings,
				$nvoos_content_graph_pro_research_on,
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
	 * Enqueue Law Firm toolkit admin styles.
	 *
	 * @param string $hook Current admin page hook.
	 */
	function wp_mcp_ai_enqueue_law_firm_toolkit_admin_styles( $hook ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_law_firm_toolkit'] ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! isset( $screen->post_type ) || ! in_array( $screen->post_type, array( 'mcp_ai_lf_matter', 'mcp_ai_lf_client', 'mcp_ai_lf_document', 'mcp_ai_lf_time_entry', 'mcp_ai_lf_trust_txn' ), true ) ) {
			return;
		}

		$css_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'assets/css/admin-law-firm-toolkit.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'wp-mcp-ai-law-firm-toolkit-admin',
				NVOOS_CONTENT_GRAPH_PRO_URL . 'assets/css/admin-law-firm-toolkit.css',
				array(),
				NVOOS_CONTENT_GRAPH_PRO_VERSION
			);
		}
	}
	add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_law_firm_toolkit_admin_styles' );

	// ---- Standalone-only tool wiring (deviation). ----
	add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_law_firm_tools', 10 );

	if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_law_firm_ecosystem_tools();
	}
} // End monolith guard (deviation).

/**
 * Standalone-only tool filter — mirrors the monolith's inline law-firm map
 * (the `enable_law_firm_toolkit` gate in `mcp-ai-wpoos-pro.php`). The map
 * fills as the law-firm tool batches land.
 *
 * @param array $tools Existing tool map.
 * @return array Extended tool map.
 */
function wp_mcp_ai_pro_register_law_firm_tools( $tools ) {
	$nvoos_content_graph_pro_law_tools = array(
		'WP_MCP_AI_Tool_LF_Calendar_Rule_Calculator'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-calendar-rule-calculator.php',
		'WP_MCP_AI_Tool_LF_Case_Outcome_Predictor'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-case-outcome-predictor.php',
		'WP_MCP_AI_Tool_LF_Case_Status_Dashboard'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-case-status-dashboard.php',
		'WP_MCP_AI_Tool_LF_Case_Timeline_Generator'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-case-timeline-generator.php',
		'WP_MCP_AI_Tool_LF_Court_Deadline_Tracker'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-court-deadline-tracker.php',
		'WP_MCP_AI_Tool_LF_Matter_Budget_Manager'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-matter-budget-manager.php',
		'WP_MCP_AI_Tool_LF_Matter_Pipeline_Manager'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-matter-pipeline-manager.php',
		'WP_MCP_AI_Tool_LF_Opposing_Counsel_Tracker'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-opposing-counsel-tracker.php',
		'WP_MCP_AI_Tool_LF_Statute_Of_Limitations_Calculator' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-statute-of-limitations-calculator.php',
		'WP_MCP_AI_Tool_LF_Task_Assignment_Manager'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-task-assignment-manager.php',
		'WP_MCP_AI_Tool_LF_Accounts_Receivable_Tracker'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-accounts-receivable-tracker.php',
		'WP_MCP_AI_Tool_LF_Billing_Compliance_Checker'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-billing-compliance-checker.php',
		'WP_MCP_AI_Tool_LF_Expense_Reimbursement_Tracker'  => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-expense-reimbursement-tracker.php',
		'WP_MCP_AI_Tool_LF_Fee_Calculator'                 => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-fee-calculator.php',
		'WP_MCP_AI_Tool_LF_Invoice_Generator'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-invoice-generator.php',
		'WP_MCP_AI_Tool_LF_Profitability_Analyzer'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-profitability-analyzer.php',
		'WP_MCP_AI_Tool_LF_Retainer_Balance_Monitor'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-retainer-balance-monitor.php',
		'WP_MCP_AI_Tool_LF_Time_Entry_Recorder'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-time-entry-recorder.php',
		'WP_MCP_AI_Tool_LF_Trust_Account_Manager'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-trust-account-manager.php',
		'WP_MCP_AI_Tool_LF_Trust_Reconciliation_Tool'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/billing-trust/class-wp-mcp-ai-tool-lf-trust-reconciliation-tool.php',
		'WP_MCP_AI_Tool_LF_Client_Communication_Logger'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-client-communication-logger.php',
		'WP_MCP_AI_Tool_LF_Client_Intake_Processor'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-client-intake-processor.php',
		'WP_MCP_AI_Tool_LF_Client_Portal_Manager'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-client-portal-manager.php',
		'WP_MCP_AI_Tool_LF_Client_Profile_Analyzer'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-client-profile-analyzer.php',
		'WP_MCP_AI_Tool_LF_Conflict_Of_Interest_Checker'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-conflict-of-interest-checker.php',
		'WP_MCP_AI_Tool_LF_Engagement_Letter_Generator'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-engagement-letter-generator.php',
		'WP_MCP_AI_Tool_LF_Lead_Scoring_Calculator'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-lead-scoring-calculator.php',
		'WP_MCP_AI_Tool_LF_Referral_Source_Tracker'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-referral-source-tracker.php',
		'WP_MCP_AI_Tool_LF_Ediscovery_Document_Analyzer'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-ediscovery-document-analyzer.php',
		'WP_MCP_AI_Tool_LF_Deposition_Summary_Generator'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-deposition-summary-generator.php',
		'WP_MCP_AI_Tool_LF_Evidence_Catalog_Manager'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-evidence-catalog-manager.php',
		'WP_MCP_AI_Tool_LF_Jury_Instruction_Drafter'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-jury-instruction-drafter.php',
		'WP_MCP_AI_Tool_LF_Settlement_Value_Calculator'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-settlement-value-calculator.php',
		'WP_MCP_AI_Tool_LF_Damages_Calculator'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-damages-calculator.php',
		'WP_MCP_AI_Tool_LF_Expert_Witness_Tracker'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-expert-witness-tracker.php',
		'WP_MCP_AI_Tool_LF_Trial_Preparation_Checklist'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-trial-preparation-checklist.php',
		'WP_MCP_AI_Tool_LF_Ethics_Rule_Checker'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-ethics-rule-checker.php',
		'WP_MCP_AI_Tool_LF_Bar_Deadline_Monitor'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-bar-deadline-monitor.php',
		'WP_MCP_AI_Tool_LF_CLE_Credit_Tracker'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-cle-credit-tracker.php',
		'WP_MCP_AI_Tool_LF_Malpractice_Risk_Scorer'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-malpractice-risk-scorer.php',
		'WP_MCP_AI_Tool_LF_Data_Privacy_Compliance_Checker' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-data-privacy-compliance-checker.php',
		'WP_MCP_AI_Tool_LF_Client_Confidentiality_Auditor' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-client-confidentiality-auditor.php',
		'WP_MCP_AI_Tool_LF_Regulatory_Change_Monitor'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-regulatory-change-monitor.php',
		'WP_MCP_AI_Tool_LF_AI_Usage_Disclosure_Generator'  => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-ai-usage-disclosure-generator.php',
		'WP_MCP_AI_Tool_LF_Document_Drafter'               => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-document-drafter.php',
		'WP_MCP_AI_Tool_LF_Contract_Reviewer'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-contract-reviewer.php',
		'WP_MCP_AI_Tool_LF_Clause_Library_Manager'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-clause-library-manager.php',
		'WP_MCP_AI_Tool_LF_Redline_Comparator'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-redline-comparator.php',
		'WP_MCP_AI_Tool_LF_Pleading_Generator'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-pleading-generator.php',
		'WP_MCP_AI_Tool_LF_Discovery_Request_Builder'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-discovery-request-builder.php',
		'WP_MCP_AI_Tool_LF_Document_Version_Tracker'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-document-version-tracker.php',
		'WP_MCP_AI_Tool_LF_Legal_Citation_Checker'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-legal-citation-checker.php',
		'WP_MCP_AI_Tool_LF_Brief_Outline_Generator'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-brief-outline-generator.php',
		'WP_MCP_AI_Tool_LF_Document_Template_Manager'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-document-template-manager.php',
		'WP_MCP_AI_Tool_LF_Legal_Research_Assistant'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-legal-research-assistant.php',
		'WP_MCP_AI_Tool_LF_Case_Law_Analyzer'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-case-law-analyzer.php',
		'WP_MCP_AI_Tool_LF_Firm_Performance_Dashboard'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-firm-performance-dashboard.php',
		'WP_MCP_AI_Tool_LF_Matter_Analytics_Generator'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-matter-analytics-generator.php',
		'WP_MCP_AI_Tool_LF_Revenue_Forecaster'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-revenue-forecaster.php',
		'WP_MCP_AI_Tool_LF_Attorney_Utilization_Tracker'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-attorney-utilization-tracker.php',
		'WP_MCP_AI_Tool_LF_Client_Satisfaction_Analyzer'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-client-satisfaction-analyzer.php',
		'WP_MCP_AI_Tool_LF_Competitive_Benchmarker'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/research-analytics/class-wp-mcp-ai-tool-lf-competitive-benchmarker.php',
		'WP_MCP_AI_Tool_Import_Law_Firm_Blueprint'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/law-firm/examples/class-wp-mcp-ai-tool-import-law-firm-blueprint.php',
	);

	return array_merge( $tools, $nvoos_content_graph_pro_law_tools );
}

/**
 * Standalone-only ecosystem registration — registers the ported law-firm
 * tools into the ecosystem graph ToolRegistry and the nvoos/core registry
 * via `WP_MCP_AI_Pro_Tool_Adapter` (same wiring as the image-production
 * inits). The list fills as the law-firm tool batches land.
 *
 * @return void
 */
function wp_mcp_ai_pro_register_law_firm_ecosystem_tools() {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-tool-adapter.php';

	$nvoos_content_graph_pro_parent_registry = nvoos_content_graph_get_tool_registry();
	if ( ! $nvoos_content_graph_pro_parent_registry instanceof \NvoosContentGraph\ToolRegistry ) {
		return;
	}

	foreach (
		array(
			'WP_MCP_AI_Tool_LF_Calendar_Rule_Calculator',
			'WP_MCP_AI_Tool_LF_Case_Outcome_Predictor',
			'WP_MCP_AI_Tool_LF_Case_Status_Dashboard',
			'WP_MCP_AI_Tool_LF_Case_Timeline_Generator',
			'WP_MCP_AI_Tool_LF_Court_Deadline_Tracker',
			'WP_MCP_AI_Tool_LF_Matter_Budget_Manager',
			'WP_MCP_AI_Tool_LF_Matter_Pipeline_Manager',
			'WP_MCP_AI_Tool_LF_Opposing_Counsel_Tracker',
			'WP_MCP_AI_Tool_LF_Statute_Of_Limitations_Calculator',
			'WP_MCP_AI_Tool_LF_Task_Assignment_Manager',
			'WP_MCP_AI_Tool_LF_Accounts_Receivable_Tracker',
			'WP_MCP_AI_Tool_LF_Billing_Compliance_Checker',
			'WP_MCP_AI_Tool_LF_Expense_Reimbursement_Tracker',
			'WP_MCP_AI_Tool_LF_Fee_Calculator',
			'WP_MCP_AI_Tool_LF_Invoice_Generator',
			'WP_MCP_AI_Tool_LF_Profitability_Analyzer',
			'WP_MCP_AI_Tool_LF_Retainer_Balance_Monitor',
			'WP_MCP_AI_Tool_LF_Time_Entry_Recorder',
			'WP_MCP_AI_Tool_LF_Trust_Account_Manager',
			'WP_MCP_AI_Tool_LF_Trust_Reconciliation_Tool',
			'WP_MCP_AI_Tool_LF_Client_Communication_Logger',
			'WP_MCP_AI_Tool_LF_Client_Intake_Processor',
			'WP_MCP_AI_Tool_LF_Client_Portal_Manager',
			'WP_MCP_AI_Tool_LF_Client_Profile_Analyzer',
			'WP_MCP_AI_Tool_LF_Conflict_Of_Interest_Checker',
			'WP_MCP_AI_Tool_LF_Engagement_Letter_Generator',
			'WP_MCP_AI_Tool_LF_Lead_Scoring_Calculator',
			'WP_MCP_AI_Tool_LF_Referral_Source_Tracker',
			'WP_MCP_AI_Tool_LF_Ediscovery_Document_Analyzer',
			'WP_MCP_AI_Tool_LF_Deposition_Summary_Generator',
			'WP_MCP_AI_Tool_LF_Evidence_Catalog_Manager',
			'WP_MCP_AI_Tool_LF_Jury_Instruction_Drafter',
			'WP_MCP_AI_Tool_LF_Settlement_Value_Calculator',
			'WP_MCP_AI_Tool_LF_Damages_Calculator',
			'WP_MCP_AI_Tool_LF_Expert_Witness_Tracker',
			'WP_MCP_AI_Tool_LF_Trial_Preparation_Checklist',
			'WP_MCP_AI_Tool_LF_Ethics_Rule_Checker',
			'WP_MCP_AI_Tool_LF_Bar_Deadline_Monitor',
			'WP_MCP_AI_Tool_LF_CLE_Credit_Tracker',
			'WP_MCP_AI_Tool_LF_Malpractice_Risk_Scorer',
			'WP_MCP_AI_Tool_LF_Data_Privacy_Compliance_Checker',
			'WP_MCP_AI_Tool_LF_Client_Confidentiality_Auditor',
			'WP_MCP_AI_Tool_LF_Regulatory_Change_Monitor',
			'WP_MCP_AI_Tool_LF_AI_Usage_Disclosure_Generator',
			'WP_MCP_AI_Tool_LF_Document_Drafter',
			'WP_MCP_AI_Tool_LF_Contract_Reviewer',
			'WP_MCP_AI_Tool_LF_Clause_Library_Manager',
			'WP_MCP_AI_Tool_LF_Redline_Comparator',
			'WP_MCP_AI_Tool_LF_Pleading_Generator',
			'WP_MCP_AI_Tool_LF_Discovery_Request_Builder',
			'WP_MCP_AI_Tool_LF_Document_Version_Tracker',
			'WP_MCP_AI_Tool_LF_Legal_Citation_Checker',
			'WP_MCP_AI_Tool_LF_Brief_Outline_Generator',
			'WP_MCP_AI_Tool_LF_Document_Template_Manager',
			'WP_MCP_AI_Tool_LF_Legal_Research_Assistant',
			'WP_MCP_AI_Tool_LF_Case_Law_Analyzer',
			'WP_MCP_AI_Tool_LF_Firm_Performance_Dashboard',
			'WP_MCP_AI_Tool_LF_Matter_Analytics_Generator',
			'WP_MCP_AI_Tool_LF_Revenue_Forecaster',
			'WP_MCP_AI_Tool_LF_Attorney_Utilization_Tracker',
			'WP_MCP_AI_Tool_LF_Client_Satisfaction_Analyzer',
			'WP_MCP_AI_Tool_LF_Competitive_Benchmarker',
			'WP_MCP_AI_Tool_Import_Law_Firm_Blueprint',
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
