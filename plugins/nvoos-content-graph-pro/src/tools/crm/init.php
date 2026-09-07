<?php
/**
 * CRM & Email Marketing Toolkit Initialization (ecosystem port — Wave F2
 * pilot, sub-cluster 1).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/crm/init.php`
 * for the standalone `nvoos-content-graph-pro` addon. Loads the CRM data
 * layer: the shared engine classes and the CRM CPT set.
 *
 * Documented deviations from the monolith copy:
 *
 * 1. `declare(strict_types=1)` added.
 * 2. File paths resolve from `NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/'`.
 * 3. Slimmed wiring — the research-add page, inbound listeners, and the
 *    remaining tool files land with their F2 sub-clusters; every deferred
 *    require stays file-gated so this init degrades gracefully until each
 *    file exists (same wave-proof pattern as the F1 module files guards).
 *    The CRM REST controller, the command-center page, the per-CPT
 *    settings pages, and the blueprints page landed with their F2
 *    slices.
 * 4. The JetEngine company-field registration stays byte-identical
 *    (`function_exists( 'jet_engine' )` + `class_exists(
 *    'WP_MCP_AI_JetEngine_Meta_Helper' )` guard — dormant standalone).
 * 5. New standalone-only tool wiring (no monolith counterpart — the
 *    monolith builds the CRM tool map inline inside
 *    `wp_mcp_ai_pro_register_tools()`): a `wp_mcp_ai_pro_tools` filter
 *    carrying the ported tool subset (inert standalone — the base plugin
 *    consumes it monolith) plus `wp_mcp_ai_pro_register_crm_ecosystem_tools()`
 *    registering the ported tools into the ecosystem graph ToolRegistry via
 *    `WP_MCP_AI_Pro_Tool_Adapter` (same wiring as the vault).
 *
 * @package NvoosContentGraphPro
 * @since   1.0.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check if CRM toolkit is enabled (byte-identical gate).
$nvoos_content_graph_pro_settings   = get_option( 'wp_mcp_ai_settings', array() );
$nvoos_content_graph_pro_is_enabled = ! empty( $nvoos_content_graph_pro_settings['enable_crm_toolkit'] );
$nvoos_content_graph_pro_is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

// Only load if enabled and not in base version.
if ( $nvoos_content_graph_pro_is_enabled && ! $nvoos_content_graph_pro_is_base ) {

	// ---- Phase A: Shared CRM engine (loaded before any tool) ----
	$nvoos_content_graph_pro_crm_engine_dir = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/';

	// Shared engine classes (mirrors Healthcare toolkit architecture).
	$nvoos_content_graph_pro_crm_files = array(
		'class-wp-mcp-ai-crm-engine.php',
		'class-wp-mcp-ai-crm-codes.php',
		'class-wp-mcp-ai-crm-audit.php',
		'class-wp-mcp-ai-crm-capabilities.php',
		'class-wp-mcp-ai-crm-consent.php',
		'class-wp-mcp-ai-crm-pipeline-stages.php',
		'class-wp-mcp-ai-crm-classifier.php',
	);
	foreach ( $nvoos_content_graph_pro_crm_files as $nvoos_content_graph_pro_file ) {
		$nvoos_content_graph_pro_path = $nvoos_content_graph_pro_crm_engine_dir . $nvoos_content_graph_pro_file;
		if ( file_exists( $nvoos_content_graph_pro_path ) ) {
			require_once $nvoos_content_graph_pro_path;
		}
	}

	// Load Company CPT.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-company-cpt.php';
	WP_MCP_AI_Company_CPT::init();

	// ---- Phase G: ICP (Ideal Customer Profile) Module ----
	// Load ICP Profile data store and scoring engine before the ICP tools
	// (mirrors the base init's Phase G).
	$nvoos_content_graph_pro_icp_dir   = $nvoos_content_graph_pro_crm_engine_dir . 'icp/';
	$nvoos_content_graph_pro_icp_files = array(
		'class-wp-mcp-ai-icp-profile.php',
		'class-wp-mcp-ai-icp-scorer.php',
	);
	foreach ( $nvoos_content_graph_pro_icp_files as $nvoos_content_graph_pro_icp_file ) {
		$nvoos_content_graph_pro_icp_path = $nvoos_content_graph_pro_icp_dir . $nvoos_content_graph_pro_icp_file;
		if ( file_exists( $nvoos_content_graph_pro_icp_path ) ) {
			require_once $nvoos_content_graph_pro_icp_path;
		}
	}

	// Register Company meta fields with JetEngine for listing/discovery
	// (dormant standalone — the helper lands with a later wave).
	if ( function_exists( 'jet_engine' ) && class_exists( 'WP_MCP_AI_JetEngine_Meta_Helper' ) ) {
		WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( 'mcp_ai_company' );
	}

	// Phase B: Lead, Deal, Activity, Support Ticket, and Customer CPTs.
	$nvoos_content_graph_pro_phase_b_cpts = array(
		'class-wp-mcp-ai-lead-cpt.php',
		'class-wp-mcp-ai-deal-cpt.php',
		'class-wp-mcp-ai-crm-activity-cpt.php',
		'class-wp-mcp-ai-support-ticket-cpt.php',
		'class-wp-mcp-ai-customer-cpt.php',
	);
	foreach ( $nvoos_content_graph_pro_phase_b_cpts as $nvoos_content_graph_pro_cpt_file ) {
		$nvoos_content_graph_pro_cpt_path = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/' . $nvoos_content_graph_pro_cpt_file;
		if ( file_exists( $nvoos_content_graph_pro_cpt_path ) ) {
			require_once $nvoos_content_graph_pro_cpt_path;
		}
	}
	WP_MCP_AI_Lead_CPT::init();
	WP_MCP_AI_Deal_CPT::init();
	WP_MCP_AI_CRM_Activity_CPT::init();
	WP_MCP_AI_Support_Ticket_CPT::init();
	WP_MCP_AI_Customer_CPT::init();

	// Phase D: Sequence and Workflow Rule CPTs.
	$nvoos_content_graph_pro_phase_d_cpts = array(
		'class-wp-mcp-ai-sequence-cpt.php',
		'class-wp-mcp-ai-crm-workflow-rule-cpt.php',
	);
	foreach ( $nvoos_content_graph_pro_phase_d_cpts as $nvoos_content_graph_pro_cpt_file ) {
		$nvoos_content_graph_pro_cpt_path = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/' . $nvoos_content_graph_pro_cpt_file;
		if ( file_exists( $nvoos_content_graph_pro_cpt_path ) ) {
			require_once $nvoos_content_graph_pro_cpt_path;
		}
	}
	WP_MCP_AI_Sequence_CPT::init();
	WP_MCP_AI_CRM_Workflow_Rule_CPT::init();

	// Load CRM REST controller for Toolkit Shell SPA (F2 REST slice).
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/rest/class-wp-mcp-ai-crm-rest-controller.php';
	WP_MCP_AI_CRM_REST_Controller::get_instance()->init();

	// ---- Deferred F2 sub-clusters (file-gated) -------------------------
	// Per-CPT settings pages, blueprints, research-add, support tools,
	// Upwork/LinkedIn files, and the inbound listeners land with their
	// sub-clusters; each require below fires only once the file exists.
	if ( is_admin() ) {
		// CRM admin menu (command-center landing page is a forward-reference
		// — its class lands with a later admin slice; the menu callback
		// resolves lazily at render time).
		$nvoos_content_graph_pro_crm_admin_menu = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-crm-admin-menu.php';
		if ( file_exists( $nvoos_content_graph_pro_crm_admin_menu ) ) {
			require_once $nvoos_content_graph_pro_crm_admin_menu;
			WP_MCP_AI_CRM_Admin_Menu::init();
		}

		// CRM Command Center page (F2 admin remainder).
		$nvoos_content_graph_pro_command_center_page = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-crm-command-center-page.php';
		if ( file_exists( $nvoos_content_graph_pro_command_center_page ) ) {
			require_once $nvoos_content_graph_pro_command_center_page;
			WP_MCP_AI_CRM_Command_Center_Page::init();
		}

		// CRM Blueprints page (F2 admin remainder).
		$nvoos_content_graph_pro_blueprints_page = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-crm-blueprints-page.php';
		if ( file_exists( $nvoos_content_graph_pro_blueprints_page ) ) {
			require_once $nvoos_content_graph_pro_blueprints_page;
			WP_MCP_AI_CRM_Blueprints_Page::init();
		}

		// Per-CPT settings pages (F2 admin settings batch).
		$nvoos_content_graph_pro_cpt_settings_pages = array(
			'company'        => 'WP_MCP_AI_Company_Settings_Page',
			'lead'           => 'WP_MCP_AI_Lead_Settings_Page',
			'deal'           => 'WP_MCP_AI_Deal_Settings_Page',
			'support-ticket' => 'WP_MCP_AI_Support_Ticket_Settings_Page',
			'customer'       => 'WP_MCP_AI_Customer_Settings_Page',
		);
		foreach ( $nvoos_content_graph_pro_cpt_settings_pages as $nvoos_content_graph_pro_cpt_slug => $nvoos_content_graph_pro_cpt_class ) {
			$nvoos_content_graph_pro_cpt_page = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-' . $nvoos_content_graph_pro_cpt_slug . '-settings-page.php';
			if ( file_exists( $nvoos_content_graph_pro_cpt_page ) ) {
				require_once $nvoos_content_graph_pro_cpt_page;
				$nvoos_content_graph_pro_cpt_class::init();
			}
		}
		unset( $nvoos_content_graph_pro_cpt_slug, $nvoos_content_graph_pro_cpt_class, $nvoos_content_graph_pro_cpt_page );

		// ICP Profiles admin page (F2 admin slice).
		$nvoos_content_graph_pro_icp_admin = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-icp-admin-page.php';
		if ( file_exists( $nvoos_content_graph_pro_icp_admin ) ) {
			require_once $nvoos_content_graph_pro_icp_admin;
			WP_MCP_AI_ICP_Admin_Page::init();
		}
	}

	// ---- F2 tool sub-clusters (file-gated) -----------------------------
	// The CRM tool files land with their sub-clusters; the proof pair wires
	// the first one. The `wp_mcp_ai_pro_tools` filter carries the ported
	// tool subset (inert standalone — the base plugin consumes it
	// monolith); the ecosystem registration below is the standalone path.
	add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_crm_tools', 10 );

	// Standalone-only ecosystem tool registration (deviation 5, same
	// wiring as the vault). The graph plugin's
	// `nvoos_content_graph/register_tools` action fired at plugins_loaded
	// 10 — before this addon boots at 15 — so register directly into the
	// registries instead of hooking the action.
	if ( ! defined( 'WP_MCP_AI_PATH' ) && function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_crm_ecosystem_tools();
	}
}

/**
 * Register the ported CRM tools with the `wp_mcp_ai_pro_tools` filter
 * (deviation 5 — a subset of the monolith's inline `$crm_tools` map built
 * inside `wp_mcp_ai_pro_register_tools()`; the base plugin consumes the
 * filter monolith, standalone it is inert — documented).
 *
 * @since 1.0.0
 *
 * @param array $tools Existing tools array.
 * @return array Updated tools array.
 */
