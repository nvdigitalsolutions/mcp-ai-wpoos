<?php
/**
 * Shopify Sync Connection Resolver Trait.
 *
 * Provides shared connection resolution logic for all Shopify Sync tools.
 * Extends the existing WP_MCP_AI_Shopify_Connection_Resolver trait with
 * sync-specific dependency checks and CCT manager instantiation.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait WP_MCP_AI_Shopify_Sync_Connection_Resolver
 *
 * Provides CCT manager access and sync-specific dependency checks.
 * Must be used alongside WP_MCP_AI_Shopify_Connection_Resolver
 * which provides auto-connection resolution.
 *
 * @since 1.3.0
 */
trait WP_MCP_AI_Shopify_Sync_Connection_Resolver {

	/**
	 * Get a Shopify Sync CCT manager instance.
	 *
	 * @since 1.3.0
	 *
	 * @param string $connection_id Remote Sites connection ID.
	 * @return WP_MCP_AI_Shopify_Sync_CCT_Manager
	 */
	protected function get_shopify_sync_cct_manager( $connection_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Shopify_Sync_CCT_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-sync-cct-manager.php';
		}

		return new WP_MCP_AI_Shopify_Sync_CCT_Manager( $connection_id );
	}

	/**
	 * Get a Shopify sync engine instance.
	 *
	 * @since 1.3.0
	 *
	 * @param string $connection_id Remote Sites connection ID.
	 * @return WP_MCP_AI_Shopify_Sync_Engine
	 */
	protected function get_shopify_sync_engine( $connection_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Shopify_Sync_Engine' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-sync-engine.php';
		}

		return new WP_MCP_AI_Shopify_Sync_Engine( $connection_id );
	}

	/**
	 * Check if the given connection ID is configured for sync.
	 *
	 * @since 1.3.0
	 *
	 * @param string $connection_id Remote Sites connection ID.
	 * @return bool True if sync is enabled for this connection.
	 */
	protected function is_shopify_sync_configured( $connection_id ) {
		$settings         = get_option( 'wp_mcp_ai_shopify_sync_toolkit_settings', array() );
		$sync_connections = isset( $settings['sync_connections'] ) ? $settings['sync_connections'] : array();

		return is_array( $sync_connections ) && in_array( $connection_id, $sync_connections, true );
	}

	/**
	 * Check if all required dependencies are active for Shopify Sync.
	 *
	 * @since 1.3.0
	 *
	 * @param string $connection_id Remote Sites connection ID.
	 * @return true|WP_Error True if ok, WP_Error if a dependency is missing.
	 */
	protected function check_shopify_sync_dependencies( $connection_id ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error(
				'wp_mcp_ai_shopify_sync_no_woocommerce',
				__( 'WooCommerce is required for Shopify Sync.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Shopify_Client' ) ) {
			return new WP_Error(
				'wp_mcp_ai_shopify_sync_no_client',
				__( 'Shopify API Client is not available. Please ensure NV oOS Pro is properly installed.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! $this->is_shopify_sync_configured( $connection_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_shopify_sync_not_configured',
				__( 'This Shopify connection is not configured for sync. Enable it in Shopify Sync Toolkit settings.', 'mcp-ai-wpoos-pro' )
			);
		}

		return true;
	}

	/**
	 * Escape a CCT item for safe output (Gate 2 — output escaping).
	 *
	 * Used by all sync tools to ensure consistent output escaping.
	 *
	 * @since 1.3.0
	 *
	 * @param array $item Raw CCT item.
	 * @return array Escaped item.
	 */
	protected function escape_sync_item( $item ) {
		$escaped     = array();
		$text_fields = array(
			'shopify_product_id',
			'shopify_variant_id',
			'inventory_item_id',
			'sku',
			'product_title',
			'variant_title',
			'product_type',
			'vendor',
			'tags',
			'status',
			'location_id',
			'location_name',
			'image_url',
			'handle',
			'sync_status',
			'shopify_updated_at',
			'last_synced_at',
		);

		foreach ( $text_fields as $field ) {
			$escaped[ $field ] = isset( $item[ $field ] ) ? esc_html( $item[ $field ] ) : '';
		}

		$escaped['available_qty']    = isset( $item['available_qty'] ) ? absint( $item['available_qty'] ) : 0;
		$escaped['on_hand_qty']      = isset( $item['on_hand_qty'] ) ? absint( $item['on_hand_qty'] ) : 0;
		$escaped['incoming_qty']     = isset( $item['incoming_qty'] ) ? absint( $item['incoming_qty'] ) : 0;
		$escaped['reserved_qty']     = isset( $item['reserved_qty'] ) ? absint( $item['reserved_qty'] ) : 0;
		$escaped['price']            = isset( $item['price'] ) ? floatval( $item['price'] ) : 0.0;
		$escaped['compare_at_price'] = isset( $item['compare_at_price'] ) ? floatval( $item['compare_at_price'] ) : 0.0;
		$escaped['woo_product_id']   = isset( $item['woo_product_id'] ) ? absint( $item['woo_product_id'] ) : 0;
		$escaped['woo_variation_id'] = isset( $item['woo_variation_id'] ) ? absint( $item['woo_variation_id'] ) : 0;
		$escaped['image_url']        = isset( $item['image_url'] ) ? esc_url( $item['image_url'] ) : '';

		return $escaped;
	}
}
