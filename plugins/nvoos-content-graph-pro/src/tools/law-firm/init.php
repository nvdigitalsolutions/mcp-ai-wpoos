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
 * `wp_mcp_ai_pro_register_law_firm_ecosystem_tools()` — both start with
 * empty maps and fill as the law-firm tool batches land; local vars
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
	$nvoos_content_graph_pro_law_tools = array();

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
		array() as $nvoos_content_graph_pro_tool_class
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
