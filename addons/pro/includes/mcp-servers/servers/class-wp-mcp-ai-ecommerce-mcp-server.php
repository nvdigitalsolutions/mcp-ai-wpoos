<?php
/**
 * E-commerce Toolkit MCP Server
 *
 * Phase 2 Tier-1 promotion. See docs/ADR_002_toolkit_mcp_servers.md.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

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
		return __( 'E-commerce', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'WooCommerce-backed product, order, and customer workflows. Owns Product research and Product consolidation surfaces.',
			'mcp-ai-wpoos-pro'
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
				'label'              => __( 'Research & Add Products', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'type'               => 'consolidate_add',
				'page_slug'          => 'product-consolidate',
				'entity_type'        => 'product',
				'class_ref'          => 'WP_MCP_AI_Product_Consolidate_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Consolidate Products', 'mcp-ai-wpoos-pro' ),
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
