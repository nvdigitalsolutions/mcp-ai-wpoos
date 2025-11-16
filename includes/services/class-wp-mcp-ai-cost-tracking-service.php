<?php
/**
 * Cost Tracking Service
 *
 * Integrates cost calculation with token usage tracking.
 * Follows separation of concerns - bridges Cost Calculator and Token Limits.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cost Tracking Service class.
 *
 * Responsibility: Data access and integration between cost calculation and token tracking.
 * Does NOT do calculations (that's Cost Calculator's job).
 * Does NOT track tokens (that's Token Limits' job).
 */
class WP_MCP_AI_Cost_Tracking_Service {

	/**
	 * Get cost breakdown for a user over a time period.
	 *
	 * Integrates token usage data with cost calculation using enhanced tracking database.
	 * Following SoC: Service orchestrates data access, doesn't perform calculations.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $start_date Start date (YYYY-MM-DD).
	 * @param string $end_date   End date (YYYY-MM-DD).
	 * @return array Cost breakdown with totals by provider, model, tool, and accuracy metrics.
	 */
	public static function get_user_cost_breakdown( $user_id, $start_date, $end_date ) {
		// Convert dates to datetime format for database queries.
		$start_datetime = gmdate( 'Y-m-d 00:00:00', strtotime( $start_date ) );
		$end_datetime   = gmdate( 'Y-m-d 23:59:59', strtotime( $end_date ) );

		// Data access - use enhanced tracking database (SoC - data layer).
		if ( ! class_exists( 'WP_MCP_AI_Token_Tracking_Database' ) ) {
			// Fallback to legacy method if enhanced tracking not available.
			$usage_data = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $user_id );
			return WP_MCP_AI_Cost_Calculator::calculate_cost_breakdown( $usage_data, $start_date, $end_date );
		}

		// Get cost summary with actual vs estimated breakdown.
		$cost_summary = WP_MCP_AI_Token_Tracking_Database::get_user_cost_summary(
			$user_id,
			$start_datetime,
			$end_datetime
		);

		// Get detailed usage data for provider/model/tool breakdown.
		$usage_records = WP_MCP_AI_Token_Tracking_Database::get_user_usage(
			$user_id,
			$start_datetime,
			$end_datetime
		);

		// Aggregate usage by provider, model, and tool (orchestration, not calculation).
		$by_provider = array();
		$by_model    = array();
		$by_tool     = array();

		foreach ( $usage_records as $record ) {
			$provider = $record['provider'];
			$model    = $record['model'];
			$tool     = $record['tool'];
			$cost     = floatval( $record['cost_usd'] );
			$tokens   = intval( $record['total_tokens'] );

			// Aggregate by provider.
			if ( ! isset( $by_provider[ $provider ] ) ) {
				$by_provider[ $provider ] = array(
					'cost'   => 0.0,
					'tokens' => 0,
				);
			}
			$by_provider[ $provider ]['cost']   += $cost;
			$by_provider[ $provider ]['tokens'] += $tokens;

			// Aggregate by model.
			$model_key = $provider . '|' . $model;
			if ( ! isset( $by_model[ $model_key ] ) ) {
				$by_model[ $model_key ] = array(
					'provider' => $provider,
					'model'    => $model,
					'cost'     => 0.0,
					'tokens'   => 0,
				);
			}
			$by_model[ $model_key ]['cost']   += $cost;
			$by_model[ $model_key ]['tokens'] += $tokens;

			// Aggregate by tool.
			if ( ! isset( $by_tool[ $tool ] ) ) {
				$by_tool[ $tool ] = array(
					'cost'   => 0.0,
					'tokens' => 0,
				);
			}
			$by_tool[ $tool ]['cost']   += $cost;
			$by_tool[ $tool ]['tokens'] += $tokens;
		}

		// Calculate accuracy percentage (SoC - simple math, not complex calculation).
		$accuracy_percentage = 0.0;
		if ( $cost_summary['total_cost'] > 0 ) {
			$accuracy_percentage = ( $cost_summary['actual_cost'] / $cost_summary['total_cost'] ) * 100;
		}

