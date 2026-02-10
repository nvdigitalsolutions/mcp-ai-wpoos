<?php
/**
 * Inventory Forecast Tool
 *
 * Predict inventory needs based on sales trends with reorder point
 * calculations and demand forecasting.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for inventory forecasting and demand prediction.
 *
 * Supports:
 * - Sales trend analysis
 * - Demand forecasting
 * - Reorder point calculation
 * - Stock out risk assessment
 * - Seasonal demand patterns
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Inventory_Forecast implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
			return __( 'Inventory forecasting requires WooCommerce to be installed and activated.', 'mcp-ai-wpoos-pro' );
		}

		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
			return __( 'E-commerce toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Inventory forecast tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'inventory_forecast';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Inventory Forecast', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Predict future inventory needs using sales trend analysis and demand forecasting. Calculate optimal reorder points, assess stock-out risks, and identify seasonal demand patterns for better inventory planning.', 'mcp-ai-wpoos-pro' );
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
				'forecast_type'        => array(
					'type'        => 'string',
					'description' => __( 'Type of forecast to generate', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'demand', 'reorder_points', 'stockout_risk', 'all' ),
					'default'     => 'all',
				),
				'product_id'           => array(
					'type'        => 'integer',
					'description' => __( 'Product ID for forecast', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'product_ids'          => array(
					'type'        => 'array',
					'description' => __( 'Product IDs for bulk forecast', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'integer' ),
				),
				'analysis_period_days' => array(
					'type'        => 'integer',
					'description' => __( 'Historical period to analyze (days)', 'mcp-ai-wpoos-pro' ),
					'default'     => 90,
					'minimum'     => 7,
					'maximum'     => 365,
				),
				'forecast_period_days' => array(
					'type'        => 'integer',
					'description' => __( 'Future period to forecast (days)', 'mcp-ai-wpoos-pro' ),
					'default'     => 30,
					'minimum'     => 7,
					'maximum'     => 180,
				),
				'lead_time_days'       => array(
					'type'        => 'integer',
					'description' => __( 'Supplier lead time in days', 'mcp-ai-wpoos-pro' ),
					'default'     => 14,
					'minimum'     => 1,
				),
				'safety_stock_days'    => array(
					'type'        => 'integer',
					'description' => __( 'Safety stock buffer in days', 'mcp-ai-wpoos-pro' ),
					'default'     => 7,
					'minimum'     => 0,
				),
				'category_ids'         => array(
					'type'        => 'array',
					'description' => __( 'Filter by category IDs', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'integer' ),
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
				__( 'You do not have permission to generate inventory forecasts.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if WooCommerce is active.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'woocommerce_not_active',
				self::get_unavailable_reason()
			);
		}

		$forecast_type = isset( $arguments['forecast_type'] ) ? sanitize_text_field( $arguments['forecast_type'] ) : 'all';

		// Determine products to forecast.
		$product_id  = isset( $arguments['product_id'] ) ? absint( $arguments['product_id'] ) : 0;
		$product_ids = isset( $arguments['product_ids'] ) && is_array( $arguments['product_ids'] ) ? array_map( 'absint', $arguments['product_ids'] ) : array();

		if ( $product_id ) {
			$product_ids = array( $product_id );
		}

		// If no specific products, get all products with stock management.
		if ( empty( $product_ids ) ) {
			$product_ids = $this->get_managed_stock_products( $arguments );
		}

		if ( empty( $product_ids ) ) {
			return new WP_Error(
				'no_products',
				__( 'No products found for forecasting.', 'mcp-ai-wpoos-pro' )
			);
		}

		$forecasts = array();
		foreach ( $product_ids as $pid ) {
			$forecast = $this->generate_product_forecast( $pid, $arguments );
			if ( ! is_wp_error( $forecast ) ) {
				$forecasts[] = $forecast;
			}
		}

		return array(
			'success'           => true,
			'forecast_type'     => $forecast_type,
			'products_analyzed' => count( $forecasts ),
			'forecasts'         => $forecasts,
			'message'           => sprintf(
				/* translators: %d: Number of products */
				__( 'Generated forecasts for %d products.', 'mcp-ai-wpoos-pro' ),
				count( $forecasts )
			),
		);
	}

	/**
	 * Get products with managed stock.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Product IDs.
	 */
	protected function get_managed_stock_products( $arguments ) {
		$category_ids = isset( $arguments['category_ids'] ) && is_array( $arguments['category_ids'] ) ? array_map( 'absint', $arguments['category_ids'] ) : array();

		$query_args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'meta_query'     => array(
				array(
					'key'     => '_manage_stock',
					'value'   => 'yes',
					'compare' => '=',
				),
			),
		);

		if ( ! empty( $category_ids ) ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $category_ids,
				),
			);
		}

		$products = get_posts( $query_args );
		return wp_list_pluck( $products, 'ID' );
	}

	/**
	 * Generate forecast for a product.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $arguments  Tool arguments.
	 * @return array|WP_Error Forecast data.
	 */
	protected function generate_product_forecast( $product_id, $arguments ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'product_not_found', __( 'Product not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$analysis_period   = isset( $arguments['analysis_period_days'] ) ? absint( $arguments['analysis_period_days'] ) : 90;
		$forecast_period   = isset( $arguments['forecast_period_days'] ) ? absint( $arguments['forecast_period_days'] ) : 30;
		$lead_time         = isset( $arguments['lead_time_days'] ) ? absint( $arguments['lead_time_days'] ) : 14;
		$safety_stock_days = isset( $arguments['safety_stock_days'] ) ? absint( $arguments['safety_stock_days'] ) : 7;
		$forecast_type     = isset( $arguments['forecast_type'] ) ? sanitize_text_field( $arguments['forecast_type'] ) : 'all';

		// Get sales history.
		$sales_history = $this->get_sales_history( $product_id, $analysis_period );

		// Calculate metrics.
		$current_stock   = $product->get_stock_quantity();
		$avg_daily_sales = $this->calculate_average_daily_sales( $sales_history );
		$sales_velocity  = $this->calculate_sales_velocity( $sales_history );

		$forecast = array(
			'product_id'    => $product_id,
			'product_name'  => $product->get_name(),
			'current_stock' => $current_stock,
			'analysis'      => array(
				'period_days'     => $analysis_period,
				'total_sold'      => array_sum( array_column( $sales_history, 'quantity' ) ),
				'avg_daily_sales' => $avg_daily_sales,
				'sales_velocity'  => $sales_velocity,
			),
		);

		// Add demand forecast.
		if ( in_array( $forecast_type, array( 'demand', 'all' ), true ) ) {
			$forecast['demand_forecast'] = $this->forecast_demand( $avg_daily_sales, $forecast_period, $sales_velocity );
		}

		// Add reorder points.
		if ( in_array( $forecast_type, array( 'reorder_points', 'all' ), true ) ) {
			$forecast['reorder_points'] = $this->calculate_reorder_points( $avg_daily_sales, $lead_time, $safety_stock_days );
		}

		// Add stockout risk.
		if ( in_array( $forecast_type, array( 'stockout_risk', 'all' ), true ) ) {
			$forecast['stockout_risk'] = $this->assess_stockout_risk( $current_stock, $avg_daily_sales, $lead_time );
		}

		return $forecast;
	}

	/**
	 * Get sales history for a product.
	 *
	 * @param int $product_id Product ID.
	 * @param int $days       Number of days.
	 * @return array Sales history.
	 */
	protected function get_sales_history( $product_id, $days ) {
		global $wpdb;

		$start_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					DATE(p.post_date) as date,
					SUM(oim_qty.meta_value) as quantity
				FROM {$wpdb->prefix}woocommerce_order_items oi
				INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_product ON oi.order_item_id = oim_product.order_item_id AND oim_product.meta_key = '_product_id'
				INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty ON oi.order_item_id = oim_qty.order_item_id AND oim_qty.meta_key = '_qty'
				INNER JOIN {$wpdb->posts} p ON oi.order_id = p.ID
				WHERE oim_product.meta_value = %d
				AND oi.order_item_type = 'line_item'
				AND p.post_type = 'shop_order'
				AND p.post_status IN ('wc-completed', 'wc-processing')
				AND p.post_date >= %s
				GROUP BY DATE(p.post_date)
				ORDER BY date ASC",
				$product_id,
				$start_date
			)
		);

		$history = array();
		foreach ( $results as $row ) {
			$history[] = array(
				'date'     => $row->date,
				'quantity' => absint( $row->quantity ),
			);
		}

		return $history;
	}

	/**
	 * Calculate average daily sales.
	 *
	 * @param array $sales_history Sales history.
	 * @return float Average daily sales.
	 */
	protected function calculate_average_daily_sales( $sales_history ) {
		if ( empty( $sales_history ) ) {
			return 0;
		}

		$total_quantity = array_sum( array_column( $sales_history, 'quantity' ) );
		$days_count     = count( $sales_history );

		return $days_count > 0 ? round( $total_quantity / $days_count, 2 ) : 0;
	}

	/**
	 * Calculate sales velocity (trend).
	 *
	 * @param array $sales_history Sales history.
	 * @return string Velocity indicator.
	 */
	protected function calculate_sales_velocity( $sales_history ) {
		if ( count( $sales_history ) < 14 ) {
			return 'insufficient_data';
		}

		// Compare first half vs second half.
		$mid_point   = (int) floor( count( $sales_history ) / 2 );
		$first_half  = array_slice( $sales_history, 0, $mid_point );
		$second_half = array_slice( $sales_history, $mid_point );

		$first_avg  = $this->calculate_average_daily_sales( $first_half );
		$second_avg = $this->calculate_average_daily_sales( $second_half );

		if ( 0.0 === $first_avg ) {
			return $second_avg > 0 ? 'increasing' : 'stable';
		}

		$change_percent = ( ( $second_avg - $first_avg ) / $first_avg ) * 100;

		if ( $change_percent > 20 ) {
			return 'increasing';
		} elseif ( $change_percent < -20 ) {
			return 'decreasing';
		} else {
			return 'stable';
		}
	}

	/**
	 * Forecast demand.
	 *
	 * @param float  $avg_daily_sales Average daily sales.
	 * @param int    $forecast_days   Forecast period.
	 * @param string $velocity        Sales velocity.
	 * @return array Demand forecast.
	 */
	protected function forecast_demand( $avg_daily_sales, $forecast_days, $velocity ) {
		// Apply velocity adjustment.
		$velocity_multiplier = 1.0;
		if ( 'increasing' === $velocity ) {
			$velocity_multiplier = 1.2; // 20% increase.
		} elseif ( 'decreasing' === $velocity ) {
			$velocity_multiplier = 0.8; // 20% decrease.
		}

		$forecasted_demand = $avg_daily_sales * $forecast_days * $velocity_multiplier;

		return array(
			'forecast_period_days' => $forecast_days,
			'forecasted_demand'    => round( $forecasted_demand, 0 ),
			'confidence'           => $this->calculate_confidence( $velocity ),
			'velocity_adjustment'  => $velocity_multiplier,
		);
	}

	/**
	 * Calculate reorder points.
	 *
	 * @param float $avg_daily_sales  Average daily sales.
	 * @param int   $lead_time        Lead time in days.
	 * @param int   $safety_stock_days Safety stock days.
	 * @return array Reorder points.
	 */
	protected function calculate_reorder_points( $avg_daily_sales, $lead_time, $safety_stock_days ) {
		$lead_time_demand = $avg_daily_sales * $lead_time;
		$safety_stock     = $avg_daily_sales * $safety_stock_days;
		$reorder_point    = $lead_time_demand + $safety_stock;

		return array(
			'reorder_point'     => round( $reorder_point, 0 ),
			'lead_time_demand'  => round( $lead_time_demand, 0 ),
			'safety_stock'      => round( $safety_stock, 0 ),
			'lead_time_days'    => $lead_time,
			'safety_stock_days' => $safety_stock_days,
			'recommendation'    => sprintf(
				/* translators: %d: Reorder point quantity */
				__( 'Reorder when stock reaches %d units', 'mcp-ai-wpoos-pro' ),
				round( $reorder_point, 0 )
			),
		);
	}

	/**
	 * Assess stockout risk.
	 *
	 * @param int   $current_stock   Current stock level.
	 * @param float $avg_daily_sales Average daily sales.
	 * @param int   $lead_time       Lead time in days.
	 * @return array Risk assessment.
	 */
	protected function assess_stockout_risk( $current_stock, $avg_daily_sales, $lead_time ) {
		if ( 0.0 === $avg_daily_sales ) {
			return array(
				'risk_level'     => 'low',
				'days_of_stock'  => 999,
				'stockout_date'  => null,
				'recommendation' => __( 'No sales activity detected', 'mcp-ai-wpoos-pro' ),
			);
		}

		$days_of_stock = $current_stock / $avg_daily_sales;
		$stockout_date = $days_of_stock > 0 ? gmdate( 'Y-m-d', strtotime( "+{$days_of_stock} days" ) ) : gmdate( 'Y-m-d' );

		// Determine risk level.
		$risk_level = 'low';
		if ( $days_of_stock < $lead_time ) {
			$risk_level = 'critical';
		} elseif ( $days_of_stock < $lead_time * 2 ) {
			$risk_level = 'high';
		} elseif ( $days_of_stock < $lead_time * 3 ) {
			$risk_level = 'medium';
		}

		$recommendations = array(
			'critical' => __( 'URGENT: Order immediately to avoid stockout', 'mcp-ai-wpoos-pro' ),
			'high'     => __( 'Place order soon to maintain safety stock', 'mcp-ai-wpoos-pro' ),
			'medium'   => __( 'Monitor closely and plan next order', 'mcp-ai-wpoos-pro' ),
			'low'      => __( 'Stock levels are healthy', 'mcp-ai-wpoos-pro' ),
		);

		return array(
			'risk_level'     => $risk_level,
			'days_of_stock'  => round( $days_of_stock, 1 ),
			'stockout_date'  => $stockout_date,
			'recommendation' => $recommendations[ $risk_level ],
		);
	}

	/**
	 * Calculate forecast confidence.
	 *
	 * @param string $velocity Sales velocity.
	 * @return string Confidence level.
	 */
	protected function calculate_confidence( $velocity ) {
		if ( 'stable' === $velocity ) {
			return 'high';
		} elseif ( 'insufficient_data' === $velocity ) {
			return 'low';
		} else {
			return 'medium';
		}
	}
}
