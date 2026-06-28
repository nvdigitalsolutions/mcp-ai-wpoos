<?php
/**
 * CRE Property Performance Tracker — Track property financial performance across periods
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/class-wp-mcp-ai-cre-debt-calculator.php';

/**
 * Tracks and analyzes property financial performance across multiple periods.
 * Calculates NOI, margins, expense ratios, and identifies performance trends
 * for occupancy, collections, and net operating income.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Property_Performance_Tracker implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_cre_debt_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason(): string {
		return __( 'CRE Debt & Securitization toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug(): string {
		return 'cre_property_performance_tracker';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Property Performance Tracker', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Track and analyze property financial performance across multiple periods. Calculates NOI, margins, expense ratios, and identifies performance trends.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'property_name' => array(
					'type'        => 'string',
					'description' => __( 'Property name.', 'mcp-ai-wpoos-pro' ),
				),
				'periods'       => array(
					'type'        => 'array',
					'description' => __( 'Array of period performance objects.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'period'          => array(
								'type'        => 'string',
								'description' => __( 'Period identifier (e.g. "2024-Q1", "2024-01").', 'mcp-ai-wpoos-pro' ),
							),
							'revenue'         => array(
								'type'        => 'number',
								'description' => __( 'Gross revenue for the period.', 'mcp-ai-wpoos-pro' ),
							),
							'opex'            => array(
								'type'        => 'number',
								'description' => __( 'Operating expenses for the period.', 'mcp-ai-wpoos-pro' ),
							),
							'occupancy_pct'   => array(
								'type'        => 'number',
								'description' => __( 'Occupancy percentage (e.g. 95 for 95%).', 'mcp-ai-wpoos-pro' ),
							),
							'collections_pct' => array(
								'type'        => 'number',
								'description' => __( 'Collections percentage (e.g. 98 for 98%). Default 100.', 'mcp-ai-wpoos-pro' ),
								'default'     => 100,
							),
							'capex'           => array(
								'type'        => 'number',
								'description' => __( 'Capital expenditures for the period. Default 0.', 'mcp-ai-wpoos-pro' ),
								'default'     => 0,
							),
						),
						'required'   => array( 'period', 'revenue', 'opex', 'occupancy_pct' ),
					),
				),
			),
			'required'   => array( 'property_name', 'periods' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only' );
	}

	/**
	 * Get required capability.
	 *
	 * @return string
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
	public function execute( array $arguments = array(), array $context = array() ): array|\WP_Error {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$property_name = sanitize_text_field( $arguments['property_name'] ?? '' );
		$raw_periods   = $arguments['periods'] ?? array();

		if ( empty( $property_name ) ) {
			return new WP_Error( 'invalid_input', __( 'property_name is required.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( empty( $raw_periods ) || ! is_array( $raw_periods ) ) {
			return new WP_Error( 'invalid_input', __( 'At least one period entry is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		$period_details  = array();
		$total_revenue   = 0.0;
		$total_opex      = 0.0;
		$total_noi       = 0.0;
		$total_capex     = 0.0;
		$sum_occupancy   = 0.0;
		$sum_collections = 0.0;
		$sum_noi_margin  = 0.0;
		$sum_opex_ratio  = 0.0;

		foreach ( $raw_periods as $raw ) {
			$period          = sanitize_text_field( $raw['period'] ?? '' );
			$revenue         = (float) ( $raw['revenue'] ?? 0 );
			$opex            = (float) ( $raw['opex'] ?? 0 );
			$occupancy_pct   = (float) ( $raw['occupancy_pct'] ?? 0 );
			$collections_pct = (float) ( $raw['collections_pct'] ?? 100 );
			$capex           = (float) ( $raw['capex'] ?? 0 );

			if ( empty( $period ) ) {
				continue;
			}

			$effective_revenue = $revenue * $collections_pct / 100;
			$noi               = $effective_revenue - $opex;
			$noi_margin        = ( $revenue > 0 ) ? $noi / $revenue : 0;
			$opex_ratio        = ( $revenue > 0 ) ? $opex / $revenue : 0;

			$period_details[] = array(
				'period'            => $period,
				'revenue'           => $calc::format_currency( $revenue ),
				'effective_revenue' => $calc::format_currency( $effective_revenue ),
				'opex'              => $calc::format_currency( $opex ),
				'noi'               => $calc::format_currency( $noi ),
				'noi_margin'        => $calc::format_percentage( $noi_margin ),
				'opex_ratio'        => $calc::format_percentage( $opex_ratio ),
				'occupancy_pct'     => $calc::format_percentage( $occupancy_pct / 100 ),
				'collections_pct'   => $calc::format_percentage( $collections_pct / 100 ),
				'capex'             => $calc::format_currency( $capex ),
				'_raw_noi'          => $noi,
				'_raw_occupancy'    => $occupancy_pct,
				'_raw_collections'  => $collections_pct,
			);

			$total_revenue   += $revenue;
			$total_opex      += $opex;
			$total_noi       += $noi;
			$total_capex     += $capex;
			$sum_occupancy   += $occupancy_pct;
			$sum_collections += $collections_pct;
			$sum_noi_margin  += $noi_margin;
			$sum_opex_ratio  += $opex_ratio;
		}

		if ( empty( $period_details ) ) {
			return new WP_Error( 'invalid_input', __( 'No valid period entries provided.', 'mcp-ai-wpoos-pro' ) );
		}

		$period_count    = count( $period_details );
		$avg_occupancy   = $sum_occupancy / $period_count;
		$avg_collections = $sum_collections / $period_count;
		$avg_noi_margin  = $sum_noi_margin / $period_count;
		$avg_opex_ratio  = $sum_opex_ratio / $period_count;

		// Determine trends.
		$first = $period_details[0];
		$last  = $period_details[ $period_count - 1 ];

		$noi_trend         = 'stable';
		$occupancy_trend   = 'stable';
		$collections_trend = 'stable';

		if ( $period_count > 1 ) {
			// NOI trend.
			$first_noi = $first['_raw_noi'];
			$last_noi  = $last['_raw_noi'];
			if ( 0.0 !== $first_noi ) {
				$noi_growth_rate = ( $last_noi - $first_noi ) / abs( $first_noi );
				if ( $noi_growth_rate > 0.02 ) {
					$noi_trend = 'improving';
				} elseif ( $noi_growth_rate < -0.02 ) {
					$noi_trend = 'declining';
				}
			}

			// Occupancy trend.
			$first_occ = $first['_raw_occupancy'];
			$last_occ  = $last['_raw_occupancy'];
			if ( 0.0 !== $first_occ ) {
				$occ_growth_rate = ( $last_occ - $first_occ ) / abs( $first_occ );
				if ( $occ_growth_rate > 0.02 ) {
					$occupancy_trend = 'improving';
				} elseif ( $occ_growth_rate < -0.02 ) {
					$occupancy_trend = 'declining';
				}
			}

			// Collections trend.
			$first_coll = $first['_raw_collections'];
			$last_coll  = $last['_raw_collections'];
			if ( 0.0 !== $first_coll ) {
				$coll_growth_rate = ( $last_coll - $first_coll ) / abs( $first_coll );
				if ( $coll_growth_rate > 0.02 ) {
					$collections_trend = 'improving';
				} elseif ( $coll_growth_rate < -0.02 ) {
					$collections_trend = 'declining';
				}
			}
		}

		// Strip internal raw values from output.
		$clean_details = array();
		foreach ( $period_details as $detail ) {
			unset( $detail['_raw_noi'], $detail['_raw_occupancy'], $detail['_raw_collections'] );
			$clean_details[] = $detail;
		}

		return array(
			'success'    => true,
			'message'    => __( 'Property performance analysis complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'       => array(
				'property_name'  => $property_name,
				'period_count'   => $period_count,
				'period_details' => $clean_details,
				'summary'        => array(
					'total_revenue'   => $calc::format_currency( $total_revenue ),
					'total_opex'      => $calc::format_currency( $total_opex ),
					'total_noi'       => $calc::format_currency( $total_noi ),
					'total_capex'     => $calc::format_currency( $total_capex ),
					'avg_occupancy'   => $calc::format_percentage( $avg_occupancy / 100 ),
					'avg_collections' => $calc::format_percentage( $avg_collections / 100 ),
					'avg_noi_margin'  => $calc::format_percentage( $avg_noi_margin ),
					'avg_opex_ratio'  => $calc::format_percentage( $avg_opex_ratio ),
				),
				'trends'         => array(
					'noi'         => $noi_trend,
					'occupancy'   => $occupancy_trend,
					'collections' => $collections_trend,
				),
			),
			'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
