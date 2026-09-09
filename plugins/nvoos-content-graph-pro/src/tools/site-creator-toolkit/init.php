<?php
/**
 * Site Creator Toolkit Initialization (ecosystem port — Wave F2, site-creator
 * data layer).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/site-creator-toolkit/init.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical.
 *
 * Documented deviations:
 *
 * 1. `declare(strict_types=1)` added.
 * 2. File paths resolve from `NVOOS_CONTENT_GRAPH_PRO_*` with the `src/`
 *    root.
 * 3. Slimmed wiring — the settings-page + CPT requires are file-gated; the
 *    base `wp_mcp_ai_load_pro_tools` registry registration is replaced by
 *    the standalone-only tool wiring (deviation 5); the base
 *    `WP_MCP_AI_PRO_VERSION` top-level guard is dropped (the standalone
 *    addon IS the Pro addon).
 * 4. Monolith guard — this init declares the global
 *    `wp_mcp_ai_enqueue_site_creator_toolkit_admin_styles()`,
 *    `wp_mcp_ai_init_site_creator_admin()`, and
 *    `wp_mcp_ai_load_site_creator_tools()` helpers that the base init also
 *    declares; the collision is a compile-time fatal, so the ENTIRE body is
 *    wrapped in a runtime `! defined( 'WP_MCP_AI_PATH' )` block
 *    (financial-init deviation 5 precedent).
 * 5. New standalone-only tool wiring (financial-init deviation 6
 *    precedent): a `wp_mcp_ai_pro_tools` filter plus
 *    `wp_mcp_ai_pro_register_site_creator_ecosystem_tools()` — both start
 *    with empty maps and fill as the site-creator tool batch lands.
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

	/**
	 * Check if Site Creator Toolkit should be loaded.
	 *
	 * Conditions:
	 * 1. Must be enabled in settings
	 * 2. Must not be in base version mode (unless Pro addon is active)
	 */
	if ( is_admin() ) {
		// Check settings.
		$nvoos_content_graph_pro_settings   = get_option( 'wp_mcp_ai_settings', array() );
		$nvoos_content_graph_pro_is_enabled = ! empty( $nvoos_content_graph_pro_settings['enable_site_creator_toolkit'] );
		$nvoos_content_graph_pro_is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

		// Load if enabled and not in base version mode.
		if ( $nvoos_content_graph_pro_is_enabled && ! $nvoos_content_graph_pro_is_base ) {

			// Load Site Creator admin pages (file-gated).
			$nvoos_content_graph_pro_site_settings = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-site-creator-toolkit-settings-page.php';
			if ( file_exists( $nvoos_content_graph_pro_site_settings ) ) {
				require_once $nvoos_content_graph_pro_site_settings;
			}

			// Load Site Creator CPTs.
			require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-site-template-cpt.php';
		}
	}

	/**
	 * Initialize site creator admin interface.
	 *
	 * @since 1.2.0
	 */
	function wp_mcp_ai_init_site_creator_admin() {
		// Skip if not in admin.
		if ( ! is_admin() ) {
			return;
		}

		// Check if site creator toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator_toolkit'] ) ) {
			return;
		}

		// Initialize CPTs if classes exist.
		if ( class_exists( 'WP_MCP_AI_Site_Template_CPT' ) ) {
			new WP_MCP_AI_Site_Template_CPT();
		}
	}
	add_action( 'admin_init', 'wp_mcp_ai_init_site_creator_admin' );

	/**
	 * Enqueue site creator toolkit admin styles.
	 *
	 * @since 1.2.0
	 *
	 * @param string $hook Current admin page hook (unused).
	 */
	function wp_mcp_ai_enqueue_site_creator_toolkit_admin_styles( $hook ) {
		// Only load if toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator_toolkit'] ) ) {
			return;
		}

		// Enqueue admin styles if available.
		$css_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'assets/css/admin-site-creator-toolkit.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'wp-mcp-ai-site-creator-toolkit-admin',
				NVOOS_CONTENT_GRAPH_PRO_URL . 'assets/css/admin-site-creator-toolkit.css',
				array(),
				NVOOS_CONTENT_GRAPH_PRO_VERSION
			);
		}
	}
	add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_site_creator_toolkit_admin_styles' );

	/**
	 * Standalone-only tool filter — carries the ported site-creator tool
	 * subset (inert standalone, consumed by the base plugin monolith). The
	 * map fills as the site-creator tool batch lands.
	 *
	 * @param array $tools Existing tool map (class => file).
	 * @return array Extended tool map.
	 */
	function wp_mcp_ai_pro_register_site_creator_tools( $tools ) {
		$nvoos_content_graph_pro_site_tools = array();

		return array_merge( $tools, $nvoos_content_graph_pro_site_tools );
	}

	/**
	 * Standalone-only ecosystem registration — registers the ported
	 * site-creator tools into the ecosystem graph ToolRegistry and the
	 * nvoos/core registry via `WP_MCP_AI_Pro_Tool_Adapter` (same wiring as
	 * the image-production/video inits). The list fills as the site-creator
	 * tool batch lands.
	 *
	 * @return void
	 */
	function wp_mcp_ai_pro_register_site_creator_ecosystem_tools() {
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
	add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_site_creator_tools', 10 );

	if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_site_creator_ecosystem_tools();
	}
} // End monolith guard (deviation 4).
