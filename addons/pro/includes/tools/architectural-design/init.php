<?php
/**
 * Architectural Design Toolkit Initialization
 *
 * Loads the Architectural Design Toolkit system for AI-powered floor plan generation,
 * 3D modeling, blueprint creation, code compliance, and cost estimation.
 *
 * Implements CPT-based structure following industry standards (AIA, CSI MasterFormat).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load CPT classes.
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-architectural-project-cpt.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-architectural-drawing-cpt.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-architectural-specification-cpt.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-architectural-precedent-cpt.php';

// Initialize CPTs - they have their own checks for enabled/base version.
WP_MCP_AI_Architectural_Project_CPT::init();
WP_MCP_AI_Architectural_Drawing_CPT::init();
WP_MCP_AI_Architectural_Specification_CPT::init();
WP_MCP_AI_Architectural_Precedent_CPT::init();

// Load Research & Add and Settings pages for admin.
if ( is_admin() ) {
	// Check if architectural design toolkit is enabled and not in base version (unless Pro addon is active).
	$settings      = get_option( 'wp_mcp_ai_settings', array() );
	$is_enabled    = ! empty( $settings['enable_architectural_design_toolkit'] );
	$is_base       = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
	$is_pro_active = defined( 'WP_MCP_AI_PRO_VERSION' );

	if ( $is_enabled && ( ! $is_base || $is_pro_active ) ) {
		// Load Project Settings and Research & Add pages (under Design Projects menu).
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-architectural-project-settings-page.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-architectural-project-research-page.php';
		WP_MCP_AI_Architectural_Project_Research_Page::init();

		// Load Drawing Settings and Research & Add pages (under Drawings menu).
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-architectural-drawing-settings-page.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-architectural-drawing-research-page.php';
		WP_MCP_AI_Architectural_Drawing_Research_Page::init();

		// Load Specification Settings and Research & Add pages (under Specifications menu).
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-architectural-specification-settings-page.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-architectural-specification-research-page.php';
		WP_MCP_AI_Architectural_Specification_Research_Page::init();
	}
}

/**
 * Initialize architectural design admin interface.
 */
function wp_mcp_ai_init_architectural_design_admin() {
	// Only load in admin context.
	if ( ! is_admin() ) {
		return;
	}

	// Check if architectural design toolkit is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_architectural_design_toolkit'] ) ) {
		return;
	}

	// Load metabox classes.
	require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-architectural-project-metabox.php';
	require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-architectural-drawing-metabox.php';
	require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-architectural-specification-metabox.php';

	// Initialize metaboxes.
	WP_MCP_AI_Architectural_Project_Metabox::init();
	WP_MCP_AI_Architectural_Drawing_Metabox::init();
	WP_MCP_AI_Architectural_Specification_Metabox::init();
}
add_action( 'admin_init', 'wp_mcp_ai_init_architectural_design_admin' );

// Only load tools if enabled and not in base version.
$settings   = get_option( 'wp_mcp_ai_settings', array() );
$is_enabled = ! empty( $settings['enable_architectural_design_toolkit'] );
$is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

if ( $is_enabled && ! $is_base ) {
	// Load Architectural Design tools.
	add_action( 'wp_mcp_ai_load_pro_tools', 'wp_mcp_ai_load_architectural_design_tools' );
}

/**
 * Load Architectural Design toolkit tools.
 *
 * Registers all 39 architectural design tools (Phase A: 16 + Phase B: 10 + Phase C: 4 + Phase D: 7 + Phase E: 2).
 *
 * @since 1.1.0
 */
