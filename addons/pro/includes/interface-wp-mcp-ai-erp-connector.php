<?php
/**
 * ERP Connector Interface
 *
 * Defines the contract for ERP system integrations.
 * Supports inventory sync, product management, and order data exchange.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ERP Connector Interface.
 *
 * All ERP adapters must implement this interface to ensure
 * consistent integration patterns across different ERP systems.
 *
 * @since 1.1.0
 */
interface WP_MCP_AI_ERP_Connector_Interface {

	/**
	 * Connect to the ERP system.
	 *
	 * @param array $config Connection configuration.
	 * @return bool|WP_Error True if connected, WP_Error on failure.
	 */
	public function connect( $config );

	/**
	 * Test the connection to ERP system.
	 *
	 * @return bool|WP_Error True if connected, WP_Error on failure.
	 */
	public function test_connection();

	/**
	 * Get inventory data for a product.
	 *
	 * @param string $sku Product SKU.
	 * @param array  $params Additional parameters.
	 * @return array|WP_Error Inventory data or error.
	 */
	public function get_inventory( $sku, $params = array() );

	/**
	 * Update inventory in ERP system.
	 *
	 * @param string $sku Product SKU.
	 * @param int    $quantity New quantity.
	 * @param array  $params Additional parameters (warehouse, reason, etc.).
	 * @return bool|WP_Error True if updated, WP_Error on failure.
	 */
	public function update_inventory( $sku, $quantity, $params = array() );

	/**
	 * Sync products from ERP to WooCommerce.
	 *
	 * @param array $filter Filter criteria.
	 * @return array|WP_Error Sync results or error.
	 */
	public function sync_products( $filter = array() );

	/**
	 * Get purchase orders from ERP.
	 *
	 * @param array $params Query parameters.
	 * @return array|WP_Error Purchase orders or error.
	 */
	public function get_purchase_orders( $params = array() );

	/**
	 * Get product data from ERP.
	 *
	 * @param string $sku Product SKU.
	 * @return array|WP_Error Product data or error.
	 */
	public function get_product( $sku );

	/**
	 * Get inventory movements/audit trail.
	 *
	 * @param string $sku Product SKU.
	 * @param array  $params Query parameters.
	 * @return array|WP_Error Movement history or error.
	 */
	public function get_inventory_movements( $sku, $params = array() );
}
