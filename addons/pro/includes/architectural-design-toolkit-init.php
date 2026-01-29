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
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load CPT classes.
require_once __DIR__ . '/class-wp-mcp-ai-architectural-project-cpt.php';
require_once __DIR__ . '/class-wp-mcp-ai-architectural-drawing-cpt.php';
require_once __DIR__ . '/class-wp-mcp-ai-architectural-specification-cpt.php';

// Initialize CPTs - they have their own checks for enabled/base version.
WP_MCP_AI_Architectural_Project_CPT::init();
WP_MCP_AI_Architectural_Drawing_CPT::init();
WP_MCP_AI_Architectural_Specification_CPT::init();

// Load Research & Add and Settings pages for admin.
if ( is_admin() ) {
	// Check if architectural design toolkit is enabled and not in base version (unless Pro addon is active).
	$settings      = get_option( 'wp_mcp_ai_settings', array() );
	$is_enabled    = ! empty( $settings['enable_architectural_design_toolkit'] );
	$is_base       = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
	$is_pro_active = defined( 'WP_MCP_AI_PRO_VERSION' );

	if ( $is_enabled && ( ! $is_base || $is_pro_active ) ) {
		// Load Project Settings and Research & Add pages (under Design Projects menu).
		require_once __DIR__ . '/admin/class-wp-mcp-ai-architectural-project-settings-page.php';
		require_once __DIR__ . '/admin/class-wp-mcp-ai-architectural-project-research-page.php';
		WP_MCP_AI_Architectural_Project_Research_Page::init();

		// Load Drawing Settings and Research & Add pages (under Drawings menu).
		require_once __DIR__ . '/admin/class-wp-mcp-ai-architectural-drawing-settings-page.php';
		require_once __DIR__ . '/admin/class-wp-mcp-ai-architectural-drawing-research-page.php';
		WP_MCP_AI_Architectural_Drawing_Research_Page::init();

		// Load Specification Settings and Research & Add pages (under Specifications menu).
		require_once __DIR__ . '/admin/class-wp-mcp-ai-architectural-specification-settings-page.php';
		require_once __DIR__ . '/admin/class-wp-mcp-ai-architectural-specification-research-page.php';
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
	require_once __DIR__ . '/admin/class-wp-mcp-ai-architectural-project-metabox.php';
	require_once __DIR__ . '/admin/class-wp-mcp-ai-architectural-drawing-metabox.php';
	require_once __DIR__ . '/admin/class-wp-mcp-ai-architectural-specification-metabox.php';

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
 * Registers all 16 architectural design tools for Phase 2.10.
 *
 * @since 1.1.0
 */
function wp_mcp_ai_load_architectural_design_tools() {
	$tools_dir = WP_MCP_AI_PRO_PATH . 'includes/tools/architectural-design/';

	// Floor Planning & Space Design tools (4 tools).
	require_once $tools_dir . 'class-wp-mcp-ai-tool-generate-floor-plan.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-optimize-space-layout.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-create-floor-plan-variations.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-convert-sketch-to-floor-plan.php';

	// 3D Modeling & Visualization tools (3 tools).
	require_once $tools_dir . 'class-wp-mcp-ai-tool-generate-3d-model.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-render-architectural-view.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-create-walkthrough-animation.php';

	// Documentation & Blueprints tools (3 tools).
	require_once $tools_dir . 'class-wp-mcp-ai-tool-generate-construction-drawings.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-generate-detail-drawings.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-export-architectural-documents.php';

	// Analysis & Compliance tools (3 tools).
	require_once $tools_dir . 'class-wp-mcp-ai-tool-check-building-code-compliance.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-analyze-structural-feasibility.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-calculate-sustainability-metrics.php';

	// Estimation & Scheduling tools (3 tools).
	require_once $tools_dir . 'class-wp-mcp-ai-tool-generate-material-schedule.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-estimate-construction-cost.php';
	require_once $tools_dir . 'class-wp-mcp-ai-tool-generate-construction-timeline.php';

	// Register all tools with the tool registry.
	$registry = wp_mcp_ai_get_tool_registry();

	if ( $registry ) {
		// Floor Planning & Space Design.
		$registry->register( new WP_MCP_AI_Tool_Generate_Floor_Plan() );
		$registry->register( new WP_MCP_AI_Tool_Optimize_Space_Layout() );
		$registry->register( new WP_MCP_AI_Tool_Create_Floor_Plan_Variations() );
		$registry->register( new WP_MCP_AI_Tool_Convert_Sketch_To_Floor_Plan() );

		// 3D Modeling & Visualization.
		$registry->register( new WP_MCP_AI_Tool_Generate_3d_Model() );
		$registry->register( new WP_MCP_AI_Tool_Render_Architectural_View() );
		$registry->register( new WP_MCP_AI_Tool_Create_Walkthrough_Animation() );

		// Documentation & Blueprints.
		$registry->register( new WP_MCP_AI_Tool_Generate_Construction_Drawings() );
		$registry->register( new WP_MCP_AI_Tool_Generate_Detail_Drawings() );
		$registry->register( new WP_MCP_AI_Tool_Export_Architectural_Documents() );

		// Analysis & Compliance.
		$registry->register( new WP_MCP_AI_Tool_Check_Building_Code_Compliance() );
		$registry->register( new WP_MCP_AI_Tool_Analyze_Structural_Feasibility() );
		$registry->register( new WP_MCP_AI_Tool_Calculate_Sustainability_Metrics() );

		// Estimation & Scheduling.
		$registry->register( new WP_MCP_AI_Tool_Generate_Material_Schedule() );
		$registry->register( new WP_MCP_AI_Tool_Estimate_Construction_Cost() );
		$registry->register( new WP_MCP_AI_Tool_Generate_Construction_Timeline() );
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
