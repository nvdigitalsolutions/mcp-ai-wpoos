<?php
/**
 * FlowHub Analytics Tool.
 *
 * Enables AI assistants to query aggregated analytics from the FlowHub
 * sync CCT cache. Provides inventory summaries, stock velocity,
 * category breakdowns, compliance summaries, and location comparisons —
 * all computed from CCT data with zero FlowHub API cost.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/tools/flowhub/trait-wp-mcp-ai-flowhub-connection-resolver.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-cct-manager.php';

/**
 * FlowHub Analytics Tool.
 *
 * @since 1.4.0
 */
class WP_MCP_AI_Pro_Tool_FlowHub_Analytics implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_FlowHub_Connection_Resolver;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'flowhub_analytics';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'FlowHub Analytics', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Compute aggregated analytics from the FlowHub sync cache. Inventory summaries, stock velocity, category breakdowns, compliance summaries, and location comparisons — all from local CCT data with zero FlowHub API cost.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'        => array(
					'type'        => 'string',
					'description' => __( 'Action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'inventory_summary', 'stock_velocity', 'category_breakdown', 'compliance_summary', 'location_comparison' ),
					'default'     => 'inventory_summary',
				),
				'category'      => array(
					'type'        => 'string',
					'description' => __( 'Filter by product category (e.g., Flower, Edible, Concentrate).', 'mcp-ai-wpoos-pro' ),
				),
				'location_id'   => array(
					'type'        => 'string',
					'description' => __( 'Filter by location ID.', 'mcp-ai-wpoos-pro' ),
				),
				'location_name' => array(
					'type'        => 'string',
					'description' => __( 'Filter by location name.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'cache-first', 'requires-capability' );
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
		$action        = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'inventory_summary';
		$category      = isset( $arguments['category'] ) ? sanitize_text_field( $arguments['category'] ) : '';
		$location_id   = isset( $arguments['location_id'] ) ? sanitize_text_field( $arguments['location_id'] ) : '';
		$location_name = isset( $arguments['location_name'] ) ? sanitize_text_field( $arguments['location_name'] ) : '';

		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error( 'wp_mcp_ai_flowhub_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		$deps = $this->check_flowhub_dependencies();
		if ( is_wp_error( $deps ) ) {
			return $deps;
		}

		$cct_manager = $this->get_flowhub_cct_manager();
		$filters     = array( 'per_page' => 100 );

		if ( ! empty( $category ) ) {
			$filters['category'] = $category;
		}
		if ( ! empty( $location_id ) ) {
			$filters['location_id'] = $location_id;
		}
		if ( ! empty( $location_name ) ) {
			$filters['location'] = $location_name;
		}

		switch ( $action ) {
			case 'inventory_summary':
				return $this->handle_inventory_summary( $cct_manager, $filters );
			case 'stock_velocity':
				return $this->handle_stock_velocity( $cct_manager, $filters );
			case 'category_breakdown':
				return $this->handle_category_breakdown( $cct_manager, $filters );
			case 'compliance_summary':
				return $this->handle_compliance_summary( $cct_manager, $filters );
			case 'location_comparison':
				return $this->handle_location_comparison( $cct_manager, $filters );
			default:
				return new WP_Error( 'wp_mcp_ai_flowhub_invalid_action', __( 'Invalid action.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * High-level inventory overview.
	 *
	 * @param WP_MCP_AI_FlowHub_CCT_Manager $cct_manager CCT manager instance.
	 * @param array                         $filters     Filter parameters.
	 * @return array Canonical envelope.
	 */
	protected function handle_inventory_summary( $cct_manager, $filters ) {
		$items         = $cct_manager->get_cached_items( $filters );
		$total_qty     = 0;
		$total_value   = 0.0;
		$locations     = array();
		$categories    = array();
		$low_stock     = 0;
		$out_of_stock  = 0;
		$settings      = get_option( 'wp_mcp_ai_flowhub_toolkit_settings', array() );
		$low_threshold = isset( $settings['low_stock_threshold'] ) ? absint( $settings['low_stock_threshold'] ) : 5;

		foreach ( $items as $item ) {
			$qty          = absint( isset( $item['quantity'] ) ? $item['quantity'] : 0 );
			$price        = floatval( isset( $item['price'] ) ? $item['price'] : 0.0 );
			$total_qty   += $qty;
			$total_value += $qty * $price;

			$loc = isset( $item['location_name'] ) ? $item['location_name'] : 'Unknown';
			if ( ! isset( $locations[ $loc ] ) ) {
				$locations[ $loc ] = array(
					'items' => 0,
					'value' => 0.0,
				);
			}
			$locations[ $loc ]['items'] += $qty;
			$locations[ $loc ]['value'] += round( $qty * $price, 2 );

			$cat = isset( $item['category'] ) ? $item['category'] : 'Unknown';
			if ( ! isset( $categories[ $cat ] ) ) {
				$categories[ $cat ] = 0;
			}
			++$categories[ $cat ];

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
				'categories'          => $categories,
			),
		);
	}

	/**
	 * Identify fast/slow movers.
	 *
	 * @param WP_MCP_AI_FlowHub_CCT_Manager $cct_manager CCT manager instance.
	 * @param array                         $filters     Filter parameters.
	 * @return array Canonical envelope.
	 */
	protected function handle_stock_velocity( $cct_manager, $filters ) {
		$items       = $cct_manager->get_cached_items( $filters );
		$settings    = get_option( 'wp_mcp_ai_flowhub_toolkit_settings', array() );
		$threshold   = isset( $settings['low_stock_threshold'] ) ? absint( $settings['low_stock_threshold'] ) : 5;
		$fast_movers = array();
		$slow_movers = array();

		foreach ( $items as $item ) {
			$qty   = absint( isset( $item['quantity'] ) ? $item['quantity'] : 0 );
			$name  = esc_html( isset( $item['product_name'] ) ? $item['product_name'] : '' );
			$sku   = esc_html( isset( $item['sku'] ) ? $item['sku'] : '' );
			$price = floatval( isset( $item['price'] ) ? $item['price'] : 0.0 );

			if ( $qty <= 0 ) {
				$fast_movers[] = array(
					'product_name' => $name,
					'sku'          => $sku,
					'status'       => 'sold_out',
					'price'        => $price,
				);
			} elseif ( $qty < $threshold ) {
				$fast_movers[] = array(
					'product_name'  => $name,
					'sku'           => $sku,
					'remaining_qty' => $qty,
					'status'        => 'low_stock',
					'price'         => $price,
				);
			} elseif ( $qty > 100 ) {
				$slow_movers[] = array(
					'product_name'  => $name,
					'sku'           => $sku,
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
			),
		);
	}

	/**
	 * Breakdown by purchase category.
	 *
	 * @param WP_MCP_AI_FlowHub_CCT_Manager $cct_manager CCT manager instance.
	 * @param array                         $filters     Filter parameters.
	 * @return array Canonical envelope.
	 */
	protected function handle_category_breakdown( $cct_manager, $filters ) {
		$items      = $cct_manager->get_cached_items( $filters );
		$categories = array();

		foreach ( $items as $item ) {
			$cat   = isset( $item['category'] ) ? $item['category'] : 'Uncategorized';
			$qty   = absint( isset( $item['quantity'] ) ? $item['quantity'] : 0 );
			$price = floatval( isset( $item['price'] ) ? $item['price'] : 0.0 );

			if ( ! isset( $categories[ $cat ] ) ) {
				$categories[ $cat ] = array(
					'products' => 0,
					'quantity' => 0,
					'value'    => 0.0,
				);
			}
			++$categories[ $cat ]['products'];
			$categories[ $cat ]['quantity'] += $qty;
			$categories[ $cat ]['value']    += round( $qty * $price, 2 );
		}

		$formatted = array();
		foreach ( $categories as $name => $data ) {
			$formatted[] = array(
				'category' => esc_html( $name ),
				'products' => $data['products'],
				'quantity' => $data['quantity'],
				'value'    => $data['value'],
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of categories */
				__( 'Category breakdown: %d categories.', 'mcp-ai-wpoos-pro' ),
				count( $formatted )
			),
			'data'    => $formatted,
		);
	}

	/**
	 * Compliance-ready inventory snapshot.
	 *
	 * @param WP_MCP_AI_FlowHub_CCT_Manager $cct_manager CCT manager instance.
	 * @param array                         $filters     Filter parameters.
	 * @return array Canonical envelope.
	 */
	protected function handle_compliance_summary( $cct_manager, $filters ) {
		$items     = $cct_manager->get_cached_items( $filters );
		$locations = array();

		foreach ( $items as $item ) {
			$loc = isset( $item['location_name'] ) ? $item['location_name'] : 'Unknown';
			if ( ! isset( $locations[ $loc ] ) ) {
				$locations[ $loc ] = array(
					'total_items'    => 0,
					'total_quantity' => 0,
					'out_of_stock'   => 0,
					'last_updated'   => '',
				);
			}
			$qty = absint( isset( $item['quantity'] ) ? $item['quantity'] : 0 );
			++$locations[ $loc ]['total_items'];
			$locations[ $loc ]['total_quantity'] += $qty;
			if ( $qty <= 0 ) {
				++$locations[ $loc ]['out_of_stock'];
			}
			$updated = isset( $item['last_updated'] ) ? $item['last_updated'] : '';
			if ( $updated > $locations[ $loc ]['last_updated'] ) {
				$locations[ $loc ]['last_updated'] = $updated;
			}
		}

		return array(
			'success' => true,
			'message' => __( 'Compliance summary generated from CCT cache.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'last_sync'  => esc_html( $cct_manager->get_last_sync_time() ? $cct_manager->get_last_sync_time() : 'Never' ),
				'locations'  => $locations,
				'total_rows' => count( $items ),
				'_note'      => __( 'For Metrc/Biotrack reconciliation, export this report and compare against state track-and-trace data.', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Compare inventory across locations.
	 *
	 * @param WP_MCP_AI_FlowHub_CCT_Manager $cct_manager CCT manager instance.
	 * @param array                         $filters     Filter parameters.
	 * @return array Canonical envelope.
	 */
	protected function handle_location_comparison( $cct_manager, $filters ) {
		$items     = $cct_manager->get_cached_items( $filters );
		$locations = array();

		foreach ( $items as $item ) {
			$loc   = isset( $item['location_name'] ) ? $item['location_name'] : 'Unknown';
			$lid   = isset( $item['location_id'] ) ? $item['location_id'] : '';
			$qty   = absint( isset( $item['quantity'] ) ? $item['quantity'] : 0 );
			$price = floatval( isset( $item['price'] ) ? $item['price'] : 0.0 );

			$key = ! empty( $lid ) ? $lid : $loc;
			if ( ! isset( $locations[ $key ] ) ) {
				$locations[ $key ] = array(
					'location_name'  => $loc,
					'location_id'    => $lid,
					'total_items'    => 0,
					'total_quantity' => 0,
					'total_value'    => 0.0,
				);
			}
			++$locations[ $key ]['total_items'];
			$locations[ $key ]['total_quantity'] += $qty;
			$locations[ $key ]['total_value']    += round( $qty * $price, 2 );
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of locations */
				__( 'Location comparison: %d locations.', 'mcp-ai-wpoos-pro' ),
				count( $locations )
			),
			'data'    => array_values( $locations ),
		);
	}
}
