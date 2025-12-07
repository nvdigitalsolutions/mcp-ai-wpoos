<?php
/**
 * Analytics Engine for WP oOS Token Manager.
 *
 * Provides advanced statistical analysis, trend detection, and pattern recognition
 * for token usage data.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analytics Engine class.
 *
 * This class provides advanced analytics features including:
 * - Trend analysis with linear regression
 * - Pattern recognition (daily, weekly, monthly cycles)
 * - Comparative analysis between users and tools
 * - Statistical insights (mean, standard deviation, Z-scores)
 */
class WP_MCP_AI_Analytics_Engine {

	/**
	 * Calculate linear regression for usage trend.
	 *
	 * Returns slope, intercept, and R-squared value for the trend line.
	 *
	 * @param array $data_points Array of [ timestamp => value ] pairs.
	 * @return array {
	 *     Regression analysis results.
	 *
	 *     @type float  $slope       Slope of the trend line (tokens per day).
	 *     @type float  $intercept   Y-intercept of the trend line.
	 *     @type float  $r_squared   Coefficient of determination (0-1).
	 *     @type string $direction   Trend direction: 'increasing', 'decreasing', or 'stable'.
	 *     @type int    $confidence  Confidence percentage (0-100).
	 * }
	 */
	public static function calculate_trend( $data_points ) {
		if ( empty( $data_points ) || count( $data_points ) < 2 ) {
			return array(
				'slope'      => 0,
				'intercept'  => 0,
				'r_squared'  => 0,
				'direction'  => 'stable',
				'confidence' => 0,
			);
		}

		// Convert timestamps to sequential days for regression.
		$timestamps = array_keys( $data_points );
		sort( $timestamps );
		$base_time = $timestamps[0];

		$x_values = array();
		$y_values = array();

		foreach ( $data_points as $timestamp => $value ) {
			$days_diff  = ( $timestamp - $base_time ) / DAY_IN_SECONDS;
			$x_values[] = $days_diff;
			$y_values[] = (float) $value;
		}

		$n = count( $x_values );

		// Calculate means.
		$mean_x = array_sum( $x_values ) / $n;
		$mean_y = array_sum( $y_values ) / $n;

		// Calculate slope and intercept.
		$numerator   = 0;
		$denominator = 0;

		for ( $i = 0; $i < $n; $i++ ) {
			$numerator   += ( $x_values[ $i ] - $mean_x ) * ( $y_values[ $i ] - $mean_y );
			$denominator += pow( $x_values[ $i ] - $mean_x, 2 );
		}

		$slope     = ( $denominator != 0 ) ? ( $numerator / $denominator ) : 0;
		$intercept = $mean_y - ( $slope * $mean_x );

		// Calculate R-squared.
		$ss_tot = 0;
		$ss_res = 0;

		for ( $i = 0; $i < $n; $i++ ) {
			$predicted = $slope * $x_values[ $i ] + $intercept;
			$ss_tot   += pow( $y_values[ $i ] - $mean_y, 2 );
			$ss_res   += pow( $y_values[ $i ] - $predicted, 2 );
		}

		$r_squared = ( $ss_tot != 0 ) ? ( 1 - ( $ss_res / $ss_tot ) ) : 0;
		$r_squared = max( 0, min( 1, $r_squared ) ); // Clamp to 0-1.

		// Determine trend direction.
		$direction = 'stable';
		if ( abs( $slope ) > 100 ) { // Significant change threshold.
			$direction = ( $slope > 0 ) ? 'increasing' : 'decreasing';
		}

		// Calculate confidence (R-squared as percentage).
		$confidence = (int) round( $r_squared * 100 );

		return array(
			'slope'      => round( $slope, 2 ),
			'intercept'  => round( $intercept, 2 ),
			'r_squared'  => round( $r_squared, 4 ),
			'direction'  => $direction,
			'confidence' => $confidence,
		);
	}