function wp_mcp_ai_load_architectural_design_tools() {
	$tools_dir = WP_MCP_AI_PRO_PATH . 'includes/tools/architectural-design/';

	// Shared engine and code registry — load first so tools can rely on them.
	require_once $tools_dir . 'class-wp-mcp-ai-architectural-engine.php';
	require_once $tools_dir . 'class-wp-mcp-ai-architectural-codes.php';
	require_once $tools_dir . 'class-wp-mcp-ai-architectural-sustainability.php';
	require_once $tools_dir . 'class-wp-mcp-ai-architectural-interop.php';

	// Floor Planning & Space Design tools (4 tools).
	require_once $tools_dir . 'floor-planning/class-wp-mcp-ai-tool-generate-floor-plan.php';
	require_once $tools_dir . 'floor-planning/class-wp-mcp-ai-tool-optimize-space-layout.php';
	require_once $tools_dir . 'floor-planning/class-wp-mcp-ai-tool-create-floor-plan-variations.php';
	require_once $tools_dir . 'floor-planning/class-wp-mcp-ai-tool-convert-sketch-to-floor-plan.php';

	// 3D Modeling & Visualization tools (3 tools).
	require_once $tools_dir . 'visualization/class-wp-mcp-ai-tool-generate-3d-model.php';
	require_once $tools_dir . 'visualization/class-wp-mcp-ai-tool-render-architectural-view.php';
	require_once $tools_dir . 'visualization/class-wp-mcp-ai-tool-create-walkthrough-animation.php';

	// Documentation & Blueprints tools (3 tools).
	require_once $tools_dir . 'documentation/class-wp-mcp-ai-tool-generate-construction-drawings.php';
	require_once $tools_dir . 'documentation/class-wp-mcp-ai-tool-generate-detail-drawings.php';
	require_once $tools_dir . 'documentation/class-wp-mcp-ai-tool-export-architectural-documents.php';

	// Analysis & Compliance tools (3 tools).
	require_once $tools_dir . 'analysis-compliance/class-wp-mcp-ai-tool-check-building-code-compliance.php';
	require_once $tools_dir . 'analysis-compliance/class-wp-mcp-ai-tool-analyze-structural-feasibility.php';
	require_once $tools_dir . 'analysis-compliance/class-wp-mcp-ai-tool-calculate-sustainability-metrics.php';

	// Estimation & Scheduling tools (3 tools).
	require_once $tools_dir . 'estimation-scheduling/class-wp-mcp-ai-tool-generate-material-schedule.php';
	require_once $tools_dir . 'estimation-scheduling/class-wp-mcp-ai-tool-estimate-construction-cost.php';
	require_once $tools_dir . 'estimation-scheduling/class-wp-mcp-ai-tool-generate-construction-timeline.php';

	// Phase B — Regional Compliance tools (7 tools).
	require_once $tools_dir . 'regional-compliance/class-wp-mcp-ai-tool-calculate-wind-loads.php';
	require_once $tools_dir . 'regional-compliance/class-wp-mcp-ai-tool-calculate-seismic-loads.php';
	require_once $tools_dir . 'regional-compliance/class-wp-mcp-ai-tool-validate-setbacks-and-far.php';
	require_once $tools_dir . 'regional-compliance/class-wp-mcp-ai-tool-check-uda-planning-compliance.php';
	require_once $tools_dir . 'regional-compliance/class-wp-mcp-ai-tool-check-jnbc-hurricane-compliance.php';
	require_once $tools_dir . 'regional-compliance/class-wp-mcp-ai-tool-check-us-ibc-irc-compliance.php';
	require_once $tools_dir . 'regional-compliance/class-wp-mcp-ai-tool-generate-compliance-dossier.php';

	// Phase B — Analysis depth tools (2 tools added to analysis-compliance/).
	require_once $tools_dir . 'analysis-compliance/class-wp-mcp-ai-tool-analyze-natural-ventilation.php';
	require_once $tools_dir . 'analysis-compliance/class-wp-mcp-ai-tool-analyze-daylight-and-solar-gain.php';

	// Phase B — Sustainability tools (1 tool).
	require_once $tools_dir . 'sustainability/class-wp-mcp-ai-tool-simulate-thermal-comfort.php';

	// Phase C — Sustainability scoring & costing depth (4 tools).
	require_once $tools_dir . 'sustainability/class-wp-mcp-ai-tool-score-edge-certification.php';
	require_once $tools_dir . 'sustainability/class-wp-mcp-ai-tool-score-leed-v4-certification.php';
	require_once $tools_dir . 'estimation-scheduling/class-wp-mcp-ai-tool-generate-bill-of-quantities.php';
	require_once $tools_dir . 'estimation-scheduling/class-wp-mcp-ai-tool-propose-value-engineering-options.php';

	// Phase D — Interoperability tools (4 tools).
	require_once $tools_dir . 'interoperability/class-wp-mcp-ai-tool-import-dwg-floor-plan.php';
	require_once $tools_dir . 'interoperability/class-wp-mcp-ai-tool-import-ifc-model.php';
	require_once $tools_dir . 'interoperability/class-wp-mcp-ai-tool-export-to-ifc.php';
	require_once $tools_dir . 'interoperability/class-wp-mcp-ai-tool-export-to-gbxml.php';

	// Phase D — Project delivery tools (3 tools).
	require_once $tools_dir . 'project-delivery/class-wp-mcp-ai-tool-generate-bim-execution-plan.php';
	require_once $tools_dir . 'project-delivery/class-wp-mcp-ai-tool-manage-rfi-log.php';
	require_once $tools_dir . 'project-delivery/class-wp-mcp-ai-tool-manage-submittal-log.php';

	// Phase E — Precedent library + semantic search (2 tools).
	require_once $tools_dir . 'class-wp-mcp-ai-architectural-precedents-engine.php';
	require_once $tools_dir . 'precedents/class-wp-mcp-ai-tool-manage-architectural-precedents.php';
	require_once $tools_dir . 'precedents/class-wp-mcp-ai-tool-search-architectural-precedents.php';

	// Register all tools with the tool registry.
	$registry = wp_mcp_ai_get_tool_registry();

	if ( $registry ) {
		$tool_classes = array(
			// Floor Planning & Space Design.
			'WP_MCP_AI_Tool_Generate_Floor_Plan',
			'WP_MCP_AI_Tool_Optimize_Space_Layout',
			'WP_MCP_AI_Tool_Create_Floor_Plan_Variations',
			'WP_MCP_AI_Tool_Convert_Sketch_To_Floor_Plan',

			// 3D Modeling & Visualization.
			'WP_MCP_AI_Tool_Generate_3d_Model',
			'WP_MCP_AI_Tool_Render_Architectural_View',
			'WP_MCP_AI_Tool_Create_Walkthrough_Animation',

			// Documentation & Blueprints.
			'WP_MCP_AI_Tool_Generate_Construction_Drawings',
			'WP_MCP_AI_Tool_Generate_Detail_Drawings',
			'WP_MCP_AI_Tool_Export_Architectural_Documents',

			// Analysis & Compliance.
			'WP_MCP_AI_Tool_Check_Building_Code_Compliance',
			'WP_MCP_AI_Tool_Analyze_Structural_Feasibility',
			'WP_MCP_AI_Tool_Calculate_Sustainability_Metrics',

			// Estimation & Scheduling.
			'WP_MCP_AI_Tool_Generate_Material_Schedule',
			'WP_MCP_AI_Tool_Estimate_Construction_Cost',
			'WP_MCP_AI_Tool_Generate_Construction_Timeline',

			// Phase B — Regional Compliance.
			'WP_MCP_AI_Tool_Calculate_Wind_Loads',
			'WP_MCP_AI_Tool_Calculate_Seismic_Loads',
			'WP_MCP_AI_Tool_Validate_Setbacks_And_Far',
			'WP_MCP_AI_Tool_Check_UDA_Planning_Compliance',
			'WP_MCP_AI_Tool_Check_JNBC_Hurricane_Compliance',
			'WP_MCP_AI_Tool_Check_US_IBC_IRC_Compliance',
			'WP_MCP_AI_Tool_Generate_Compliance_Dossier',

			// Phase B — Analysis depth.
			'WP_MCP_AI_Tool_Analyze_Natural_Ventilation',
			'WP_MCP_AI_Tool_Analyze_Daylight_And_Solar_Gain',

			// Phase B — Sustainability.
			'WP_MCP_AI_Tool_Simulate_Thermal_Comfort',

			// Phase C — Sustainability scoring & costing depth.
			'WP_MCP_AI_Tool_Score_Edge_Certification',
			'WP_MCP_AI_Tool_Score_Leed_V4_Certification',
			'WP_MCP_AI_Tool_Generate_Bill_Of_Quantities',
			'WP_MCP_AI_Tool_Propose_Value_Engineering_Options',

			// Phase D — Interoperability.
			'WP_MCP_AI_Tool_Import_Dwg_Floor_Plan',
			'WP_MCP_AI_Tool_Import_Ifc_Model',
			'WP_MCP_AI_Tool_Export_To_Ifc',
			'WP_MCP_AI_Tool_Export_To_Gbxml',

			// Phase D — Project delivery.
			'WP_MCP_AI_Tool_Generate_Bim_Execution_Plan',
			'WP_MCP_AI_Tool_Manage_Rfi_Log',
			'WP_MCP_AI_Tool_Manage_Submittal_Log',

			// Phase E — Precedent library + semantic search.
			'WP_MCP_AI_Tool_Manage_Architectural_Precedents',
			'WP_MCP_AI_Tool_Search_Architectural_Precedents',
		);

		foreach ( $tool_classes as $tool_class ) {
			if ( ! class_exists( $tool_class ) ) {
				continue;
			}
			// Honour optional is_available() availability check.
			if ( method_exists( $tool_class, 'is_available' ) && ! call_user_func( array( $tool_class, 'is_available' ) ) ) {
				continue;
			}
			$registry->register_tool( new $tool_class() );
		}
	}
}

/**
 * Enqueue architectural design toolkit admin styles.
 *
 * @since 1.1.0
 *
 * @param string $hook Current admin page hook (unused).
 */
function wp_mcp_ai_enqueue_architectural_design_toolkit_admin_styles( $hook ) {
	// Only load if toolkit is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_architectural_design_toolkit'] ) ) {
		return;
	}

	// Enqueue admin styles if available.
	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-architectural-design-toolkit.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-architectural-design-toolkit-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-architectural-design-toolkit.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_architectural_design_toolkit_admin_styles' );