function wp_mcp_ai_pro_register_crm_tools( $tools ) {
	$crm_tools = array(
		'WP_MCP_AI_Tool_Create_Company'                  => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/class-wp-mcp-ai-tool-create-company.php',
		'WP_MCP_AI_Tool_Manage_CRM_Contact'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/class-wp-mcp-ai-tool-manage-crm-contact.php',
		'WP_MCP_AI_Tool_CRM_Email_Search_Leads'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/class-wp-mcp-ai-tool-crm-email-search-leads.php',
		'WP_MCP_AI_Tool_CRM_Email_Search_Correspondence' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/class-wp-mcp-ai-tool-crm-email-search-correspondence.php',
		'WP_MCP_AI_Tool_CRM_Email_Search_Accounting'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/class-wp-mcp-ai-tool-crm-email-search-accounting.php',
		'WP_MCP_AI_Tool_CRM_Capture_Interaction'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/class-wp-mcp-ai-tool-crm-capture-interaction.php',
		'WP_MCP_AI_Tool_Search_Upwork_Jobs'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/upwork/class-wp-mcp-ai-tool-search-upwork-jobs.php',
		'WP_MCP_AI_Tool_Score_Upwork_Job'                => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/upwork/class-wp-mcp-ai-tool-score-upwork-job.php',
		'WP_MCP_AI_Tool_Draft_Upwork_Proposal'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/upwork/class-wp-mcp-ai-tool-draft-upwork-proposal.php',
		'WP_MCP_AI_Tool_Create_Outreach_Sequence'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/sequences/class-wp-mcp-ai-tool-create-outreach-sequence.php',
		'WP_MCP_AI_Tool_Update_Outreach_Sequence'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/sequences/class-wp-mcp-ai-tool-update-outreach-sequence.php',
		'WP_MCP_AI_Tool_Delete_Outreach_Sequence'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/sequences/class-wp-mcp-ai-tool-delete-outreach-sequence.php',
		'WP_MCP_AI_Tool_List_Outreach_Sequences'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/sequences/class-wp-mcp-ai-tool-list-outreach-sequences.php',
		'WP_MCP_AI_Tool_Enroll_Lead_In_Sequence'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/sequences/class-wp-mcp-ai-tool-enroll-lead-in-sequence.php',
		'WP_MCP_AI_Tool_Manage_Sequence_State'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/sequences/class-wp-mcp-ai-tool-manage-sequence-state.php',
		'WP_MCP_AI_Tool_Get_Sequence_Performance'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/sequences/class-wp-mcp-ai-tool-get-sequence-performance.php',
		'WP_MCP_AI_Tool_Get_Companies'                   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/class-wp-mcp-ai-tool-get-companies.php',
		'WP_MCP_AI_Tool_Research_Company'                => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/class-wp-mcp-ai-tool-research-company.php',
		'WP_MCP_AI_Tool_Archive_Stale_Contacts'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/class-wp-mcp-ai-tool-archive-stale-contacts.php',
		'WP_MCP_AI_Tool_Create_Lead'                     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/leads/class-wp-mcp-ai-tool-create-lead.php',
		'WP_MCP_AI_Tool_List_Leads'                      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/leads/class-wp-mcp-ai-tool-list-leads.php',
		'WP_MCP_AI_Tool_Get_Lead'                        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/leads/class-wp-mcp-ai-tool-get-lead.php',
		'WP_MCP_AI_Tool_Update_Lead'                     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/leads/class-wp-mcp-ai-tool-update-lead.php',
		'WP_MCP_AI_Tool_Delete_Lead'                     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/leads/class-wp-mcp-ai-tool-delete-lead.php',
		'WP_MCP_AI_Tool_Convert_Lead_To_Customer'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/leads/class-wp-mcp-ai-tool-convert-lead-to-customer.php',
		'WP_MCP_AI_Tool_Create_Customer'                 => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/customers/class-wp-mcp-ai-tool-create-customer.php',
		'WP_MCP_AI_Tool_Create_Deal'                     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/deals/class-wp-mcp-ai-tool-create-deal.php',
		'WP_MCP_AI_Tool_List_Deals'                      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/deals/class-wp-mcp-ai-tool-list-deals.php',
		'WP_MCP_AI_Tool_Get_Deal'                        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/deals/class-wp-mcp-ai-tool-get-deal.php',
		'WP_MCP_AI_Tool_Update_Deal'                     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/deals/class-wp-mcp-ai-tool-update-deal.php',
		'WP_MCP_AI_Tool_Delete_Deal'                     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/deals/class-wp-mcp-ai-tool-delete-deal.php',
		'WP_MCP_AI_Tool_Move_Deal_Stage'                 => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/deals/class-wp-mcp-ai-tool-move-deal-stage.php',
		'WP_MCP_AI_Tool_Create_CRM_Activity'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/activities/class-wp-mcp-ai-tool-create-crm-activity.php',
		'WP_MCP_AI_Tool_List_CRM_Activities'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/activities/class-wp-mcp-ai-tool-list-crm-activities.php',
		'WP_MCP_AI_Tool_Get_CRM_Activity'                => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/activities/class-wp-mcp-ai-tool-get-crm-activity.php',
		'WP_MCP_AI_Tool_Complete_CRM_Activity'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/activities/class-wp-mcp-ai-tool-complete-crm-activity.php',
		'WP_MCP_AI_Tool_Snooze_CRM_Activity'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/activities/class-wp-mcp-ai-tool-snooze-crm-activity.php',
		'WP_MCP_AI_Tool_Get_Pipeline_View'               => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/analytics/class-wp-mcp-ai-tool-get-pipeline-view.php',
		'WP_MCP_AI_Tool_Get_Conversion_Funnel'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/analytics/class-wp-mcp-ai-tool-get-conversion-funnel.php',
		'WP_MCP_AI_Tool_Forecast_Pipeline_Revenue'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/analytics/class-wp-mcp-ai-tool-forecast-pipeline-revenue.php',
		'WP_MCP_AI_Tool_Identify_Top_Customers'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/analytics/class-wp-mcp-ai-tool-identify-top-customers.php',
		'WP_MCP_AI_Tool_Identify_Top_Clients'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/analytics/class-wp-mcp-ai-tool-identify-top-clients.php',
		'WP_MCP_AI_Tool_Assign_Lead_To_Owner'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/routing/class-wp-mcp-ai-tool-assign-lead-to-owner.php',
		'WP_MCP_AI_Tool_Rotate_Leads'                    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/routing/class-wp-mcp-ai-tool-rotate-leads.php',
		'WP_MCP_AI_Tool_Compute_ICP_Score'               => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/icp/class-wp-mcp-ai-tool-compute-icp-score.php',
		'WP_MCP_AI_Tool_Manage_ICP_Profile'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/icp/class-wp-mcp-ai-tool-manage-icp-profile.php',
		'WP_MCP_AI_Tool_Evaluate_Inbound_Message'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/inbound/class-wp-mcp-ai-tool-evaluate-inbound-message.php',
		'WP_MCP_AI_Tool_Classify_Message_Intent'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/inbound/class-wp-mcp-ai-tool-classify-message-intent.php',
		'WP_MCP_AI_Tool_Extract_Lead_From_Message'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/inbound/class-wp-mcp-ai-tool-extract-lead-from-message.php',
		'WP_MCP_AI_Tool_Detect_Buying_Signals'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/inbound/class-wp-mcp-ai-tool-detect-buying-signals.php',
		'WP_MCP_AI_Tool_Score_Lead'                      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/inbound/class-wp-mcp-ai-tool-score-lead.php',
		'WP_MCP_AI_Tool_Qualify_Lead_Bant'               => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/inbound/class-wp-mcp-ai-tool-qualify-lead-bant.php',
		'WP_MCP_AI_Tool_Qualify_Lead_Meddic'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/inbound/class-wp-mcp-ai-tool-qualify-lead-meddic.php',
		'WP_MCP_AI_Tool_Send_Lead_Email'                 => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/outbound/class-wp-mcp-ai-tool-send-lead-email.php',
		'WP_MCP_AI_Tool_Send_Lead_SMS'                   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/outbound/class-wp-mcp-ai-tool-send-lead-sms.php',
		'WP_MCP_AI_Tool_Send_Lead_Whatsapp'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/outbound/class-wp-mcp-ai-tool-send-lead-whatsapp.php',
		'WP_MCP_AI_Tool_Send_Lead_Dm'                    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/outbound/class-wp-mcp-ai-tool-send-lead-dm.php',
		'WP_MCP_AI_Tool_Log_Call_Outcome'                => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/outbound/class-wp-mcp-ai-tool-log-call-outcome.php',
		'WP_MCP_AI_Tool_Draft_Lead_Reply'                => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/outbound/class-wp-mcp-ai-tool-draft-lead-reply.php',
		'WP_MCP_AI_Tool_Auto_Reply_Inbound'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/outbound/class-wp-mcp-ai-tool-auto-reply-inbound.php',
		'WP_MCP_AI_Tool_Schedule_Follow_Up'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/outbound/class-wp-mcp-ai-tool-schedule-follow-up.php',
	);

	return array_merge( $tools, $crm_tools );
}

