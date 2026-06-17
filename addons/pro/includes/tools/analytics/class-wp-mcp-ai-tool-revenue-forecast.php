<?php
/**
 * Revenue Forecast Tool
 *
 * Predicts future revenue based on historical trends using
 * time series analysis and machine learning techniques.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for predicting future revenue.
 *
 * Supports:
 * - Linear regression forecasting
 * - Moving average analysis
 * - Seasonal trend decomposition
 * - Confidence intervals
 * - Multiple forecast periods
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Revenue_Forecast implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if analytics toolkit is enabled.
	 */
	public static function is_available() {
		// Check if base version.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		// Check if analytics toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_analytics_toolkit'] );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_analytics_toolkit'] ) ) {
			return __( 'Advanced Analytics toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Revenue forecast tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool slug.
	 */
	public function get_slug() {
		return 'revenue_forecast';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool name.
	 */
	public function get_name() {
		return __( 'Revenue Forecast', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool description.
	 */
	public function get_description() {
		return __( 'Predict future revenue based on historical trends using time series analysis. Supports linear regression, moving averages, seasonal decomposition, and confidence intervals.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool parameters schema.
	 *
	 * @since 1.1.0
	 *
	 * @return array Parameters schema.
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'forecast_period'  => array(
					'type'        => 'string',
					'description' => 'Period to forecast: daily, weekly, monthly, quarterly, yearly',
					'enum'        => array( 'daily', 'weekly', 'monthly', 'quarterly', 'yearly' ),
					'default'     => 'monthly',
				),
				'periods_ahead'    => array(
					'type'        => 'integer',
					'description' => 'Number of periods to forecast ahead',
					'minimum'     => 1,
					'maximum'     => 365,
					'default'     => 3,
				),
				'historical_days'  => array(
					'type'        => 'integer',
					'description' => 'Days of historical data to analyze',
					'minimum'     => 30,
					'maximum'     => 730,
					'default'     => 365,
				),
				'method'           => array(
					'type'        => 'string',
					'description' => 'Forecasting method: linear, moving_average, seasonal',
					'enum'        => array( 'linear', 'moving_average', 'seasonal' ),
					'default'     => 'linear',
				),
				'confidence_level' => array(
					'type'        => 'number',
					'description' => 'Confidence level for prediction interval (0-1)',
					'minimum'     => 0.5,
					'maximum'     => 0.99,
					'default'     => 0.95,
				),
				'source'           => array(
					'type'        => 'string',
					'description' => 'Revenue source: all, products, subscriptions, services',
					'enum'        => array( 'all', 'products', 'subscriptions', 'services' ),
					'default'     => 'all',
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * Get required capability.
	 *
	 * @since 1.1.0
	 *
	 * @return string Required capability.
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * Get capability flags.
	 *
	 * @since 1.1.0
	 *
	 * @return array Capability flags.
	 */
	public function get_capability_flags() {
		return array(
			'analytics'  => true,
			'predictive' => true,
			'read_only'  => true,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @since 1.1.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool result or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Parse arguments.
		$forecast_period  = ! empty( $arguments['forecast_period'] ) ? sanitize_text_field( $arguments['forecast_period'] ) : 'monthly';
		$periods_ahead    = isset( $arguments['periods_ahead'] ) ? absint( $arguments['periods_ahead'] ) : 3;
		$historical_days  = isset( $arguments['historical_days'] ) ? absint( $arguments['historical_days'] ) : 365;
		$method           = ! empty( $arguments['method'] ) ? sanitize_text_field( $arguments['method'] ) : 'linear';
		$confidence_level = isset( $arguments['confidence_level'] ) ? floatval( $arguments['confidence_level'] ) : 0.95;
		$source           = ! empty( $arguments['source'] ) ? sanitize_text_field( $arguments['source'] ) : 'all';

		// Validate periods_ahead.
		if ( $periods_ahead < 1 || $periods_ahead > 365 ) {
			return new WP_Error(
				'invalid_periods',
				__( 'Periods ahead must be between 1 and 365.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate historical_days.
		if ( $historical_days < 30 || $historical_days > 730 ) {
			return new WP_Error(
				'invalid_historical_days',
				__( 'Historical days must be between 30 and 730.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate confidence_level.
		if ( $confidence_level < 0.5 || $confidence_level > 0.99 ) {
			return new WP_Error(
				'invalid_confidence_level',
				__( 'Confidence level must be between 0.5 and 0.99.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Collect historical data.
		$historical_data = $this->collect_historical_revenue( $historical_days, $forecast_period, $source );

		if ( is_wp_error( $historical_data ) ) {
			return $historical_data;
		}

		if ( empty( $historical_data ) ) {
			return new WP_Error(
				'insufficient_data',
				__( 'Insufficient historical data for forecasting.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Generate forecast.
		$forecast = $this->generate_forecast( $historical_data, $periods_ahead, $method, $confidence_level );

		if ( is_wp_error( $forecast ) ) {
			return $forecast;
		}

		// Prepare response.
		return array(
			'success'      => true,
			'forecast'     => $forecast,
			'historical'   => array(
				'data_points' => count( $historical_data ),
				'period'      => $forecast_period,
				'days'        => $historical_days,
			),
			'method'       => $method,
			'confidence'   => $confidence_level,
			'source'       => $source,
			'generated_at' => current_time( 'mysql' ),
			'message'      => sprintf(
				/* translators: 1: forecast period, 2: periods ahead */
				__( 'Generated %1$s forecast for next %2$d periods.', 'mcp-ai-wpoos-pro' ),
				$forecast_period,
				$periods_ahead
			),
		);
	}

	/**
	 * Collect historical revenue data.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $days   Number of days to collect.
	 * @param string $period Aggregation period.
	 * @param string $source Revenue source.
	 * @return array|WP_Error Historical data or error.
	 */
	private function collect_historical_revenue( $days, $period, $source ) {
		global $wpdb;

		$start_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$end_date   = current_time( 'Y-m-d' );

		// Determine date grouping format.
		$date_format = $this->get_date_format_sql( $period );

		// Build query based on source.
		$query = "
			SELECT 
				DATE_FORMAT(p.post_date, '{$date_format}') as period,
				SUM(CAST(pm.meta_value AS DECIMAL(10,2))) as revenue,
				COUNT(DISTINCT p.ID) as order_count
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = 'shop_order'
				AND p.post_status IN ('wc-completed', 'wc-processing')
				AND p.post_date BETWEEN %s AND %s
				AND pm.meta_key = '_order_total'
		";

		// Add source filter if needed.
		if ( 'products' === $source ) {
			$query .= " AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm2 
				WHERE pm2.post_id = p.ID 
				AND pm2.meta_key LIKE '_subscription_%'
			)";
		} elseif ( 'subscriptions' === $source ) {
			$query .= " AND EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm2 
				WHERE pm2.post_id = p.ID 
				AND pm2.meta_key LIKE '_subscription_%'
			)";
		}

		$query .= ' GROUP BY period ORDER BY period ASC';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $wpdb->prepare( $query, $start_date, $end_date ), ARRAY_A );

		if ( ! $results ) {
			return array();
		}

		return $results;
	}

	/**
	 * Get SQL date format for period.
	 *
	 * @since 1.1.0
	 *
	 * @param string $period Period type.
	 * @return string SQL date format.
	 */
	private function get_date_format_sql( $period ) {
		$formats = array(
			'daily'     => '%Y-%m-%d',
			'weekly'    => '%Y-%u',
			'monthly'   => '%Y-%m',
			'quarterly' => '%Y-Q%q',
			'yearly'    => '%Y',
		);

		return isset( $formats[ $period ] ) ? $formats[ $period ] : '%Y-%m';
	}

	/**
	 * Generate forecast using specified method.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $historical_data Historical data points.
	 * @param int    $periods_ahead   Periods to forecast.
	 * @param string $method          Forecasting method.
	 * @param float  $confidence      Confidence level.
	 * @return array|WP_Error Forecast data or error.
	 */
	private function generate_forecast( $historical_data, $periods_ahead, $method, $confidence ) {
		switch ( $method ) {
			case 'moving_average':
				return $this->forecast_moving_average( $historical_data, $periods_ahead, $confidence );

			case 'seasonal':
				return $this->forecast_seasonal( $historical_data, $periods_ahead, $confidence );

			case 'linear':
			default:
				return $this->forecast_linear_regression( $historical_data, $periods_ahead, $confidence );
		}
	}

	/**
	 * Linear regression forecast.
	 *
	 * @since 1.1.0
	 *
	 * @param array $data           Historical data.
	 * @param int   $periods_ahead  Periods to forecast.
	 * @param float $confidence     Confidence level.
	 * @return array Forecast data.
	 */
	private function forecast_linear_regression( $data, $periods_ahead, $confidence ) {
		$n = count( $data );

		// Extract revenue values.
		$revenues = array_column( $data, 'revenue' );
		$x_values = range( 1, $n );

		// Calculate regression coefficients.
		$sum_x  = array_sum( $x_values );
		$sum_y  = array_sum( $revenues );
		$sum_xy = 0;
		$sum_x2 = 0;

		$n_count = $n;
		for ( $i = 0; $i < $n_count; $i++ ) {
			$sum_xy += $x_values[ $i ] * $revenues[ $i ];
			$sum_x2 += $x_values[ $i ] * $x_values[ $i ];
		}

		$slope     = ( $n * $sum_xy - $sum_x * $sum_y ) / ( $n * $sum_x2 - $sum_x * $sum_x );
		$intercept = ( $sum_y - $slope * $sum_x ) / $n;

		// Calculate standard error.
		$residuals = array();
		$n_count   = $n;
		for ( $i = 0; $i < $n_count; $i++ ) {
			$predicted   = $slope * $x_values[ $i ] + $intercept;
			$residuals[] = $revenues[ $i ] - $predicted;
		}

		$std_error = sqrt( array_sum( array_map( fn( $r ) => $r * $r, $residuals ) ) / ( $n - 2 ) );

		// Z-score for confidence level.
		$z_score = $this->get_z_score( $confidence );

		// Generate forecasts.
		$forecasts = array();
		for ( $i = 1; $i <= $periods_ahead; $i++ ) {
			$x               = $n + $i;
			$predicted       = $slope * $x + $intercept;
			$margin_of_error = $z_score * $std_error * sqrt( 1 + 1 / $n + pow( $x - array_sum( $x_values ) / $n, 2 ) / $sum_x2 );

			$forecasts[] = array(
				'period'      => $i,
				'predicted'   => round( max( 0, $predicted ), 2 ),
				'lower_bound' => round( max( 0, $predicted - $margin_of_error ), 2 ),
				'upper_bound' => round( $predicted + $margin_of_error, 2 ),
				'confidence'  => $confidence,
			);
		}

		return $forecasts;
	}

	/**
	 * Moving average forecast.
	 *
	 * @since 1.1.0
	 *
	 * @param array $data           Historical data.
	 * @param int   $periods_ahead  Periods to forecast.
	 * @param float $confidence     Confidence level.
	 * @return array Forecast data.
	 */
	private function forecast_moving_average( $data, $periods_ahead, $confidence ) {
		$window_size = min( 12, count( $data ) );
		$revenues    = array_column( $data, 'revenue' );

		// Calculate moving average.
		$last_revenues = array_slice( $revenues, -$window_size );
		$average       = array_sum( $last_revenues ) / count( $last_revenues );

		// Calculate standard deviation.
		$variance = array_sum( array_map( fn( $r ) => pow( $r - $average, 2 ), $last_revenues ) ) / count( $last_revenues );
		$std_dev  = sqrt( $variance );

		// Z-score for confidence level.
		$z_score = $this->get_z_score( $confidence );

		// Generate forecasts.
		$forecasts = array();
		for ( $i = 1; $i <= $periods_ahead; $i++ ) {
			$margin_of_error = $z_score * $std_dev;

			$forecasts[] = array(
				'period'      => $i,
				'predicted'   => round( max( 0, $average ), 2 ),
				'lower_bound' => round( max( 0, $average - $margin_of_error ), 2 ),
				'upper_bound' => round( $average + $margin_of_error, 2 ),
				'confidence'  => $confidence,
			);
		}

		return $forecasts;
	}

	/**
	 * Seasonal forecast with trend decomposition.
	 *
	 * @since 1.1.0
	 *
	 * @param array $data           Historical data.
	 * @param int   $periods_ahead  Periods to forecast.
	 * @param float $confidence     Confidence level.
	 * @return array Forecast data.
	 */
	private function forecast_seasonal( $data, $periods_ahead, $confidence ) {
		$revenues = array_column( $data, 'revenue' );
		$n        = count( $revenues );

		// Calculate seasonal period (assume 12 for monthly, 4 for quarterly).
		$seasonal_period = min( 12, $n );

		// Calculate trend (moving average).
		$trend   = array();
		$n_count = $n;
		for ( $i = 0; $i < $n_count; $i++ ) {
			$start   = max( 0, $i - floor( $seasonal_period / 2 ) );
			$end     = min( $n, $i + ceil( $seasonal_period / 2 ) );
			$window  = array_slice( $revenues, $start, $end - $start );
			$trend[] = array_sum( $window ) / count( $window );
		}

		// Calculate seasonal indices.
		$seasonal_indices = array();
		for ( $i = 0; $i < $seasonal_period; $i++ ) {
			$season_values = array();
			for ( $j = $i; $j < $n_count; $j += $seasonal_period ) {
				if ( isset( $trend[ $j ] ) && $trend[ $j ] > 0 ) {
					$season_values[] = $revenues[ $j ] / $trend[ $j ];
				}
			}
			$seasonal_indices[ $i ] = ! empty( $season_values ) ? array_sum( $season_values ) / count( $season_values ) : 1;
		}

		// Calculate trend slope.
		$last_trend_values = array_slice( $trend, -min( 6, count( $trend ) ) );
		$trend_slope       = ( end( $last_trend_values ) - reset( $last_trend_values ) ) / count( $last_trend_values );

		// Generate forecasts.
		$last_trend = end( $trend );
		$z_score    = $this->get_z_score( $confidence );
		$std_dev    = $this->calculate_std_dev( $revenues );

		$forecasts = array();
		for ( $i = 1; $i <= $periods_ahead; $i++ ) {
			$predicted_trend = $last_trend + ( $trend_slope * $i );
			$seasonal_index  = $seasonal_indices[ ( $n + $i - 1 ) % $seasonal_period ];
			$predicted       = $predicted_trend * $seasonal_index;
			$margin_of_error = $z_score * $std_dev;

			$forecasts[] = array(
				'period'       => $i,
				'predicted'    => round( max( 0, $predicted ), 2 ),
				'lower_bound'  => round( max( 0, $predicted - $margin_of_error ), 2 ),
				'upper_bound'  => round( $predicted + $margin_of_error, 2 ),
				'confidence'   => $confidence,
				'seasonal_idx' => round( $seasonal_index, 3 ),
			);
		}

		return $forecasts;
	}

	/**
	 * Get Z-score for confidence level.
	 *
	 * @since 1.1.0
	 *
	 * @param float $confidence Confidence level (0-1).
	 * @return float Z-score.
	 */
	private function get_z_score( $confidence ) {
		// Common confidence levels to z-scores.
		$z_scores = array(
			'0.90' => 1.645,
			'0.95' => 1.96,
			'0.99' => 2.576,
		);

		// Find closest match.
		$closest  = '0.95';
		$min_diff = abs( $confidence - (float) $closest );

		foreach ( array_keys( $z_scores ) as $level ) {
			$diff = abs( $confidence - (float) $level );
			if ( $diff < $min_diff ) {
				$closest  = $level;
				$min_diff = $diff;
			}
		}

		return $z_scores[ $closest ];
	}

	/**
	 * Calculate standard deviation.
	 *
	 * @since 1.1.0
	 *
	 * @param array $values Array of values.
	 * @return float Standard deviation.
	 */
	private function calculate_std_dev( $values ) {
		$count = count( $values );
		if ( $count < 2 ) {
			return 0;
		}

		$mean     = array_sum( $values ) / $count;
		$variance = array_sum( array_map( fn( $v ) => pow( $v - $mean, 2 ), $values ) ) / $count;

		return sqrt( $variance );
	}
}
