<?php
/**
 * Comic Creation Toolkit Initialization (ecosystem port — Wave F2,
 * comic-creation data layer).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/comic-creation/init.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical.
 *
 * Documented deviations:
 *
 * 1. `declare(strict_types=1)` added.
 * 2. File paths resolve from `NVOOS_CONTENT_GRAPH_PRO_*` with the `src/`
 *    root.
 * 3. Slimmed wiring — the admin pages (comic settings + comic research),
 *    the research-add page, and the consolidate page requires are
 *    file-gated (they land with the later comic-creation slices).
 * 4. Monolith guard — this init declares the global
 *    `wp_mcp_ai_enqueue_comic_creation_toolkit_admin_styles()` helper that
 *    the base init also declares; the collision is a compile-time fatal, so
 *    the ENTIRE body is wrapped in a runtime `! defined( 'WP_MCP_AI_PATH' )`
 *    block (financial-init deviation 5 precedent).
 * 5. New standalone-only tool wiring (financial-init deviation 6
 *    precedent): a `wp_mcp_ai_pro_tools` filter plus
 *    `wp_mcp_ai_pro_register_comic_creation_ecosystem_tools()` — both carry
 *    all thirteen ported comic tools (the twelve monolith-map tools +
 *    the tree-only import-blueprint tool).
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

	// Load Comic CPT class.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-comic-cpt.php';

	// Load Comic Panel CPT class.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-comic-panel-cpt.php';

	// Load Comic Character CPT class.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-comic-character-cpt.php';

	// Load Comic Script CPT class.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-comic-script-cpt.php';

	// Load Comic Creation admin pages (always load so menu items appear).
	if ( is_admin() ) {
		// Load CPT-based settings page (deferred — file-gated until the
		// comic-creation admin slice lands).
		$nvoos_content_graph_pro_comic_settings = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-comic-settings-page.php';
		if ( file_exists( $nvoos_content_graph_pro_comic_settings ) ) {
			require_once $nvoos_content_graph_pro_comic_settings;
			new WP_MCP_AI_Comic_Settings_Page();
		}

		// Load and initialize Research & Add page for comics (deferred — file-gated).
		$nvoos_content_graph_pro_comic_research = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-comic-research-page.php';
		if ( file_exists( $nvoos_content_graph_pro_comic_research ) ) {
			require_once $nvoos_content_graph_pro_comic_research;
			WP_MCP_AI_Comic_Research_Page::init();
		}
	}

	// Check if Comic Creation toolkit is enabled for advanced features.
	$nvoos_content_graph_pro_settings      = get_option( 'wp_mcp_ai_settings', array() );
	$nvoos_content_graph_pro_is_enabled    = ! empty( $nvoos_content_graph_pro_settings['enable_comic_creation_toolkit'] );
	$nvoos_content_graph_pro_is_base       = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
	$nvoos_content_graph_pro_is_pro_active = defined( 'WP_MCP_AI_PRO_VERSION' );

	// Only load advanced features if enabled and (not in base version or the Pro addon is active).
	if ( $nvoos_content_graph_pro_is_enabled && ( ! $nvoos_content_graph_pro_is_base || $nvoos_content_graph_pro_is_pro_active ) ) {
		// Load Research & Add for CCT/CPT integration (deferred — file-gated).
		$nvoos_content_graph_pro_comic_research_add = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/research-add/class-wp-mcp-ai-comic-research-add.php';
		if ( file_exists( $nvoos_content_graph_pro_comic_research_add ) ) {
			require_once $nvoos_content_graph_pro_comic_research_add;
			new WP_MCP_AI_Comic_Research_Add();
		}

		// Load Consolidate & Add page (deferred — file-gated).
		$nvoos_content_graph_pro_comic_consolidate = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-comic-consolidate-page.php';
		if ( file_exists( $nvoos_content_graph_pro_comic_consolidate ) ) {
			require_once $nvoos_content_graph_pro_comic_consolidate;
			WP_MCP_AI_Comic_Consolidate_Page::init();
		}

		// Register tools will be loaded automatically via the tools directory structure.
		// Tools are located in: plugins/nvoos-content-graph-pro/src/tools/comic-creation/.
	}

	// Initialize CPTs (always register so admin menu items are visible).
	add_action(
		'init',
		function () {
			WP_MCP_AI_Comic_CPT::init();
			WP_MCP_AI_Comic_Panel_CPT::init();
			WP_MCP_AI_Comic_Character_CPT::init();
			WP_MCP_AI_Comic_Script_CPT::init();
		},
		5
	);

	/**
	 * Enqueue comic creation toolkit admin styles.
	 *
	 * @since 2.0.0
	 *
	 * @param string $hook Current admin page hook (unused).
	 */
	function wp_mcp_ai_enqueue_comic_creation_toolkit_admin_styles( $hook ) {
		// Only load if toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_comic_creation_toolkit'] ) ) {
			return;
		}

		// Enqueue admin styles if available.
		$css_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'assets/css/admin-comic-creation-toolkit.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'wp-mcp-ai-comic-creation-toolkit-admin',
				NVOOS_CONTENT_GRAPH_PRO_URL . 'assets/css/admin-comic-creation-toolkit.css',
				array(),
				NVOOS_CONTENT_GRAPH_PRO_VERSION
			);
		}
	}
	add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_comic_creation_toolkit_admin_styles' );

	/**
	 * Standalone-only tool filter — carries the ported comic-creation tool
	 * subset (inert standalone, consumed by the base plugin monolith). The
	 * twelve gated tools mirror the monolith's inline `$comic_tools` map;
	 * the import-blueprint tool is tree-only (the base registers it
	 * nowhere — CRM CC-extras precedent).
	 *
	 * @param array $tools Existing tool map (class => file).
	 * @return array Extended tool map.
	 */
	function wp_mcp_ai_pro_register_comic_creation_tools( $tools ) {
		$nvoos_content_graph_pro_comic_tools = array(
			'WP_MCP_AI_Tool_Generate_Comic_Script'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/comic-creation/class-wp-mcp-ai-tool-generate-comic-script.php',
			'WP_MCP_AI_Tool_Breakdown_Comic_Panels'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/comic-creation/class-wp-mcp-ai-tool-breakdown-comic-panels.php',
			'WP_MCP_AI_Tool_Generate_Character_Sheet' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/comic-creation/class-wp-mcp-ai-tool-generate-character-sheet.php',
			'WP_MCP_AI_Tool_Generate_Comic_Panel'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/comic-creation/class-wp-mcp-ai-tool-generate-comic-panel.php',
			'WP_MCP_AI_Tool_Create_Comic_Layout'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/comic-creation/class-wp-mcp-ai-tool-create-comic-layout.php',
			'WP_MCP_AI_Tool_Add_Speech_Bubbles'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/comic-creation/class-wp-mcp-ai-tool-add-speech-bubbles.php',
			'WP_MCP_AI_Tool_Export_Comic_Cbz'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/comic-creation/class-wp-mcp-ai-tool-export-comic-cbz.php',
			'WP_MCP_AI_Tool_Colorize_Comic_Panel'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/comic-creation/class-wp-mcp-ai-tool-colorize-comic-panel.php',
			'WP_MCP_AI_Tool_Ink_Comic_Panel'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/comic-creation/class-wp-mcp-ai-tool-ink-comic-panel.php',
			'WP_MCP_AI_Tool_Letter_Comic_Panel'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/comic-creation/class-wp-mcp-ai-tool-letter-comic-panel.php',
			'WP_MCP_AI_Tool_Upscale_Comic_Page'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/comic-creation/class-wp-mcp-ai-tool-upscale-comic-page.php',
			'WP_MCP_AI_Tool_Apply_Comic_Style'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/comic-creation/class-wp-mcp-ai-tool-apply-comic-style.php',
			'WP_MCP_AI_Tool_Import_Comic_Creation_Blueprint' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/comic-creation/examples/class-wp-mcp-ai-tool-import-comic-creation-blueprint.php',
		);

		return array_merge( $tools, $nvoos_content_graph_pro_comic_tools );
	}

	/**
	 * Standalone-only ecosystem registration — registers the ported
	 * comic-creation tools into the ecosystem graph ToolRegistry and the
	 * nvoos/core registry via `WP_MCP_AI_Pro_Tool_Adapter` (same wiring as
	 * the image-production/video inits). Carries all thirteen ported tools
	 * (the twelve monolith-map tools + the tree-only import-blueprint tool).
	 *
	 * @return void
	 */
	function wp_mcp_ai_pro_register_comic_creation_ecosystem_tools() {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-tool-adapter.php';

		$nvoos_content_graph_pro_parent_registry = nvoos_content_graph_get_tool_registry();
		if ( ! $nvoos_content_graph_pro_parent_registry instanceof \NvoosContentGraph\ToolRegistry ) {
			return;
		}

		foreach (
			array(
				'WP_MCP_AI_Tool_Generate_Comic_Script',
				'WP_MCP_AI_Tool_Breakdown_Comic_Panels',
				'WP_MCP_AI_Tool_Generate_Character_Sheet',
				'WP_MCP_AI_Tool_Generate_Comic_Panel',
				'WP_MCP_AI_Tool_Create_Comic_Layout',
				'WP_MCP_AI_Tool_Add_Speech_Bubbles',
				'WP_MCP_AI_Tool_Export_Comic_Cbz',
				'WP_MCP_AI_Tool_Colorize_Comic_Panel',
				'WP_MCP_AI_Tool_Ink_Comic_Panel',
				'WP_MCP_AI_Tool_Letter_Comic_Panel',
				'WP_MCP_AI_Tool_Upscale_Comic_Page',
				'WP_MCP_AI_Tool_Apply_Comic_Style',
				'WP_MCP_AI_Tool_Import_Comic_Creation_Blueprint',
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
	add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_comic_creation_tools', 10 );

	if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_comic_creation_ecosystem_tools();
	}
} // End monolith guard (deviation 4).
