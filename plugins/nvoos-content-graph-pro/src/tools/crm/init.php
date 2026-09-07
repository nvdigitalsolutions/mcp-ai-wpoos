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
 * 3. Slimmed wiring — the admin pages, REST controller, research-add,
 *    inbound listeners, and the remaining tool files land with their F2
 *    sub-clusters; every deferred require stays file-gated so this init
 *    degrades gracefully until each file exists (same wave-proof pattern
 *    as the F1 module files guards).
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

	// ---- Deferred F2 sub-clusters (file-gated) -------------------------
	// Admin pages, CRM REST controller, research-add, support tools,
	// Upwork/LinkedIn/ICP files, and the inbound listeners land with their
	// sub-clusters; each require below fires only once the file exists.
	if ( is_admin() ) {
		$nvoos_content_graph_pro_crm_admin_menu = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-crm-admin-menu.php';
		if ( file_exists( $nvoos_content_graph_pro_crm_admin_menu ) ) {
			require_once $nvoos_content_graph_pro_crm_admin_menu;
			WP_MCP_AI_CRM_Admin_Menu::init();
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
		'WP_MCP_AI_Tool_Create_Company'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/class-wp-mcp-ai-tool-create-company.php',
		'WP_MCP_AI_Tool_Get_Companies'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/class-wp-mcp-ai-tool-get-companies.php',
		'WP_MCP_AI_Tool_Research_Company'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/class-wp-mcp-ai-tool-research-company.php',
		'WP_MCP_AI_Tool_Archive_Stale_Contacts'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/class-wp-mcp-ai-tool-archive-stale-contacts.php',
		'WP_MCP_AI_Tool_Create_Lead'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/leads/class-wp-mcp-ai-tool-create-lead.php',
		'WP_MCP_AI_Tool_List_Leads'               => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/leads/class-wp-mcp-ai-tool-list-leads.php',
		'WP_MCP_AI_Tool_Get_Lead'                 => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/leads/class-wp-mcp-ai-tool-get-lead.php',
		'WP_MCP_AI_Tool_Update_Lead'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/leads/class-wp-mcp-ai-tool-update-lead.php',
		'WP_MCP_AI_Tool_Delete_Lead'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/leads/class-wp-mcp-ai-tool-delete-lead.php',
		'WP_MCP_AI_Tool_Convert_Lead_To_Customer' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/leads/class-wp-mcp-ai-tool-convert-lead-to-customer.php',
		'WP_MCP_AI_Tool_Create_Customer'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/customers/class-wp-mcp-ai-tool-create-customer.php',
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
