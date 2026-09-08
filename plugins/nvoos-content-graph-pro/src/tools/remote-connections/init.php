<?php
/**
 * Remote Connections toolkit initialization (ecosystem port — Wave F6,
 * remote-connections tools slice).
 *
 * Standalone-only init — the base tree ships NO remote-connections init:
 * the two tools register inline via `wp_mcp_ai_pro_register_tools()` in
 * `mcp-ai-wpoos-pro.php` (the main `$pro_tools` map and the shopify map).
 * The standalone registry gains a standalone-only `remote_connections`
 * module that boots this init (financial-planning/social-media precedent).
 *
 * Documented deviations:
 *
 * 1. `declare(strict_types=1)` added.
 * 2. File paths resolve from `NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/'` — the
 *    remote-site manager, the shopify connection-resolver trait, and the
 *    shopify client all resolve from the addon's already-ported copies.
 * 3. Monolith guard — the base plugin declares the tool map inline, so the
 *    entire body is wrapped in a runtime `! defined( 'WP_MCP_AI_PATH' )`
 *    block (same pattern as the financial init deviation 5).
 * 4. New standalone-only tool wiring (same pattern as the CRM/financial
 *    deviation 6): a `wp_mcp_ai_pro_tools` filter carrying the two ported
 *    tools plus `wp_mcp_ai_pro_register_remote_connections_ecosystem_tools()`
 *    registering them into the ecosystem graph ToolRegistry and the
 *    nvoos/core registry via `WP_MCP_AI_Pro_Tool_Adapter`.
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

// Monolith guard (deviation 3): full-body runtime wrap — see the header.
if ( ! defined( 'WP_MCP_AI_PATH' ) ) {

	// Load the shared remote-site manager (both tools require it at file load).
	if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-remote-site-manager.php';
	}

	/**
	 * Standalone-only tool filter — carries the ported remote-connections
	 * tool subset (inert standalone, consumed by the base plugin monolith).
	 *
	 * @param array $tools Existing tool map (class => file).
	 * @return array Extended tool map.
	 */
	function wp_mcp_ai_pro_register_remote_connections_tools( $tools ) {
		$nvoos_content_graph_pro_remote_tools = array(
			'WP_MCP_AI_Tool_Remote_WP_Connection'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/remote-connections/class-wp-mcp-ai-tool-remote-wp-connection.php',
			'WP_MCP_AI_Tool_Remote_Shopify_Connection' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/remote-connections/class-wp-mcp-ai-tool-remote-shopify-connection.php',
		);

		return array_merge( $tools, $nvoos_content_graph_pro_remote_tools );
	}

	/**
	 * Standalone-only ecosystem registration — registers the ported
	 * remote-connections tools into the ecosystem graph ToolRegistry and the
	 * nvoos/core registry via `WP_MCP_AI_Pro_Tool_Adapter` (same wiring as
	 * the vault/ecommerce/financial inits). The tool files load their
	 * D8-compat interface/trait dependencies via the entry's spl autoloader
	 * at class-declaration time.
	 *
	 * @return void
	 */
	function wp_mcp_ai_pro_register_remote_connections_ecosystem_tools() {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-tool-adapter.php';
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/remote-connections/class-wp-mcp-ai-tool-remote-wp-connection.php';
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/remote-connections/class-wp-mcp-ai-tool-remote-shopify-connection.php';

		$nvoos_content_graph_pro_parent_registry = nvoos_content_graph_get_tool_registry();
		if ( ! $nvoos_content_graph_pro_parent_registry instanceof \NvoosContentGraph\ToolRegistry ) {
			return;
		}

		foreach (
			array(
				'WP_MCP_AI_Tool_Remote_WP_Connection',
				'WP_MCP_AI_Tool_Remote_Shopify_Connection',
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

	// ---- Standalone-only tool wiring (deviation 4). ----
	add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_remote_connections_tools', 10 );

	if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_remote_connections_ecosystem_tools();
	}
} // End monolith guard (deviation 3).
