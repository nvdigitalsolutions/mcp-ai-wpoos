<?php
/**
 * Sync Product Inventory Tool
 *
 * Sync inventory across multiple locations/warehouses with bulk sync,
 * individual product updates, and stock level reconciliation.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for syncing product inventory across locations.
 *
 * Supports:
 * - Multi-location inventory sync
 * - Bulk synchronization
 * - Individual product updates
 * - Stock level reconciliation
 * - Inventory audit trail
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Sync_Product_Inventory implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if WooCommerce is active and toolkit is enabled.
	 */
	public static function is_available() {
		// Check if WooCommerce is active.
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		// Check if base version.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		// Check if e-commerce toolkit is enabled.
		return function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && wp_mcp_ai_is_ecommerce_toolkit_enabled();
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return __( 'Inventory sync requires WooCommerce to be installed and activated.', 'mcp-ai-wpoos-pro' );
		}

		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
			return __( 'E-commerce toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Inventory sync tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'sync_product_inventory';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Sync Product Inventory', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Synchronize product inventory across multiple locations or warehouses. Supports bulk sync operations, individual product updates, stock level reconciliation, and maintains inventory audit trail for compliance.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'sync_type'           => array(
					'type'        => 'string',
					'description' => __( 'Type of synchronization', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'single', 'bulk', 'reconcile' ),
					'default'     => 'single',
				),
				'product_id'          => array(
					'type'        => 'integer',
					'description' => __( 'Product ID for single sync', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'product_ids'         => array(
					'type'        => 'array',
					'description' => __( 'Product IDs for bulk sync', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'integer' ),
				),
				'inventory_data'      => array(
					'type'        => 'array',
					'description' => __( 'Inventory data to sync', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'product_id' => array(
								'type'        => 'integer',
								'description' => __( 'Product ID', 'mcp-ai-wpoos-pro' ),
							),
							'location'   => array(
								'type'        => 'string',
								'description' => __( 'Warehouse/location identifier', 'mcp-ai-wpoos-pro' ),
							),
							'quantity'   => array(
								'type'        => 'integer',
								'description' => __( 'Stock quantity', 'mcp-ai-wpoos-pro' ),
								'minimum'     => 0,
							),
						),
					),
				),
				'reconcile_method'    => array(
					'type'        => 'string',
					'description' => __( 'Reconciliation method', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'sum', 'max', 'average', 'override' ),
					'default'     => 'sum',
				),
				'update_stock_status' => array(
					'type'        => 'boolean',
					'description' => __( 'Update stock status based on quantity', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'log_changes'         => array(
					'type'        => 'boolean',
					'description' => __( 'Log inventory changes for audit', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-read',
			'database-write',
			'requires-plugin',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check permissions.
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_woocommerce' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to sync inventory.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if WooCommerce is active.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'woocommerce_not_active',
				self::get_unavailable_reason()
			);
		}

		$sync_type = isset( $arguments['sync_type'] ) ? sanitize_text_field( $arguments['sync_type'] ) : 'single';

		switch ( $sync_type ) {
			case 'single':
				return $this->sync_single_product( $arguments );
			case 'bulk':
				return $this->sync_bulk_products( $arguments );
			case 'reconcile':
				return $this->reconcile_inventory( $arguments );
			default:
				return new WP_Error(
					'invalid_sync_type',
					__( 'Invalid sync type specified.', 'mcp-ai-wpoos-pro' )
				);
		}
	}

	/**
	 * Sync single product inventory.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Result.
	 */
	protected function sync_single_product( $arguments ) {
		$product_id = isset( $arguments['product_id'] ) ? absint( $arguments['product_id'] ) : 0;

		if ( ! $product_id ) {
			return new WP_Error(
				'missing_product_id',
				__( 'Product ID is required for single product sync.', 'mcp-ai-wpoos-pro' )
			);
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error(
				'product_not_found',
				__( 'Product not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		$inventory_data      = isset( $arguments['inventory_data'] ) && is_array( $arguments['inventory_data'] ) ? $arguments['inventory_data'] : array();
		$update_stock_status = isset( $arguments['update_stock_status'] ) ? (bool) $arguments['update_stock_status'] : true;
		$log_changes         = isset( $arguments['log_changes'] ) ? (bool) $arguments['log_changes'] : true;

		if ( empty( $inventory_data ) ) {
			return new WP_Error(
				'missing_inventory_data',
				__( 'Inventory data is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$old_stock = $product->get_stock_quantity();
		$new_stock = 0;

		// Calculate total stock from all locations.
		foreach ( $inventory_data as $location_data ) {
			if ( isset( $location_data['product_id'] ) && absint( $location_data['product_id'] ) === $product_id ) {
				$new_stock += isset( $location_data['quantity'] ) ? absint( $location_data['quantity'] ) : 0;
			}
		}

		// Update product stock.
		$product->set_stock_quantity( $new_stock );
		$product->set_manage_stock( true );

		if ( $update_stock_status ) {
			$product->set_stock_status( $new_stock > 0 ? 'instock' : 'outofstock' );
		}

		$product->save();

		// Log changes.
		if ( $log_changes ) {
			$this->log_inventory_change( $product_id, $old_stock, $new_stock, 'sync_single', $inventory_data );
		}

		return array(
			'success'    => true,
			'sync_type'  => 'single',
			'product_id' => $product_id,
			'old_stock'  => $old_stock,
			'new_stock'  => $new_stock,
			'locations'  => count( $inventory_data ),
			'message'    => sprintf(
				/* translators: 1: Product ID, 2: Old stock, 3: New stock */
				__( 'Product #%1$d inventory synced: %2$d → %3$d.', 'mcp-ai-wpoos-pro' ),
				$product_id,
				$old_stock,
				$new_stock
			),
		);
	}

	/**
	 * Sync bulk products inventory.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Result.
	 */
	protected function sync_bulk_products( $arguments ) {
		$product_ids    = isset( $arguments['product_ids'] ) && is_array( $arguments['product_ids'] ) ? array_map( 'absint', $arguments['product_ids'] ) : array();
		$inventory_data = isset( $arguments['inventory_data'] ) && is_array( $arguments['inventory_data'] ) ? $arguments['inventory_data'] : array();

		if ( empty( $product_ids ) && empty( $inventory_data ) ) {
			return new WP_Error(
				'missing_data',
				__( 'Product IDs or inventory data is required for bulk sync.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Extract product IDs from inventory data if not provided.
		if ( empty( $product_ids ) ) {
			foreach ( $inventory_data as $location_data ) {
				if ( isset( $location_data['product_id'] ) ) {
					$product_ids[] = absint( $location_data['product_id'] );
				}
			}
			$product_ids = array_unique( $product_ids );
		}

		$synced_count   = 0;
		$failed_count   = 0;
		$synced_details = array();

		foreach ( $product_ids as $product_id ) {
			$result = $this->sync_single_product(
				array_merge(
					$arguments,
					array(
						'product_id' => $product_id,
					)
				)
			);

			if ( is_wp_error( $result ) ) {
				++$failed_count;
			} else {
				++$synced_count;
				$synced_details[] = array(
					'product_id' => $product_id,
					'old_stock'  => $result['old_stock'],
					'new_stock'  => $result['new_stock'],
				);
			}
		}

		return array(
			'success'        => true,
			'sync_type'      => 'bulk',
			'total_products' => count( $product_ids ),
			'synced'         => $synced_count,
			'failed'         => $failed_count,
			'details'        => $synced_details,
			'message'        => sprintf(
				/* translators: 1: Synced count, 2: Total count */
				__( 'Bulk sync completed: %1$d of %2$d products updated.', 'mcp-ai-wpoos-pro' ),
				$synced_count,
				count( $product_ids )
			),
		);
	}

	/**
	 * Reconcile inventory across locations.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Result.
	 */
	protected function reconcile_inventory( $arguments ) {
		$inventory_data   = isset( $arguments['inventory_data'] ) && is_array( $arguments['inventory_data'] ) ? $arguments['inventory_data'] : array();
		$reconcile_method = isset( $arguments['reconcile_method'] ) ? sanitize_text_field( $arguments['reconcile_method'] ) : 'sum';

		if ( empty( $inventory_data ) ) {
			return new WP_Error(
				'missing_inventory_data',
				__( 'Inventory data is required for reconciliation.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Group by product ID.
		$product_locations = array();
		foreach ( $inventory_data as $location_data ) {
			$product_id = isset( $location_data['product_id'] ) ? absint( $location_data['product_id'] ) : 0;
			$location   = isset( $location_data['location'] ) ? sanitize_text_field( $location_data['location'] ) : '';
			$quantity   = isset( $location_data['quantity'] ) ? absint( $location_data['quantity'] ) : 0;

			if ( ! $product_id ) {
				continue;
			}

			if ( ! isset( $product_locations[ $product_id ] ) ) {
				$product_locations[ $product_id ] = array();
			}

			$product_locations[ $product_id ][ $location ] = $quantity;
		}

		$reconciled = array();

		foreach ( $product_locations as $product_id => $locations ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			$old_stock = $product->get_stock_quantity();
			$new_stock = $this->calculate_reconciled_stock( $locations, $reconcile_method );

			// Update product.
			$product->set_stock_quantity( $new_stock );
			$product->set_manage_stock( true );

			if ( isset( $arguments['update_stock_status'] ) && $arguments['update_stock_status'] ) {
				$product->set_stock_status( $new_stock > 0 ? 'instock' : 'outofstock' );
			}

			$product->save();

			// Log changes.
			if ( isset( $arguments['log_changes'] ) && $arguments['log_changes'] ) {
				$this->log_inventory_change( $product_id, $old_stock, $new_stock, 'reconcile', $locations );
			}

			$reconciled[] = array(
				'product_id'       => $product_id,
				'product_name'     => $product->get_name(),
				'old_stock'        => $old_stock,
				'new_stock'        => $new_stock,
				'locations'        => $locations,
				'reconcile_method' => $reconcile_method,
			);
		}

		return array(
			'success'             => true,
			'sync_type'           => 'reconcile',
			'reconcile_method'    => $reconcile_method,
			'products_reconciled' => count( $reconciled ),
			'details'             => $reconciled,
			'message'             => sprintf(
				/* translators: %d: Number of products */
				__( 'Inventory reconciled for %d products.', 'mcp-ai-wpoos-pro' ),
				count( $reconciled )
			),
		);
	}

	/**
	 * Calculate reconciled stock based on method.
	 *
	 * @param array  $locations Locations with quantities.
	 * @param string $method    Reconciliation method.
	 * @return int Reconciled stock quantity.
	 */
	protected function calculate_reconciled_stock( $locations, $method ) {
		$quantities = array_values( $locations );

		switch ( $method ) {
			case 'sum':
				return array_sum( $quantities );

			case 'max':
				return max( $quantities );

			case 'average':
				return (int) round( array_sum( $quantities ) / count( $quantities ) );

			case 'override':
				// Use last location value.
				return end( $quantities );

			default:
				return array_sum( $quantities );
		}
	}

	/**
	 * Log inventory change.
	 *
	 * @param int    $product_id Product ID.
	 * @param int    $old_stock  Old stock quantity.
	 * @param int    $new_stock  New stock quantity.
	 * @param string $action     Action type.
	 * @param array  $details    Additional details.
	 */
	protected function log_inventory_change( $product_id, $old_stock, $new_stock, $action, $details ) {
		$log_entry = array(
			'product_id' => $product_id,
			'old_stock'  => $old_stock,
			'new_stock'  => $new_stock,
			'action'     => $action,
			'details'    => $details,
			'timestamp'  => current_time( 'mysql' ),
			'user_id'    => get_current_user_id(),
		);

		// Store in product meta.
		$existing_log = get_post_meta( $product_id, '_inventory_sync_log', true );
		if ( ! is_array( $existing_log ) ) {
			$existing_log = array();
		}

		$existing_log[] = $log_entry;

		// Keep only last 50 entries.
		if ( count( $existing_log ) > 50 ) {
			$existing_log = array_slice( $existing_log, -50 );
		}

		update_post_meta( $product_id, '_inventory_sync_log', $existing_log );
	}
}
