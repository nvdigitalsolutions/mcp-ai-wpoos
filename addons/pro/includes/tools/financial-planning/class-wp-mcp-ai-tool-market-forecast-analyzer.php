<?php
/**
 * Market Forecast Analyzer Tool
 *
 * Generates statistical time-series forecasts using linear regression,
 * moving average, and exponential smoothing methods with optional
 * sentiment-based adjustments and confidence intervals.
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
 * Tool for generating statistical time-series forecasts.
 *
 * Supports:
 * - Linear regression with R² calculation
 * - Simple moving average projection
 * - Exponential smoothing with configurable alpha
 * - Sentiment-based adjustment factors
 * - Confidence interval bands
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Market_Forecast_Analyzer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if financial planner toolkit is enabled.
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_financial_planner_toolkit'] );
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
		if ( empty( $settings['enable_financial_planner_toolkit'] ) ) {
			return __( 'Financial planner toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Market forecast analyzer tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'market_forecast_analyzer';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Market Forecast Analyzer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Generate statistical time-series forecasts from historical data using linear regression, moving average, or exponential smoothing. Includes confidence intervals and optional sentiment adjustments. EDUCATIONAL ONLY - Forecasts are statistical projections and not predictions. Not investment advice.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'historical_data'              => array(
					'type'        => 'array',
					'description' => __( 'Array of historical data points with date and value.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'date'  => array(
								'type'        => 'string',
								'description' => __( 'Date in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ),
							),
							'value' => array(
								'type'        => 'number',
								'description' => __( 'Numeric value for this date.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
				'forecast_periods'             => array(
					'type'        => 'integer',
					'description' => __( 'Number of periods to forecast.', 'mcp-ai-wpoos-pro' ),
					'default'     => 7,
					'minimum'     => 1,
					'maximum'     => 90,
				),
				'method'                       => array(
					'type'        => 'string',
					'description' => __( 'Forecasting method to use.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'linear_regression', 'moving_average', 'exponential_smoothing' ),
					'default'     => 'linear_regression',
				),
				'moving_average_window'        => array(
					'type'        => 'integer',
					'description' => __( 'Window size for moving average method.', 'mcp-ai-wpoos-pro' ),
					'default'     => 5,
					'minimum'     => 2,
					'maximum'     => 50,
				),
				'smoothing_factor'             => array(
					'type'        => 'number',
					'description' => __( 'Alpha parameter for exponential smoothing (0.0-1.0).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0.3,
					'minimum'     => 0.0,
					'maximum'     => 1.0,
				),
				'sentiment_adjustment'         => array(
					'type'        => 'number',
					'description' => __( 'Sentiment-based adjustment factor (-1.0 to +1.0).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
					'minimum'     => -1.0,
					'maximum'     => 1.0,
				),
				'include_confidence_intervals' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include confidence interval bands.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'historical_data' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'computation',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @since 1.1.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use the forecast analyzer.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$historical_data = isset( $arguments['historical_data'] ) && is_array( $arguments['historical_data'] ) ? $arguments['historical_data'] : array();

		if ( count( $historical_data ) < 3 ) {
			return new WP_Error(
				'insufficient_data',
				__( 'At least 3 historical data points are required for forecasting.', 'mcp-ai-wpoos-pro' )
			);
		}

		$forecast_periods = isset( $arguments['forecast_periods'] ) ? min( absint( $arguments['forecast_periods'] ), 90 ) : 7;
		$method           = isset( $arguments['method'] ) ? sanitize_text_field( $arguments['method'] ) : 'linear_regression';
		$ma_window        = isset( $arguments['moving_average_window'] ) ? max( 2, min( absint( $arguments['moving_average_window'] ), 50 ) ) : 5;
		$alpha            = isset( $arguments['smoothing_factor'] ) ? max( 0.0, min( floatval( $arguments['smoothing_factor'] ), 1.0 ) ) : 0.3;
		$sentiment_adj    = isset( $arguments['sentiment_adjustment'] ) ? max( -1.0, min( floatval( $arguments['sentiment_adjustment'] ), 1.0 ) ) : 0.0;
		$include_ci       = isset( $arguments['include_confidence_intervals'] ) ? (bool) $arguments['include_confidence_intervals'] : true;

		if ( $forecast_periods < 1 ) {
			$forecast_periods = 7;
		}

		// Parse and validate historical data.
		$values = array();
		$dates  = array();
		foreach ( $historical_data as $point ) {
			$date  = isset( $point['date'] ) ? sanitize_text_field( $point['date'] ) : '';
			$value = isset( $point['value'] ) ? floatval( $point['value'] ) : null;

			if ( empty( $date ) || null === $value ) {
				continue;
			}

			$dates[]  = $date;
			$values[] = $value;
		}

		if ( count( $values ) < 3 ) {
			return new WP_Error(
				'insufficient_valid_data',
				__( 'At least 3 valid data points (with date and value) are required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$valid_methods = array( 'linear_regression', 'moving_average', 'exponential_smoothing' );
		if ( ! in_array( $method, $valid_methods, true ) ) {
			$method = 'linear_regression';
		}

		// Calculate statistics.
		$stats = $this->calculate_statistics( $values );

		// Generate forecast.
		switch ( $method ) {
			case 'moving_average':
				$forecast = $this->forecast_moving_average( $values, $dates, $forecast_periods, $ma_window );
				break;

			case 'exponential_smoothing':
				$forecast = $this->forecast_exponential_smoothing( $values, $dates, $forecast_periods, $alpha );
				break;

			default:
				$forecast = $this->forecast_linear_regression( $values, $dates, $forecast_periods );
				break;
		}

		// Apply sentiment adjustment.
		if ( 0.0 !== $sentiment_adj ) {
			$forecast = $this->apply_sentiment_adjustment( $forecast, $sentiment_adj );
		}

		// Add confidence intervals.
		if ( $include_ci ) {
			$forecast = $this->add_confidence_intervals( $forecast, $stats['std_dev'] );
		}

		// Determine trend.
		$trend_direction = $this->determine_trend_direction( $values );
		$trend_strength  = $this->determine_trend_strength( $values );

		return array(
			'success'          => true,
			'method'           => $method,
			'data_points'      => count( $values ),
			'forecast_periods' => $forecast_periods,
			'forecast'         => $forecast,
			'statistics'       => $stats,
			'trend'            => array(
				'direction' => $trend_direction,
				'strength'  => $trend_strength,
			),
			'parameters'       => array(
				'method'                => $method,
				'moving_average_window' => $ma_window,
				'smoothing_factor'      => $alpha,
				'sentiment_adjustment'  => $sentiment_adj,
			),
			'disclaimer'       => __( 'EDUCATIONAL ONLY. These forecasts are purely statistical projections based on historical data and do NOT predict actual future market performance. Past performance does not guarantee future results. Markets are inherently unpredictable. Do not make investment decisions based solely on these projections. Consult a licensed financial advisor. Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Calculate basic statistics for a data set.
	 *
	 * @since 1.1.0
	 *
	 * @param array $values Array of numeric values.
	 * @return array Statistics array with mean, std_dev, min, max, volatility.
	 */
	private function calculate_statistics( $values ) {
		$count = count( $values );
		$mean  = array_sum( $values ) / $count;

		$variance = 0;
		foreach ( $values as $v ) {
			$variance += pow( $v - $mean, 2 );
		}
		$variance = $variance / $count;
		$std_dev  = sqrt( $variance );

		// Calculate daily returns for volatility.
		$returns = array();
		for ( $i = 1; $i < $count; $i++ ) {
			if ( $values[ $i - 1 ] > 0 ) {
				$returns[] = ( $values[ $i ] - $values[ $i - 1 ] ) / $values[ $i - 1 ];
			}
		}

		$volatility = 0;
		if ( ! empty( $returns ) ) {
			$ret_mean = array_sum( $returns ) / count( $returns );
			$ret_var  = 0;
			foreach ( $returns as $r ) {
				$ret_var += pow( $r - $ret_mean, 2 );
			}
			$volatility = sqrt( $ret_var / count( $returns ) );
		}

		return array(
			'mean'       => round( $mean, 4 ),
			'std_dev'    => round( $std_dev, 4 ),
			'min'        => round( min( $values ), 4 ),
			'max'        => round( max( $values ), 4 ),
			'volatility' => round( $volatility, 4 ),
			'count'      => $count,
		);
	}

	/**
	 * Generate forecast using linear regression (least squares).
	 *
	 * @since 1.1.0
	 *
	 * @param array $values           Numeric values.
	 * @param array $dates            Date strings.
	 * @param int   $forecast_periods Number of periods to forecast.
	 * @return array Forecast data with r_squared.
	 */
	private function forecast_linear_regression( $values, $dates, $forecast_periods ) {
		$n      = count( $values );
		$sum_x  = 0;
		$sum_y  = 0;
		$sum_xy = 0;
		$sum_xx = 0;

		for ( $i = 0; $i < $n; $i++ ) {
			$x       = $i;
			$y       = $values[ $i ];
			$sum_x  += $x;
			$sum_y  += $y;
			$sum_xy += $x * $y;
			$sum_xx += $x * $x;
		}

		$denominator = ( $n * $sum_xx ) - ( $sum_x * $sum_x );
		if ( abs( $denominator ) < 0.0001 ) {
			$slope     = 0;
			$intercept = $sum_y / $n;
		} else {
			$slope     = ( ( $n * $sum_xy ) - ( $sum_x * $sum_y ) ) / $denominator;
			$intercept = ( $sum_y - ( $slope * $sum_x ) ) / $n;
		}

		// Calculate R² value.
		$mean   = $sum_y / $n;
		$ss_res = 0;
		$ss_tot = 0;
		for ( $i = 0; $i < $n; $i++ ) {
			$predicted = $intercept + ( $slope * $i );
			$ss_res   += pow( $values[ $i ] - $predicted, 2 );
			$ss_tot   += pow( $values[ $i ] - $mean, 2 );
		}
		$r_squared = $ss_tot > 0 ? 1 - ( $ss_res / $ss_tot ) : 0;

		// Generate forecast points.
		$forecast  = array();
		$last_date = end( $dates );
		for ( $i = 0; $i < $forecast_periods; $i++ ) {
			$x_val      = $n + $i;
			$predicted  = $intercept + ( $slope * $x_val );
			$forecast[] = array(
				'period'         => $i + 1,
				'date'           => $this->add_days_to_date( $last_date, $i + 1 ),
				'forecast_value' => round( $predicted, 4 ),
			);
		}

		return array(
			'points' => $forecast,
			'model'  => array(
				'slope'     => round( $slope, 6 ),
				'intercept' => round( $intercept, 4 ),
				'r_squared' => round( $r_squared, 4 ),
			),
		);
	}

	/**
	 * Generate forecast using moving average.
	 *
	 * @since 1.1.0
	 *
	 * @param array $values           Numeric values.
	 * @param array $dates            Date strings.
	 * @param int   $forecast_periods Number of periods to forecast.
	 * @param int   $window           Moving average window size.
	 * @return array Forecast data.
	 */
	private function forecast_moving_average( $values, $dates, $forecast_periods, $window ) {
		$n                = count( $values );
		$effective_window = min( $window, $n );

		// Calculate the last moving average.
		$last_values = array_slice( $values, -$effective_window );
		$ma_value    = array_sum( $last_values ) / count( $last_values );

		$forecast  = array();
		$last_date = end( $dates );
		for ( $i = 0; $i < $forecast_periods; $i++ ) {
			$forecast[] = array(
				'period'         => $i + 1,
				'date'           => $this->add_days_to_date( $last_date, $i + 1 ),
				'forecast_value' => round( $ma_value, 4 ),
			);
		}

		return array(
			'points' => $forecast,
			'model'  => array(
				'window'        => $effective_window,
				'last_ma_value' => round( $ma_value, 4 ),
			),
		);
	}

	/**
	 * Generate forecast using exponential smoothing.
	 *
	 * @since 1.1.0
	 *
	 * @param array $values           Numeric values.
	 * @param array $dates            Date strings.
	 * @param int   $forecast_periods Number of periods to forecast.
	 * @param float $alpha            Smoothing factor (0.0-1.0).
	 * @return array Forecast data.
	 */
	private function forecast_exponential_smoothing( $values, $dates, $forecast_periods, $alpha ) {
		$n = count( $values );

		// Initialize smoothed value with first observation.
		$smoothed = $values[0];

		// Apply exponential smoothing through the data.
		for ( $i = 1; $i < $n; $i++ ) {
			$smoothed = ( $alpha * $values[ $i ] ) + ( ( 1 - $alpha ) * $smoothed );
		}

		$forecast  = array();
		$last_date = end( $dates );
		for ( $i = 0; $i < $forecast_periods; $i++ ) {
			$forecast[] = array(
				'period'         => $i + 1,
				'date'           => $this->add_days_to_date( $last_date, $i + 1 ),
				'forecast_value' => round( $smoothed, 4 ),
			);
		}

		return array(
			'points' => $forecast,
			'model'  => array(
				'alpha'          => $alpha,
				'smoothed_value' => round( $smoothed, 4 ),
			),
		);
	}

	/**
	 * Apply sentiment adjustment to forecast values.
	 *
	 * @since 1.1.0
	 *
	 * @param array $forecast       Forecast data with 'points' array.
	 * @param float $sentiment_adj  Adjustment factor (-1.0 to 1.0).
	 * @return array Adjusted forecast.
	 */
	private function apply_sentiment_adjustment( $forecast, $sentiment_adj ) {
		// Sentiment adjustment modifies forecast values by a percentage.
		$adjustment_pct = $sentiment_adj * 0.05; // Max ±5% adjustment.

		foreach ( $forecast['points'] as &$point ) {
			$point['forecast_value_unadjusted'] = $point['forecast_value'];
			$point['forecast_value']            = round( $point['forecast_value'] * ( 1 + $adjustment_pct ), 4 );
			$point['sentiment_adjustment']      = round( $adjustment_pct * 100, 2 );
		}

		return $forecast;
	}

	/**
	 * Add confidence interval bands to forecast points.
	 *
	 * @since 1.1.0
	 *
	 * @param array $forecast Forecast data with 'points' array.
	 * @param float $std_dev  Standard deviation from historical data.
	 * @return array Forecast with confidence intervals.
	 */
	private function add_confidence_intervals( $forecast, $std_dev ) {
		foreach ( $forecast['points'] as &$point ) {
			$period = $point['period'];
			// Confidence intervals widen over time.
			$ci_width = $std_dev * sqrt( $period ) * 1.96; // 95% CI.

			$point['confidence_interval'] = array(
				'lower_95' => round( $point['forecast_value'] - $ci_width, 4 ),
				'upper_95' => round( $point['forecast_value'] + $ci_width, 4 ),
				'lower_68' => round( $point['forecast_value'] - ( $ci_width / 1.96 ), 4 ),
				'upper_68' => round( $point['forecast_value'] + ( $ci_width / 1.96 ), 4 ),
			);
		}

		return $forecast;
	}

	/**
	 * Determine trend direction from values.
	 *
	 * @since 1.1.0
	 *
	 * @param array $values Numeric values.
	 * @return string 'upward', 'downward', or 'sideways'.
	 */
	private function determine_trend_direction( $values ) {
		$n = count( $values );
		if ( $n < 2 ) {
			return 'sideways';
		}

		$first_half  = array_slice( $values, 0, (int) floor( $n / 2 ) );
		$second_half = array_slice( $values, (int) floor( $n / 2 ) );

		$first_avg  = array_sum( $first_half ) / count( $first_half );
		$second_avg = array_sum( $second_half ) / count( $second_half );

		$diff_pct = $first_avg > 0 ? ( ( $second_avg - $first_avg ) / $first_avg ) * 100 : 0;

		if ( $diff_pct > 2 ) {
			return 'upward';
		}
		if ( $diff_pct < -2 ) {
			return 'downward';
		}
		return 'sideways';
	}

	/**
	 * Determine trend strength.
	 *
	 * @since 1.1.0
	 *
	 * @param array $values Numeric values.
	 * @return string 'strong', 'moderate', or 'weak'.
	 */
	private function determine_trend_strength( $values ) {
		$n = count( $values );
		if ( $n < 2 ) {
			return 'weak';
		}

		// Calculate consistency of direction changes.
		$up_count   = 0;
		$down_count = 0;
		for ( $i = 1; $i < $n; $i++ ) {
			if ( $values[ $i ] > $values[ $i - 1 ] ) {
				++$up_count;
			} elseif ( $values[ $i ] < $values[ $i - 1 ] ) {
				++$down_count;
			}
		}

		$total   = $up_count + $down_count;
		$max_dir = max( $up_count, $down_count );

		if ( $total > 0 ) {
			$consistency = $max_dir / $total;
			if ( $consistency > 0.8 ) {
				return 'strong';
			}
			if ( $consistency > 0.6 ) {
				return 'moderate';
			}
		}

		return 'weak';
	}

	/**
	 * Add days to a date string.
	 *
	 * @since 1.1.0
	 *
	 * @param string $date Date in YYYY-MM-DD format.
	 * @param int    $days Number of days to add.
	 * @return string New date in YYYY-MM-DD format.
	 */
	private function add_days_to_date( $date, $days ) {
		$timestamp = strtotime( $date );
		if ( false === $timestamp ) {
			$timestamp = current_time( 'timestamp' );
		}
		return gmdate( 'Y-m-d', $timestamp + ( $days * DAY_IN_SECONDS ) );
	}
}
