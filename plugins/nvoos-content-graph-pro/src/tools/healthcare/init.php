<?php
/**
 * Healthcare Toolkit Initialization (ecosystem port — Wave F4, healthcare
 * data layer).
 *
 * Slimmed standalone init for the `nvoos-content-graph-pro` addon. The base
 * Pro addon owns the same init monolith (booted via its module registry's
 * `toolkit_healthcare` module) — the addon boots nothing when
 * `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps; the
 * sub-init and data-class requires resolve from the addon's already-ported
 * `src/` copies; the OpenMed tool requires + the `wp_mcp_ai_register_tools`
 * registration are file-gated until the healthcare tool batches land; NEW
 * standalone-only wiring (deviation, same as the CRM init): a
	 * `wp_mcp_ai_pro_tools` filter plus
	 * `wp_mcp_ai_pro_register_healthcare_ecosystem_tools()` — both carry the
	 * eleven wellness CRUD batch-1 tools and fill further as the healthcare
	 * tool batches land; local vars
 * prefixed `$nvoos_content_graph_pro_*`.
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

// Full-body monolith guard (deviation): the base init boots via the base
// registry monolith; the wellness sub-init declares global helper functions,
// so the standalone copy must not compile alongside the base copy in the
// monorepo test matrix.
if ( ! defined( 'WP_MCP_AI_PATH' ) ) {

	// Always load shared infrastructure so other Pro code can rely on it.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/class-wp-mcp-ai-healthcare-engine.php';
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/class-wp-mcp-ai-healthcare-codes.php';
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/class-wp-mcp-ai-healthcare-fhir.php';
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/class-wp-mcp-ai-healthcare-audit.php';
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/class-wp-mcp-ai-healthcare-capabilities.php';

	// OpenMed clinical NLP client (v1.4.0). Always loaded for health checks.
	// Configuration-gated — tools only register when OpenMed service is configured.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/class-wp-mcp-ai-openmed-client.php';

	// PHI-acknowledged gate (multisite); single-site installs always pass.
	if ( ! WP_MCP_AI_Healthcare_Engine::phi_acknowledged() ) {
		return;
	}

	$nvoos_content_graph_pro_settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( ! is_array( $nvoos_content_graph_pro_settings ) ) {
		$nvoos_content_graph_pro_settings = array();
	}

	// Sub-toolkit B: Health & Wellness Management (members / records / etc.).
	// Loaded unconditionally to preserve pre-existing behaviour — the init file
	// itself gates on `enable_health_wellness_management` for admin UI bits and
	// always registers its CPTs and migration so existing data remains
	// accessible even when the toggle is off.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/init.php';

	// Sub-toolkit C: Healthcare Imaging.
	if ( ! empty( $nvoos_content_graph_pro_settings['enable_healthcare_imaging'] ) ) {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/imaging-init.php';
	}

	// Sub-toolkit A: Medical Vitals (Phase B).
	// Defaults to the value of `enable_health_wellness_management` for BC.
	$nvoos_content_graph_pro_vitals_enabled = array_key_exists( 'enable_medical_vitals', $nvoos_content_graph_pro_settings )
		? ! empty( $nvoos_content_graph_pro_settings['enable_medical_vitals'] )
		: ! empty( $nvoos_content_graph_pro_settings['enable_health_wellness_management'] );
	if ( $nvoos_content_graph_pro_vitals_enabled ) {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/vitals/class-wp-mcp-ai-healthcare-vaccination-schedules.php';
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/vitals/class-wp-mcp-ai-healthcare-vital-log-cpt.php';
		WP_MCP_AI_Healthcare_Vital_Log_CPT::init();
	}

	// --- Performance optimization (per-member autoload, reminder pruning, care-plan cap) ---
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/class-wp-mcp-ai-healthcare-optimization.php';
	WP_MCP_AI_Healthcare_Optimization::init();

	// --- OpenMed clinical NLP tools (v1.4.0) ---
	// Registered via wp_mcp_ai_register_tools action; tools gate themselves on
	// OpenMed client availability at execution time. File-gated until the
	// healthcare tool batches land (the base ships both files — this gate is a
	// documented standalone-only deviation).
	$nvoos_content_graph_pro_deidentify = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/class-wp-mcp-ai-tool-deidentify-health-record.php';
	$nvoos_content_graph_pro_extract    = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/class-wp-mcp-ai-tool-extract-clinical-entities.php';
	if ( file_exists( $nvoos_content_graph_pro_deidentify ) && file_exists( $nvoos_content_graph_pro_extract ) ) {
		require_once $nvoos_content_graph_pro_deidentify;
		require_once $nvoos_content_graph_pro_extract;

		add_action(
			'wp_mcp_ai_register_tools',
			function ( $registry ) {
				$registry->register_tool( new WP_MCP_AI_Tool_Deidentify_Health_Record() );
				$registry->register_tool( new WP_MCP_AI_Tool_Extract_Clinical_Entities() );
			}
		);
	}

	unset(
		$nvoos_content_graph_pro_vitals_enabled,
		$nvoos_content_graph_pro_settings,
		$nvoos_content_graph_pro_deidentify,
		$nvoos_content_graph_pro_extract
	);

	// ---- Standalone-only tool wiring (deviation). ----
	add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_healthcare_tools', 10 );

	if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_healthcare_ecosystem_tools();
	}
} // End monolith guard (deviation).

/**
 * Standalone-only tool filter — mirrors the monolith's inline healthcare map
 * (the wellness/vitals/imaging/interop gates in `mcp-ai-wpoos-pro.php`). The
 * map fills as the healthcare tool batches land.
 *
 * @param array $tools Existing tool map.
 * @return array Extended tool map.
 */
