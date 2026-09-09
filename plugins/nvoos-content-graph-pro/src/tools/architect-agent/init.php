<?php
/**
 * Architect Agent Toolkit Initialization (ecosystem port — Wave F2,
 * architect-agent toolkit).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/architect-agent/init.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical.
 *
 * Documented deviations:
 *
 * 1. `declare(strict_types=1)` added.
 * 2. File paths resolve from `NVOOS_CONTENT_GRAPH_PRO_*`.
 * 3. Slimmed wiring — the settings-page require is file-gated; the base
 *    `wp_mcp_ai_load_architect_agent_tools()` registry registration (via the
 *    `wp_mcp_ai_load_pro_tools` action) is replaced by the standalone-only
 *    tool filter + ecosystem registration (the base tool registry does not
 *    exist standalone).
 * 4. Monolith guard — this init declares the global
 *    `wp_mcp_ai_enqueue_architect_agent_toolkit_admin_styles()` helper (and
 *    the base init the same), so the ENTIRE body is wrapped in a runtime
 *    `! defined( 'WP_MCP_AI_PATH' )` block (financial-init deviation 5
 *    precedent).
 * 5. New standalone-only tool wiring (financial-init deviation 6
 *    precedent): a `wp_mcp_ai_pro_tools` filter plus
 *    `wp_mcp_ai_pro_register_architect_agent_ecosystem_tools()` carrying the
 *    five registered tools (manage-files, execute-shell-command, git-inspect,
 *    git-change, search-codebase) — the legacy git-operations class stays
 *    loadable but unregistered, byte-identical with the base init's
 *    deprecated-alias handling.
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

	// Check if Architect Agent toolkit is enabled.
	$nvoos_content_graph_pro_settings   = get_option( 'wp_mcp_ai_settings', array() );
	$nvoos_content_graph_pro_is_enabled = ! empty( $nvoos_content_graph_pro_settings['enable_architect_agent_toolkit'] );
	$nvoos_content_graph_pro_is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

	// Only load if enabled and not in base version.
	if ( $nvoos_content_graph_pro_is_enabled && ! $nvoos_content_graph_pro_is_base ) {

		// Load Architect Agent admin pages.
		if ( is_admin() ) {
			$nvoos_content_graph_pro_architect_settings = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-architect-agent-settings-page.php';
			if ( file_exists( $nvoos_content_graph_pro_architect_settings ) ) {
				require_once $nvoos_content_graph_pro_architect_settings;
			}
		}
	}

	/**
	 * Enqueue Architect Agent toolkit admin styles.
	 *
	 * @since 1.1.0
	 *
	 * @param string $hook Current admin page hook (unused).
	 */
	function wp_mcp_ai_enqueue_architect_agent_toolkit_admin_styles( $hook ) {
		// Only load if toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_architect_agent_toolkit'] ) ) {
			return;
		}

		// Enqueue admin styles if available.
		$css_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'assets/css/admin-architect-agent-toolkit.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'wp-mcp-ai-architect-agent-toolkit-admin',
				NVOOS_CONTENT_GRAPH_PRO_URL . 'assets/css/admin-architect-agent-toolkit.css',
				array(),
				NVOOS_CONTENT_GRAPH_PRO_VERSION
			);
		}
	}
	add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_architect_agent_toolkit_admin_styles' );

	/**
	 * Standalone-only tool filter — carries the ported architect-agent tools
	 * (inert standalone, consumed by the base plugin monolith). The base
	 * registers the five live tools via the tool registry; the legacy
	 * git-operations class stays loadable but unregistered.
	 *
	 * @param array $tools Existing tool map (class => file).
	 * @return array Extended tool map.
	 */
	function wp_mcp_ai_pro_register_architect_agent_tools( $tools ) {
		$nvoos_content_graph_pro_architect_tools = array(
			'WP_MCP_AI_Tool_Manage_Files'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/architect-agent/class-wp-mcp-ai-tool-manage-files.php',
			'WP_MCP_AI_Tool_Execute_Shell_Command' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/architect-agent/class-wp-mcp-ai-tool-execute-shell-command.php',
			'WP_MCP_AI_Tool_Git_Inspect'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/architect-agent/class-wp-mcp-ai-tool-git-inspect.php',
			'WP_MCP_AI_Tool_Git_Change'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/architect-agent/class-wp-mcp-ai-tool-git-change.php',
			'WP_MCP_AI_Tool_Search_Codebase'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/architect-agent/class-wp-mcp-ai-tool-search-codebase.php',
		);

		return array_merge( $tools, $nvoos_content_graph_pro_architect_tools );
	}

	/**
	 * Standalone-only ecosystem registration — registers the ported
	 * architect-agent tools into the ecosystem graph ToolRegistry and the
	 * nvoos/core registry via `WP_MCP_AI_Pro_Tool_Adapter` (same wiring as
	 * the image-production/video inits). Carries the five registered tools
	 * (the legacy git-operations class stays unregistered — byte-identical).
	 *
	 * @return void
	 */
	function wp_mcp_ai_pro_register_architect_agent_ecosystem_tools() {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-tool-adapter.php';
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/architect-agent/helpers.php';
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/architect-agent/trait-wp-mcp-ai-tool-git-helpers.php';

		$nvoos_content_graph_pro_parent_registry = nvoos_content_graph_get_tool_registry();
		if ( ! $nvoos_content_graph_pro_parent_registry instanceof \NvoosContentGraph\ToolRegistry ) {
			return;
		}

		foreach (
			array(
				'WP_MCP_AI_Tool_Manage_Files',
				'WP_MCP_AI_Tool_Execute_Shell_Command',
				'WP_MCP_AI_Tool_Git_Inspect',
				'WP_MCP_AI_Tool_Git_Change',
				'WP_MCP_AI_Tool_Search_Codebase',
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
	add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_architect_agent_tools', 10 );

	if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_architect_agent_ecosystem_tools();
	}
} // End monolith guard (deviation 4).