		return array(
			'total_cost'          => $cost_summary['total_cost'],
			'total_tokens'        => $cost_summary['total_tokens'],
			'estimated_cost'      => $cost_summary['estimated_cost'],
			'actual_cost'         => $cost_summary['actual_cost'],
			'accuracy_percentage' => round( $accuracy_percentage, 2 ),
			'by_provider'         => $by_provider,
			'by_model'            => $by_model,
			'by_tool'             => $by_tool,
		);
	}

	/**
	 * Get cost breakdown for all users over a time period.
	 *
	 * Business logic layer - orchestrates data access and builds cost breakdown.
	 * Following SoC: Service orchestrates, database provides data, no calculations here.
	 *
	 * @param string $start_date Start date (YYYY-MM-DD).
	 * @param string $end_date   End date (YYYY-MM-DD).
	 * @return array Cost breakdown aggregated across all users with accuracy metrics.
	 */
	public static function get_site_cost_breakdown( $start_date, $end_date ) {
		// Initialize breakdown structure with accuracy metrics.
		$site_breakdown = array(
			'total_cost'          => 0.0,
			'total_tokens'        => 0,
			'estimated_cost'      => 0.0,
			'actual_cost'         => 0.0,
			'accuracy_percentage' => 0.0,
			'by_provider'         => array(),
			'by_model'            => array(),
			'by_tool'             => array(),
			'by_date'             => array(),
			'by_user'             => array(),
		);

		// Convert dates to datetime format for database queries.
		$start_datetime = gmdate( 'Y-m-d 00:00:00', strtotime( $start_date ) );
		$end_datetime   = gmdate( 'Y-m-d 23:59:59', strtotime( $end_date ) );

		// Use Token Tracking Database for data access (SoC - data layer).
		if ( ! class_exists( 'WP_MCP_AI_Token_Tracking_Database' ) ) {
			return $site_breakdown;
		}

		// Get site-wide cost summary with estimated vs actual breakdown.
		$cost_summary = WP_MCP_AI_Token_Tracking_Database::get_site_cost_summary(
			$start_datetime,
			$end_datetime
		);

		$site_breakdown['total_cost']     = $cost_summary['total_cost'];
		$site_breakdown['total_tokens']   = $cost_summary['total_tokens'];
		$site_breakdown['estimated_cost'] = $cost_summary['estimated_cost'];
		$site_breakdown['actual_cost']    = $cost_summary['actual_cost'];

		// Calculate accuracy percentage (SoC - simple math, not complex calculation).
		if ( $cost_summary['total_cost'] > 0 ) {
			$site_breakdown['accuracy_percentage'] = round(
				( $cost_summary['actual_cost'] / $cost_summary['total_cost'] ) * 100,
				2
			);
		}

		// Aggregate by provider.
		$provider_data = WP_MCP_AI_Token_Tracking_Database::get_aggregated_by_provider( $start_datetime, $end_datetime );
		foreach ( $provider_data as $row ) {
			$provider                              = $row['provider'];
			$cost                                  = floatval( $row['total_cost'] );
			$site_breakdown['by_provider'][ $provider ] = $cost;
		}

		// Aggregate by model.
		$model_data = WP_MCP_AI_Token_Tracking_Database::get_aggregated_by_model( $start_datetime, $end_datetime );
		foreach ( $model_data as $row ) {
			$provider = $row['provider'];
			$model    = $row['model'];
			$key      = $provider . '|' . $model;

			$site_breakdown['by_model'][ $key ] = array(
				'provider'     => $provider,
				'model'        => $model,
				'total_cost'   => floatval( $row['total_cost'] ),
				'total_tokens' => intval( $row['total_tokens'] ),
			);
		}

		// Aggregate by tool.
		$tool_data = WP_MCP_AI_Token_Tracking_Database::get_aggregated_by_tool( $start_datetime, $end_datetime );
		foreach ( $tool_data as $row ) {
			$tool                             = $row['tool'];
			$site_breakdown['by_tool'][ $tool ] = floatval( $row['total_cost'] );
		}

		// Aggregate by date.
		$date_data = WP_MCP_AI_Token_Tracking_Database::get_aggregated_by_date( $start_datetime, $end_datetime );
		foreach ( $date_data as $row ) {
			$date                             = $row['date'];
			$site_breakdown['by_date'][ $date ] = floatval( $row['total_cost'] );
		}

		// Aggregate by user.
		$user_data = WP_MCP_AI_Token_Tracking_Database::get_aggregated_by_user( $start_datetime, $end_datetime );
		foreach ( $user_data as $row ) {
			$user_id                             = intval( $row['user_id'] );
			$site_breakdown['by_user'][ $user_id ] = floatval( $row['total_cost'] );
		}

		return $site_breakdown;
	}

	/**
	 * Get ROI for a user.
	 *
	 * @param int   $user_id User ID.
	 * @param array $metrics Productivity metrics (time_saved_hours, tasks_automated, hourly_rate).
	 * @param int   $days    Number of days to analyze (default: 30).
	 * @return array ROI data.
	 */
	public static function get_user_roi( $user_id, $metrics, $days = 30 ) {
		// Data access - get cost breakdown for the period.
		$start_date     = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$end_date       = gmdate( 'Y-m-d' );
		$cost_breakdown = self::get_user_cost_breakdown( $user_id, $start_date, $end_date );

		// Delegate calculation to Cost Calculator (separation of concerns).
		return WP_MCP_AI_Cost_Calculator::calculate_roi( $cost_breakdown['total_cost'], $metrics );
	}

	/**
	 * Get cost summary for dashboard widget.
	 *
	 * Business logic layer - prepares cost data for dashboard presentation.
	 *
	 * @param int $days Number of days to analyze (default: 7).
	 * @return array Cost summary data for widget display.
	 */
	public static function get_dashboard_cost_summary( $days = 7 ) {
		$start_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$end_date   = gmdate( 'Y-m-d' );

		$breakdown = self::get_site_cost_breakdown( $start_date, $end_date );

		return array(
			'total_cost'   => $breakdown['total_cost'],
			'total_tokens' => $breakdown['total_tokens'],
			'by_provider'  => $breakdown['by_provider'],
			'by_model'     => $breakdown['by_model'],
			'by_tool'      => $breakdown['by_tool'],
			'period_start' => $start_date,
			'period_end'   => $end_date,
			'top_tools'    => self::get_top_cost_tools( $breakdown['by_tool'], 5 ),
			'top_users'    => self::get_top_cost_users( $breakdown['by_user'], 5 ),
		);
	}

	/**
	 * Get top tools by cost.
	 *
	 * @param array $by_tool Tool cost data.
	 * @param int   $limit   Number of tools to return.
	 * @return array Top tools with costs.
	 */
	private static function get_top_cost_tools( $by_tool, $limit = 5 ) {
		arsort( $by_tool );
		return array_slice( $by_tool, 0, $limit, true );
	}

	/**
	 * Get top users by cost.
	 *
	 * @param array $by_user User cost data.
	 * @param int   $limit   Number of users to return.
	 * @return array Top users with costs and names.
	 */
	private static function get_top_cost_users( $by_user, $limit = 5 ) {
		arsort( $by_user );
		$top_users = array_slice( $by_user, 0, $limit, true );

		// Enrich with user names.
		$enriched = array();
		foreach ( $top_users as $user_id => $cost ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$enriched[ $user_id ] = array(
					'name' => $user->display_name,
					'cost' => $cost,
				);
			}
		}

		return $enriched;
	}

	/**
	 * Get cost trend data for charts.
	 *
	 * @param int $days Number of days to analyze.
	 * @return array Chart-ready data with labels and datasets.
	 */
	public static function get_cost_trend_data( $days = 30 ) {
		$start_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$end_date   = gmdate( 'Y-m-d' );

		$breakdown = self::get_site_cost_breakdown( $start_date, $end_date );

		// Prepare chart data.
		$labels = array();
		$costs  = array();

		// Fill in all dates in range (including zero-cost days).
		$current = strtotime( $start_date );
		$end     = strtotime( $end_date );

		while ( $current <= $end ) {
			$date_key = gmdate( 'Y-m-d', $current );
			$labels[] = gmdate( 'M j', $current );
			$costs[]  = isset( $breakdown['by_date'][ $date_key ] ) ? $breakdown['by_date'][ $date_key ] : 0.0;
			$current  = strtotime( '+1 day', $current );
		}

		return array(
			'labels'   => $labels,
			'datasets' => array(
				array(
					'label'           => __( 'Daily Cost ($)', 'wp-mcp-ai' ),
					'data'            => $costs,
					'backgroundColor' => 'rgba(54, 162, 235, 0.2)',
					'borderColor'     => 'rgba(54, 162, 235, 1)',
					'borderWidth'     => 2,
					'fill'            => true,
				),
			),
		);
	}

	/**
	 * Get cost by provider data for pie chart.
	 *
	 * @param int $days Number of days to analyze.
	 * @return array Chart-ready data for provider distribution.
	 */
	public static function get_cost_by_provider_data( $days = 30 ) {
		$start_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$end_date   = gmdate( 'Y-m-d' );

		$breakdown = self::get_site_cost_breakdown( $start_date, $end_date );

		if ( empty( $breakdown['by_provider'] ) ) {
			return array(
				'labels'   => array(),
				'datasets' => array(
					array(
						'data'            => array(),
						'backgroundColor' => array(),
					),
				),
			);
		}

		$labels = array_keys( $breakdown['by_provider'] );
		$costs  = array_values( $breakdown['by_provider'] );

		// Provider colors.
		$colors = array(
			'rgba(54, 162, 235, 0.8)',  // Blue - OpenAI.
			'rgba(75, 192, 192, 0.8)',  // Green - Gemini.
			'rgba(153, 102, 255, 0.8)', // Purple - Anthropic.
			'rgba(255, 159, 64, 0.8)',  // Orange - Ollama.
			'rgba(255, 99, 132, 0.8)',  // Red - LM Studio.
		);

		return array(
			'labels'   => $labels,
			'datasets' => array(
				array(
					'data'            => $costs,
					'backgroundColor' => array_slice( $colors, 0, count( $costs ) ),
				),
			),
		);
	}
}