	/**
	 * Calculate statistical insights for usage data.
	 *
	 * @param array $values Array of numeric values.
	 * @return array {
	 *     Statistical metrics.
	 *
	 *     @type float $mean            Mean (average) value.
	 *     @type float $median          Median value.
	 *     @type float $std_dev         Standard deviation.
	 *     @type float $variance        Variance.
	 *     @type float $min             Minimum value.
	 *     @type float $max             Maximum value.
	 *     @type int   $count           Number of data points.
	 *     @type float $coefficient_of_variation Coefficient of variation (%).
	 * }
	 */
	public static function calculate_statistics( $values ) {
		if ( empty( $values ) ) {
			return array(
				'mean'                     => 0,
				'median'                   => 0,
				'std_dev'                  => 0,
				'variance'                 => 0,
				'min'                      => 0,
				'max'                      => 0,
				'count'                    => 0,
				'coefficient_of_variation' => 0,
			);
		}

		$count = count( $values );
		$mean  = array_sum( $values ) / $count;

		// Calculate median.
		sort( $values );
		if ( $count % 2 === 0 ) {
			$median = ( $values[ $count / 2 - 1 ] + $values[ $count / 2 ] ) / 2;
		} else {
			$median = $values[ floor( $count / 2 ) ];
		}

		// Calculate variance and standard deviation.
		$variance = 0;
		foreach ( $values as $value ) {
			$variance += pow( $value - $mean, 2 );
		}
		$variance = $variance / $count;
		$std_dev  = sqrt( $variance );

		// Coefficient of variation (CV).
		$cv = ( $mean != 0 ) ? ( $std_dev / $mean ) * 100 : 0;

		return array(
			'mean'                     => round( $mean, 2 ),
			'median'                   => round( $median, 2 ),
			'std_dev'                  => round( $std_dev, 2 ),
			'variance'                 => round( $variance, 2 ),
			'min'                      => min( $values ),
			'max'                      => max( $values ),
			'count'                    => $count,
			'coefficient_of_variation' => round( $cv, 2 ),
		);
	}

	/**
	 * Calculate Z-score for a value.
	 *
	 * @param float $value     Value to calculate Z-score for.
	 * @param float $mean      Mean of the dataset.
	 * @param float $std_dev   Standard deviation of the dataset.
	 * @return float Z-score.
	 */
	public static function calculate_z_score( $value, $mean, $std_dev ) {
		if ( $std_dev == 0 ) {
			return 0;
		}

		return ( $value - $mean ) / $std_dev;
	}

	/**
	 * Detect usage patterns.
	 *
	 * Analyzes hourly, daily, and weekly usage patterns.
	 *
	 * @param int $user_id User ID.
	 * @return array {
	 *     Pattern analysis results.
	 *
	 *     @type array  $peak_hours       Array of peak usage hours (0-23).
	 *     @type array  $peak_days        Array of peak usage days (Mon-Sun).
	 *     @type array  $hourly_pattern   Average usage by hour.
	 *     @type array  $daily_pattern    Average usage by day of week.
	 *     @type string $usage_type       Classification: 'consistent', 'sporadic', or 'bursty'.
	 * }
	 */
	public static function detect_patterns( $user_id ) {
		$usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $user_id );

		if ( empty( $usage ) ) {
			return array(
				'peak_hours'     => array(),
				'peak_days'      => array(),
				'hourly_pattern' => array(),
				'daily_pattern'  => array(),
				'usage_type'     => 'consistent',
			);
		}

		// Aggregate hourly and daily data across all tools.
		$hourly_totals = array_fill( 0, 24, 0 );
		$daily_totals  = array_fill( 0, 7, 0 ); // 0 = Sunday, 6 = Saturday.

		foreach ( $usage as $tool_slug => $tool_data ) {
			if ( ! isset( $tool_data['hourly'] ) || ! is_array( $tool_data['hourly'] ) ) {
				continue;
			}

			foreach ( $tool_data['hourly'] as $hour_key => $tokens ) {
				// Extract hour from 'Y-m-d-H' format.
				$hour_parts = explode( '-', $hour_key );
				if ( count( $hour_parts ) === 4 ) {
					$hour                    = (int) $hour_parts[3];
					$hourly_totals[ $hour ] += $tokens;

					// Calculate day of week.
					$date_str                      = $hour_parts[0] . '-' . $hour_parts[1] . '-' . $hour_parts[2];
					$day_of_week                   = (int) gmdate( 'w', strtotime( $date_str ) );
					$daily_totals[ $day_of_week ] += $tokens;
				}
			}
		}

		// Find peak hours (top 3).
		$hourly_with_keys = $hourly_totals;
		arsort( $hourly_with_keys );
		$peak_hours = array_slice( array_keys( $hourly_with_keys ), 0, 3, true );

