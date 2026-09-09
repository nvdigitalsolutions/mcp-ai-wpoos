<?php
/**
 * Architectural Design Toolkit Initialization (ecosystem port — Wave F2,
 * architectural-design data layer).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/architectural-design/init.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical.
 *
 * Documented deviations:
 *
 * 1. `declare(strict_types=1)` added.
 * 2. File paths resolve from `NVOOS_CONTENT_GRAPH_PRO_*` with the `src/`
 *    root.
 * 3. Slimmed wiring — the admin pages (project/drawing/specification
 *    settings + research) and the metabox classes are file-gated (they land
 *    with the architectural-design admin slice); the tool loader is
 *    replaced by the standalone-only tool wiring (deviation 5).
 * 4. Monolith guard — this init declares the global
 *    `wp_mcp_ai_enqueue_architectural_design_toolkit_admin_styles()`,
 *    `wp_mcp_ai_init_architectural_design_admin()`, and
 *    `wp_mcp_ai_load_architectural_design_tools()` helpers that the base
 *    init also declares; the collision is a compile-time fatal, so the
 *    ENTIRE body is wrapped in a runtime `! defined( 'WP_MCP_AI_PATH' )`
 *    block (financial-init deviation 5 precedent).
 * 5. New standalone-only tool wiring (financial-init deviation 6
 *    precedent): a `wp_mcp_ai_pro_tools` filter plus
 *    `wp_mcp_ai_pro_register_architectural_design_ecosystem_tools()` — both
 *    start with empty maps and fill as the architectural-design tool batch
 *    lands.
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

	// Load CPT classes.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-architectural-project-cpt.php';
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-architectural-drawing-cpt.php';
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-architectural-specification-cpt.php';
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-architectural-precedent-cpt.php';

	// Register architectural meta fields with JetEngine for listing/discovery.
	if ( function_exists( 'jet_engine' ) && class_exists( 'WP_MCP_AI_JetEngine_Meta_Helper' ) ) {
		WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( 'mcp_ai_arch_proj' );
		WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( 'mcp_ai_arch_draw' );
		WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( 'mcp_ai_arch_spec' );
	}

	// Initialize CPTs - they have their own checks for enabled/base version.
	WP_MCP_AI_Architectural_Project_CPT::init();
	WP_MCP_AI_Architectural_Drawing_CPT::init();
	WP_MCP_AI_Architectural_Specification_CPT::init();
	WP_MCP_AI_Architectural_Precedent_CPT::init();

	// Load Research & Add and Settings pages for admin.
	if ( is_admin() ) {
		// Check if architectural design toolkit is enabled and not in base version (unless Pro addon is active).
		$nvoos_content_graph_pro_settings   = get_option( 'wp_mcp_ai_settings', array() );
		$nvoos_content_graph_pro_is_enabled = ! empty( $nvoos_content_graph_pro_settings['enable_architectural_design_toolkit'] );
		$nvoos_content_graph_pro_is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
		$nvoos_content_graph_pro_is_pro     = defined( 'NVOOS_CONTENT_GRAPH_PRO_VERSION' );

		if ( $nvoos_content_graph_pro_is_enabled && ( ! $nvoos_content_graph_pro_is_base || $nvoos_content_graph_pro_is_pro ) ) {
			// Deferred — file-gated until the architectural-design admin slice lands.
			$nvoos_content_graph_pro_project_settings = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-architectural-project-settings-page.php';
			$nvoos_content_graph_pro_project_research = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-architectural-project-research-page.php';
			if ( file_exists( $nvoos_content_graph_pro_project_settings ) ) {
				require_once $nvoos_content_graph_pro_project_settings;
			}
			if ( file_exists( $nvoos_content_graph_pro_project_research ) ) {
				require_once $nvoos_content_graph_pro_project_research;
				WP_MCP_AI_Architectural_Project_Research_Page::init();
			}

			$nvoos_content_graph_pro_drawing_settings = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-architectural-drawing-settings-page.php';
			$nvoos_content_graph_pro_drawing_research = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-architectural-drawing-research-page.php';
			if ( file_exists( $nvoos_content_graph_pro_drawing_settings ) ) {
				require_once $nvoos_content_graph_pro_drawing_settings;
			}
			if ( file_exists( $nvoos_content_graph_pro_drawing_research ) ) {
				require_once $nvoos_content_graph_pro_drawing_research;
				WP_MCP_AI_Architectural_Drawing_Research_Page::init();
			}

			$nvoos_content_graph_pro_spec_settings = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-architectural-specification-settings-page.php';
			$nvoos_content_graph_pro_spec_research = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-architectural-specification-research-page.php';
			if ( file_exists( $nvoos_content_graph_pro_spec_settings ) ) {
				require_once $nvoos_content_graph_pro_spec_settings;
			}
			if ( file_exists( $nvoos_content_graph_pro_spec_research ) ) {
				require_once $nvoos_content_graph_pro_spec_research;
				WP_MCP_AI_Architectural_Specification_Research_Page::init();
			}
		}
	}

	/**
	 * Initialize architectural design admin interface.
	 */
	function wp_mcp_ai_init_architectural_design_admin() {
		// Only load in admin context.
		if ( ! is_admin() ) {
			return;
		}

		// Check if architectural design toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_architectural_design_toolkit'] ) ) {
			return;
		}

		// Deferred — file-gated until the architectural-design admin slice lands.
		$nvoos_content_graph_pro_project_metabox = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-architectural-project-metabox.php';
		$nvoos_content_graph_pro_drawing_metabox = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-architectural-drawing-metabox.php';
		$nvoos_content_graph_pro_spec_metabox    = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-architectural-specification-metabox.php';
		if ( file_exists( $nvoos_content_graph_pro_project_metabox ) ) {
			require_once $nvoos_content_graph_pro_project_metabox;
			WP_MCP_AI_Architectural_Project_Metabox::init();
		}
		if ( file_exists( $nvoos_content_graph_pro_drawing_metabox ) ) {
			require_once $nvoos_content_graph_pro_drawing_metabox;
			WP_MCP_AI_Architectural_Drawing_Metabox::init();
		}
		if ( file_exists( $nvoos_content_graph_pro_spec_metabox ) ) {
			require_once $nvoos_content_graph_pro_spec_metabox;
			WP_MCP_AI_Architectural_Specification_Metabox::init();
		}
	}
	add_action( 'admin_init', 'wp_mcp_ai_init_architectural_design_admin' );

	/**
	 * Enqueue architectural design toolkit admin styles.
	 *
	 * @since 1.1.0
	 *
	 * @param string $hook Current admin page hook (unused).
	 */
	function wp_mcp_ai_enqueue_architectural_design_toolkit_admin_styles( $hook ) {
		// Only load if toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_architectural_design_toolkit'] ) ) {
			return;
		}

		// Enqueue admin styles if available.
		$css_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'assets/css/admin-architectural-design-toolkit.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'wp-mcp-ai-architectural-design-toolkit-admin',
				NVOOS_CONTENT_GRAPH_PRO_URL . 'assets/css/admin-architectural-design-toolkit.css',
				array(),
				NVOOS_CONTENT_GRAPH_PRO_VERSION
			);
		}
	}
	add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_architectural_design_toolkit_admin_styles' );

	/**
	 * Standalone-only tool filter — carries the ported architectural-design
	 * tool subset (inert standalone, consumed by the base plugin monolith).
	 * The map fills as the architectural-design tool batch lands.
	 *
	 * @param array $tools Existing tool map (class => file).
	 * @return array Extended tool map.
	 */
	function wp_mcp_ai_pro_register_architectural_design_tools( $tools ) {
		$nvoos_content_graph_pro_arch_design_tools = array();

		return array_merge( $tools, $nvoos_content_graph_pro_arch_design_tools );
	}

	/**
	 * Standalone-only ecosystem registration — registers the ported
	 * architectural-design tools into the ecosystem graph ToolRegistry and
	 * the nvoos/core registry via `WP_MCP_AI_Pro_Tool_Adapter` (same wiring
	 * as the image-production/video inits). The list fills as the
	 * architectural-design tool batch lands.
	 *
	 * @return void
	 */
	function wp_mcp_ai_pro_register_architectural_design_ecosystem_tools() {
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
	add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_architectural_design_tools', 10 );

	if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_architectural_design_ecosystem_tools();
	}
} // End monolith guard (deviation 4).
