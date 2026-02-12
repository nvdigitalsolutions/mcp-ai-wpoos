<?php
/**
 * Get Order Analytics Tool
 *
 * Retrieves comprehensive analytics and insights for WooCommerce orders
 * including revenue, trends, top products, and customer behavior.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for retrieving WooCommerce order analytics.
 *
 * Supports:
 * - Revenue analytics
 * - Order trends over time
 * - Top selling products
 * - Customer behavior analysis
 * - Status distribution
 * - Payment method breakdown
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Get_Order_Analytics implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
			return __( 'Order analytics requires WooCommerce to be installed and activated.', 'mcp-ai-wpoos-pro' );
		}

		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
			return __( 'E-commerce toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Order analytics tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'get_order_analytics';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Get Order Analytics', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Retrieve comprehensive WooCommerce order analytics including revenue trends, top products, customer behavior, status distribution, and payment method breakdown. Supports custom date ranges and grouping by day/week/month.', 'mcp-ai-wpoos-pro' );
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
				'date_from'         => array(
					'type'        => 'string',
					'description' => __( 'Start date (Y-m-d format, default: 30 days ago)', 'mcp-ai-wpoos-pro' ),
				),
				'date_to'           => array(
					'type'        => 'string',
					'description' => __( 'End date (Y-m-d format, default: today)', 'mcp-ai-wpoos-pro' ),
				),
				'group_by'          => array(
					'type'        => 'string',
					'description' => __( 'Group results by time period', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'day', 'week', 'month' ),
					'default'     => 'day',
				),
				'include_trends'    => array(
					'type'        => 'boolean',
					'description' => __( 'Include order trends over time', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_products'  => array(
					'type'        => 'boolean',
					'description' => __( 'Include top selling products', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_customers' => array(
					'type'        => 'boolean',
					'description' => __( 'Include customer analytics', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'top_count'         => array(
					'type'        => 'integer',
					'description' => __( 'Number of top items to include', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 100,
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
				__( 'You do not have permission to view order analytics.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if WooCommerce is active.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'woocommerce_not_active',
				self::get_unavailable_reason()
			);
		}

		// Parse date range.
		$date_from = isset( $arguments['date_from'] ) ? sanitize_text_field( $arguments['date_from'] ) : gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$date_to   = isset( $arguments['date_to'] ) ? sanitize_text_field( $arguments['date_to'] ) : gmdate( 'Y-m-d' );
		$group_by  = isset( $arguments['group_by'] ) ? sanitize_text_field( $arguments['group_by'] ) : 'day';
		$top_count = isset( $arguments['top_count'] ) ? absint( $arguments['top_count'] ) : 10;

		// Get all orders in range.
		$orders = wc_get_orders(
			array(
				'limit'        => -1,
				'date_created' => $date_from . '...' . $date_to,
				'return'       => 'ids',
			)
		);

		if ( empty( $orders ) ) {
			return array(
				'success' => true,
				'period'  => array(
					'from' => $date_from,
					'to'   => $date_to,
				),
				'message' => __( 'No orders found in the specified date range.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Gather analytics data.
		$analytics = array(
			'success' => true,
			'period'  => array(
				'from' => $date_from,
				'to'   => $date_to,
			),
			'summary' => $this->get_summary_analytics( $orders ),
		);

		// Add trends if requested.
		if ( isset( $arguments['include_trends'] ) && $arguments['include_trends'] ) {
			$analytics['trends'] = $this->get_order_trends( $orders, $group_by, $date_from, $date_to );
		}

		// Add top products if requested.
		if ( isset( $arguments['include_products'] ) && $arguments['include_products'] ) {
			$analytics['top_products'] = $this->get_top_products( $orders, $top_count );
		}

		// Add customer analytics if requested.
		if ( isset( $arguments['include_customers'] ) && $arguments['include_customers'] ) {
			$analytics['customers'] = $this->get_customer_analytics( $orders, $top_count );
		}

		return $analytics;
	}

	/**
	 * Get summary analytics.
	 *
	 * @param array $order_ids Order IDs.
	 * @return array Summary data.
	 */
	protected function get_summary_analytics( $order_ids ) {
		$total_orders    = count( $order_ids );
		$total_revenue   = 0;
		$status_counts   = array();
		$payment_methods = array();

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				continue;
			}

			// Add to revenue.
			$total_revenue += $order->get_total();

			// Count statuses.
			$status = $order->get_status();
			if ( ! isset( $status_counts[ $status ] ) ) {
				$status_counts[ $status ] = 0;
			}
			++$status_counts[ $status ];

			// Count payment methods.
			$payment_method = $order->get_payment_method_title();
			if ( ! empty( $payment_method ) ) {
				if ( ! isset( $payment_methods[ $payment_method ] ) ) {
					$payment_methods[ $payment_method ] = 0;
				}
				++$payment_methods[ $payment_method ];
			}
		}

		return array(
			'total_orders'        => $total_orders,
			'total_revenue'       => wc_format_decimal( $total_revenue, 2 ),
			'average_order_value' => wc_format_decimal( $total_revenue / max( $total_orders, 1 ), 2 ),
			'currency'            => get_woocommerce_currency(),
			'status_distribution' => $status_counts,
			'payment_methods'     => $payment_methods,
		);
	}

	/**
	 * Get order trends over time.
	 *
	 * @param array  $order_ids Order IDs.
	 * @param string $group_by  Grouping period.
	 * @param string $date_from Start date.
	 * @param string $date_to   End date.
	 * @return array Trends data.
	 */
	protected function get_order_trends( $order_ids, $group_by, $date_from, $date_to ) {
		$trends = array();

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				continue;
			}

			$date = $order->get_date_created();
			if ( ! $date ) {
				continue;
			}

			// Group by period.
			$period_key = $this->get_period_key( $date, $group_by );

			if ( ! isset( $trends[ $period_key ] ) ) {
				$trends[ $period_key ] = array(
					'period'      => $period_key,
					'order_count' => 0,
					'revenue'     => 0,
				);
			}

			++$trends[ $period_key ]['order_count'];
			$trends[ $period_key ]['revenue'] += $order->get_total();
		}

		// Sort by period.
		ksort( $trends );

		// Format revenue.
		foreach ( $trends as &$trend ) {
			$trend['revenue'] = wc_format_decimal( $trend['revenue'], 2 );
		}

		return array_values( $trends );
	}

	/**
	 * Get period key for grouping.
	 *
	 * @param WC_DateTime $date     Order date.
	 * @param string      $group_by Grouping period.
	 * @return string Period key.
	 */
	protected function get_period_key( $date, $group_by ) {
		switch ( $group_by ) {
			case 'week':
				return $date->date( 'Y-W' );
			case 'month':
				return $date->date( 'Y-m' );
			case 'day':
			default:
				return $date->date( 'Y-m-d' );
		}
	}

	/**
	 * Get top selling products.
	 *
	 * @param array $order_ids Order IDs.
	 * @param int   $top_count Number of top products.
	 * @return array Top products.
	 */
	protected function get_top_products( $order_ids, $top_count ) {
		$product_sales = array();

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				continue;
			}

			foreach ( $order->get_items() as $item ) {
				$product_id = $item->get_product_id();
				$product    = $item->get_product();

				if ( ! $product ) {
					continue;
				}

				if ( ! isset( $product_sales[ $product_id ] ) ) {
					$product_sales[ $product_id ] = array(
						'product_id'    => $product_id,
						'name'          => $product->get_name(),
						'sku'           => $product->get_sku(),
						'quantity_sold' => 0,
						'revenue'       => 0,
					);
				}

				$product_sales[ $product_id ]['quantity_sold'] += $item->get_quantity();
				$product_sales[ $product_id ]['revenue']       += $item->get_total();
			}
		}

		// Sort by revenue.
		uasort(
			$product_sales,
			function ( $a, $b ) {
				return $b['revenue'] <=> $a['revenue'];
			}
		);

		// Format and limit.
		$top_products = array_slice( $product_sales, 0, $top_count );

		foreach ( $top_products as &$product ) {
			$product['revenue'] = wc_format_decimal( $product['revenue'], 2 );
		}

		return array_values( $top_products );
	}

	/**
	 * Get customer analytics.
	 *
	 * @param array $order_ids Order IDs.
	 * @param int   $top_count Number of top customers.
	 * @return array Customer analytics.
	 */
	protected function get_customer_analytics( $order_ids, $top_count ) {
		$customer_data       = array();
		$new_customers       = 0;
		$returning_customers = 0;

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				continue;
			}

			$customer_id    = $order->get_customer_id();
			$customer_email = $order->get_billing_email();

			// Use email as key if no customer ID.
			$customer_key = $customer_id ? $customer_id : $customer_email;

			if ( ! isset( $customer_data[ $customer_key ] ) ) {
				$customer_data[ $customer_key ] = array(
					'customer_id'      => $customer_id,
					'name'             => $order->get_formatted_billing_full_name(),
					'email'            => $customer_email,
					'order_count'      => 0,
					'total_spent'      => 0,
					'first_order_date' => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '',
				);
			}

			++$customer_data[ $customer_key ]['order_count'];
			$customer_data[ $customer_key ]['total_spent'] += $order->get_total();
		}

		// Count new vs returning.
		foreach ( $customer_data as $customer ) {
			if ( 1 === $customer['order_count'] ) {
				++$new_customers;
			} else {
				++$returning_customers;
			}
		}

		// Sort by total spent.
		uasort(
			$customer_data,
			function ( $a, $b ) {
				return $b['total_spent'] <=> $a['total_spent'];
			}
		);

		// Format and limit.
		$top_customers = array_slice( $customer_data, 0, $top_count );

		foreach ( $top_customers as &$customer ) {
			$customer['total_spent'] = wc_format_decimal( $customer['total_spent'], 2 );
		}

		return array(
			'total_customers'     => count( $customer_data ),
			'new_customers'       => $new_customers,
			'returning_customers' => $returning_customers,
			'top_customers'       => array_values( $top_customers ),
		);
	}
}
