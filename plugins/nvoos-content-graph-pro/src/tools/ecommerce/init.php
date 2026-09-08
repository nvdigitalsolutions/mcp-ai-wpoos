<?php
/**
 * E-commerce Toolkit Initialization (ecosystem port — Wave F2, e-commerce data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/ecommerce/init.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `WP_MCP_AI_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; path/URL/version constants resolve from
 * `NVOOS_CONTENT_GRAPH_PRO_*`. The init's four admin-page requires are
 * file-gated — the pages land with the F2 admin slice (wave-proof).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load shared Sync Log Manager (provides persistent audit trail for all toolkits).
if ( ! class_exists( 'WP_MCP_AI_Sync_Log_Manager' ) ) {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-sync-log-manager.php';
}
WP_MCP_AI_Sync_Log_Manager::init();

// Shared enablement helper, kept in its own side-effect-free file so callers
// can check the opt-in setting without booting the full toolkit.
if ( ! function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) ) {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-ecommerce-helpers.php';
}

// Load E-commerce admin pages when in admin area.
if ( is_admin() ) {
	// Check if e-commerce toolkit is enabled and not in base version (unless Pro addon is active).
	$nvoos_content_graph_pro_is_enabled    = wp_mcp_ai_is_ecommerce_toolkit_enabled();
	$nvoos_content_graph_pro_is_base       = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
	$nvoos_content_graph_pro_is_pro_active = defined( 'NVOOS_CONTENT_GRAPH_PRO_VERSION' );
	$nvoos_content_graph_pro_has_wc        = class_exists( 'WooCommerce' );

	if ( $nvoos_content_graph_pro_is_enabled && ( ! $nvoos_content_graph_pro_is_base || $nvoos_content_graph_pro_is_pro_active ) && $nvoos_content_graph_pro_has_wc ) {
		// Deviation: file-gated requires — the four e-commerce admin pages
		// land with the F2 admin slice; each require degrades until the file
		// exists (same wave-proof pattern as the CRM init).

		// Load E-commerce Toolkit Settings page.
		$nvoos_content_graph_pro_eco_settings = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-ecommerce-settings-page.php';
		if ( ! class_exists( 'WP_MCP_AI_Ecommerce_Settings_Page' ) && file_exists( $nvoos_content_graph_pro_eco_settings ) ) {
			require_once $nvoos_content_graph_pro_eco_settings;
			new WP_MCP_AI_Ecommerce_Settings_Page();
		}

		// Load Product Research & Add page for WooCommerce integration.
		$nvoos_content_graph_pro_product_research = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-product-research-page.php';
		if ( ! class_exists( 'WP_MCP_AI_Product_Research_Page' ) && file_exists( $nvoos_content_graph_pro_product_research ) ) {
			require_once $nvoos_content_graph_pro_product_research;
			WP_MCP_AI_Product_Research_Page::init();
		}

		// Load Product Consolidate & Add page for data import/validation.
		$nvoos_content_graph_pro_product_consolidate = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-product-consolidate-page.php';
		if ( ! class_exists( 'WP_MCP_AI_Product_Consolidate_Page' ) && file_exists( $nvoos_content_graph_pro_product_consolidate ) ) {
			require_once $nvoos_content_graph_pro_product_consolidate;
			WP_MCP_AI_Product_Consolidate_Page::init();
		}

		// Load Product Settings page (tab-based interface).
		$nvoos_content_graph_pro_product_settings = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-product-settings-page.php';
		if ( ! class_exists( 'WP_MCP_AI_Product_Settings_Page' ) && file_exists( $nvoos_content_graph_pro_product_settings ) ) {
			require_once $nvoos_content_graph_pro_product_settings;
			new WP_MCP_AI_Product_Settings_Page();
		}

		// Register tools will be loaded automatically via the tools directory structure.
		// Tools are located in: addons/pro/includes/tools/ecommerce/.
	}
}

/**
 * Enqueue e-commerce toolkit admin styles.
 *
 * @param string $hook Current admin page hook.
 */