function wp_mcp_ai_pro_register_healthcare_tools( $tools ) {
	$nvoos_content_graph_pro_health_tools = array(
		'WP_MCP_AI_Tool_Create_Member'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/members/class-wp-mcp-ai-tool-create-member.php',
		'WP_MCP_AI_Tool_List_Members'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/members/class-wp-mcp-ai-tool-list-members.php',
		'WP_MCP_AI_Tool_Get_Member'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/members/class-wp-mcp-ai-tool-get-member.php',
		'WP_MCP_AI_Tool_Update_Member'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/members/class-wp-mcp-ai-tool-update-member.php',
		'WP_MCP_AI_Tool_Delete_Member'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/members/class-wp-mcp-ai-tool-delete-member.php',
		'WP_MCP_AI_Tool_Create_Policy'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-create-policy.php',
		'WP_MCP_AI_Tool_List_Policies'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-list-policies.php',
		'WP_MCP_AI_Tool_Get_Policy'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-get-policy.php',
		'WP_MCP_AI_Tool_Update_Policy'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-update-policy.php',
		'WP_MCP_AI_Tool_Delete_Policy'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-delete-policy.php',
		'WP_MCP_AI_Tool_Search_Policies' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-search-policies.php',
	);

	return array_merge( $tools, $nvoos_content_graph_pro_health_tools );
}

/**
 * Standalone-only ecosystem registration — registers the ported healthcare
 * tools into the ecosystem graph ToolRegistry and the nvoos/core registry
 * via `WP_MCP_AI_Pro_Tool_Adapter` (same wiring as the image-production
 * inits). The list fills as the healthcare tool batches land.
 *
 * @return void
 */
function wp_mcp_ai_pro_register_healthcare_ecosystem_tools() {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-tool-adapter.php';

	$nvoos_content_graph_pro_parent_registry = nvoos_content_graph_get_tool_registry();
	if ( ! $nvoos_content_graph_pro_parent_registry instanceof \NvoosContentGraph\ToolRegistry ) {
		return;
	}

	foreach (
		array(
			'WP_MCP_AI_Tool_Create_Member',
			'WP_MCP_AI_Tool_List_Members',
			'WP_MCP_AI_Tool_Get_Member',
			'WP_MCP_AI_Tool_Update_Member',
			'WP_MCP_AI_Tool_Delete_Member',
			'WP_MCP_AI_Tool_Create_Policy',
			'WP_MCP_AI_Tool_List_Policies',
			'WP_MCP_AI_Tool_Get_Policy',
			'WP_MCP_AI_Tool_Update_Policy',
			'WP_MCP_AI_Tool_Delete_Policy',
			'WP_MCP_AI_Tool_Search_Policies',
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
