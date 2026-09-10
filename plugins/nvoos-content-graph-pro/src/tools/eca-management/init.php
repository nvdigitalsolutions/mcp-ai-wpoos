<?php
/**
 * tools/eca-management/init.php (ecosystem port — Wave F5, eca-management data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/eca-management/init.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps; the CPT/metabox/eca-DB requires resolve from the
 * addon's `src/` copies; the REST-controller require is file-gated until the ECA REST slice lands
 * and the four admin-page requires are file-gated until the ECA admin slice lands (all inside the
 * byte-identical enabled/base-version/pro-active gate); NEW standalone-only wiring (deviation,
 * same as the quiz init): a `wp_mcp_ai_pro_tools` filter plus
 * `wp_mcp_ai_pro_register_eca_ecosystem_tools()` — both start empty and fill as the ECA tool
 * batch lands; full-body `! defined( 'WP_MCP_AI_PATH' )` guard (the global enqueue + REST-route
 * helpers would collide compile-time with the base copy in the monorepo test matrix).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_MCP_AI_PATH' ) ) {

	// Load ECA CPT class.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-eca-cpt.php';

	// Register ECA and Student meta fields with JetEngine for listing/discovery.
	if ( function_exists( 'jet_engine' ) && class_exists( 'WP_MCP_AI_JetEngine_Meta_Helper' ) ) {
		WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( 'mcp_ai_eca' );
		WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( 'mcp_ai_student' );
	}

	// Load ECA database tables (enrollments + attendance).
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/eca/init.php';

	// Load ECA REST API Controller (file-gated until the ECA REST slice lands).
	$nvoos_content_graph_pro_eca_rest = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/rest/class-wp-mcp-ai-eca-rest-controller.php';
	if ( file_exists( $nvoos_content_graph_pro_eca_rest ) ) {
		require_once $nvoos_content_graph_pro_eca_rest;
	}

	// Load ECA Research & Add page.
	if ( is_admin() ) {
		// Check if ECA management is enabled and not in base version (unless Pro addon is active).
		$settings      = get_option( 'wp_mcp_ai_settings', array() );
		$is_enabled    = ! empty( $settings['enable_eca_management'] );
		$is_base       = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
		$is_pro_active = defined( 'WP_MCP_AI_PRO_VERSION' );

		if ( $is_enabled && ( ! $is_base || $is_pro_active ) ) {
			$nvoos_content_graph_pro_eca_research = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-eca-research-page.php';
			if ( file_exists( $nvoos_content_graph_pro_eca_research ) ) {
				require_once $nvoos_content_graph_pro_eca_research;
			}
			$nvoos_content_graph_pro_eca_settings = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-eca-settings-page.php';
			if ( file_exists( $nvoos_content_graph_pro_eca_settings ) ) {
				require_once $nvoos_content_graph_pro_eca_settings;
			}
			$nvoos_content_graph_pro_eca_dashboard = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-eca-dashboard-page.php';
			if ( file_exists( $nvoos_content_graph_pro_eca_dashboard ) ) {
				require_once $nvoos_content_graph_pro_eca_dashboard;
			}
			$nvoos_content_graph_pro_eca_consolidate = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-eca-consolidate-page.php';
			if ( file_exists( $nvoos_content_graph_pro_eca_consolidate ) ) {
				require_once $nvoos_content_graph_pro_eca_consolidate;
			}
		}
	}

	/**
	 * Enqueue ECA management admin styles.
	 *
	 * @param string $hook Current admin page hook.
	 */
	function wp_mcp_ai_enqueue_eca_management_admin_styles( $hook ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter required by admin_enqueue_scripts action.
		// Only load on ECA management edit screens.
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, array( 'mcp_ai_eca', 'mcp_ai_student' ), true ) ) {
			return;
		}

		// Check if ECA management is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_eca_management'] ) ) {
			return;
		}

		// Enqueue admin styles if available.
		$css_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'assets/css/admin-eca-management.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'wp-mcp-ai-eca-management-admin',
				NVOOS_CONTENT_GRAPH_PRO_URL . 'assets/css/admin-eca-management.css',
				array(),
				NVOOS_CONTENT_GRAPH_PRO_VERSION
			);
		}
	}
	add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_eca_management_admin_styles' );

	/**
	 * Register ECA Management REST API routes.
	 */
	function wp_mcp_ai_register_eca_rest_routes() {
		// Check if ECA management is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_eca_management'] ) ) {
			return;
		}

		// Check if not in Base Version or Pro addon is active.
		$is_base       = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
		$is_pro_active = defined( 'WP_MCP_AI_PRO_VERSION' );

		if ( $is_base && ! $is_pro_active ) {
			return;
		}

		$controller = new WP_MCP_AI_ECA_REST_Controller();
		$controller->register_routes();
	}
	add_action( 'rest_api_init', 'wp_mcp_ai_register_eca_rest_routes' );

	// ---- Standalone-only tool wiring (deviation). ----
	add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_eca_tools', 10 );

	if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_eca_ecosystem_tools();
	}
} // End monolith guard (deviation).

/**
 * Standalone-only tool filter — mirrors the monolith's inline ECA map (the
 * `enable_eca_management` gate in `mcp-ai-wpoos-pro.php`). The map fills as
 * the ECA tool batch lands.
 *
 * @param array $tools Existing tool map.
 * @return array Extended tool map.
 */
function wp_mcp_ai_pro_register_eca_tools( $tools ) {
	$nvoos_content_graph_pro_eca_tools = array();

	return array_merge( $tools, $nvoos_content_graph_pro_eca_tools );
}

/**
 * Standalone-only ecosystem registration — registers the ported ECA tools
 * into the ecosystem graph ToolRegistry and the nvoos/core registry via
 * `WP_MCP_AI_Pro_Tool_Adapter` (same wiring as the quiz inits). The list
 * fills as the ECA tool batch lands.
 *
 * @return void
 */
function wp_mcp_ai_pro_register_eca_ecosystem_tools() {
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