function wp_mcp_ai_enqueue_ecommerce_toolkit_admin_styles( $hook ) {
	// Only load if toolkit is enabled.
	if ( ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
		return;
	}

	// Check if we're on a relevant admin page.
	$nvoos_content_graph_pro_screen = get_current_screen();
	if ( ! $nvoos_content_graph_pro_screen || ! in_array( $nvoos_content_graph_pro_screen->id, array( 'product', 'shop_order' ), true ) ) {
		return;
	}

	// Enqueue admin styles if available.
	$nvoos_content_graph_pro_css_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'assets/css/admin-ecommerce-toolkit.css';
	if ( file_exists( $nvoos_content_graph_pro_css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-ecommerce-toolkit-admin',
			NVOOS_CONTENT_GRAPH_PRO_URL . 'assets/css/admin-ecommerce-toolkit.css',
			array(),
			NVOOS_CONTENT_GRAPH_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_ecommerce_toolkit_admin_styles' );

// --- Performance optimization (inventory autoload, temp file cleanup, post-meta prune) ---
// Must load even when not in admin so its daily cron runs.
$nvoos_content_graph_pro_is_base       = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
$nvoos_content_graph_pro_is_pro_active = defined( 'NVOOS_CONTENT_GRAPH_PRO_VERSION' );
if ( wp_mcp_ai_is_ecommerce_toolkit_enabled() && ( ! $nvoos_content_graph_pro_is_base || $nvoos_content_graph_pro_is_pro_active ) ) {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-ecommerce-optimization.php';
	WP_MCP_AI_Ecommerce_Optimization::init();
}

// ---- Standalone-only tool wiring (documented deviation, same pattern as
// the CRM init deviation 5): a `wp_mcp_ai_pro_tools` filter carrying the
// ported e-commerce tool subset (inert standalone — the base plugin
// consumes it monolith) plus the ecosystem registration below. ----
add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_ecommerce_tools', 10 );

if ( ! defined( 'WP_MCP_AI_PATH' ) && function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
	wp_mcp_ai_pro_register_ecommerce_ecosystem_tools();
}

/**
 * Register the ported e-commerce tools with the `wp_mcp_ai_pro_tools`
 * filter (standalone-only wiring — a subset of the monolith's inline
 * `$ecommerce_toolkit_tools` map built inside
 * `wp_mcp_ai_pro_register_tools()`; the base plugin consumes the filter
 * monolith, standalone it is inert — documented).
 *
 * @since 1.0.0
 *
 * @param array $tools Existing tools array.
 * @return array Updated tools array.
 */
function wp_mcp_ai_pro_register_ecommerce_tools( $tools ) {
	$nvoos_content_graph_pro_ecommerce_tools = array(
		'WP_MCP_AI_Tool_Create_Product_Advanced'  => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-create-product-advanced.php',
		'WP_MCP_AI_Tool_Bulk_Update_Products'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-bulk-update-products.php',
		'WP_MCP_AI_Tool_Update_Woo_Product_Price' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-update-woo-product-price.php',
		'WP_MCP_AI_Tool_Update_Woo_Product_Qty'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-update-woo-product-qty.php',
		'WP_MCP_AI_Tool_Import_Products_CSV'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-import-products-csv.php',
		'WP_MCP_AI_Tool_Export_Products_Report'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-export-products-report.php',
		'WP_MCP_AI_Tool_Sync_Product_Inventory'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-sync-product-inventory.php',
	);

	return array_merge( $tools, $nvoos_content_graph_pro_ecommerce_tools );
}

/**
 * Register the ported e-commerce tools with the ecosystem registries
 * (standalone only — same wiring as the CRM init deviation 5).
 *
 * @since 1.0.0
 * @return void
 */
function wp_mcp_ai_pro_register_ecommerce_ecosystem_tools() {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-tool-adapter.php';
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-create-product-advanced.php';

	$nvoos_content_graph_pro_parent_registry = nvoos_content_graph_get_tool_registry();
	if ( ! $nvoos_content_graph_pro_parent_registry instanceof \NvoosContentGraph\ToolRegistry ) {
		return;
	}

	foreach (
		array(
			'WP_MCP_AI_Tool_Create_Product_Advanced',
			'WP_MCP_AI_Tool_Bulk_Update_Products',
			'WP_MCP_AI_Tool_Update_Woo_Product_Price',
			'WP_MCP_AI_Tool_Update_Woo_Product_Qty',
			'WP_MCP_AI_Tool_Import_Products_CSV',
			'WP_MCP_AI_Tool_Export_Products_Report',
			'WP_MCP_AI_Tool_Sync_Product_Inventory',
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
