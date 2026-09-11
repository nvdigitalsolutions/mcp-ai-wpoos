<?php
/**
 * Multi-language Content Toolkit Initialization (ecosystem port — Wave F2,
 * multilingual toolkit).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/multilingual/init.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical.
 *
 * Documented deviations:
 *
 * 1. `declare(strict_types=1)` added.
 * 2. File paths resolve from `NVOOS_CONTENT_GRAPH_PRO_*`.
 * 3. Slimmed wiring — the admin settings page require is file-gated (it
 *    lands with the multilingual admin slice).
 * 4. Monolith guard — this init declares the global
 *    `wp_mcp_ai_enqueue_multilingual_toolkit_admin_styles()` helper that the
 *    base init also declares; the collision is a compile-time fatal, so the
 *    ENTIRE body is wrapped in a runtime `! defined( 'WP_MCP_AI_PATH' )`
 *    block (financial-init deviation 5 precedent).
 * 5. New standalone-only tool wiring (financial-init deviation 6
 *    precedent): a `wp_mcp_ai_pro_tools` filter carrying the ported
 *    multilingual tool subset (the base main file registers these inline
 *    behind the `enable_multilingual_toolkit` gate) plus
 *    `wp_mcp_ai_pro_register_multilingual_ecosystem_tools()` registering
 *    them into the ecosystem graph ToolRegistry and the nvoos/core registry
 *    via `WP_MCP_AI_Pro_Tool_Adapter`. The tree-only
 *    import-multilingual-blueprint tool (the base registers it nowhere) is
 *    carried here too (CRM CC-extras precedent).
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

	// Check if Multi-language toolkit is enabled.
	$nvoos_content_graph_pro_settings      = get_option( 'wp_mcp_ai_settings', array() );
	$nvoos_content_graph_pro_is_enabled    = ! empty( $nvoos_content_graph_pro_settings['enable_multilingual_toolkit'] );
	$nvoos_content_graph_pro_is_base       = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
	$nvoos_content_graph_pro_is_pro_active = defined( 'WP_MCP_AI_PRO_VERSION' );

	// Only load if enabled and (not in base version or the Pro addon is active).
	if ( $nvoos_content_graph_pro_is_enabled && ( ! $nvoos_content_graph_pro_is_base || $nvoos_content_graph_pro_is_pro_active ) ) {

		// Load Multilingual admin pages (deferred — file-gated until the
		// multilingual admin slice lands).
		if ( is_admin() ) {
			$nvoos_content_graph_pro_multilingual_settings = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-multilingual-settings-page.php';
			if ( file_exists( $nvoos_content_graph_pro_multilingual_settings ) ) {
				require_once $nvoos_content_graph_pro_multilingual_settings;
			}
		}

		// Register tools will be loaded automatically via the tools directory structure.
		// Tools are located in: plugins/nvoos-content-graph-pro/src/tools/multilingual/.
	}

	/**
	 * Enqueue multilingual toolkit admin styles.
	 *
	 * @param string $hook Current admin page hook (unused).
	 */
	function wp_mcp_ai_enqueue_multilingual_toolkit_admin_styles( $hook ) {
		// Only load if toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_multilingual_toolkit'] ) ) {
			return;
		}

		// Enqueue admin styles if available.
		$css_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'assets/css/admin-multilingual-toolkit.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'wp-mcp-ai-multilingual-toolkit-admin',
				NVOOS_CONTENT_GRAPH_PRO_URL . 'assets/css/admin-multilingual-toolkit.css',
				array(),
				NVOOS_CONTENT_GRAPH_PRO_VERSION
			);
		}
	}
	add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_multilingual_toolkit_admin_styles' );

	/**
	 * Standalone-only tool filter — carries the ported multilingual tool
	 * subset (inert standalone, consumed by the base plugin monolith), plus
	 * the tree-only import-blueprint tool.
	 *
	 * @param array $tools Existing tool map (class => file).
	 * @return array Extended tool map.
	 */
	function wp_mcp_ai_pro_register_multilingual_tools( $tools ) {
		$nvoos_content_graph_pro_multilingual_tools = array(
			'WP_MCP_AI_Tool_Auto_Translate_Content'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/multilingual/class-wp-mcp-ai-tool-auto-translate-content.php',
			'WP_MCP_AI_Tool_Translate_WooCommerce_Products' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/multilingual/class-wp-mcp-ai-tool-translate-woocommerce-products.php',
			'WP_MCP_AI_Tool_Translation_Memory_Search'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/multilingual/class-wp-mcp-ai-tool-translation-memory-search.php',
			'WP_MCP_AI_Tool_Export_Import_Translations'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/multilingual/class-wp-mcp-ai-tool-export-import-translations.php',
			'WP_MCP_AI_Tool_Detect_Content_Language'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/multilingual/class-wp-mcp-ai-tool-detect-content-language.php',
			'WP_MCP_AI_Tool_Localize_Dates_Currencies'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/multilingual/class-wp-mcp-ai-tool-localize-dates-currencies.php',
			'WP_MCP_AI_Tool_RTL_Content_Optimization'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/multilingual/class-wp-mcp-ai-tool-rtl-content-optimization.php',
			'WP_MCP_AI_Tool_Translation_Quality_Check'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/multilingual/class-wp-mcp-ai-tool-translation-quality-check.php',
			'WP_MCP_AI_Tool_Find_Untranslated_Strings'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/multilingual/class-wp-mcp-ai-tool-find-untranslated-strings.php',
			'WP_MCP_AI_Tool_Multilingual_SEO_Audit'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/multilingual/class-wp-mcp-ai-tool-multilingual-seo-audit.php',
			// Tree-only tool (the base registers it nowhere — the standalone
			// filter/ecosystem additions are its registration, CRM CC-extras
			// precedent).
			'WP_MCP_AI_Tool_Import_Multilingual_Blueprint' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/multilingual/examples/class-wp-mcp-ai-tool-import-multilingual-blueprint.php',
		);

		return array_merge( $tools, $nvoos_content_graph_pro_multilingual_tools );
	}

	/**
	 * Standalone-only ecosystem registration — registers the ported
	 * multilingual tools into the ecosystem graph ToolRegistry and the
	 * nvoos/core registry via `WP_MCP_AI_Pro_Tool_Adapter` (same wiring as
	 * the analytics/video inits).
	 *
	 * @return void
	 */
	function wp_mcp_ai_pro_register_multilingual_ecosystem_tools() {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-tool-adapter.php';
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/multilingual/class-wp-mcp-ai-tool-detect-content-language.php';

		$nvoos_content_graph_pro_parent_registry = nvoos_content_graph_get_tool_registry();
		if ( ! $nvoos_content_graph_pro_parent_registry instanceof \NvoosContentGraph\ToolRegistry ) {
			return;
		}

		foreach (
			array(
				'WP_MCP_AI_Tool_Auto_Translate_Content',
				'WP_MCP_AI_Tool_Translate_WooCommerce_Products',
				'WP_MCP_AI_Tool_Translation_Memory_Search',
				'WP_MCP_AI_Tool_Export_Import_Translations',
				'WP_MCP_AI_Tool_Detect_Content_Language',
				'WP_MCP_AI_Tool_Localize_Dates_Currencies',
				'WP_MCP_AI_Tool_RTL_Content_Optimization',
				'WP_MCP_AI_Tool_Translation_Quality_Check',
				'WP_MCP_AI_Tool_Find_Untranslated_Strings',
				'WP_MCP_AI_Tool_Multilingual_SEO_Audit',
				'WP_MCP_AI_Tool_Import_Multilingual_Blueprint',
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
	add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_multilingual_tools', 10 );

	if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_multilingual_ecosystem_tools();
	}
} // End monolith guard (deviation 4).
