<?php
/**
 * WooCommerce Orders Tool - Pro add-on tool for WooCommerce order operations.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for WooCommerce order operations.
 *
 * Provides read access to WooCommerce orders including:
 * - Listing orders
 * - Getting order details
 * - Searching orders
 *
 * Requires WooCommerce plugin to be active.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Tool_Woo_Orders implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if WooCommerce is active.
	 */
	public static function is_available() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.0.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		return __( 'WooCommerce Orders tool requires WooCommerce to be installed and activated.', 'wp-mcp-ai-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'woo_orders';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'WooCommerce Orders', 'wp-mcp-ai-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Query WooCommerce orders. View order details, statuses, customer information, and order items.', 'wp-mcp-ai-pro' );
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
				'action'   => array(
					'type'        => 'string',
					'description' => __( 'The action to perform: get, list, search.', 'wp-mcp-ai-pro' ),
					'enum'        => array( 'get', 'list', 'search' ),
					'default'     => 'list',
				),
				'order_id' => array(
					'type'        => 'integer',
					'description' => __( 'Order ID for get action.', 'wp-mcp-ai-pro' ),
				),
				'per_page' => array(
					'type'        => 'integer',
					'description' => __( 'Number of orders to return. Default: 10. Max: 100.', 'wp-mcp-ai-pro' ),
					'default'     => 10,
					'maximum'     => 100,
				),
				'page'     => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination. Default: 1.', 'wp-mcp-ai-pro' ),
					'default'     => 1,
				),
				'status'   => array(
					'type'        => 'string',
					'description' => __( 'Filter by order status.', 'wp-mcp-ai-pro' ),
					'enum'        => array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ),
				),
				'customer' => array(
					'type'        => 'integer',
					'description' => __( 'Filter by customer ID.', 'wp-mcp-ai-pro' ),
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'read-only',        // Only read operations.
			'requires-plugin',  // Requires WooCommerce.
			'local-only',       // No external API calls.
			'pii-data',         // May contain customer data.
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return mixed|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if WooCommerce is active.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'woocommerce_not_active',
				__( 'WooCommerce is not installed or activated.', 'wp-mcp-ai-pro' )
			);
		}

		// Check permission.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! user_can( $user_id, 'edit_shop_orders' ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to view orders.', 'wp-mcp-ai-pro' )
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'list';

		switch ( $action ) {
			case 'get':
				return $this->get_order( $arguments );
			case 'list':
				return $this->list_orders( $arguments );
			case 'search':
				return $this->search_orders( $arguments );
			default:
				return new WP_Error(
					'invalid_action',
					__( 'Invalid action specified.', 'wp-mcp-ai-pro' )
				);
		}
	}

	/**
	 * Get a single order by ID.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function get_order( $arguments ) {
		if ( empty( $arguments['order_id'] ) ) {
			return new WP_Error(
				'missing_order_id',
				__( 'Order ID is required for get action.', 'wp-mcp-ai-pro' )
			);
		}

		$order = wc_get_order( absint( $arguments['order_id'] ) );

		if ( ! $order ) {
			return new WP_Error(
				'order_not_found',
				__( 'Order not found.', 'wp-mcp-ai-pro' )
			);
		}

		return $this->format_order( $order );
	}

	/**
	 * List orders.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	protected function list_orders( $arguments ) {
		$per_page = isset( $arguments['per_page'] ) ? min( absint( $arguments['per_page'] ), 100 ) : 10;
		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		$query_args = array(
			'limit'    => $per_page,
			'page'     => $page,
			'orderby'  => 'date',
			'order'    => 'DESC',
			'paginate' => true,
		);

		if ( ! empty( $arguments['status'] ) ) {
			$query_args['status'] = sanitize_key( $arguments['status'] );
		}

		if ( ! empty( $arguments['customer'] ) ) {
			$query_args['customer'] = absint( $arguments['customer'] );
		}

		$results = wc_get_orders( $query_args );

		$orders = array();
		foreach ( $results->orders as $order ) {
			$orders[] = $this->format_order( $order, false );
		}

		return array(
			'orders'      => $orders,
			'total'       => $results->total,
			'total_pages' => $results->max_num_pages,
			'page'        => $page,
		);
	}

	/**
	 * Search orders.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	protected function search_orders( $arguments ) {
		// Use list with existing filters.
		return $this->list_orders( $arguments );
	}

	/**
	 * Format an order for output.
	 *
	 * @param WC_Order $order       Order object.
	 * @param bool     $include_items Whether to include line items.
	 * @return array
	 */
	protected function format_order( $order, $include_items = true ) {
		$data = array(
			'id'             => $order->get_id(),
			'status'         => $order->get_status(),
			'currency'       => $order->get_currency(),
			'total'          => $order->get_total(),
			'subtotal'       => $order->get_subtotal(),
			'total_tax'      => $order->get_total_tax(),
			'shipping_total' => $order->get_shipping_total(),
			'discount_total' => $order->get_discount_total(),
			'customer_id'    => $order->get_customer_id(),
			'billing_email'  => $order->get_billing_email(),
			'billing_name'   => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
			'payment_method' => $order->get_payment_method_title(),
			'date_created'   => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : null,
			'date_modified'  => $order->get_date_modified() ? $order->get_date_modified()->format( 'c' ) : null,
			'date_completed' => $order->get_date_completed() ? $order->get_date_completed()->format( 'c' ) : null,
			'item_count'     => $order->get_item_count(),
		);

		if ( $include_items ) {
			$data['items'] = array();
			foreach ( $order->get_items() as $item ) {
				$product         = $item->get_product();
				$data['items'][] = array(
					'id'       => $item->get_id(),
					'name'     => $item->get_name(),
					'quantity' => $item->get_quantity(),
					'total'    => $item->get_total(),
					'sku'      => $product ? $product->get_sku() : '',
				);
			}
		}

		return $data;
	}
}
