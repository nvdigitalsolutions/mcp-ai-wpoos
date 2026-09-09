<?php
/**
 * Image Production Toolkit Initialization (ecosystem port — Wave F2,
 * image-production data layer).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/image-production/init.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical.
 *
 * Documented deviations:
 *
 * 1. `declare(strict_types=1)` added.
 * 2. File paths resolve from `NVOOS_CONTENT_GRAPH_PRO_*` with the `src/`
 *    root.
 * 3. Slimmed wiring — the admin pages (CPT settings + image-template
 *    research) and the research-add page requires are file-gated and the
 *    harmonization init require stays file-gated (all land with the later
 *    image-production slices).
 * 4. Monolith guard — this init declares the global
 *    `wp_mcp_ai_enqueue_image_production_toolkit_admin_styles()` helper that
 *    the base init also declares; the collision is a compile-time fatal, so
 *    the ENTIRE body is wrapped in a runtime `! defined( 'WP_MCP_AI_PATH' )`
 *    block (financial-init deviation 5 precedent).
 * 5. New standalone-only tool wiring (financial-init deviation 6
 *    precedent): a `wp_mcp_ai_pro_tools` filter plus
 *    `wp_mcp_ai_pro_register_image_production_ecosystem_tools()` — both
 *    start with empty maps and fill as the image-production tool batches
 *    land.
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

	// Load Image Template CPT class.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-image-template-cpt.php';

	// Register Image Template meta fields with JetEngine for listing/discovery.
	if ( function_exists( 'jet_engine' ) && class_exists( 'WP_MCP_AI_JetEngine_Meta_Helper' ) ) {
		WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( 'mcp_ai_image_tpl' );
	}

	// Load Image Production admin pages (always load so menu items appear).
	if ( is_admin() ) {
		// Load CPT-based settings page (deferred — file-gated until the
		// image-production admin slice lands).
		$nvoos_content_graph_pro_img_cpt_settings = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-image-production-cpt-settings-page.php';
		if ( file_exists( $nvoos_content_graph_pro_img_cpt_settings ) ) {
			require_once $nvoos_content_graph_pro_img_cpt_settings;
			new WP_MCP_AI_Image_Production_Settings_Page();
		}

		// Load and initialize Research & Add page for image templates (deferred
		// — file-gated until the image-production admin slice lands).
		$nvoos_content_graph_pro_img_research = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-image-template-research-page.php';
		if ( file_exists( $nvoos_content_graph_pro_img_research ) ) {
			require_once $nvoos_content_graph_pro_img_research;
			WP_MCP_AI_Image_Template_Research_Page::init();
		}
	}

	// Check if Image Production toolkit is enabled for advanced features.
	$nvoos_content_graph_pro_settings   = get_option( 'wp_mcp_ai_settings', array() );
	$nvoos_content_graph_pro_is_enabled = ! empty( $nvoos_content_graph_pro_settings['enable_image_production_toolkit'] );
	$nvoos_content_graph_pro_is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

	// Only load advanced features if enabled and not in base version.
	if ( $nvoos_content_graph_pro_is_enabled && ! $nvoos_content_graph_pro_is_base ) {
		// Load Research & Add for CCT/CPT integration (deferred — file-gated
		// until the image-production admin slice lands).
		$nvoos_content_graph_pro_img_research_add = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/research-add/class-wp-mcp-ai-image-production-research-add.php';
		if ( file_exists( $nvoos_content_graph_pro_img_research_add ) ) {
			require_once $nvoos_content_graph_pro_img_research_add;
			new WP_MCP_AI_Image_Production_Research_Add();
		}

		// Register tools will be loaded automatically via the tools directory structure.
		// Tools are located in: plugins/nvoos-content-graph-pro/src/tools/image-production/.

		// Load the harmonization sub-toolkit (deferred — file-gated until the
		// harmonization slice lands).
		$nvoos_content_graph_pro_harmonization_init = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/harmonization/harmonization-init.php';
		if ( file_exists( $nvoos_content_graph_pro_harmonization_init ) ) {
			require_once $nvoos_content_graph_pro_harmonization_init;
		}
	}

	// Initialize Image Template CPT.
	add_action(
		'init',
		function () {
			WP_MCP_AI_Image_Template_CPT::init();
		},
		5
	);

	/**
	 * Enqueue image production toolkit admin styles.
	 *
	 * @since 1.1.0
	 *
	 * @param string $hook Current admin page hook (unused).
	 */
	function wp_mcp_ai_enqueue_image_production_toolkit_admin_styles( $hook ) {
		// Only load if toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_image_production_toolkit'] ) ) {
			return;
		}

		// Enqueue admin styles if available.
		$css_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'assets/css/admin-image-production-toolkit.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'wp-mcp-ai-image-production-toolkit-admin',
				NVOOS_CONTENT_GRAPH_PRO_URL . 'assets/css/admin-image-production-toolkit.css',
				array(),
				NVOOS_CONTENT_GRAPH_PRO_VERSION
			);
		}
	}
	add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_image_production_toolkit_admin_styles' );

	/**
	 * Standalone-only tool filter — carries the ported image-production tool
	 * subset (inert standalone, consumed by the base plugin monolith). The
	 * map fills as the image-production tool batches land.
	 *
	 * @param array $tools Existing tool map (class => file).
	 * @return array Extended tool map.
	 */
	function wp_mcp_ai_pro_register_image_production_tools( $tools ) {
		$nvoos_content_graph_pro_img_tools = array();

		return array_merge( $tools, $nvoos_content_graph_pro_img_tools );
	}

	/**
	 * Standalone-only ecosystem registration — registers the ported
	 * image-production tools into the ecosystem graph ToolRegistry and the
	 * nvoos/core registry via `WP_MCP_AI_Pro_Tool_Adapter` (same wiring as
	 * the analytics/video inits). The list fills as the image-production
	 * tool batches land.
	 *
	 * @return void
	 */
	function wp_mcp_ai_pro_register_image_production_ecosystem_tools() {
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
	add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_image_production_tools', 10 );

	if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_image_production_ecosystem_tools();
	}
} // End monolith guard (deviation 4).
