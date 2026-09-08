<?php
/**
 * Cloudways Pro Toolkit Initialization (ecosystem port — Wave F2, cloudways
 * infra slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/cloudways/init.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical.
 *
 * Documented deviations:
 *
 * 1. `declare(strict_types=1)` added.
 * 2. File paths resolve from `NVOOS_CONTENT_GRAPH_PRO_*` with the `src/`
 *    root (the client/helpers resolve from `src/cloudways/`).
 * 3. Slimmed wiring — the admin settings page require is file-gated (the
 *    page lands with the cloudways infra slice; the instantiation guard
 *    stays byte-identical).
 * 4. Monolith guard — the helpers file declares the global
 *    `wp_mcp_ai_is_cloudways_toolkit_enabled()` function that the base copy
 *    also declares; the collision is a compile-time fatal, so the ENTIRE
 *    body is wrapped in a runtime `! defined( 'WP_MCP_AI_PATH' )` block
 *    (financial-init deviation 5 precedent).
 * 5. New standalone-only tool wiring (financial-init deviation 6
 *    precedent): a `wp_mcp_ai_pro_tools` filter plus
 *    `wp_mcp_ai_pro_register_cloudways_ecosystem_tools()` — both start with
 *    empty maps and fill as the cloudways tool batch lands.
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

	// Load the API v2 client and helpers (always needed when toolkit is enabled).
	if ( ! class_exists( 'WP_MCP_AI_Cloudways_Client' ) ) {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/cloudways/class-wp-mcp-ai-cloudways-client.php';
	}

	// Register Cloudways disconnect admin-post handler.
	add_action(
		'admin_post_wp_mcp_ai_cloudways_disconnect',
		function () {
			if ( class_exists( 'WP_MCP_AI_Cloudways_Client' ) ) {
				WP_MCP_AI_Cloudways_Client::instance()->handle_cloudways_disconnect();
			}
		}
	);
	if ( ! class_exists( 'WP_MCP_AI_Cloudways_Helpers' ) && ! function_exists( 'wp_mcp_ai_is_cloudways_toolkit_enabled' ) ) {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/cloudways/class-wp-mcp-ai-cloudways-helpers.php';
	}

	// Load the abstract tool base (required by all Cloudways tool classes).
	if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Base' ) ) {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-base.php';
	}

	// Load Cloudways admin settings page when in admin area.
	if ( is_admin() ) {
		$nvoos_content_graph_pro_cloudways_settings = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-cloudways-settings-page.php';
		if ( ! class_exists( 'WP_MCP_AI_Cloudways_Settings_Page' ) && file_exists( $nvoos_content_graph_pro_cloudways_settings ) ) {
			require_once $nvoos_content_graph_pro_cloudways_settings;
			new WP_MCP_AI_Cloudways_Settings_Page();
		}
	}

	/**
	 * Standalone-only tool filter — carries the ported cloudways tool subset
	 * (inert standalone, consumed by the base plugin monolith). The map
	 * fills as the cloudways tool batch lands.
	 *
	 * @param array $tools Existing tool map (class => file).
	 * @return array Extended tool map.
	 */
	function wp_mcp_ai_pro_register_cloudways_tools( $tools ) {
		$nvoos_content_graph_pro_cloudways_tools = array();

		return array_merge( $tools, $nvoos_content_graph_pro_cloudways_tools );
	}

	/**
	 * Standalone-only ecosystem registration — registers the ported
	 * cloudways tools into the ecosystem graph ToolRegistry and the
	 * nvoos/core registry via `WP_MCP_AI_Pro_Tool_Adapter` (same wiring as
	 * the analytics/video inits). The list fills as the cloudways tool batch
	 * lands.
	 *
	 * @return void
	 */
	function wp_mcp_ai_pro_register_cloudways_ecosystem_tools() {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-tool-adapter.php';

		$nvoos_content_graph_pro_parent_registry = nvoos_content_graph_get_tool_registry();
		if ( ! $nvoos_content_graph_pro_parent_registry instanceof \NvoosContentGraph\ToolRegistry ) {
			return;
		}

		foreach ( array() as $nvoos_content_graph_pro_tool_class ) {
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
	add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_cloudways_tools', 10 );

	if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_cloudways_ecosystem_tools();
	}
} // End monolith guard (deviation 4).