		// Find peak days (top 3).
		$daily_with_keys = $daily_totals;
		arsort( $daily_with_keys );
		$peak_days_numeric = array_slice( array_keys( $daily_with_keys ), 0, 3, true );

		// Convert numeric days to names.
		$day_names = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
		$peak_days = array_map(
			function ( $day_num ) use ( $day_names ) {
				return $day_names[ $day_num ];
			},
			$peak_days_numeric
		);

		// Determine usage type based on coefficient of variation.
		$hourly_values = array_values( $hourly_totals );
		$stats         = self::calculate_statistics( $hourly_values );
		$cv            = $stats['coefficient_of_variation'];

		if ( $cv < 30 ) {
			$usage_type = 'consistent';
		} elseif ( $cv < 70 ) {
			$usage_type = 'sporadic';
		} else {
			$usage_type = 'bursty';
		}

		return array(
			'peak_hours'     => $peak_hours,
			'peak_days'      => $peak_days,
			'hourly_pattern' => $hourly_totals,
			'daily_pattern'  => $daily_totals,
			'usage_type'     => $usage_type,
		);
	}

	/**
	 * Compare usage between two users.
	 *
	 * @param int $user_id_1 First user ID.
	 * @param int $user_id_2 Second user ID.
	 * @param int $days      Number of days to analyze (default: 30).
	 * @return array {
	 *     Comparison results.
	 *
	 *     @type array $user1_stats      Statistics for user 1.
	 *     @type array $user2_stats      Statistics for user 2.
	 *     @type float $usage_ratio      Ratio of user1 usage to user2 usage.
	 *     @type string $higher_user     User ID with higher usage.
	 *     @type float $difference_pct   Percentage difference.
	 * }
	 */
	public static function compare_users( $user_id_1, $user_id_2, $days = 30 ) {
		$cutoff_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		// Get usage for both users.
		$usage_1 = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $user_id_1 );
		$usage_2 = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $user_id_2 );

		$user_1_totals = self::extract_daily_totals( $usage_1, $cutoff_date );
		$user_2_totals = self::extract_daily_totals( $usage_2, $cutoff_date );

		$user_1_stats = self::calculate_statistics( array_values( $user_1_totals ) );
		$user_2_stats = self::calculate_statistics( array_values( $user_2_totals ) );

		$total_1 = array_sum( $user_1_totals );
		$total_2 = array_sum( $user_2_totals );

		$usage_ratio = ( $total_2 != 0 ) ? ( $total_1 / $total_2 ) : 0;
		$higher_user = ( $total_1 > $total_2 ) ? $user_id_1 : $user_id_2;

		$avg_usage      = ( $total_1 + $total_2 ) / 2;
		$difference_pct = ( $avg_usage != 0 ) ? ( abs( $total_1 - $total_2 ) / $avg_usage ) * 100 : 0;

		return array(
			'user1_stats'    => $user_1_stats,
			'user2_stats'    => $user_2_stats,
			'usage_ratio'    => round( $usage_ratio, 2 ),
			'higher_user'    => $higher_user,
			'difference_pct' => round( $difference_pct, 2 ),
		);
	}

	/**
	 * Compare usage between two tools.
	 *
	 * @param string $tool_slug_1 First tool slug.
	 * @param string $tool_slug_2 Second tool slug.
	 * @param int    $days        Number of days to analyze (default: 30).
	 * @return array {
	 *     Comparison results.
	 *
	 *     @type array  $tool1_stats      Statistics for tool 1.
	 *     @type array  $tool2_stats      Statistics for tool 2.
	 *     @type float  $usage_ratio      Ratio of tool1 usage to tool2 usage.
	 *     @type string $popular_tool     Tool slug with higher usage.
	 *     @type float  $difference_pct   Percentage difference.
	 * }
	 */
	public static function compare_tools( $tool_slug_1, $tool_slug_2, $days = 30 ) {
		global $wpdb;

		$cutoff_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		// Get all users' usage data.
		$meta_key = WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY;
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
				$meta_key
			)
		);

		$tool_1_totals = array();
		$tool_2_totals = array();

		foreach ( $user_ids as $user_id ) {
			$usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $user_id );

			if ( isset( $usage[ $tool_slug_1 ]['daily'] ) ) {
				$tool_1_data   = self::filter_by_date( $usage[ $tool_slug_1 ]['daily'], $cutoff_date );
				$tool_1_totals = array_merge( $tool_1_totals, array_values( $tool_1_data ) );
			}

			if ( isset( $usage[ $tool_slug_2 ]['daily'] ) ) {
				$tool_2_data   = self::filter_by_date( $usage[ $tool_slug_2 ]['daily'], $cutoff_date );
				$tool_2_totals = array_merge( $tool_2_totals, array_values( $tool_2_data ) );
			}
		}

		$tool_1_stats = self::calculate_statistics( $tool_1_totals );
		$tool_2_stats = self::calculate_statistics( $tool_2_totals );

		$total_1 = array_sum( $tool_1_totals );
		$total_2 = array_sum( $tool_2_totals );

		$usage_ratio  = ( $total_2 != 0 ) ? ( $total_1 / $total_2 ) : 0;
		$popular_tool = ( $total_1 > $total_2 ) ? $tool_slug_1 : $tool_slug_2;

		$avg_usage      = ( $total_1 + $total_2 ) / 2;
		$difference_pct = ( $avg_usage != 0 ) ? ( abs( $total_1 - $total_2 ) / $avg_usage ) * 100 : 0;

		return array(
			'tool1_stats'    => $tool_1_stats,
			'tool2_stats'    => $tool_2_stats,
			'usage_ratio'    => round( $usage_ratio, 2 ),
			'popular_tool'   => $popular_tool,
			'difference_pct' => round( $difference_pct, 2 ),
		);
	}

	/**
	 * Get usage trends for a user or site-wide.
	 *
	 * @param int $user_id User ID. Use 0 for site-wide trends.
	 * @param int $days    Number of days to analyze (default: 30).
	 * @return array {
	 *     Trend analysis results.
	 *
	 *     @type array  $daily_usage    Daily usage data points.
	 *     @type array  $trend          Linear regression results.
	 *     @type array  $statistics     Statistical metrics.
	 *     @type array  $patterns       Usage patterns.
	 *     @type int    $projected_7d   Projected usage in 7 days.
	 *     @type int    $projected_30d  Projected usage in 30 days.
	 * }
	 */
	public static function get_user_trends( $user_id, $days = 30 ) {
		$user_id = absint( $user_id );

		// For site-wide analytics (user_id = 0), use site-wide trends.
		if ( 0 === $user_id ) {
			return self::get_site_wide_trends( $days );
		}

		$cutoff_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$usage       = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $user_id );

		$daily_totals = self::extract_daily_totals( $usage, $cutoff_date );

		// Convert to timestamp => value pairs for regression.
		$data_points = array();
		foreach ( $daily_totals as $date => $tokens ) {
			$data_points[ strtotime( $date ) ] = $tokens;
		}

		$trend      = self::calculate_trend( $data_points );
		$statistics = self::calculate_statistics( array_values( $daily_totals ) );
		$patterns   = self::detect_patterns( $user_id );

		// Project future usage.
		$projected_7d  = 0;
		$projected_30d = 0;

		// Only calculate projections if we have data points.
		if ( ! empty( $data_points ) ) {
			$current_time   = time();
			$days_from_base = ( $current_time - min( array_keys( $data_points ) ) ) / DAY_IN_SECONDS;

			$projected_7d  = (int) ( $trend['slope'] * ( $days_from_base + 7 ) + $trend['intercept'] );
			$projected_30d = (int) ( $trend['slope'] * ( $days_from_base + 30 ) + $trend['intercept'] );

			$projected_7d  = max( 0, $projected_7d );
			$projected_30d = max( 0, $projected_30d );
		}

		return array(
			'daily_usage'   => $daily_totals,
			'trend'         => $trend,
			'statistics'    => $statistics,
			'patterns'      => $patterns,
			'projected_7d'  => $projected_7d,
			'projected_30d' => $projected_30d,
		);
	}

	/**
	 * Get site-wide usage trends aggregated from all users.
	 *
	 * @param int $days Number of days to analyze (default: 30).
	 * @return array {
	 *     Trend analysis results.
	 *
	 *     @type array  $daily_usage    Daily usage data points.
	 *     @type array  $trend          Linear regression results.
	 *     @type array  $statistics     Statistical metrics.
	 *     @type array  $patterns       Usage patterns (empty for site-wide).
	 *     @type int    $projected_7d   Projected usage in 7 days.
	 *     @type int    $projected_30d  Projected usage in 30 days.
	 * }
	 */
	public static function get_site_wide_trends( $days = 30 ) {
		global $wpdb;

		$cutoff_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		// Get all users with tool usage data.
		$meta_key = WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY;
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
				$meta_key
			)
		);

		$daily_totals = array();

		// Aggregate usage from all users.
		foreach ( $user_ids as $user_id ) {
			$usage             = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $user_id );
			$user_daily_totals = self::extract_daily_totals( $usage, $cutoff_date );

			foreach ( $user_daily_totals as $date => $tokens ) {
				if ( ! isset( $daily_totals[ $date ] ) ) {
					$daily_totals[ $date ] = 0;
				}
				$daily_totals[ $date ] += $tokens;
			}
		}

		ksort( $daily_totals );

		// Convert to timestamp => value pairs for regression.
		$data_points = array();
		foreach ( $daily_totals as $date => $tokens ) {
			$data_points[ strtotime( $date ) ] = $tokens;
		}

		$trend      = self::calculate_trend( $data_points );
		$statistics = self::calculate_statistics( array_values( $daily_totals ) );

		// Project future usage.
		$projected_7d  = 0;
		$projected_30d = 0;

		// Only calculate projections if we have data points.
		if ( ! empty( $data_points ) ) {
			$current_time   = time();
			$days_from_base = ( $current_time - min( array_keys( $data_points ) ) ) / DAY_IN_SECONDS;

			$projected_7d  = (int) ( $trend['slope'] * ( $days_from_base + 7 ) + $trend['intercept'] );
			$projected_30d = (int) ( $trend['slope'] * ( $days_from_base + 30 ) + $trend['intercept'] );

			$projected_7d  = max( 0, $projected_7d );
			$projected_30d = max( 0, $projected_30d );
		}

		return array(
			'daily_usage'   => $daily_totals,
			'trend'         => $trend,
			'statistics'    => $statistics,
			'patterns'      => array(), // Patterns not applicable for site-wide.
			'projected_7d'  => $projected_7d,
			'projected_30d' => $projected_30d,
		);
	}

	/**
	 * Extract daily totals from usage data.
	 *
	 * @param array  $usage       Usage data array.
	 * @param string $cutoff_date Cutoff date (Y-m-d format).
	 * @return array Daily totals keyed by date.
	 */
	private static function extract_daily_totals( $usage, $cutoff_date ) {
		$daily_totals = array();

		if ( empty( $usage ) || ! is_array( $usage ) ) {
			return $daily_totals;
		}

		foreach ( $usage as $tool_slug => $tool_data ) {
			if ( ! isset( $tool_data['daily'] ) || ! is_array( $tool_data['daily'] ) ) {
				continue;
			}

			foreach ( $tool_data['daily'] as $date => $tokens ) {
				if ( $date >= $cutoff_date ) {
					if ( ! isset( $daily_totals[ $date ] ) ) {
						$daily_totals[ $date ] = 0;
					}
					$daily_totals[ $date ] += $tokens;
				}
			}
		}

		ksort( $daily_totals );
		return $daily_totals;
	}

	/**
	 * Filter data by date.
	 *
	 * @param array  $data        Data array keyed by date.
	 * @param string $cutoff_date Cutoff date (Y-m-d format).
	 * @return array Filtered data.
	 */
	private static function filter_by_date( $data, $cutoff_date ) {
		$filtered = array();

		foreach ( $data as $date => $value ) {
			if ( $date >= $cutoff_date ) {
				$filtered[ $date ] = $value;
			}
		}

		return $filtered;
	}

	/**
	 * Get anomalies for a user based on Z-score analysis.
	 *
	 * @param int   $user_id   User ID.
	 * @param float $threshold Z-score threshold (default: 3.0).
	 * @return array List of anomalies with dates and Z-scores.
	 */
	public static function detect_anomalies( $user_id, $threshold = 3.0 ) {
		$usage        = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $user_id );
		$cutoff_date  = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$daily_totals = self::extract_daily_totals( $usage, $cutoff_date );

		if ( empty( $daily_totals ) ) {
			return array();
		}

		$values     = array_values( $daily_totals );
		$statistics = self::calculate_statistics( $values );

		$anomalies = array();

		foreach ( $daily_totals as $date => $tokens ) {
			$z_score = self::calculate_z_score( $tokens, $statistics['mean'], $statistics['std_dev'] );

			if ( abs( $z_score ) >= $threshold ) {
				$anomalies[] = array(
					'date'           => $date,
					'tokens'         => $tokens,
					'z_score'        => round( $z_score, 2 ),
					'expected_value' => (int) $statistics['mean'],
					'severity'       => self::classify_severity( abs( $z_score ) ),
				);
			}
		}

		return $anomalies;
	}

	/**
	 * Classify severity based on Z-score.
	 *
	 * @param float $z_score Absolute Z-score value.
	 * @return string Severity level.
	 */
	private static function classify_severity( $z_score ) {
		if ( $z_score >= 6 ) {
			return 'critical';
		} elseif ( $z_score >= 5 ) {
			return 'high';
		} elseif ( $z_score >= 4 ) {
			return 'medium';
		} else {
			return 'low';
		}
	}

	/**
	 * Rebuild usage analytics data from chat transcripts stored in CCT.
	 *
	 * This method can be used to recover/rebuild analytics data when it was not
	 * being tracked initially but chat transcripts were being saved. The transcripts
	 * contain token usage information in their metadata.
	 *
	 * @param int  $user_id   User ID to rebuild data for (0 for all users).
	 * @param bool $overwrite Whether to overwrite existing usage data (default: false).
	 * @return array {
	 *     Rebuild results.
	 *
	 *     @type int   $transcripts_processed Number of transcripts processed.
	 *     @type int   $users_updated         Number of users whose data was updated.
	 *     @type int   $tokens_recovered      Total tokens recovered.
	 *     @type array $errors                Any errors encountered.
	 * }
	 */
	public static function rebuild_usage_from_transcripts( $user_id = 0, $overwrite = false ) {
		// Verify JetEngine and CCT are available.
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_CCT' ) ) {
			return array(
				'transcripts_processed' => 0,
				'users_updated'         => 0,
				'tokens_recovered'      => 0,
				'errors'                => array( 'JetEngine CCT not available' ),
			);
		}

		global $wpdb;

		$repository = new WP_MCP_AI_Transcript_Repository();
		$table      = $repository->get_table_name();

		if ( ! $table || ! $repository->table_exists() ) {
			return array(
				'transcripts_processed' => 0,
				'users_updated'         => 0,
				'tokens_recovered'      => 0,
				'errors'                => array( 'Transcript table not found' ),
			);
		}

		$results = array(
			'transcripts_processed' => 0,
			'users_updated'         => 0,
			'tokens_recovered'      => 0,
			'errors'                => array(),
		);

		// Build query to fetch transcripts.
		$where = '';
		if ( $user_id > 0 ) {
			$where = $wpdb->prepare( 'WHERE cct_author_id = %d', $user_id );
		}

		// Escape table name for defense-in-depth.
		$table = esc_sql( $table );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is escaped with esc_sql(), $where is prepared above with $wpdb->prepare().
		$transcripts = $wpdb->get_results( "SELECT _ID, cct_author_id, metadata, request_started_at FROM {$table} {$where} ORDER BY request_started_at ASC" );

		if ( empty( $transcripts ) ) {
			return $results;
		}

		$users_data = array();

		foreach ( $transcripts as $transcript ) {
			++$results['transcripts_processed'];

			$transcript_user_id = absint( $transcript->cct_author_id );
			if ( ! $transcript_user_id ) {
				continue;
			}

			// Parse metadata.
			$metadata = maybe_unserialize( $transcript->metadata );
			if ( ! is_string( $metadata ) ) {
				$metadata = wp_json_encode( $metadata );
			}
			$metadata = json_decode( $metadata, true );

			if ( ! isset( $metadata['usage'] ) || ! is_array( $metadata['usage'] ) ) {
				continue;
			}

			$usage_data = $metadata['usage'];

			// Extract token counts.
			$prompt_tokens     = isset( $usage_data['prompt_tokens'] ) ? absint( $usage_data['prompt_tokens'] ) : 0;
			$completion_tokens = isset( $usage_data['completion_tokens'] ) ? absint( $usage_data['completion_tokens'] ) : 0;
			$total_tokens      = isset( $usage_data['total_tokens'] ) ? absint( $usage_data['total_tokens'] ) : ( $prompt_tokens + $completion_tokens );

			if ( $total_tokens <= 0 ) {
				continue;
			}

			$results['tokens_recovered'] += $total_tokens;

			// Parse timestamp.
			$timestamp = strtotime( $transcript->request_started_at );
			if ( ! $timestamp ) {
				$timestamp = time();
			}

			$date_key = gmdate( 'Y-m-d', $timestamp );
			$hour_key = gmdate( 'Y-m-d-H', $timestamp );

			// Initialize user data structure if not exists.
			if ( ! isset( $users_data[ $transcript_user_id ] ) ) {
				$users_data[ $transcript_user_id ] = array();
			}

			// Use a generic tool name for chat interactions.
			$tool_slug = 'chat_interaction';

			if ( ! isset( $users_data[ $transcript_user_id ][ $tool_slug ] ) ) {
				$users_data[ $transcript_user_id ][ $tool_slug ] = array(
					'total_tokens' => 0,
					'requests'     => 0,
					'first_used'   => '',
					'last_used'    => '',
					'daily'        => array(),
					'hourly'       => array(),
				);
			}

			// Aggregate data.
			$users_data[ $transcript_user_id ][ $tool_slug ]['total_tokens'] += $total_tokens;
			++$users_data[ $transcript_user_id ][ $tool_slug ]['requests'];

			// Track first/last usage.
			$current_timestamp = gmdate( 'Y-m-d H:i:s', $timestamp );
			if ( empty( $users_data[ $transcript_user_id ][ $tool_slug ]['first_used'] ) ) {
				$users_data[ $transcript_user_id ][ $tool_slug ]['first_used'] = $current_timestamp;
			}
			$users_data[ $transcript_user_id ][ $tool_slug ]['last_used'] = $current_timestamp;

			// Aggregate daily usage.
			if ( ! isset( $users_data[ $transcript_user_id ][ $tool_slug ]['daily'][ $date_key ] ) ) {
				$users_data[ $transcript_user_id ][ $tool_slug ]['daily'][ $date_key ] = 0;
			}
			$users_data[ $transcript_user_id ][ $tool_slug ]['daily'][ $date_key ] += $total_tokens;

			// Aggregate hourly usage.
			if ( ! isset( $users_data[ $transcript_user_id ][ $tool_slug ]['hourly'][ $hour_key ] ) ) {
				$users_data[ $transcript_user_id ][ $tool_slug ]['hourly'][ $hour_key ] = 0;
			}
			$users_data[ $transcript_user_id ][ $tool_slug ]['hourly'][ $hour_key ] += $total_tokens;
		}

		// Update user meta for each user.
		foreach ( $users_data as $uid => $tools_data ) {
			if ( ! $overwrite ) {
				// Merge with existing data.
				$existing = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $uid );
				if ( ! empty( $existing ) ) {
					foreach ( $tools_data as $tool => $data ) {
						if ( isset( $existing[ $tool ] ) ) {
							// Merge tool data.
							$existing[ $tool ]['total_tokens'] = ( $existing[ $tool ]['total_tokens'] ?? 0 ) + $data['total_tokens'];
							$existing[ $tool ]['requests']     = ( $existing[ $tool ]['requests'] ?? 0 ) + $data['requests'];

							// Merge daily data.
							foreach ( $data['daily'] as $date => $tokens ) {
								$existing[ $tool ]['daily'][ $date ] = ( $existing[ $tool ]['daily'][ $date ] ?? 0 ) + $tokens;
							}

							// Merge hourly data.
							foreach ( $data['hourly'] as $hour => $tokens ) {
								$existing[ $tool ]['hourly'][ $hour ] = ( $existing[ $tool ]['hourly'][ $hour ] ?? 0 ) + $tokens;
							}

							// Update first/last used.
							if ( empty( $existing[ $tool ]['first_used'] ) || $data['first_used'] < $existing[ $tool ]['first_used'] ) {
								$existing[ $tool ]['first_used'] = $data['first_used'];
							}
							if ( empty( $existing[ $tool ]['last_used'] ) || $data['last_used'] > $existing[ $tool ]['last_used'] ) {
								$existing[ $tool ]['last_used'] = $data['last_used'];
							}
						} else {
							$existing[ $tool ] = $data;
						}
					}
					$tools_data = $existing;
				}
			}

			update_user_meta( $uid, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY, $tools_data );
			++$results['users_updated'];
		}

		return $results;
	}
}
