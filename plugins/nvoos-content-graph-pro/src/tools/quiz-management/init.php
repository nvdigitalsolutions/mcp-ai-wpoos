<?php
/**
 * tools/quiz-management/init.php (ecosystem port — Wave F5, quiz-management data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/quiz-management/init.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps; the CPT/CCT/metabox requires resolve from the
 * addon's `src/` copies; the two admin-page requires are file-gated until the quiz admin slice
 * lands (the research `::init()` and the settings-page `new` fire inside the same gates); NEW
 * standalone-only wiring (deviation, same as the CRM init): a `wp_mcp_ai_pro_tools` filter plus
 * `wp_mcp_ai_pro_register_quiz_ecosystem_tools()` — both start empty and fill as the quiz tool
 * batch lands; full-body `! defined( 'WP_MCP_AI_PATH' )` guard (the global enqueue helper would
 * collide compile-time with the base copy in the monorepo test matrix).
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

	// Load Quiz CPT class.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-quiz-cpt.php';

	// Register Quiz meta fields with JetEngine for listing/discovery.
	if ( function_exists( 'jet_engine' ) && class_exists( 'WP_MCP_AI_JetEngine_Meta_Helper' ) ) {
		WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( 'mcp_ai_quiz' );
	}

	// Load JetEngine quiz CCT if JetEngine is active.
	if ( function_exists( 'jet_engine' ) ) {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-jetengine-quizzes-cct.php';
	}

	// Load Quiz admin pages (always load so menu items appear when CPT is registered).
	if ( is_admin() ) {
		$nvoos_content_graph_pro_quiz_research_page = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-quiz-research-page.php';
		if ( file_exists( $nvoos_content_graph_pro_quiz_research_page ) ) {
			require_once $nvoos_content_graph_pro_quiz_research_page;
			WP_MCP_AI_Quiz_Research_Page::init();
		}

		$nvoos_content_graph_pro_quiz_settings_page = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-quiz-settings-page.php';
		if ( file_exists( $nvoos_content_graph_pro_quiz_settings_page ) ) {
			require_once $nvoos_content_graph_pro_quiz_settings_page;
			new WP_MCP_AI_Quiz_Settings_Page();
		}
	}

	/**
	 * Enqueue Quiz management admin styles.
	 *
	 * @param string $hook Current admin page hook.
	 */
	function wp_mcp_ai_enqueue_quiz_management_admin_styles( $hook ) {
		// Only load on Quiz management edit screens.
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, array( 'mcp_ai_quiz', 'mcp_ai_submission' ), true ) ) {
			return;
		}

		// Check if quiz system is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_quiz_system'] ) ) {
			return;
		}

		// Enqueue admin styles if available.
		$css_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'assets/css/admin-quiz-management.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'wp-mcp-ai-quiz-management-admin',
				NVOOS_CONTENT_GRAPH_PRO_URL . 'assets/css/admin-quiz-management.css',
				array(),
				NVOOS_CONTENT_GRAPH_PRO_VERSION
			);
		}
	}
	add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_quiz_management_admin_styles' );

	// ---- Standalone-only tool wiring (deviation). ----
	add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_quiz_tools', 10 );

	if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_quiz_ecosystem_tools();
	}
} // End monolith guard (deviation).

/**
 * Standalone-only tool filter — mirrors the monolith's inline quiz map (the
 * `enable_quiz_system` gate in `mcp-ai-wpoos-pro.php`). The map fills as the
 * quiz tool batch lands.
 *
 * @param array $tools Existing tool map.
 * @return array Extended tool map.
 */
function wp_mcp_ai_pro_register_quiz_tools( $tools ) {
	$nvoos_content_graph_pro_quiz_tools = array();

	return array_merge( $tools, $nvoos_content_graph_pro_quiz_tools );
}

/**
 * Standalone-only ecosystem registration — registers the ported quiz tools
 * into the ecosystem graph ToolRegistry and the nvoos/core registry via
 * `WP_MCP_AI_Pro_Tool_Adapter` (same wiring as the image-production inits).
 * The list fills as the quiz tool batch lands.
 *
 * @return void
 */
function wp_mcp_ai_pro_register_quiz_ecosystem_tools() {
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