/**
 * Register the ported CRM tools with the ecosystem registries (standalone
 * only — deviation 5, same wiring as the vault).
 *
 * @since 1.0.0
 * @return void
 */
function wp_mcp_ai_pro_register_crm_ecosystem_tools() {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-tool-adapter.php';
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/class-wp-mcp-ai-tool-create-company.php';

	$parent_registry = nvoos_content_graph_get_tool_registry();
	if ( ! $parent_registry instanceof \NvoosContentGraph\ToolRegistry ) {
		return;
	}

	foreach (
		array(
			'WP_MCP_AI_Tool_Create_Company',
			'WP_MCP_AI_Tool_Manage_CRM_Contact',
			'WP_MCP_AI_Tool_CRM_Email_Search_Leads',
			'WP_MCP_AI_Tool_CRM_Email_Search_Correspondence',
			'WP_MCP_AI_Tool_CRM_Email_Search_Accounting',
			'WP_MCP_AI_Tool_CRM_Capture_Interaction',
			'WP_MCP_AI_Tool_Search_Upwork_Jobs',
			'WP_MCP_AI_Tool_Score_Upwork_Job',
			'WP_MCP_AI_Tool_Draft_Upwork_Proposal',
			'WP_MCP_AI_Tool_Create_Outreach_Sequence',
			'WP_MCP_AI_Tool_Update_Outreach_Sequence',
			'WP_MCP_AI_Tool_Delete_Outreach_Sequence',
			'WP_MCP_AI_Tool_List_Outreach_Sequences',
			'WP_MCP_AI_Tool_Enroll_Lead_In_Sequence',
			'WP_MCP_AI_Tool_Manage_Sequence_State',
			'WP_MCP_AI_Tool_Get_Sequence_Performance',
			'WP_MCP_AI_Tool_Get_Companies',
			'WP_MCP_AI_Tool_Research_Company',
			'WP_MCP_AI_Tool_Archive_Stale_Contacts',
			'WP_MCP_AI_Tool_Create_Lead',
			'WP_MCP_AI_Tool_List_Leads',
			'WP_MCP_AI_Tool_Get_Lead',
			'WP_MCP_AI_Tool_Update_Lead',
			'WP_MCP_AI_Tool_Delete_Lead',
			'WP_MCP_AI_Tool_Convert_Lead_To_Customer',
			'WP_MCP_AI_Tool_Create_Customer',
			'WP_MCP_AI_Tool_Create_Deal',
			'WP_MCP_AI_Tool_List_Deals',
			'WP_MCP_AI_Tool_Get_Deal',
			'WP_MCP_AI_Tool_Update_Deal',
			'WP_MCP_AI_Tool_Delete_Deal',
			'WP_MCP_AI_Tool_Move_Deal_Stage',
			'WP_MCP_AI_Tool_Create_CRM_Activity',
			'WP_MCP_AI_Tool_List_CRM_Activities',
			'WP_MCP_AI_Tool_Get_CRM_Activity',
			'WP_MCP_AI_Tool_Complete_CRM_Activity',
			'WP_MCP_AI_Tool_Snooze_CRM_Activity',
			'WP_MCP_AI_Tool_Get_Pipeline_View',
			'WP_MCP_AI_Tool_Get_Conversion_Funnel',
			'WP_MCP_AI_Tool_Forecast_Pipeline_Revenue',
			'WP_MCP_AI_Tool_Identify_Top_Customers',
			'WP_MCP_AI_Tool_Identify_Top_Clients',
			'WP_MCP_AI_Tool_Assign_Lead_To_Owner',
			'WP_MCP_AI_Tool_Rotate_Leads',
			'WP_MCP_AI_Tool_Compute_ICP_Score',
			'WP_MCP_AI_Tool_Manage_ICP_Profile',
			'WP_MCP_AI_Tool_Evaluate_Inbound_Message',
			'WP_MCP_AI_Tool_Classify_Message_Intent',
			'WP_MCP_AI_Tool_Extract_Lead_From_Message',
			'WP_MCP_AI_Tool_Detect_Buying_Signals',
			'WP_MCP_AI_Tool_Score_Lead',
			'WP_MCP_AI_Tool_Qualify_Lead_Bant',
			'WP_MCP_AI_Tool_Qualify_Lead_Meddic',
			'WP_MCP_AI_Tool_Send_Lead_Email',
			'WP_MCP_AI_Tool_Send_Lead_SMS',
			'WP_MCP_AI_Tool_Send_Lead_Whatsapp',
			'WP_MCP_AI_Tool_Send_Lead_Dm',
			'WP_MCP_AI_Tool_Log_Call_Outcome',
			'WP_MCP_AI_Tool_Draft_Lead_Reply',
			'WP_MCP_AI_Tool_Auto_Reply_Inbound',
			'WP_MCP_AI_Tool_Schedule_Follow_Up',
		) as $tool_class
	) {
		$adapter = new WP_MCP_AI_Pro_Tool_Adapter( new $tool_class() );
		try {
			$parent_registry->register( $adapter );
		} catch ( \RuntimeException $e ) {
			unset( $e ); // Duplicate slug — non-fatal.
		}

		// Wrap into the nvoos/core registry so the agentic chat loop can
		// resolve and execute the tool (same path the AI addon uses for
		// graph tools).
		if ( class_exists( 'NvoosContentGraphAi\\CoreBridge' ) ) {
			$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
			try {
				$core_tools->register( new \NvoosContentGraphAi\Adapter\GraphToolAdapter( $adapter ) );
			} catch ( \RuntimeException $e ) {
				unset( $e ); // Duplicate slug — non-fatal.
			}
		}
	}
}
