<?php
/**
 * AI Tool Builder Toolkit Initialization (ecosystem port — Wave F2,
 * ai-tool-builder toolkit).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/ai-tool-builder/init.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical.
 *
 * Documented deviations:
 *
 * 1. `declare(strict_types=1)` added.
 * 2. File paths resolve from `NVOOS_CONTENT_GRAPH_PRO_*`.
 * 3. Slimmed wiring — the settings-page require is file-gated.
 * 4. Monolith guard — this init declares the global
 *    `wp_mcp_ai_enqueue_ai_tool_builder_toolkit_admin_styles()` helper that
 *    the base init also declares; the collision is a compile-time fatal, so
 *    the ENTIRE body is wrapped in a runtime `! defined( 'WP_MCP_AI_PATH' )`
 *    block (financial-init deviation 5 precedent).
 * 5. New standalone-only tool wiring (financial-init deviation 6
 *    precedent): a `wp_mcp_ai_pro_tools` filter plus
 *    `wp_mcp_ai_pro_register_ai_tool_builder_ecosystem_tools()` carrying all
 *    eleven ported tools — the base registers these tools nowhere (the
 *    files are "Phase 2.9 planned" tree-only), so the standalone filter and
 *    ecosystem registrations are the only registrations (CRM CC-extras
 *    precedent).
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

// Monolith guard (deviation 4): full-body runtime wrap — see the header.
if ( ! defined( 'WP_MCP_AI_PATH' ) ) {

	// Check if AI Tool Builder toolkit is enabled.
	$nvoos_content_graph_pro_settings      = get_option( 'wp_mcp_ai_settings', array() );
	$nvoos_content_graph_pro_is_enabled    = ! empty( $nvoos_content_graph_pro_settings['enable_ai_tool_builder_toolkit'] );
	$nvoos_content_graph_pro_is_base       = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
	$nvoos_content_graph_pro_is_pro_active = defined( 'WP_MCP_AI_PRO_VERSION' );

	// Only load if enabled and (not in base version or the Pro addon is active).
	if ( $nvoos_content_graph_pro_is_enabled && ( ! $nvoos_content_graph_pro_is_base || $nvoos_content_graph_pro_is_pro_active ) ) {

		// Load AI Tool Builder admin pages.
		if ( is_admin() ) {
			$nvoos_content_graph_pro_tool_builder_settings = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-ai-tool-builder-settings-page.php';
			if ( file_exists( $nvoos_content_graph_pro_tool_builder_settings ) ) {
				require_once $nvoos_content_graph_pro_tool_builder_settings;
			}
		}
	}

	/**
	 * Enqueue AI tool builder toolkit admin styles.
	 *
	 * @since 1.1.0
	 *
	 * @param string $hook Current admin page hook (unused).
	 */
	function wp_mcp_ai_enqueue_ai_tool_builder_toolkit_admin_styles( $hook ) {
		// Only load if toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_ai_tool_builder_toolkit'] ) ) {
			return;
		}

		// Enqueue admin styles if available.
		$css_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'assets/css/admin-ai-tool-builder-toolkit.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'wp-mcp-ai-ai-tool-builder-toolkit-admin',
				NVOOS_CONTENT_GRAPH_PRO_URL . 'assets/css/admin-ai-tool-builder-toolkit.css',
				array(),
				NVOOS_CONTENT_GRAPH_PRO_VERSION
			);
		}
	}
	add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_ai_tool_builder_toolkit_admin_styles' );

	/**
	 * Standalone-only tool filter — carries the ported ai-tool-builder tools
	 * (inert standalone, consumed by the base plugin monolith). The base
	 * registers these tools nowhere (tree-only files), so this map is the
	 * only registration.
	 *
	 * @param array $tools Existing tool map (class => file).
	 * @return array Extended tool map.
	 */
	function wp_mcp_ai_pro_register_ai_tool_builder_tools( $tools ) {
		$nvoos_content_graph_pro_tool_builder_tools = array(
			'WP_MCP_AI_Tool_Analyze_Tool_Security'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ai-tool-builder/class-wp-mcp-ai-tool-analyze-tool-security.php',
			'WP_MCP_AI_Tool_Benchmark_Tool_Performance'  => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ai-tool-builder/class-wp-mcp-ai-tool-benchmark-tool-performance.php',
			'WP_MCP_AI_Tool_Check_Tool_Compliance'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ai-tool-builder/class-wp-mcp-ai-tool-check-tool-compliance.php',
			'WP_MCP_AI_Tool_Generate_Tool_Documentation' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ai-tool-builder/class-wp-mcp-ai-tool-generate-tool-documentation.php',
			'WP_MCP_AI_Tool_Generate_Tool_Logic'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ai-tool-builder/class-wp-mcp-ai-tool-generate-tool-logic.php',
			'WP_MCP_AI_Tool_Generate_Tool_Parameters'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ai-tool-builder/class-wp-mcp-ai-tool-generate-tool-parameters.php',
			'WP_MCP_AI_Tool_Generate_Tool_Scaffold'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ai-tool-builder/class-wp-mcp-ai-tool-generate-tool-scaffold.php',
			'WP_MCP_AI_Tool_Generate_Tool_Tests'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ai-tool-builder/class-wp-mcp-ai-tool-generate-tool-tests.php',
			'WP_MCP_AI_Tool_Refactor_Tool_Code'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ai-tool-builder/class-wp-mcp-ai-tool-refactor-tool-code.php',
			'WP_MCP_AI_Tool_Validate_Tool_Schema'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ai-tool-builder/class-wp-mcp-ai-tool-validate-tool-schema.php',
			'WP_MCP_AI_Tool_Import_AI_Tool_Builder_Blueprint' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ai-tool-builder/examples/class-wp-mcp-ai-tool-import-ai-tool-builder-blueprint.php',
		);

		return array_merge( $tools, $nvoos_content_graph_pro_tool_builder_tools );
	}

	/**
	 * Standalone-only ecosystem registration — registers the ported
	 * ai-tool-builder tools into the ecosystem graph ToolRegistry and the
	 * nvoos/core registry via `WP_MCP_AI_Pro_Tool_Adapter` (same wiring as
	 * the image-production/video inits). Carries all eleven ported tools.
	 *
	 * @return void
	 */
	function wp_mcp_ai_pro_register_ai_tool_builder_ecosystem_tools() {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-tool-adapter.php';

		$nvoos_content_graph_pro_parent_registry = nvoos_content_graph_get_tool_registry();
		if ( ! $nvoos_content_graph_pro_parent_registry instanceof \NvoosContentGraph\ToolRegistry ) {
			return;
		}

		foreach (
			array(
				'WP_MCP_AI_Tool_Analyze_Tool_Security',
				'WP_MCP_AI_Tool_Benchmark_Tool_Performance',
				'WP_MCP_AI_Tool_Check_Tool_Compliance',
				'WP_MCP_AI_Tool_Generate_Tool_Documentation',
				'WP_MCP_AI_Tool_Generate_Tool_Logic',
				'WP_MCP_AI_Tool_Generate_Tool_Parameters',
				'WP_MCP_AI_Tool_Generate_Tool_Scaffold',
				'WP_MCP_AI_Tool_Generate_Tool_Tests',
				'WP_MCP_AI_Tool_Refactor_Tool_Code',
				'WP_MCP_AI_Tool_Validate_Tool_Schema',
				'WP_MCP_AI_Tool_Import_AI_Tool_Builder_Blueprint',
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

	// ---- Standalone-only tool wiring (deviation 5). ----
	add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_ai_tool_builder_tools', 10 );

	if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_ai_tool_builder_ecosystem_tools();
	}
} // End monolith guard (deviation 4).
