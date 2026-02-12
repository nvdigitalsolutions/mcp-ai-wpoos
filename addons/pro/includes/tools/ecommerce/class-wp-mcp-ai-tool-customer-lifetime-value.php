<?php
/**
 * Customer Lifetime Value Tool
 *
 * Calculates customer lifetime value (CLV) metrics to identify
 * high-value customers and predict future revenue potential.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for calculating customer lifetime value.
 *
 * Supports:
 * - Historical CLV calculation
 * - Predictive CLV estimation
 * - Customer segmentation by value
 * - Trend analysis
 * - Churn risk assessment
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Customer_Lifetime_Value implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
			return __( 'CLV calculation requires WooCommerce to be installed and activated.', 'mcp-ai-wpoos-pro' );
		}

		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
			return __( 'E-commerce toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Customer lifetime value tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'customer_lifetime_value';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Calculate Customer Lifetime Value', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Calculate customer lifetime value (CLV) metrics to identify high-value customers and predict future revenue. Includes historical CLV, predictive estimates, churn risk assessment, and value-based segmentation.', 'mcp-ai-wpoos-pro' );
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
				'customer_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Calculate CLV for specific customer ID', 'mcp-ai-wpoos-pro' ),
				),
				'email'             => array(
					'type'        => 'string',
					'description' => __( 'Calculate CLV for customer email', 'mcp-ai-wpoos-pro' ),
				),
				'include_all'       => array(
					'type'        => 'boolean',
					'description' => __( 'Calculate CLV for all customers', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'calculation_type'  => array(
					'type'        => 'string',
					'description' => __( 'Type of CLV calculation', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'historical', 'predictive', 'both' ),
					'default'     => 'both',
				),
				'timeframe_months'  => array(
					'type'        => 'integer',
					'description' => __( 'Historical timeframe in months', 'mcp-ai-wpoos-pro' ),
					'default'     => 12,
					'minimum'     => 1,
					'maximum'     => 60,
				),
				'prediction_months' => array(
					'type'        => 'integer',
					'description' => __( 'Prediction timeframe in months', 'mcp-ai-wpoos-pro' ),
					'default'     => 12,
					'minimum'     => 3,
					'maximum'     => 36,
				),
				'top_count'         => array(
					'type'        => 'integer',
					'description' => __( 'Number of top customers to include when include_all is true', 'mcp-ai-wpoos-pro' ),
					'default'     => 100,
					'minimum'     => 1,
					'maximum'     => 1000,
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
				__( 'You do not have permission to calculate CLV.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if WooCommerce is active.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'woocommerce_not_active',
				self::get_unavailable_reason()
			);
		}

		$calculation_type  = isset( $arguments['calculation_type'] ) ? sanitize_text_field( $arguments['calculation_type'] ) : 'both';
		$timeframe_months  = isset( $arguments['timeframe_months'] ) ? absint( $arguments['timeframe_months'] ) : 12;
		$prediction_months = isset( $arguments['prediction_months'] ) ? absint( $arguments['prediction_months'] ) : 12;
		$include_all       = isset( $arguments['include_all'] ) && $arguments['include_all'];

		// Determine which customers to analyze.
		$customers = array();

		if ( ! empty( $arguments['customer_id'] ) ) {
			$customer_id = absint( $arguments['customer_id'] );
			$customer    = new WC_Customer( $customer_id );

			if ( $customer->get_id() ) {
				$customers[] = array(
					'id'    => $customer->get_id(),
					'email' => $customer->get_email(),
					'name'  => $customer->get_first_name() . ' ' . $customer->get_last_name(),
				);
			}
		} elseif ( ! empty( $arguments['email'] ) ) {
			$email = sanitize_email( $arguments['email'] );
			$user  = get_user_by( 'email', $email );

			if ( $user ) {
				$customer    = new WC_Customer( $user->ID );
				$customers[] = array(
					'id'    => $customer->get_id(),
					'email' => $customer->get_email(),
					'name'  => $customer->get_first_name() . ' ' . $customer->get_last_name(),
				);
			}
		} elseif ( $include_all ) {
			$top_count = isset( $arguments['top_count'] ) ? absint( $arguments['top_count'] ) : 100;
			$customers = $this->get_top_customers( $top_count );
		}

		if ( empty( $customers ) ) {
			return new WP_Error(
				'no_customers_found',
				__( 'No customers found for CLV calculation.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Calculate CLV for each customer.
		$clv_data = array();

		foreach ( $customers as $customer_info ) {
			$clv = $this->calculate_customer_clv(
				$customer_info['email'],
				$calculation_type,
				$timeframe_months,
				$prediction_months
			);

			$clv['customer'] = $customer_info;
			$clv_data[]      = $clv;
		}

		// Sort by total CLV.
		usort(
			$clv_data,
			function ( $a, $b ) {
				return $b['total_clv'] <=> $a['total_clv'];
			}
		);

		return array(
			'success'           => true,
			'calculation_type'  => $calculation_type,
			'timeframe_months'  => $timeframe_months,
			'prediction_months' => $prediction_months,
			'total_customers'   => count( $clv_data ),
			'customers'         => $clv_data,
			'currency'          => get_woocommerce_currency(),
		);
	}

	/**
	 * Get top customers by order count.
	 *
	 * @param int $limit Number of customers.
	 * @return array Customer data.
	 */
	protected function get_top_customers( $limit ) {
		global $wpdb;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_value as email, u.ID as user_id,
					u.display_name as name, COUNT(p.ID) as order_count
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_billing_email'
				LEFT JOIN {$wpdb->users} u ON pm.meta_value = u.user_email
				WHERE p.post_type = 'shop_order'
				AND p.post_status IN ('wc-completed', 'wc-processing')
				GROUP BY pm.meta_value
				ORDER BY order_count DESC
				LIMIT %d",
				$limit
			)
		);

		$customers = array();

		foreach ( $results as $row ) {
			$customers[] = array(
				'id'    => absint( $row->user_id ),
				'email' => $row->email,
				'name'  => $row->name,
			);
		}

		return $customers;
	}

	/**
	 * Calculate CLV for a single customer.
	 *
	 * @param string $email             Customer email.
	 * @param string $calculation_type  Calculation type.
	 * @param int    $timeframe_months  Historical timeframe.
	 * @param int    $prediction_months Prediction timeframe.
	 * @return array CLV data.
	 */
	protected function calculate_customer_clv( $email, $calculation_type, $timeframe_months, $prediction_months ) {
		$date_from = gmdate( 'Y-m-d', strtotime( "-{$timeframe_months} months" ) );

		// Get customer orders.
		$orders = wc_get_orders(
			array(
				'limit'         => -1,
				'billing_email' => $email,
				'date_created'  => '>=' . $date_from,
				'status'        => array( 'completed', 'processing' ),
			)
		);

		$historical_clv = $this->calculate_historical_clv( $orders );
		$predictive_clv = 0;

		if ( in_array( $calculation_type, array( 'predictive', 'both' ), true ) ) {
			$predictive_clv = $this->calculate_predictive_clv( $historical_clv, $orders, $prediction_months );
		}

		$total_clv = $historical_clv + $predictive_clv;

		return array(
			'historical_clv'  => wc_format_decimal( $historical_clv, 2 ),
			'predictive_clv'  => wc_format_decimal( $predictive_clv, 2 ),
			'total_clv'       => wc_format_decimal( $total_clv, 2 ),
			'order_count'     => count( $orders ),
			'avg_order_value' => count( $orders ) > 0 ? wc_format_decimal( $historical_clv / count( $orders ), 2 ) : 0,
			'churn_risk'      => $this->assess_churn_risk( $orders ),
		);
	}

	/**
	 * Calculate historical CLV from orders.
	 *
	 * @param array $orders Order objects.
	 * @return float Historical CLV.
	 */
	protected function calculate_historical_clv( $orders ) {
		$total = 0;

		foreach ( $orders as $order ) {
			$total += $order->get_total();
		}

		return floatval( $total );
	}

	/**
	 * Calculate predictive CLV using simple extrapolation.
	 *
	 * @param float $historical_clv     Historical CLV.
	 * @param array $orders             Order objects.
	 * @param int   $prediction_months  Prediction timeframe.
	 * @return float Predictive CLV.
	 */
	protected function calculate_predictive_clv( $historical_clv, $orders, $prediction_months ) {
		if ( empty( $orders ) ) {
			return 0;
		}

		// Calculate average monthly revenue.
		$first_order = end( $orders );
		$last_order  = reset( $orders );

		$first_date = $first_order->get_date_created();
		$last_date  = $last_order->get_date_created();

		if ( ! $first_date || ! $last_date ) {
			return 0;
		}

		$months_active = max( 1, ( $last_date->getTimestamp() - $first_date->getTimestamp() ) / ( 30 * DAY_IN_SECONDS ) );
		$monthly_avg   = $historical_clv / $months_active;

		// Simple projection.
		return $monthly_avg * $prediction_months;
	}

	/**
	 * Assess churn risk based on order patterns.
	 *
	 * @param array $orders Order objects.
	 * @return string Churn risk level.
	 */
	protected function assess_churn_risk( $orders ) {
		if ( empty( $orders ) ) {
			return 'high';
		}

		// Check days since last order.
		$last_order = reset( $orders );
		$last_date  = $last_order->get_date_created();

		if ( ! $last_date ) {
			return 'high';
		}

		$days_since_last = ( time() - $last_date->getTimestamp() ) / DAY_IN_SECONDS;

		if ( $days_since_last < 30 ) {
			return 'low';
		} elseif ( $days_since_last < 90 ) {
			return 'medium';
		} else {
			return 'high';
		}
	}
}
