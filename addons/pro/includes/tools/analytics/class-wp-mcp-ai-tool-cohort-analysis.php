<?php
/**
 * Cohort Analysis Tool
 *
 * Analyzes user cohort behavior patterns and retention
 * over time to identify trends and opportunities.
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
 * Tool for analyzing user cohort behavior.
 *
 * Supports:
 * - Time-based cohort grouping
 * - Retention rate analysis
 * - Revenue cohort tracking
 * - Behavioral pattern identification
 * - Cohort comparison
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Cohort_Analysis implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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

		return __( 'Cohort analysis tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool slug.
	 */
	public function get_slug() {
		return 'cohort_analysis';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool name.
	 */
	public function get_name() {
		return __( 'Analyze User Cohorts', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool description.
	 */
	public function get_description() {
		return __( 'Analyze user cohort behavior patterns and retention over time. Track how different groups of customers behave, calculate retention rates, and identify trends across cohorts.', 'mcp-ai-wpoos-pro' );
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
				'cohort_by'          => array(
					'type'        => 'string',
					'description' => 'Cohort grouping basis: registration, first_purchase, campaign',
					'enum'        => array( 'registration', 'first_purchase', 'campaign' ),
					'default'     => 'first_purchase',
				),
				'cohort_period'      => array(
					'type'        => 'string',
					'description' => 'Cohort grouping period: daily, weekly, monthly',
					'enum'        => array( 'daily', 'weekly', 'monthly' ),
					'default'     => 'monthly',
				),
				'analysis_months'    => array(
					'type'        => 'integer',
					'description' => 'Number of months to analyze',
					'minimum'     => 1,
					'maximum'     => 24,
					'default'     => 6,
				),
				'metric'             => array(
					'type'        => 'string',
					'description' => 'Metric to track: retention, revenue, orders, engagement',
					'enum'        => array( 'retention', 'revenue', 'orders', 'engagement' ),
					'default'     => 'retention',
				),
				'min_cohort_size'    => array(
					'type'        => 'integer',
					'description' => 'Minimum users in cohort to include',
					'minimum'     => 1,
					'maximum'     => 1000,
					'default'     => 10,
				),
				'include_chart_data' => array(
					'type'        => 'boolean',
					'description' => 'Include formatted data for visualization',
					'default'     => true,
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
			'analytics' => true,
			'cohorts'   => true,
			'customers' => true,
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
		$cohort_by          = ! empty( $arguments['cohort_by'] ) ? sanitize_text_field( $arguments['cohort_by'] ) : 'first_purchase';
		$cohort_period      = ! empty( $arguments['cohort_period'] ) ? sanitize_text_field( $arguments['cohort_period'] ) : 'monthly';
		$analysis_months    = isset( $arguments['analysis_months'] ) ? absint( $arguments['analysis_months'] ) : 6;
		$metric             = ! empty( $arguments['metric'] ) ? sanitize_text_field( $arguments['metric'] ) : 'retention';
		$min_cohort_size    = isset( $arguments['min_cohort_size'] ) ? absint( $arguments['min_cohort_size'] ) : 10;
		$include_chart_data = ! isset( $arguments['include_chart_data'] ) || $arguments['include_chart_data'];

		// Validate parameters.
		if ( $analysis_months < 1 || $analysis_months > 24 ) {
			return new WP_Error(
				'invalid_analysis_months',
				__( 'Analysis months must be between 1 and 24.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( $min_cohort_size < 1 || $min_cohort_size > 1000 ) {
			return new WP_Error(
				'invalid_min_cohort_size',
				__( 'Minimum cohort size must be between 1 and 1000.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Build cohorts.
		$cohorts = $this->build_cohorts( $cohort_by, $cohort_period, $analysis_months, $min_cohort_size );

		if ( is_wp_error( $cohorts ) ) {
			return $cohorts;
		}

		// Analyze cohorts based on metric.
		$analysis = $this->analyze_cohorts( $cohorts, $metric, $analysis_months );

		// Calculate insights.
		$insights = $this->generate_insights( $analysis, $metric );

		// Prepare result.
		$result = array(
			'success'     => true,
			'cohorts'     => $analysis['cohorts'],
			'summary'     => $analysis['summary'],
			'insights'    => $insights,
			'parameters'  => array(
				'cohort_by'       => $cohort_by,
				'cohort_period'   => $cohort_period,
				'analysis_months' => $analysis_months,
				'metric'          => $metric,
			),
			'analyzed_at' => current_time( 'mysql' ),
			'message'     => sprintf(
				/* translators: 1: cohort count, 2: metric */
				__( 'Analyzed %1$d cohorts for %2$s tracking.', 'mcp-ai-wpoos-pro' ),
				count( $analysis['cohorts'] ),
				$metric
			),
		);

		if ( $include_chart_data ) {
			$result['chart_data'] = $this->prepare_chart_data( $analysis['cohorts'], $metric );
		}

		return $result;
	}

	/**
	 * Build cohorts based on criteria.
	 *
	 * @since 1.1.0
	 *
	 * @param string $cohort_by       Cohort grouping basis.
	 * @param string $cohort_period   Cohort period.
	 * @param int    $analysis_months Months to analyze.
	 * @param int    $min_cohort_size Minimum cohort size.
	 * @return array|WP_Error Cohorts or error.
	 */
	private function build_cohorts( $cohort_by, $cohort_period, $analysis_months, $min_cohort_size ) {
		global $wpdb;

		$cohorts    = array();
		$start_date = gmdate( 'Y-m-d', strtotime( "-{$analysis_months} months" ) );

		// Determine date format based on period.
		switch ( $cohort_period ) {
			case 'daily':
				$date_format = '%Y-%m-%d';
				break;
			case 'weekly':
				$date_format = '%Y-%u';
				break;
			case 'monthly':
			default:
				$date_format = '%Y-%m';
				break;
		}

		// Build query based on cohort_by.
		if ( 'registration' === $cohort_by ) {
			$query = $wpdb->prepare(
				"SELECT 
					DATE_FORMAT(user_registered, %s) as cohort_period,
					ID as user_id,
					user_registered as cohort_date
				FROM {$wpdb->users}
				WHERE user_registered >= %s
				ORDER BY user_registered",
				$date_format,
				$start_date
			);
		} else {
			// first_purchase cohort.
			$query = $wpdb->prepare(
				"SELECT 
					DATE_FORMAT(MIN(p.post_date), %s) as cohort_period,
					p.post_author as user_id,
					MIN(p.post_date) as cohort_date
				FROM {$wpdb->posts} p
				WHERE p.post_type = 'shop_order'
					AND p.post_status IN ('wc-completed', 'wc-processing')
					AND p.post_author > 0
					AND p.post_date >= %s
				GROUP BY p.post_author
				HAVING MIN(p.post_date) >= %s
				ORDER BY cohort_date",
				$date_format,
				$start_date,
				$start_date
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $query, ARRAY_A );

		if ( ! $results ) {
			return array();
		}

		// Group users by cohort period.
		foreach ( $results as $row ) {
			$period = $row['cohort_period'];
			if ( ! isset( $cohorts[ $period ] ) ) {
				$cohorts[ $period ] = array(
					'period'      => $period,
					'users'       => array(),
					'cohort_date' => $row['cohort_date'],
				);
			}
			$cohorts[ $period ]['users'][] = intval( $row['user_id'] );
		}

		// Filter cohorts by minimum size.
		$cohorts = array_filter(
			$cohorts,
			function ( $cohort ) use ( $min_cohort_size ) {
				return count( $cohort['users'] ) >= $min_cohort_size;
			}
		);

		return array_values( $cohorts );
	}

	/**
	 * Analyze cohorts for the specified metric.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $cohorts         Cohort data.
	 * @param string $metric          Metric to analyze.
	 * @param int    $analysis_months Months to track.
	 * @return array Analysis results.
	 */
	private function analyze_cohorts( $cohorts, $metric, $analysis_months ) {
		$analyzed_cohorts = array();
		$total_users      = 0;

		foreach ( $cohorts as $cohort ) {
			$cohort_size  = count( $cohort['users'] );
			$total_users += $cohort_size;

			$cohort_analysis = array(
				'period'      => $cohort['period'],
				'cohort_date' => $cohort['cohort_date'],
				'size'        => $cohort_size,
				'metrics'     => array(),
			);

			// Track metric over time periods.
			for ( $i = 0; $i <= $analysis_months; $i++ ) {
				$period_start = gmdate( 'Y-m-d', strtotime( "+{$i} months", strtotime( $cohort['cohort_date'] ) ) );
				$period_end   = gmdate( 'Y-m-d', strtotime( '+1 month', strtotime( $period_start ) ) );

				$cohort_analysis['metrics'][ $i ] = $this->calculate_cohort_metric(
					$cohort['users'],
					$metric,
					$period_start,
					$period_end,
					$cohort_size
				);
			}

			$analyzed_cohorts[] = $cohort_analysis;
		}

		// Calculate summary statistics.
		$summary = $this->calculate_cohort_summary( $analyzed_cohorts, $metric, $total_users );

		return array(
			'cohorts' => $analyzed_cohorts,
			'summary' => $summary,
		);
	}

	/**
	 * Calculate metric for a cohort in a time period.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $users        User IDs in cohort.
	 * @param string $metric       Metric to calculate.
	 * @param string $period_start Period start date.
	 * @param string $period_end   Period end date.
	 * @param int    $cohort_size  Original cohort size.
	 * @return array Metric data.
	 */
	private function calculate_cohort_metric( $users, $metric, $period_start, $period_end, $cohort_size ) {
		global $wpdb;

		$user_ids = implode( ',', array_map( 'absint', $users ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		switch ( $metric ) {
			case 'retention':
				// Count users with activity in period.
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$active_users = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT post_author)
						FROM {$wpdb->posts}
						WHERE post_type = 'shop_order'
							AND post_status IN ('wc-completed', 'wc-processing')
							// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic IN clause
							AND post_author IN ({$user_ids})
							AND post_date BETWEEN %s AND %s",
						$period_start,
						$period_end
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				$retention_rate = $cohort_size > 0 ? ( $active_users / $cohort_size ) * 100 : 0;

				return array(
					'value'      => round( $retention_rate, 2 ),
					'count'      => intval( $active_users ),
					'percentage' => round( $retention_rate, 2 ),
				);

			case 'revenue':
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$revenue = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT SUM(CAST(pm.meta_value AS DECIMAL(10,2)))
						FROM {$wpdb->posts} p
						INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
						WHERE p.post_type = 'shop_order'
							AND p.post_status IN ('wc-completed', 'wc-processing')
							AND pm.meta_key = '_order_total'
							AND p.post_author IN ({$user_ids}) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic IN clause
							AND p.post_date BETWEEN %s AND %s",
						$period_start,
						$period_end
					)
				);

				return array(
					'value'    => round( floatval( $revenue ), 2 ),
					'per_user' => $cohort_size > 0 ? round( floatval( $revenue ) / $cohort_size, 2 ) : 0,
				);

			case 'orders':
				$order_count = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*)
						FROM {$wpdb->posts}
						WHERE post_type = 'shop_order'
							AND post_status IN ('wc-completed', 'wc-processing')
							AND post_author IN ({$user_ids}) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic IN clause
							AND post_date BETWEEN %s AND %s",
						$period_start,
						$period_end
					)
				);

				return array(
					'value'    => intval( $order_count ),
					'per_user' => $cohort_size > 0 ? round( intval( $order_count ) / $cohort_size, 2 ) : 0,
				);

			default:
				return array( 'value' => 0 );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Calculate cohort summary statistics.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $cohorts     Analyzed cohorts.
	 * @param string $metric      Metric analyzed.
	 * @param int    $total_users Total users across cohorts.
	 * @return array Summary data.
	 */
	private function calculate_cohort_summary( $cohorts, $metric, $total_users ) {
		$summary = array(
			'total_cohorts'   => count( $cohorts ),
			'total_users'     => $total_users,
			'avg_cohort_size' => count( $cohorts ) > 0 ? round( $total_users / count( $cohorts ), 2 ) : 0,
		);

		if ( 'retention' === $metric ) {
			// Calculate average retention by period.
			$retention_by_period = array();
			foreach ( $cohorts as $cohort ) {
				foreach ( $cohort['metrics'] as $period => $data ) {
					if ( ! isset( $retention_by_period[ $period ] ) ) {
						$retention_by_period[ $period ] = array();
					}
					$retention_by_period[ $period ][] = $data['percentage'];
				}
			}

			$avg_retention = array();
			foreach ( $retention_by_period as $period => $rates ) {
				$avg_retention[ $period ] = round( array_sum( $rates ) / count( $rates ), 2 );
			}

			$summary['avg_retention_by_period'] = $avg_retention;
		}

		return $summary;
	}

	/**
	 * Generate insights from cohort analysis.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $analysis Analysis data.
	 * @param string $metric   Metric analyzed.
	 * @return array Insights.
	 */
	private function generate_insights( $analysis, $metric ) {
		$insights = array();

		if ( 'retention' === $metric && isset( $analysis['summary']['avg_retention_by_period'] ) ) {
			$retention = $analysis['summary']['avg_retention_by_period'];

			// Check for retention drop-off.
			if ( isset( $retention[0], $retention[1] ) && $retention[1] < 50 ) {
				$insights[] = array(
					'type'    => 'retention_drop',
					/* translators: %s: retention percentage */
					'message' => sprintf( __( 'Retention drops to %s%% in month 1', 'mcp-ai-wpoos-pro' ), $retention[1] ),
					'action'  => __( 'Implement early engagement campaign', 'mcp-ai-wpoos-pro' ),
				);
			}

			// Check for stable retention.
			if ( count( $retention ) >= 3 ) {
				$last_periods = array_slice( $retention, -3, 3, true );
				$variance     = max( $last_periods ) - min( $last_periods );
				if ( $variance < 5 ) {
					$insights[] = array(
						'type'    => 'stable_retention',
						'message' => __( 'Retention has stabilized in recent periods', 'mcp-ai-wpoos-pro' ),
					);
				}
			}
		}

		return $insights;
	}

	/**
	 * Prepare chart data for visualization.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $cohorts Analyzed cohorts.
	 * @param string $metric  Metric type.
	 * @return array Chart-ready data.
	 */
	private function prepare_chart_data( $cohorts, $metric ) {
		$chart_data = array(
			'labels'   => array(),
			'datasets' => array(),
		);

		foreach ( $cohorts as $cohort ) {
			$dataset = array(
				'label' => $cohort['period'],
				'data'  => array(),
			);

			foreach ( $cohort['metrics'] as $period => $data ) {
				if ( ! in_array( "Month {$period}", $chart_data['labels'], true ) ) {
					$chart_data['labels'][] = "Month {$period}";
				}
				$dataset['data'][] = $data['value'];
			}

			$chart_data['datasets'][] = $dataset;
		}

		return $chart_data;
	}
}
