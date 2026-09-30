<?php
/**
 * Vision Analysis Toolkit Initialization (ecosystem port — Wave F3, vision-analysis toolkit).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/vision-analysis/init.php` for the standalone
 * `nvoos-content-graph-pro` addon. The base Pro addon owns the same helper
 * functions and toolkit registration in monolith installs — this slim init
 * is full-body guarded behind `! defined( 'WP_MCP_AI_PATH' )` (compile-time
 * fatal otherwise; the base init declares the same globals).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; the admin-settings require is file-gated — the
 * Vision Analysis settings page lands with the F-UI admin slice (wave-proof);
 * the tool filter + ecosystem registration below are standalone-only wiring
 * (same pattern as the CRM/e-commerce init deviations).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_MCP_AI_PATH' ) ) {

	/**
	 * Check whether the Vision Analysis toolkit is enabled.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	function wp_mcp_ai_vision_analysis_is_enabled() {
		$nvoos_content_graph_pro_settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $nvoos_content_graph_pro_settings['enable_vision_analysis_toolkit'] );
	}

	/**
	 * Get the Vision Analysis settings subset from the main wp_mcp_ai_settings option.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	function wp_mcp_ai_vision_analysis_get_settings() {
		$nvoos_content_graph_pro_all = get_option( 'wp_mcp_ai_settings', array() );

		return array(
			'enabled'                 => ! empty( $nvoos_content_graph_pro_all['enable_vision_analysis_toolkit'] ),
			'detection_model'         => isset( $nvoos_content_graph_pro_all['va_detection_model'] ) && '' !== $nvoos_content_graph_pro_all['va_detection_model'] ? sanitize_text_field( $nvoos_content_graph_pro_all['va_detection_model'] ) : 'google/owlv2-base-patch16',
			'min_confidence'          => isset( $nvoos_content_graph_pro_all['va_min_confidence'] ) ? (float) $nvoos_content_graph_pro_all['va_min_confidence'] : 0.5,
			'vlm_provider'            => isset( $nvoos_content_graph_pro_all['va_vlm_provider'] ) ? sanitize_text_field( $nvoos_content_graph_pro_all['va_vlm_provider'] ) : 'auto',
			'vlm_model'               => isset( $nvoos_content_graph_pro_all['va_vlm_model'] ) ? sanitize_text_field( $nvoos_content_graph_pro_all['va_vlm_model'] ) : '',
			'annotate_default'        => ! empty( $nvoos_content_graph_pro_all['va_annotate_default'] ),
			'max_image_bytes'         => isset( $nvoos_content_graph_pro_all['va_max_image_bytes'] ) ? absint( $nvoos_content_graph_pro_all['va_max_image_bytes'] ) : 5242880,
			'reverse_search_provider' => isset( $nvoos_content_graph_pro_all['va_reverse_search_provider'] ) ? sanitize_text_field( $nvoos_content_graph_pro_all['va_reverse_search_provider'] ) : 'auto',
			'bing_visual_search_key'  => isset( $nvoos_content_graph_pro_all['va_bing_visual_search_key'] ) ? sanitize_text_field( $nvoos_content_graph_pro_all['va_bing_visual_search_key'] ) : '',
			'serpapi_api_key'         => isset( $nvoos_content_graph_pro_all['va_serpapi_api_key'] ) ? sanitize_text_field( $nvoos_content_graph_pro_all['va_serpapi_api_key'] ) : '',
			'roboflow_api_url'        => isset( $nvoos_content_graph_pro_all['va_roboflow_api_url'] ) && '' !== $nvoos_content_graph_pro_all['va_roboflow_api_url'] ? sanitize_text_field( $nvoos_content_graph_pro_all['va_roboflow_api_url'] ) : '',
			'roboflow_api_key'        => isset( $nvoos_content_graph_pro_all['va_roboflow_api_key'] ) ? sanitize_text_field( $nvoos_content_graph_pro_all['va_roboflow_api_key'] ) : '',
			'roboflow_model'          => isset( $nvoos_content_graph_pro_all['va_roboflow_model'] ) && '' !== $nvoos_content_graph_pro_all['va_roboflow_model'] ? sanitize_text_field( $nvoos_content_graph_pro_all['va_roboflow_model'] ) : 'rfdetr-small',
			'roboflow_catalog_model'  => isset( $nvoos_content_graph_pro_all['va_roboflow_catalog_model'] ) ? sanitize_text_field( $nvoos_content_graph_pro_all['va_roboflow_catalog_model'] ) : '',
			'roboflow_allow_pml'      => ! empty( $nvoos_content_graph_pro_all['va_roboflow_allow_pml'] ),
		);
	}
}

// ---- Standalone-only tool wiring (documented deviation, same pattern as
// the CRM/e-commerce init deviations): a `wp_mcp_ai_pro_tools` filter
// carrying the ported vision-analysis tool subset (inert standalone — the
// base plugin consumes it monolith) plus the ecosystem registration
// below. ----
add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_vision_analysis_tools', 10 );

if ( ! defined( 'WP_MCP_AI_PATH' ) && function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
	wp_mcp_ai_pro_register_vision_analysis_ecosystem_tools();
}

/**
 * Register the ported vision-analysis tools with the `wp_mcp_ai_pro_tools`
 * filter (standalone-only wiring — a subset of the monolith's inline
 * `$vision_analysis_tools` map built inside `wp_mcp_ai_pro_register_tools()`).
 *
 * @since 1.1.0
 *
 * @param array $tools Existing tools array.
 * @return array Updated tools array.
 */
function wp_mcp_ai_pro_register_vision_analysis_tools( $tools ) {
	$nvoos_content_graph_pro_vision_analysis_tools = array(
		'WP_MCP_AI_Tool_Rfdetr_Detect' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/vision-analysis/class-wp-mcp-ai-tool-rfdetr-detect.php',
	);

	return array_merge( $tools, $nvoos_content_graph_pro_vision_analysis_tools );
}

/**
 * Register the ported vision-analysis tools with the ecosystem registries
 * (standalone only — same wiring as the CRM init deviation 5).
 *
 * @since 1.1.0
 * @return void
 */
function wp_mcp_ai_pro_register_vision_analysis_ecosystem_tools() {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-tool-adapter.php';
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/vision-analysis/class-wp-mcp-ai-tool-rfdetr-detect.php';

	// Re-entrancy guard: when the tool file itself required this init (its
	// settings-accessor seam), the class is not declared yet mid-include —
	// the boot-time init load performs the registration instead.
	if ( ! class_exists( 'WP_MCP_AI_Tool_Rfdetr_Detect' ) ) {
		return;
	}

	$nvoos_content_graph_pro_parent_registry = nvoos_content_graph_get_tool_registry();
	if ( ! $nvoos_content_graph_pro_parent_registry instanceof \NvoosContentGraph\ToolRegistry ) {
		return;
	}

	foreach (
		array(
			'WP_MCP_AI_Tool_Rfdetr_Detect',
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
