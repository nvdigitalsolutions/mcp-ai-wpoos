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
 *    inbound listeners, and the tool files land with their F2
 *    sub-clusters; every deferred require stays file-gated so this init
 *    degrades gracefully until each file exists (same wave-proof pattern
 *    as the F1 module files guards).
 * 4. The JetEngine company-field registration stays byte-identical
 *    (`function_exists( 'jet_engine' )` + `class_exists(
 *    'WP_MCP_AI_JetEngine_Meta_Helper' )` guard — dormant standalone).
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
}
