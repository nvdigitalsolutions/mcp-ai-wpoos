<?php
/**
 * Sales Performance Dashboard Tool
 *
 * Provides comprehensive sales analytics and reporting with revenue trends,
 * top products, customer metrics and filterable reporting.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for sales performance analytics dashboard.
 *
 * Supports:
 * - Revenue trends and KPIs
 * - Top products analysis
 * - Customer metrics
 * - Filterable by date range and categories
 * - Export capabilities
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Sales_Performance_Dashboard implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
			return __( 'Sales performance dashboard requires WooCommerce to be installed and activated.', 'mcp-ai-wpoos-pro' );
		}

		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
			return __( 'E-commerce toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Sales performance dashboard tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'sales_performance_dashboard';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Sales Performance Dashboard', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Comprehensive sales analytics dashboard with revenue trends, top products, customer metrics, and KPIs. Filter by date range, categories, and product types. Export reports in multiple formats.', 'mcp-ai-wpoos-pro' );
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
				'start_date'         => array(
					'type'        => 'string',
					'description' => __( 'Start date for analytics (Y-m-d format)', 'mcp-ai-wpoos-pro' ),
					'default'     => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
				),
				'end_date'           => array(
					'type'        => 'string',
					'description' => __( 'End date for analytics (Y-m-d format)', 'mcp-ai-wpoos-pro' ),
					'default'     => gmdate( 'Y-m-d' ),
				),
				'category_ids'       => array(
					'type'        => 'array',
					'description' => __( 'Filter by product category IDs', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'integer' ),
				),
				'include_refunds'    => array(
					'type'        => 'boolean',
					'description' => __( 'Include refunded orders in analytics', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'metrics'            => array(
					'type'        => 'array',
					'description' => __( 'Specific metrics to include', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'revenue', 'orders', 'customers', 'products', 'conversion', 'average_order' ),
					),
					'default'     => array( 'revenue', 'orders', 'customers' ),
				),
				'top_products_limit' => array(
					'type'        => 'integer',
					'description' => __( 'Number of top products to include', 'mcp-ai-wpoos-pro' ),
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
				__( 'You do not have permission to view sales analytics.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if WooCommerce is active.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'woocommerce_not_active',
				self::get_unavailable_reason()
			);
		}

		$start_date         = isset( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$end_date           = isset( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : gmdate( 'Y-m-d' );
		$category_ids       = isset( $arguments['category_ids'] ) && is_array( $arguments['category_ids'] ) ? array_map( 'absint', $arguments['category_ids'] ) : array();
		$include_refunds    = isset( $arguments['include_refunds'] ) && $arguments['include_refunds'];
		$metrics            = isset( $arguments['metrics'] ) && is_array( $arguments['metrics'] ) ? $arguments['metrics'] : array( 'revenue', 'orders', 'customers' );
		$top_products_limit = isset( $arguments['top_products_limit'] ) ? absint( $arguments['top_products_limit'] ) : 10;

		// Build the dashboard data.
		$dashboard = array(
			'period'       => array(
				'start_date' => $start_date,
				'end_date'   => $end_date,
				'days'       => $this->calculate_days_between( $start_date, $end_date ),
			),
			'metrics'      => $this->get_metrics( $start_date, $end_date, $include_refunds, $metrics ),
			'trends'       => $this->get_revenue_trends( $start_date, $end_date, $include_refunds ),
			'top_products' => $this->get_top_products( $start_date, $end_date, $category_ids, $top_products_limit ),
			'customers'    => $this->get_customer_metrics( $start_date, $end_date ),
		);

		return array(
			'success'   => true,
			'dashboard' => $dashboard,
			'message'   => sprintf(
				/* translators: 1: Start date, 2: End date */
				__( 'Sales performance dashboard generated for %1$s to %2$s.', 'mcp-ai-wpoos-pro' ),
				$start_date,
				$end_date
			),
		);
	}

	/**
	 * Calculate days between two dates.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return int Days.
	 */
	protected function calculate_days_between( $start_date, $end_date ) {
		$start = strtotime( $start_date );
		$end   = strtotime( $end_date );
		return max( 1, round( ( $end - $start ) / DAY_IN_SECONDS ) );
	}

	/**
	 * Get sales metrics.
	 *
	 * @param string $start_date     Start date.
	 * @param string $end_date       End date.
	 * @param bool   $include_refunds Include refunds.
	 * @param array  $metrics         Metrics to include.
	 * @return array Metrics data.
	 */
	protected function get_metrics( $start_date, $end_date, $include_refunds, $metrics ) {
		global $wpdb;

		$statuses = $include_refunds ? array( 'wc-completed', 'wc-processing', 'wc-refunded' ) : array( 'wc-completed', 'wc-processing' );

		// Prepare placeholders for IN clause.
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$results      = array();

		// Revenue.
		if ( in_array( 'revenue', $metrics, true ) ) {
			$revenue = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					"SELECT SUM(pm.meta_value) 
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					WHERE p.post_type = 'shop_order'
					AND p.post_status IN ($placeholders)
					AND pm.meta_key = '_order_total'
					AND p.post_date >= %s
					AND p.post_date <= %s",
					// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					array_merge( $statuses, array( $start_date . ' 00:00:00', $end_date . ' 23:59:59' ) )
				)
			);
			$results['revenue'] = wc_format_decimal( $revenue ? $revenue : 0, 2 );
		}

		// Orders count.
		if ( in_array( 'orders', $metrics, true ) ) {
			$orders_count = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					"SELECT COUNT(DISTINCT p.ID) 
					FROM {$wpdb->posts} p
					WHERE p.post_type = 'shop_order'
					AND p.post_status IN ($placeholders)
					AND p.post_date >= %s
					AND p.post_date <= %s",
					// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					array_merge( $statuses, array( $start_date . ' 00:00:00', $end_date . ' 23:59:59' ) )
				)
			);
			$results['orders'] = absint( $orders_count ? $orders_count : 0 );
		}

		// Customers.
		if ( in_array( 'customers', $metrics, true ) ) {
			$customers_count = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					"SELECT COUNT(DISTINCT pm.meta_value) 
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					WHERE p.post_type = 'shop_order'
					AND p.post_status IN ($placeholders)
					AND pm.meta_key = '_customer_user'
					AND pm.meta_value > 0
					AND p.post_date >= %s
					AND p.post_date <= %s",
					// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					array_merge( $statuses, array( $start_date . ' 00:00:00', $end_date . ' 23:59:59' ) )
				)
			);
			$results['customers'] = absint( $customers_count ? $customers_count : 0 );
		}

		// Average order value.
		if ( in_array( 'average_order', $metrics, true ) && isset( $results['revenue'], $results['orders'] ) && $results['orders'] > 0 ) {
			$results['average_order'] = wc_format_decimal( $results['revenue'] / $results['orders'], 2 );
		}

		return $results;
	}

	/**
	 * Get revenue trends.
	 *
	 * @param string $start_date      Start date.
	 * @param string $end_date        End date.
	 * @param bool   $include_refunds Include refunds.
	 * @return array Trends data.
	 */
	protected function get_revenue_trends( $start_date, $end_date, $include_refunds ) {
		global $wpdb;

		$statuses = $include_refunds ? array( 'wc-completed', 'wc-processing', 'wc-refunded' ) : array( 'wc-completed', 'wc-processing' );

		// Prepare placeholders for IN clause.
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$daily_revenue = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				"SELECT DATE(p.post_date) as date, SUM(pm.meta_value) as revenue, COUNT(DISTINCT p.ID) as orders
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = 'shop_order'
				AND p.post_status IN ($placeholders)
				AND pm.meta_key = '_order_total'
				AND p.post_date >= %s
				AND p.post_date <= %s
				GROUP BY DATE(p.post_date)
				ORDER BY date ASC",
				// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				array_merge( $statuses, array( $start_date . ' 00:00:00', $end_date . ' 23:59:59' ) )
			)
		);

		$trends = array();
		foreach ( $daily_revenue as $day ) {
			$trends[] = array(
				'date'    => $day->date,
				'revenue' => wc_format_decimal( $day->revenue, 2 ),
				'orders'  => absint( $day->orders ),
			);
		}

		return $trends;
	}

	/**
	 * Get top products.
	 *
	 * @param string $start_date  Start date.
	 * @param string $end_date    End date.
	 * @param array  $category_ids Category IDs.
	 * @param int    $limit        Limit.
	 * @return array Top products.
	 */
	protected function get_top_products( $start_date, $end_date, $category_ids, $limit ) {
		global $wpdb;

		// Prepare query parts.
		$query = "SELECT 
				oi.order_item_id,
				oim_product.meta_value as product_id,
				oim_qty.meta_value as quantity,
				oim_total.meta_value as total,
				p.post_title as product_name
			FROM {$wpdb->prefix}woocommerce_order_items oi
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_product ON oi.order_item_id = oim_product.order_item_id AND oim_product.meta_key = '_product_id'
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty ON oi.order_item_id = oim_qty.order_item_id AND oim_qty.meta_key = '_qty'
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_total ON oi.order_item_id = oim_total.order_item_id AND oim_total.meta_key = '_line_total'
			INNER JOIN {$wpdb->posts} o ON oi.order_id = o.ID
			INNER JOIN {$wpdb->posts} p ON oim_product.meta_value = p.ID
			WHERE oi.order_item_type = 'line_item'
			AND o.post_type = 'shop_order'
			AND o.post_status IN ('wc-completed', 'wc-processing')
			AND o.post_date >= %s
			AND o.post_date <= %s";

		$params = array( $start_date . ' 00:00:00', $end_date . ' 23:59:59' );

		if ( ! empty( $category_ids ) ) {
			$category_placeholders = implode( ', ', array_fill( 0, count( $category_ids ), '%d' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$query .= " AND tr.term_taxonomy_id IN ($category_placeholders)";
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$params = array_merge( $params, $category_ids );
		}

		$query   .= ' GROUP BY oim_product.meta_value ORDER BY SUM(oim_total.meta_value) DESC LIMIT %d';
		$params[] = $limit;

		$top_products = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$query,
				$params
			)
		);

		$products = array();
		foreach ( $top_products as $product ) {
			$products[] = array(
				'product_id'   => absint( $product->product_id ),
				'product_name' => $product->product_name,
				'quantity'     => absint( $product->quantity ),
				'revenue'      => wc_format_decimal( $product->total, 2 ),
			);
		}

		return $products;
	}

	/**
	 * Get customer metrics.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array Customer metrics.
	 */
	protected function get_customer_metrics( $start_date, $end_date ) {
		global $wpdb;

		$new_customers = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT pm.meta_value)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = 'shop_order'
				AND p.post_status IN ('wc-completed', 'wc-processing')
				AND pm.meta_key = '_customer_user'
				AND pm.meta_value > 0
				AND pm.meta_value IN (
					SELECT DISTINCT pm2.meta_value
					FROM {$wpdb->posts} p2
					INNER JOIN {$wpdb->postmeta} pm2 ON p2.ID = pm2.post_id
					WHERE p2.post_type = 'shop_order'
					AND p2.post_status IN ('wc-completed', 'wc-processing')
					AND pm2.meta_key = '_customer_user'
					GROUP BY pm2.meta_value
					HAVING MIN(p2.post_date) >= %s
					AND MIN(p2.post_date) <= %s
				)",
				$start_date . ' 00:00:00',
				$end_date . ' 23:59:59'
			)
		);

		return array(
			'new_customers' => absint( $new_customers ? $new_customers : 0 ),
		);
	}
}
