<?php
/**
 * Toolkit MCP server (ecosystem port — Wave F2, mcp-servers batch A).
 *
 * Ported from the base Pro addon's `addons/pro/includes/mcp-servers/servers/class-wp-mcp-ai-ecommerce-mcp-server.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; no path constants — no path swaps.
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

/**
 * E-commerce MCP server.
 */
class WP_MCP_AI_Ecommerce_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'ecommerce';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'E-commerce', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'WooCommerce-backed product, order, and customer workflows. Owns Product research and Product consolidation surfaces.',
			'nvoos-content-graph-pro'
		);
	}

	/**
	 * Get the ingestion surfaces for this server.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function ingestion_surfaces() {
		return array(
			array(
				'type'               => 'research_add',
				'page_slug'          => 'research-product',
				'entity_type'        => 'product',
				'class_ref'          => 'WP_MCP_AI_Product_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Products', 'nvoos-content-graph-pro' ),
			),
			array(
				'type'               => 'consolidate_add',
				'page_slug'          => 'product-consolidate',
				'entity_type'        => 'product',
				'class_ref'          => 'WP_MCP_AI_Product_Consolidate_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Consolidate Products', 'nvoos-content-graph-pro' ),
			),
		);
	}

	/**
	 * Get the candidate tool slugs for this server.
	 *
	 * @return string[]
	 */
	public function candidate_tool_slugs() {
		/**
		 * Filter the candidate tool slugs the E-commerce MCP server exposes.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_ecommerce_candidate_tools',
			array(
				'create_product_advanced',
				'bulk_update_products',
				'update_woo_product_price',
				'update_woo_product_qty',
				'import_products_csv',
				'export_products_report',
				'sync_product_inventory',
				'track_inventory_movement',
				'inventory_forecast',
				'low_stock_alert_automation',
				'process_order_workflow',
				'bulk_order_status_update',
				'refund_order_advanced',
				'get_order_analytics',
				'sales_performance_dashboard',
				'generate_invoice_pdf',
				'segment_customers',
				'customer_lifetime_value',
				'export_customer_data',
				'abandoned_cart_recovery',
				'upsell_recommendations',
				'create_discount_campaign',
				'shipping_box_packer',
				'shipping_rate_estimator',
			)
		);
	}
}
