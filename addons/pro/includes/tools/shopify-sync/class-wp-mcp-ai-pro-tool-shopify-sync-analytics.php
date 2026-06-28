<?php
/**
 * Shopify Sync Analytics Tool.
 *
 * Enables AI assistants to query aggregated analytics from the Shopify
 * sync CCT cache. Provides inventory summaries, stock velocity insights,
 * product performance metrics, and cross-store comparisons — all computed
 * from CCT data with zero GraphQL API cost.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/tools/shopify-sync/trait-wp-mcp-ai-shopify-sync-connection-resolver.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/trait-wp-mcp-ai-shopify-connection-resolver.php';

/**
 * Shopify Sync Analytics Tool.
 *
 * Compute analytics from CCT data — zero API cost.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_Tool_Shopify_Sync_Analytics implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Shopify_Connection_Resolver;
	use WP_MCP_AI_Shopify_Sync_Connection_Resolver;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'shopify_sync_analytics';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Shopify Sync Analytics', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Compute aggregated analytics from the Shopify sync cache. Inventory summaries, stock velocity, product performance metrics, and cross-store comparisons — all from local CCT data with zero GraphQL API cost.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Remote Sites connection ID. Auto-resolved if omitted.', 'mcp-ai-wpoos-pro' ),
				),
				'action'        => array(
					'type'        => 'string',
					'description' => __( 'Action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'inventory_summary', 'stock_velocity', 'product_performance', 'vendor_breakdown' ),
					'default'     => 'inventory_summary',
				),
				'vendor'        => array(
					'type'        => 'string',
					'description' => __( 'Filter analytics to a specific vendor.', 'mcp-ai-wpoos-pro' ),
				),
				'product_type'  => array(
					'type'        => 'string',
					'description' => __( 'Filter analytics to a specific product type.', 'mcp-ai-wpoos-pro' ),
				),
				'location_id'   => array(
					'type'        => 'string',
					'description' => __( 'Filter analytics to a specific location.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'cache-first',
			'requires-capability',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_woocommerce';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Gate 1: Sanitize.
		$action       = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'inventory_summary';
		$vendor       = isset( $arguments['vendor'] ) ? sanitize_text_field( $arguments['vendor'] ) : '';
		$product_type = isset( $arguments['product_type'] ) ? sanitize_text_field( $arguments['product_type'] ) : '';
		$location_id  = isset( $arguments['location_id'] ) ? sanitize_text_field( $arguments['location_id'] ) : '';

		// Capability.
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_sync_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		// Resolve connection ID.
		$connection_id = $this->resolve_shopify_connection_id( $arguments, $context );
		if ( is_wp_error( $connection_id ) ) {
			return $connection_id;
		}

		// Dependencies.
		$deps = $this->check_shopify_sync_dependencies( $connection_id );
		if ( is_wp_error( $deps ) ) {
			return $deps;
		}

		$cct_manager = $this->get_shopify_sync_cct_manager( $connection_id );

		$filters = array( 'per_page' => 100 );
		if ( ! empty( $vendor ) ) {
			$filters['vendor'] = $vendor;
		}
		if ( ! empty( $product_type ) ) {
			$filters['product_type'] = $product_type;
		}
		if ( ! empty( $location_id ) ) {
			$filters['location_id'] = $location_id;
		}

		switch ( $action ) {
			case 'inventory_summary':
				return $this->handle_inventory_summary( $cct_manager, $filters );

			case 'stock_velocity':
				return $this->handle_stock_velocity( $cct_manager, $filters );

			case 'product_performance':
				return $this->handle_product_performance( $cct_manager, $filters );

			case 'vendor_breakdown':
				return $this->handle_vendor_breakdown( $cct_manager, $filters );

			default:
				return new WP_Error( 'wp_mcp_ai_shopify_sync_invalid_action', __( 'Invalid action.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Handle inventory_summary — high-level inventory overview.
	 *
	 * @param WP_MCP_AI_Shopify_Sync_CCT_Manager $cct_manager CCT manager.
	 * @param array                              $filters     Filter parameters.
	 * @return array
	 */
	protected function handle_inventory_summary( $cct_manager, $filters ) {
		$items         = $cct_manager->get_cached_items( $filters );
		$total_qty     = 0;
		$total_value   = 0.0;
		$locations     = array();
		$vendors       = array();
		$types         = array();
		$low_stock     = 0;
		$out_of_stock  = 0;
		$settings      = get_option( 'wp_mcp_ai_shopify_sync_toolkit_settings', array() );
		$low_threshold = isset( $settings['low_stock_threshold'] ) ? absint( $settings['low_stock_threshold'] ) : 5;

		foreach ( $items as $item ) {
			$qty   = absint( isset( $item['available_qty'] ) ? $item['available_qty'] : 0 );
			$price = floatval( isset( $item['price'] ) ? $item['price'] : 0.0 );

			$total_qty   += $qty;
			$total_value += $qty * $price;

			// Location breakdown.
			$loc = isset( $item['location_name'] ) ? $item['location_name'] : 'Unknown';
			if ( ! isset( $locations[ $loc ] ) ) {
				$locations[ $loc ] = array(
					'items' => 0,
					'value' => 0.0,
				);
			}
			$locations[ $loc ]['items'] += $qty;
			$locations[ $loc ]['value'] += round( $qty * $price, 2 );

			// Vendor breakdown.
			$ven = isset( $item['vendor'] ) ? $item['vendor'] : 'Unknown';
			if ( ! isset( $vendors[ $ven ] ) ) {
				$vendors[ $ven ] = array(
					'items' => 0,
					'value' => 0.0,
				);
			}
			$vendors[ $ven ]['items'] += $qty;
			$vendors[ $ven ]['value'] += round( $qty * $price, 2 );

			// Product type breakdown.
			$typ = isset( $item['product_type'] ) ? $item['product_type'] : 'Unknown';
			if ( ! isset( $types[ $typ ] ) ) {
				$types[ $typ ] = 0;
			}
			++$types[ $typ ];

			// Stock status.
			if ( $qty <= 0 ) {
				++$out_of_stock;
			} elseif ( $qty < $low_threshold ) {
				++$low_stock;
			}
		}

		return array(
			'success' => true,
			'message' => __( 'Inventory summary computed from CCT cache.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'total_products'      => count( $items ),
				'total_quantity'      => $total_qty,
				'total_value'         => round( $total_value, 2 ),
				'in_stock'            => count( $items ) - $low_stock - $out_of_stock,
				'low_stock'           => $low_stock,
				'out_of_stock'        => $out_of_stock,
				'low_stock_threshold' => $low_threshold,
				'locations'           => $locations,
				'top_vendors'         => $this->sort_and_limit( $vendors, 5 ),
				'product_types'       => $types,
				'connection_id'       => $cct_manager->get_connection_id(),
			),
		);
	}

	/**
	 * Handle stock_velocity — identify fast and slow movers.
	 *
	 * @param WP_MCP_AI_Shopify_Sync_CCT_Manager $cct_manager CCT manager.
	 * @param array                              $filters     Filter parameters.
	 * @return array
	 */
	protected function handle_stock_velocity( $cct_manager, $filters ) {
		$items       = $cct_manager->get_cached_items( $filters );
		$fast_movers = array();
		$slow_movers = array();

		$settings      = get_option( 'wp_mcp_ai_shopify_sync_toolkit_settings', array() );
		$low_threshold = isset( $settings['low_stock_threshold'] ) ? absint( $settings['low_stock_threshold'] ) : 5;

		foreach ( $items as $item ) {
			$qty         = absint( isset( $item['available_qty'] ) ? $item['available_qty'] : 0 );
			$price       = floatval( isset( $item['price'] ) ? $item['price'] : 0.0 );
			$product_key = esc_html( isset( $item['product_title'] ) ? $item['product_title'] : '' );

			if ( $qty <= 0 ) {
				// Potentially fast-moving (sold out).
				$fast_movers[] = array(
					'product_title' => $product_key,
					'sku'           => esc_html( isset( $item['sku'] ) ? $item['sku'] : '' ),
					'vendor'        => esc_html( isset( $item['vendor'] ) ? $item['vendor'] : '' ),
					'status'        => 'sold_out',
					'price'         => $price,
				);
			} elseif ( $qty < $low_threshold ) {
				$fast_movers[] = array(
					'product_title' => $product_key,
					'sku'           => esc_html( isset( $item['sku'] ) ? $item['sku'] : '' ),
					'vendor'        => esc_html( isset( $item['vendor'] ) ? $item['vendor'] : '' ),
					'remaining_qty' => $qty,
					'status'        => 'low_stock',
					'price'         => $price,
				);
			} elseif ( $qty > 100 ) {
				$slow_movers[] = array(
					'product_title' => $product_key,
					'sku'           => esc_html( isset( $item['sku'] ) ? $item['sku'] : '' ),
					'vendor'        => esc_html( isset( $item['vendor'] ) ? $item['vendor'] : '' ),
					'available_qty' => $qty,
					'status'        => 'overstocked',
					'price'         => $price,
				);
			}
		}

		return array(
			'success' => true,
			'message' => __( 'Stock velocity analysis from CCT cache.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'fast_movers_count' => count( $fast_movers ),
				'slow_movers_count' => count( $slow_movers ),
				'fast_movers'       => array_slice( $fast_movers, 0, 20 ),
				'slow_movers'       => array_slice( $slow_movers, 0, 20 ),
				'connection_id'     => $cct_manager->get_connection_id(),
			),
		);
	}

	/**
	 * Handle product_performance — top products by various metrics.
	 *
	 * @param WP_MCP_AI_Shopify_Sync_CCT_Manager $cct_manager CCT manager.
	 * @param array                              $filters     Filter parameters.
	 * @return array
	 */
	protected function handle_product_performance( $cct_manager, $filters ) {
		$items = $cct_manager->get_cached_items( $filters );

		// Sort by available quantity descending.
		usort(
			$items,
			function ( $a, $b ) {
				$qa = absint( isset( $a['available_qty'] ) ? $a['available_qty'] : 0 );
				$qb = absint( isset( $b['available_qty'] ) ? $b['available_qty'] : 0 );
				return $qb - $qa;
			}
		);

		$top_by_quantity = array();
		foreach ( array_slice( $items, 0, 20 ) as $item ) {
			$top_by_quantity[] = array(
				'product_title' => esc_html( isset( $item['product_title'] ) ? $item['product_title'] : '' ),
				'sku'           => esc_html( isset( $item['sku'] ) ? $item['sku'] : '' ),
				'vendor'        => esc_html( isset( $item['vendor'] ) ? $item['vendor'] : '' ),
				'available_qty' => absint( isset( $item['available_qty'] ) ? $item['available_qty'] : 0 ),
				'price'         => floatval( isset( $item['price'] ) ? $item['price'] : 0.0 ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Product performance metrics from CCT cache.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'total_products'  => count( $items ),
				'top_by_quantity' => $top_by_quantity,
				'connection_id'   => $cct_manager->get_connection_id(),
			),
		);
	}

	/**
	 * Handle vendor_breakdown — analytics grouped by vendor.
	 *
	 * @param WP_MCP_AI_Shopify_Sync_CCT_Manager $cct_manager CCT manager.
	 * @param array                              $filters     Filter parameters.
	 * @return array
	 */
	protected function handle_vendor_breakdown( $cct_manager, $filters ) {
		$items   = $cct_manager->get_cached_items( $filters );
		$vendors = array();

		foreach ( $items as $item ) {
			$ven = isset( $item['vendor'] ) ? $item['vendor'] : 'Unknown';
			if ( empty( $ven ) ) {
				$ven = 'Unknown';
			}

			if ( ! isset( $vendors[ $ven ] ) ) {
				$vendors[ $ven ] = array(
					'products'          => 0,
					'quantity'          => 0,
					'value'             => 0.0,
					'distinct_products' => array(),
				);
			}

			$qty   = absint( isset( $item['available_qty'] ) ? $item['available_qty'] : 0 );
			$price = floatval( isset( $item['price'] ) ? $item['price'] : 0.0 );

			++$vendors[ $ven ]['products'];
			$vendors[ $ven ]['quantity'] += $qty;
			$vendors[ $ven ]['value']    += round( $qty * $price, 2 );

			$product_title = isset( $item['product_title'] ) ? $item['product_title'] : '';
			if ( ! empty( $product_title ) && ! in_array( $product_title, $vendors[ $ven ]['distinct_products'], true ) ) {
				$vendors[ $ven ]['distinct_products'][] = $product_title;
			}
		}

		// Format for output.
		$formatted = array();
		foreach ( $vendors as $name => $data ) {
			$formatted[] = array(
				'vendor'            => esc_html( $name ),
				'products'          => $data['products'],
				'total_quantity'    => $data['quantity'],
				'total_value'       => $data['value'],
				'distinct_products' => count( $data['distinct_products'] ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Vendor breakdown from CCT cache.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'vendors'       => $formatted,
				'connection_id' => $cct_manager->get_connection_id(),
			),
		);
	}

	/**
	 * Sort an associative array by value and limit to top N.
	 *
	 * @param array $data  Associative array.
	 * @param int   $limit Max entries.
	 * @return array
	 */
	protected function sort_and_limit( $data, $limit ) {
		uasort(
			$data,
			function ( $a, $b ) {
				$av = is_array( $a ) ? ( isset( $a['value'] ) ? $a['value'] : ( isset( $a['items'] ) ? $a['items'] : 0 ) ) : $a;
				$bv = is_array( $b ) ? ( isset( $b['value'] ) ? $b['value'] : ( isset( $b['items'] ) ? $b['items'] : 0 ) ) : $b;
				return $bv - $av;
			}
		);

		return array_slice( $data, 0, $limit, true );
	}
}
