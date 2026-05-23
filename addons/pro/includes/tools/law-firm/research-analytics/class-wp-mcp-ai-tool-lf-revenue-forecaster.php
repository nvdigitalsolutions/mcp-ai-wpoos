<?php
/**
 * Revenue Forecaster Tool
 *
 * Forecasts firm revenue based on historical data, current matters,
 * and billing trends with confidence intervals.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Forecasts firm revenue using historical billing data, trends, and confidence intervals.
 */
class WP_MCP_AI_Tool_LF_Revenue_Forecaster implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_law_firm_toolkit'] );
	}

	/**
	 * Get unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason(): string {
		return __( 'Law Firm toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'lf_revenue_forecaster'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Revenue Forecaster', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Forecasts firm revenue based on historical billing data, current pipeline, and collection trends. Includes confidence intervals and breakdown by source.', 'mcp-ai-wpoos-pro' ); }


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'forecast_period'     => array(
					'type'        => 'string',
					'enum'        => array( 'month', 'quarter', 'year' ),
					'description' => __( 'Period to forecast revenue for.', 'mcp-ai-wpoos-pro' ),
				),
				'practice_area'       => array(
					'type'        => 'string',
					'description' => __( 'Filter forecast by practice area (optional).', 'mcp-ai-wpoos-pro' ),
				),
				'include_contingency' => array(
					'type'        => 'boolean',
					'description' => __( 'Include contingency fee matters in forecast (default true).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'forecast_period' ),
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only', 'cacheable' ); }

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		require_once dirname( __DIR__ ) . '/class-wp-mcp-ai-law-firm-calculator.php';

		$forecast_period     = isset( $arguments['forecast_period'] ) ? sanitize_text_field( $arguments['forecast_period'] ) : 'quarter';
		$practice_area       = isset( $arguments['practice_area'] ) ? sanitize_text_field( $arguments['practice_area'] ) : '';
		$include_contingency = isset( $arguments['include_contingency'] ) ? (bool) $arguments['include_contingency'] : true;

		$allowed_periods = array( 'month', 'quarter', 'year' );
		if ( ! in_array( $forecast_period, $allowed_periods, true ) ) {
			$forecast_period = 'quarter';
		}

		// Determine historical lookback (use same-length past period for comparison).
		$months_map = array(
			'month'   => 1,
			'quarter' => 3,
			'year'    => 12,
		);
		$months     = $months_map[ $forecast_period ];

		$historical_start = gmdate( 'Y-m-d', strtotime( "-{$months} months" ) );
		$two_periods_back = gmdate( 'Y-m-d', strtotime( '-' . ( $months * 2 ) . ' months' ) );

		// Query recent period revenue.
		$recent_meta_query = array(
			array(
				'key'     => '_lf_entry_date',
				'value'   => $historical_start,
				'compare' => '>=',
				'type'    => 'DATE',
			),
		);
		if ( ! empty( $practice_area ) ) {
			$recent_meta_query[] = array(
				'key'     => '_lf_practice_area',
				'value'   => $practice_area,
				'compare' => 'LIKE',
			);
		}

		$recent_entries = get_posts(
			array(
				'post_type'      => 'mcp_ai_lf_time_entry',
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'lf_revenue_forecaster', 0, 1000 ) : 1000,
				'post_status'    => 'publish',
				'meta_query'     => $recent_meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);

		$recent_revenue    = 0;
		$recent_collected  = 0;
		$revenue_by_source = array(
			'hourly'      => 0,
			'flat_fee'    => 0,
			'contingency' => 0,
			'retainer'    => 0,
		);

		foreach ( $recent_entries as $entry ) {
			$amount    = (float) get_post_meta( $entry->ID, '_lf_amount', true );
			$collected = (float) get_post_meta( $entry->ID, '_lf_collected_amount', true );
			$fee_type  = get_post_meta( $entry->ID, '_lf_fee_type', true );

			if ( 'contingency' === $fee_type && ! $include_contingency ) {
				continue;
			}

			$recent_revenue   += $amount;
			$recent_collected += $collected;

			$source_key                        = isset( $revenue_by_source[ $fee_type ] ) ? $fee_type : 'hourly';
			$revenue_by_source[ $source_key ] += $amount;
		}

		// Query prior period for trend comparison.
		$prior_meta_query = array(
			'relation' => 'AND',
			array(
				'key'     => '_lf_entry_date',
				'value'   => $two_periods_back,
				'compare' => '>=',
				'type'    => 'DATE',
			),
			array(
				'key'     => '_lf_entry_date',
				'value'   => $historical_start,
				'compare' => '<',
				'type'    => 'DATE',
			),
		);
		if ( ! empty( $practice_area ) ) {
			$prior_meta_query[] = array(
				'key'     => '_lf_practice_area',
				'value'   => $practice_area,
				'compare' => 'LIKE',
			);
		}

		$prior_entries = get_posts(
			array(
				'post_type'      => 'mcp_ai_lf_time_entry',
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'lf_revenue_forecaster', 0, 1000 ) : 1000,
				'post_status'    => 'publish',
				'meta_query'     => $prior_meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);

		$prior_revenue = 0;
		foreach ( $prior_entries as $entry ) {
			$amount   = (float) get_post_meta( $entry->ID, '_lf_amount', true );
			$fee_type = get_post_meta( $entry->ID, '_lf_fee_type', true );
			if ( 'contingency' === $fee_type && ! $include_contingency ) {
				continue;
			}
			$prior_revenue += $amount;
		}

		// Calculate growth rate and forecast.
		$growth_rate = 0;
		if ( $prior_revenue > 0 ) {
			$growth_rate = ( $recent_revenue - $prior_revenue ) / $prior_revenue;
		}

		$forecast_amount = round( $recent_revenue * ( 1 + $growth_rate ), 2 );

		// Collection rate for adjustment.
		$collection_rate   = $recent_revenue > 0 ? $recent_collected / $recent_revenue : 0.85;
		$adjusted_forecast = round( $forecast_amount * $collection_rate, 2 );

		// Confidence interval based on variance between periods.
		$variance = abs( $recent_revenue - $prior_revenue );
		$std_dev  = $variance / 2;
		$ci_low   = round( max( 0, $adjusted_forecast - ( 1.96 * $std_dev ) ), 2 );
		$ci_high  = round( $adjusted_forecast + ( 1.96 * $std_dev ), 2 );

		// Count active matters in pipeline.
		$pipeline_args = array(
			'post_type'      => 'mcp_ai_lf_matter',
			'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'lf_revenue_forecaster', 0, 1000 ) : 1000,
			'post_status'    => 'publish',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_lf_status',
					'value'   => array( 'active', 'in_progress' ),
					'compare' => 'IN',
				),
			),
		);
		if ( ! empty( $practice_area ) ) {
			$pipeline_args['meta_query'][] = array(
				'key'     => '_lf_practice_area',
				'value'   => $practice_area,
				'compare' => 'LIKE',
			);
		}
		$active_matters = get_posts( $pipeline_args );

		$pipeline_value = 0;
		foreach ( $active_matters as $am ) {
			$budget          = (float) get_post_meta( $am->ID, '_lf_budget', true );
			$spent           = (float) get_post_meta( $am->ID, '_lf_total_billed', true );
			$pipeline_value += max( 0, $budget - $spent );
		}

		// Trend direction.
		$trend = 'stable';
		if ( $growth_rate > 0.05 ) {
			$trend = 'growing';
		} elseif ( $growth_rate < -0.05 ) {
			$trend = 'declining';
		}

		// Forecast breakdown by source (apply growth rate proportionally).
		$forecast_by_source = array();
		foreach ( $revenue_by_source as $source => $amount ) {
			$forecast_by_source[ $source ] = round( $amount * ( 1 + $growth_rate ), 2 );
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: forecast amount, 2: forecast period, 3: trend */
				__( 'Revenue forecast: $%1$s for next %2$s (%3$s trend). ', 'mcp-ai-wpoos-pro' ),
				number_format( $adjusted_forecast, 2 ),
				$forecast_period,
				$trend
			) . self::DISCLAIMER,
			'data'       => array(
				'forecast_period'     => $forecast_period,
				'practice_area'       => $practice_area,
				'forecast_amount'     => $adjusted_forecast,
				'gross_forecast'      => $forecast_amount,
				'confidence_interval' => array(
					'low'  => $ci_low,
					'high' => $ci_high,
				),
				'breakdown_by_source' => $forecast_by_source,
				'trend_analysis'      => array(
					'direction'             => $trend,
					'growth_rate'           => round( $growth_rate * 100, 1 ),
					'prior_period_revenue'  => round( $prior_revenue, 2 ),
					'recent_period_revenue' => round( $recent_revenue, 2 ),
					'collection_rate'       => round( $collection_rate * 100, 1 ),
				),
				'pipeline'            => array(
					'active_matters' => count( $active_matters ),
					'pipeline_value' => round( $pipeline_value, 2 ),
				),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
