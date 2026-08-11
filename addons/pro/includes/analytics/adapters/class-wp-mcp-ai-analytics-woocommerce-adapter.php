<?php
/**
 * WooCommerce Adapter — Ecommerce analytics via local database queries.
 *
 * Implements WP_MCP_AI_Analytics_Adapter for WooCommerce. Unlike social
 * adapters that hit external APIs, this adapter queries the local WordPress
 * database through WooCommerce core functions. No API credentials needed —
 * just an active WooCommerce installation.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.7.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license  Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce analytics adapter.
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Analytics_WooCommerce_Adapter implements WP_MCP_AI_Analytics_Adapter {

	/**
	 * Get the platform slug.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	public function get_platform() {
		return 'woocommerce';
	}

	/**
	 * Check if WooCommerce is active.
	 *
	 * @since 1.7.0
	 * @return bool
	 */
	public function is_configured() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string   $account_id Not used for WC — pass empty string.
	 * @param string[] $metrics    Metric names to fetch.
	 * @param string   $since      ISO 8601 start date.
	 * @param string   $until      ISO 8601 end date.
	 * @return array|WP_Error
	 */
	public function get_account_insights( $account_id, array $metrics, $since, $until ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'woocommerce_not_active',
				__( 'WooCommerce is not installed or activated.', 'mcp-ai-wpoos-pro' )
			);
		}

		$orders = $this->get_orders_in_range( $since, $until );

		$total_revenue = 0;
		$total_orders  = count( $orders );
		$total_items   = 0;

		foreach ( $orders as $order ) {
			$total_revenue += (float) $order->get_total();
			$total_items   += (int) $order->get_item_count();
		}

		$avg_order_value = $total_orders > 0 ? round( $total_revenue / $total_orders, 2 ) : 0;

		return array(
			array(
				'metric_name'  => 'revenue',
				'metric_value' => $total_revenue,
				'platform'     => 'woocommerce',
				'account_id'   => $account_id,
				'period_start' => $since,
				'period_end'   => $until,
				'granularity'  => 'day',
			),
			array(
				'metric_name'  => 'orders',
				'metric_value' => $total_orders,
				'platform'     => 'woocommerce',
				'account_id'   => $account_id,
				'period_start' => $since,
				'period_end'   => $until,
				'granularity'  => 'day',
			),
			array(
				'metric_name'  => 'items_sold',
				'metric_value' => $total_items,
				'platform'     => 'woocommerce',
				'account_id'   => $account_id,
				'period_start' => $since,
				'period_end'   => $until,
				'granularity'  => 'day',
			),
			array(
				'metric_name'  => 'avg_order_value',
				'metric_value' => $avg_order_value,
				'platform'     => 'woocommerce',
				'account_id'   => $account_id,
				'period_start' => $since,
				'period_end'   => $until,
				'granularity'  => 'day',
			),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string   $post_id WC order ID.
	 * @param string[] $metrics Metric names.
	 * @return WP_MCP_AI_Analytics_Post_DTO|WP_Error
	 */
	public function get_post_insights( $post_id, array $metrics ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'woocommerce_not_active',
				__( 'WooCommerce is not installed or activated.', 'mcp-ai-wpoos-pro' )
			);
		}

		$order = wc_get_order( absint( $post_id ) );

		if ( ! $order ) {
			return new WP_Error(
				'order_not_found',
				__( 'Order not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		return WP_MCP_AI_Analytics_Post_DTO::from_array(
			array(
				'platform'     => 'woocommerce',
				'post_id'      => $post_id,
				'account_id'   => '',
				'content_type' => 'order',
				'posted_at'    => $order->get_date_created()
					? $order->get_date_created()->format( 'c' )
					: '',
				'metrics'      => array(
					'revenue' => (float) $order->get_total(),
					'items'   => (int) $order->get_item_count(),
				),
			)
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $account_id  Not used.
	 * @param string $since       ISO 8601 start date.
	 * @param string $until       ISO 8601 end date.
	 * @param string $granularity Aggregation period.
	 * @return WP_MCP_AI_Analytics_Metric_DTO[]|WP_Error
	 */
	public function get_follower_growth( $account_id, $since, $until, $granularity = 'day' ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'woocommerce_not_active',
				__( 'WooCommerce is not installed or activated.', 'mcp-ai-wpoos-pro' )
			);
		}

		$customer_count = $this->get_customer_count();

		return array(
			WP_MCP_AI_Analytics_Metric_DTO::from_array(
				array(
					'metric_name'  => 'customers',
					'metric_value' => $customer_count,
					'platform'     => 'woocommerce',
					'account_id'   => $account_id,
					'period_start' => $since,
					'period_end'   => $until,
					'granularity'  => $granularity,
				)
			),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $account_id Not used.
	 * @param string $since      ISO 8601 start date.
	 * @param string $until      ISO 8601 end date.
	 * @param int    $limit      Maximum orders.
	 * @return WP_MCP_AI_Analytics_Post_DTO[]|WP_Error
	 */
	public function get_top_posts( $account_id, $since, $until, $limit = 10 ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'woocommerce_not_active',
				__( 'WooCommerce is not installed or activated.', 'mcp-ai-wpoos-pro' )
			);
		}

		$orders = $this->get_orders_in_range( $since, $until, $limit, 'total', 'DESC' );
		$posts  = array();

		foreach ( $orders as $order ) {
			$posts[] = WP_MCP_AI_Analytics_Post_DTO::from_array(
				array(
					'platform'     => 'woocommerce',
					'post_id'      => (string) $order->get_id(),
					'account_id'   => '',
					'content_type' => 'order',
					'posted_at'    => $order->get_date_created()
						? $order->get_date_created()->format( 'c' )
						: '',
					'metrics'      => array(
						'revenue' => (float) $order->get_total(),
						'items'   => (int) $order->get_item_count(),
					),
				)
			);
		}

		return $posts;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return int|null
	 */
	public function get_rate_limit_remaining() {
		$limiter = WP_MCP_AI_Analytics_Rate_Limiter::instance();
		return $limiter->get_remaining( 'woocommerce' );
	}

	/**
	 * Get orders within a date range.
	 *
	 * @since 1.7.0
	 *
	 * @param string $since    ISO 8601 start date.
	 * @param string $until    ISO 8601 end date.
	 * @param int    $limit    Maximum orders.
	 * @param string $orderby  Sort field.
	 * @param string $order    Sort direction.
	 * @return \WC_Order[]
	 */
	private function get_orders_in_range( $since, $until, $limit = -1, $orderby = 'date', $order = 'DESC' ) {
		$args = array(
			'status'       => array( 'completed', 'processing' ),
			'date_created' => $since . '...' . $until,
			'limit'        => $limit,
			'orderby'      => $orderby,
			'order'        => $order,
			'return'       => 'objects',
		);

		return wc_get_orders( $args );
	}

	/**
	 * Get total customer count.
	 *
	 * @since 1.7.0
	 * @return int
	 */
	private function get_customer_count() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
		$count = $wpdb->get_var(
			"SELECT COUNT(DISTINCT meta_value) FROM {$wpdb->postmeta}
			WHERE meta_key = '_customer_user' AND meta_value > 0"
		);
		return (int) $count;
	}
}
