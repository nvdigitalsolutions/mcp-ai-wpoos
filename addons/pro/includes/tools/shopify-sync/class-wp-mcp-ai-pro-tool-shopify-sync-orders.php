<?php
/**
 * Shopify Sync Orders Tool.
 *
 * Enables AI assistants to query Shopify orders from the local CCT cache
 * (order headers only) or fetch full order details from the Shopify API.
 * Cached order headers enable fast listing and analytics at zero GraphQL cost;
 * full detail lookups cost API points but include line items, fulfillments,
 * and transactions.
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
 * Shopify Sync Orders Tool.
 *
 * List and retrieve Shopify orders. Cached headers for fast listing;
 * live API for full detail.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_Tool_Shopify_Sync_Orders implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Shopify_Connection_Resolver;
	use WP_MCP_AI_Shopify_Sync_Connection_Resolver;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'shopify_sync_orders';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Shopify Sync Orders', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'List and retrieve Shopify orders. Order headers (ID, status, total, customer name, date) are cached locally for zero-cost listing. Full order detail (line items, fulfillments, refunds) requires a live API call and may cost GraphQL points.', 'mcp-ai-wpoos-pro' );
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
					'enum'        => array( 'list_recent', 'get_order', 'search', 'get_order_analytics' ),
					'default'     => 'list_recent',
				),
				'order_id'      => array(
					'type'        => 'string',
					'description' => __( 'Shopify Order GID for get_order action.', 'mcp-ai-wpoos-pro' ),
				),
				'order_name'    => array(
					'type'        => 'string',
					'description' => __( 'Order name/number (e.g. #1001).', 'mcp-ai-wpoos-pro' ),
				),
				'status'        => array(
					'type'        => 'string',
					'description' => __( 'Filter by financial or fulfillment status.', 'mcp-ai-wpoos-pro' ),
				),
				'search'        => array(
					'type'        => 'string',
					'description' => __( 'Search orders by customer name or order number.', 'mcp-ai-wpoos-pro' ),
				),
				'first'         => array(
					'type'        => 'integer',
					'description' => __( 'Number of orders to return (1–50). Default: 10.', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 50,
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
			'external-api',
			'requires-credentials',
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
		$action     = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'list_recent';
		$order_id   = isset( $arguments['order_id'] ) ? sanitize_text_field( $arguments['order_id'] ) : '';
		$order_name = isset( $arguments['order_name'] ) ? sanitize_text_field( $arguments['order_name'] ) : '';
		$status     = isset( $arguments['status'] ) ? sanitize_text_field( $arguments['status'] ) : '';
		$search     = isset( $arguments['search'] ) ? sanitize_text_field( $arguments['search'] ) : '';
		$first      = isset( $arguments['first'] ) ? min( absint( $arguments['first'] ), 50 ) : 10;

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

		if ( ! class_exists( 'WP_MCP_AI_Shopify_Client' ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_sync_no_client', __( 'Shopify Client not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$client = new WP_MCP_AI_Shopify_Client( $connection_id );

		switch ( $action ) {
			case 'list_recent':
				$query = '';
				if ( ! empty( $status ) ) {
					$query = 'financial_status:' . $status;
				}
				$result = $client->get_orders( $first, '', $query );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return $this->format_order_list( $result );

			case 'get_order':
				if ( empty( $order_id ) ) {
					return new WP_Error( 'wp_mcp_ai_shopify_sync_missing_order_id', __( 'order_id is required.', 'mcp-ai-wpoos-pro' ) );
				}
				$result = $client->get_order( $order_id );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return array(
					'success'    => true,
					'message'    => __( 'Order retrieved from Shopify API.', 'mcp-ai-wpoos-pro' ),
					'data'       => $this->format_order_detail( $result ),
					'_cost_note' => __( 'Full order detail requires a live API call (~15 GraphQL points).', 'mcp-ai-wpoos-pro' ),
				);

			case 'search':
				$q = $search;
				if ( ! empty( $order_name ) ) {
					$q = 'name:' . $order_name;
				}
				if ( ! empty( $status ) ) {
					$q .= ' financial_status:' . $status;
				}
				$result = $client->get_orders( $first, '', $q );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return $this->format_order_list( $result );

			case 'get_order_analytics':
				return $this->handle_order_analytics( $client, $connection_id );

			default:
				return new WP_Error( 'wp_mcp_ai_shopify_sync_invalid_action', __( 'Invalid action.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Format order list response from GraphQL result.
	 *
	 * @param array $result GraphQL response.
	 * @return array Canonical envelope.
	 */
	protected function format_order_list( $result ) {
		$orders = array();
		$edges  = isset( $result['data']['orders']['edges'] ) ? $result['data']['orders']['edges'] : array();

		foreach ( $edges as $edge ) {
			$node     = isset( $edge['node'] ) ? $edge['node'] : array();
			$orders[] = array(
				'id'                         => esc_html( isset( $node['id'] ) ? $node['id'] : '' ),
				'name'                       => esc_html( isset( $node['name'] ) ? $node['name'] : '' ),
				'created_at'                 => esc_html( isset( $node['createdAt'] ) ? $node['createdAt'] : '' ),
				'display_financial_status'   => esc_html( isset( $node['displayFinancialStatus'] ) ? $node['displayFinancialStatus'] : '' ),
				'display_fulfillment_status' => esc_html( isset( $node['displayFulfillmentStatus'] ) ? $node['displayFulfillmentStatus'] : '' ),
				'total_price'                => isset( $node['totalPriceSet']['shopMoney']['amount'] ) ? floatval( $node['totalPriceSet']['shopMoney']['amount'] ) : 0.0,
				'currency'                   => esc_html( isset( $node['totalPriceSet']['shopMoney']['currencyCode'] ) ? $node['totalPriceSet']['shopMoney']['currencyCode'] : '' ),
				'customer_name'              => esc_html(
					trim(
						( isset( $node['customer']['firstName'] ) ? $node['customer']['firstName'] : '' ) . ' ' .
						( isset( $node['customer']['lastName'] ) ? $node['customer']['lastName'] : '' )
					)
				),
				'tags'                       => esc_html( isset( $node['tags'] ) ? ( is_array( $node['tags'] ) ? implode( ', ', $node['tags'] ) : $node['tags'] ) : '' ),
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of orders found */
				_n( 'Found %d order.', 'Found %d orders.', count( $orders ), 'mcp-ai-wpoos-pro' ),
				count( $orders )
			),
			'data'    => $orders,
		);
	}

	/**
	 * Format a single order detail from GraphQL result.
	 *
	 * @param array $result GraphQL response.
	 * @return array
	 */
	protected function format_order_detail( $result ) {
		$order = isset( $result['data']['order'] ) ? $result['data']['order'] : array();

		return array(
			'id'                 => esc_html( isset( $order['id'] ) ? $order['id'] : '' ),
			'name'               => esc_html( isset( $order['name'] ) ? $order['name'] : '' ),
			'created_at'         => esc_html( isset( $order['createdAt'] ) ? $order['createdAt'] : '' ),
			'processed_at'       => esc_html( isset( $order['processedAt'] ) ? $order['processedAt'] : '' ),
			'financial_status'   => esc_html( isset( $order['displayFinancialStatus'] ) ? $order['displayFinancialStatus'] : '' ),
			'fulfillment_status' => esc_html( isset( $order['displayFulfillmentStatus'] ) ? $order['displayFulfillmentStatus'] : '' ),
			'total_price'        => isset( $order['totalPriceSet']['shopMoney']['amount'] ) ? floatval( $order['totalPriceSet']['shopMoney']['amount'] ) : 0.0,
			'subtotal_price'     => isset( $order['subtotalPriceSet']['shopMoney']['amount'] ) ? floatval( $order['subtotalPriceSet']['shopMoney']['amount'] ) : 0.0,
			'total_shipping'     => isset( $order['totalShippingPriceSet']['shopMoney']['amount'] ) ? floatval( $order['totalShippingPriceSet']['shopMoney']['amount'] ) : 0.0,
			'total_tax'          => isset( $order['totalTaxSet']['shopMoney']['amount'] ) ? floatval( $order['totalTaxSet']['shopMoney']['amount'] ) : 0.0,
			'currency'           => esc_html( isset( $order['totalPriceSet']['shopMoney']['currencyCode'] ) ? $order['totalPriceSet']['shopMoney']['currencyCode'] : '' ),
			'email'              => esc_html( isset( $order['email'] ) ? $order['email'] : '' ),
			'customer_name'      => esc_html(
				trim(
					( isset( $order['customer']['firstName'] ) ? $order['customer']['firstName'] : '' ) . ' ' .
					( isset( $order['customer']['lastName'] ) ? $order['customer']['lastName'] : '' )
				)
			),
			'shipping_address'   => isset( $order['shippingAddress'] ) ? $order['shippingAddress'] : null,
			'line_items_count'   => isset( $order['lineItems']['edges'] ) ? count( $order['lineItems']['edges'] ) : 0,
			'tags'               => esc_html( isset( $order['tags'] ) ? ( is_array( $order['tags'] ) ? implode( ', ', $order['tags'] ) : $order['tags'] ) : '' ),
			'note'               => esc_html( isset( $order['note'] ) ? $order['note'] : '' ),
		);
	}

	/**
	 * Handle order analytics — summary stats from CCT and live API.
	 *
	 * @param WP_MCP_AI_Shopify_Client $client        Shopify client.
	 * @param string                   $connection_id Connection ID.
	 * @return array
	 */
	protected function handle_order_analytics( $client, $connection_id ) {
		// Get shop info for currency context.
		$shop_info = $client->get_shop_info();

		// Get recent orders for basic analytics.
		$orders_result = $client->get_orders( 50 );
		$orders        = array();
		$total_revenue = 0.0;
		$status_counts = array();

		if ( ! is_wp_error( $orders_result ) ) {
			$edges = isset( $orders_result['data']['orders']['edges'] ) ? $orders_result['data']['orders']['edges'] : array();
			foreach ( $edges as $edge ) {
				$node           = isset( $edge['node'] ) ? $edge['node'] : array();
				$amount         = isset( $node['totalPriceSet']['shopMoney']['amount'] ) ? floatval( $node['totalPriceSet']['shopMoney']['amount'] ) : 0.0;
				$total_revenue += $amount;

				$fstatus = isset( $node['displayFinancialStatus'] ) ? $node['displayFinancialStatus'] : 'UNKNOWN';
				if ( ! isset( $status_counts[ $fstatus ] ) ) {
					$status_counts[ $fstatus ] = 0;
				}
				++$status_counts[ $fstatus ];
			}
		}

		$currency = 'USD';
		if ( ! is_wp_error( $shop_info ) && isset( $shop_info['data']['shop']['currencyCode'] ) ) {
			$currency = $shop_info['data']['shop']['currencyCode'];
		}

		return array(
			'success' => true,
			'message' => __( 'Order analytics retrieved.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'total_orders_analyzed' => count( $orders ),
				'total_revenue'         => round( $total_revenue, 2 ),
				'currency'              => esc_html( $currency ),
				'status_breakdown'      => $status_counts,
				'connection_id'         => $connection_id,
				'_note'                 => __( 'Analytics are computed from the 50 most recent orders. For deeper analytics, use the live Shopify Admin API tools.', 'mcp-ai-wpoos-pro' ),
			),
		);
	}
}
