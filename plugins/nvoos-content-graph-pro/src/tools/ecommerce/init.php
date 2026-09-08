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
		// landed with the F2 admin slice; each require stays file-gated so a
		// partial checkout degrades gracefully (same wave-proof pattern as
		// the CRM init).

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
 * Byte-identical WooCommerce-tools enablement gate (deviation: the
 * monolith defines this inline inside `mcp-ai-wpoos-pro.php`; standalone it
 * lives here — enabled by default unless `enable_woocommerce_tools` is
 * explicitly falsy).
 *
 * @since 1.0.0
 *
 * @param array|null $settings Optional settings array.
 * @return bool Whether the WooCommerce tools are enabled.
 */
if ( ! function_exists( 'wp_mcp_ai_pro_is_woocommerce_tools_enabled' ) ) {
	function wp_mcp_ai_pro_is_woocommerce_tools_enabled( $settings = null ) {
		if ( null === $settings ) {
			$settings = get_option( 'wp_mcp_ai_settings', array() );
		}

		return isset( $settings['enable_woocommerce_tools'] ) ? (bool) $settings['enable_woocommerce_tools'] : true;
	}
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
		'WP_MCP_AI_Tool_Create_Product_Advanced'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-create-product-advanced.php',
		'WP_MCP_AI_Tool_Bulk_Update_Products'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-bulk-update-products.php',
		'WP_MCP_AI_Tool_Update_Woo_Product_Price'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-update-woo-product-price.php',
		'WP_MCP_AI_Tool_Update_Woo_Product_Qty'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-update-woo-product-qty.php',
		'WP_MCP_AI_Tool_Import_Products_CSV'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-import-products-csv.php',
		'WP_MCP_AI_Tool_Export_Products_Report'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-export-products-report.php',
		'WP_MCP_AI_Tool_Sync_Product_Inventory'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-sync-product-inventory.php',
		'WP_MCP_AI_Tool_Bulk_Order_Status_Update'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-bulk-order-status-update.php',
		'WP_MCP_AI_Tool_Get_Order_Analytics'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-get-order-analytics.php',
		'WP_MCP_AI_Tool_Process_Order_Workflow'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-process-order-workflow.php',
		'WP_MCP_AI_Tool_Refund_Order_Advanced'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-refund-order-advanced.php',
		'WP_MCP_AI_Tool_Segment_Customers'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-segment-customers.php',
		'WP_MCP_AI_Tool_Customer_Lifetime_Value'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-customer-lifetime-value.php',
		'WP_MCP_AI_Tool_Export_Customer_Data'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-export-customer-data.php',
		'WP_MCP_AI_Tool_Track_Inventory_Movement'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-track-inventory-movement.php',
		'WP_MCP_AI_Tool_Low_Stock_Alert_Automation'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-low-stock-alert-automation.php',
		'WP_MCP_AI_Tool_Inventory_Forecast'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-inventory-forecast.php',
		'WP_MCP_AI_Tool_Create_Discount_Campaign'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-create-discount-campaign.php',
		'WP_MCP_AI_Tool_Abandoned_Cart_Recovery'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-abandoned-cart-recovery.php',
		'WP_MCP_AI_Tool_Get_Abandoned_Carts'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-get-abandoned-carts.php',
		'WP_MCP_AI_Tool_Send_Cart_Recovery_Email'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-send-cart-recovery-email.php',
		'WP_MCP_AI_Tool_Upsell_Recommendations'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-upsell-recommendations.php',
		'WP_MCP_AI_Tool_Sales_Performance_Dashboard'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-sales-performance-dashboard.php',
		'WP_MCP_AI_Tool_Shipping_Box_Packer'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-shipping-box-packer.php',
		'WP_MCP_AI_Tool_Shipping_Rate_Estimator'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-shipping-rate-estimator.php',
		'WP_MCP_AI_Tool_Generate_WooCommerce_Order_Invoice_PDF' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-tool-generate-woocommerce-order-invoice-pdf.php',
		'WP_MCP_AI_Pro_Tool_Woo_Products'               => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-pro-tool-woo-products.php',
		'WP_MCP_AI_Pro_Tool_Woo_Orders'                 => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-pro-tool-woo-orders.php',
		'WP_MCP_AI_Pro_Tool_Woo_Customers'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-pro-tool-woo-customers.php',
		'WP_MCP_AI_Pro_Tool_Woo_Coupons'                => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-pro-tool-woo-coupons.php',
		'WP_MCP_AI_Pro_Tool_Shopify_Products'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-pro-tool-shopify-products.php',
		'WP_MCP_AI_Pro_Tool_Shopify_Orders'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-pro-tool-shopify-orders.php',
		'WP_MCP_AI_Pro_Tool_Shopify_Customers'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-pro-tool-shopify-customers.php',
		'WP_MCP_AI_Pro_Tool_Shopify_Inventory'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-pro-tool-shopify-inventory.php',
		'WP_MCP_AI_Pro_Tool_Shopify_Catalog'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-pro-tool-shopify-catalog.php',
		'WP_MCP_AI_Pro_Tool_Printful'                   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-pro-tool-printful.php',
		'WP_MCP_AI_Pro_Tool_Product_Actualization'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-pro-tool-product-actualization.php',
		'WP_MCP_AI_Pro_Tool_Validate_Image_For_Product' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-product.php',
		'WP_MCP_AI_Pro_Tool_Validate_Image_For_Vehicle' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-pro-tool-validate-image-for-vehicle.php',
		'WP_MCP_AI_Pro_Tool_Lookup_Product_Price'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-pro-tool-lookup-product-price.php',
		'WP_MCP_AI_Pro_Tool_Get_QuickBooks_Report'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-pro-tool-get-quickbooks-report.php',
		'WP_MCP_AI_Pro_Tool_QuickBooks_Desktop_Sync'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-pro-tool-quickbooks-desktop-sync.php',
		'WP_MCP_AI_Pro_Tool_Get_Import_Duty'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-pro-tool-get-import-duty.php',
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
			'WP_MCP_AI_Tool_Bulk_Order_Status_Update',
			'WP_MCP_AI_Tool_Get_Order_Analytics',
			'WP_MCP_AI_Tool_Process_Order_Workflow',
			'WP_MCP_AI_Tool_Refund_Order_Advanced',
			'WP_MCP_AI_Tool_Segment_Customers',
			'WP_MCP_AI_Tool_Customer_Lifetime_Value',
			'WP_MCP_AI_Tool_Export_Customer_Data',
			'WP_MCP_AI_Tool_Track_Inventory_Movement',
			'WP_MCP_AI_Tool_Low_Stock_Alert_Automation',
			'WP_MCP_AI_Tool_Inventory_Forecast',
			'WP_MCP_AI_Tool_Create_Discount_Campaign',
			'WP_MCP_AI_Tool_Abandoned_Cart_Recovery',
			'WP_MCP_AI_Tool_Get_Abandoned_Carts',
			'WP_MCP_AI_Tool_Send_Cart_Recovery_Email',
			'WP_MCP_AI_Tool_Upsell_Recommendations',
			'WP_MCP_AI_Tool_Sales_Performance_Dashboard',
			'WP_MCP_AI_Tool_Shipping_Box_Packer',
			'WP_MCP_AI_Tool_Shipping_Rate_Estimator',
			'WP_MCP_AI_Tool_Generate_WooCommerce_Order_Invoice_PDF',
			'WP_MCP_AI_Pro_Tool_Woo_Products',
			'WP_MCP_AI_Pro_Tool_Woo_Orders',
			'WP_MCP_AI_Pro_Tool_Woo_Customers',
			'WP_MCP_AI_Pro_Tool_Woo_Coupons',
			'WP_MCP_AI_Pro_Tool_Shopify_Products',
			'WP_MCP_AI_Pro_Tool_Shopify_Orders',
			'WP_MCP_AI_Pro_Tool_Shopify_Customers',
			'WP_MCP_AI_Pro_Tool_Shopify_Inventory',
			'WP_MCP_AI_Pro_Tool_Shopify_Catalog',
			'WP_MCP_AI_Pro_Tool_Printful',
			'WP_MCP_AI_Pro_Tool_Product_Actualization',
			'WP_MCP_AI_Pro_Tool_Validate_Image_For_Product',
			'WP_MCP_AI_Pro_Tool_Validate_Image_For_Vehicle',
			'WP_MCP_AI_Pro_Tool_Lookup_Product_Price',
			'WP_MCP_AI_Pro_Tool_Get_QuickBooks_Report',
			'WP_MCP_AI_Pro_Tool_QuickBooks_Desktop_Sync',
			'WP_MCP_AI_Pro_Tool_Get_Import_Duty',
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
