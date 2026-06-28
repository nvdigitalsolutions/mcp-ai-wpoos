<?php
/**
 * CRE Origination Volume Tracker — Analyze pipeline volume, conversion rates, and originator performance
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

/**
 * Analyzes origination deal data to produce volume metrics, stage breakdowns,
 * conversion rates, average time-to-close, and originator rankings.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Origination_Volume_Tracker implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_origination_volume_tracker';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Origination Volume Tracker', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Analyze a set of origination deals to calculate total pipeline volume, stage-by-stage breakdown, conversion rates, average deal size, average time-to-close, and top originator rankings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'deals' => array(
					'type'        => 'array',
					'description' => __( 'Array of deal records.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'amount'        => array(
								'type'        => 'number',
								'description' => __( 'Deal loan amount.', 'mcp-ai-wpoos-pro' ),
							),
							'stage'         => array(
								'type'        => 'string',
								'description' => __( 'Current pipeline stage.', 'mcp-ai-wpoos-pro' ),
								'enum'        => array( 'sourced', 'screened', 'loi', 'ic_review', 'approved', 'closing', 'closed', 'dead' ),
							),
							'property_type' => array(
								'type'        => 'string',
								'description' => __( 'Property type.', 'mcp-ai-wpoos-pro' ),
								'enum'        => array( 'office', 'retail', 'industrial', 'multifamily', 'hotel', 'other' ),
							),
							'originator'    => array(
								'type'        => 'string',
								'description' => __( 'Originator name.', 'mcp-ai-wpoos-pro' ),
							),
							'date_entered'  => array(
								'type'        => 'string',
								'description' => __( 'Date entered pipeline (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
							),
							'date_closed'   => array(
								'type'        => 'string',
								'description' => __( 'Date closed, if applicable (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
			),
			'required'   => array( 'deals' ),
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
	public function execute( array $arguments = array(), array $context = array() ): array|WP_Error {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$deals_raw = $arguments['deals'] ?? array();
		if ( empty( $deals_raw ) || ! is_array( $deals_raw ) ) {
			return new WP_Error( 'invalid_input', __( 'At least one deal is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$total_volume     = 0.0;
		$closed_volume    = 0.0;
		$total_count      = 0;
		$closed_count     = 0;
		$stage_breakdown  = array();
		$type_breakdown   = array();
		$originator_stats = array();
		$close_durations  = array();

		// Pipeline stage ordering for funnel analysis.
		$stage_order = array(
			'sourced'   => 1,
			'screened'  => 2,
			'loi'       => 3,
			'ic_review' => 4,
			'approved'  => 5,
			'closing'   => 6,
			'closed'    => 7,
			'dead'      => 0,
		);

		$screened_count = 0;

		foreach ( $deals_raw as $deal ) {
			$amount     = (float) ( $deal['amount'] ?? 0 );
			$stage      = sanitize_text_field( $deal['stage'] ?? 'sourced' );
			$prop_type  = sanitize_text_field( $deal['property_type'] ?? 'other' );
			$originator = sanitize_text_field( $deal['originator'] ?? 'Unknown' );
			$entered    = sanitize_text_field( $deal['date_entered'] ?? '' );
			$closed_dt  = sanitize_text_field( $deal['date_closed'] ?? '' );

			$total_volume += $amount;
			++$total_count;

			// Stage breakdown.
			if ( ! isset( $stage_breakdown[ $stage ] ) ) {
				$stage_breakdown[ $stage ] = array(
					'count'  => 0,
					'volume' => 0.0,
				);
			}
			++$stage_breakdown[ $stage ]['count'];
			$stage_breakdown[ $stage ]['volume'] += $amount;

			// Type breakdown.
			if ( ! isset( $type_breakdown[ $prop_type ] ) ) {
				$type_breakdown[ $prop_type ] = array(
					'count'  => 0,
					'volume' => 0.0,
				);
			}
			++$type_breakdown[ $prop_type ]['count'];
			$type_breakdown[ $prop_type ]['volume'] += $amount;

			// Originator stats.
			if ( ! isset( $originator_stats[ $originator ] ) ) {
				$originator_stats[ $originator ] = array(
					'total_deals'   => 0,
					'total_volume'  => 0.0,
					'closed_deals'  => 0,
					'closed_volume' => 0.0,
				);
			}
			++$originator_stats[ $originator ]['total_deals'];
			$originator_stats[ $originator ]['total_volume'] += $amount;

			// Track screened+ for conversion.
			if ( isset( $stage_order[ $stage ] ) && $stage_order[ $stage ] >= 2 ) {
				++$screened_count;
			}

			// Closed deals.
			if ( 'closed' === $stage ) {
				$closed_volume += $amount;
				++$closed_count;
				++$originator_stats[ $originator ]['closed_deals'];
				$originator_stats[ $originator ]['closed_volume'] += $amount;

				// Time to close.
				if ( $entered && $closed_dt ) {
					$entered_ts = strtotime( $entered );
					$closed_ts  = strtotime( $closed_dt );
					if ( $entered_ts > 0 && $closed_ts > $entered_ts ) {
						$close_durations[] = ( $closed_ts - $entered_ts ) / DAY_IN_SECONDS;
					}
				}
			}
		}

		$avg_deal_size     = ( $total_count > 0 ) ? $total_volume / $total_count : 0.0;
		$avg_time_to_close = ! empty( $close_durations ) ? array_sum( $close_durations ) / count( $close_durations ) : 0.0;

		// Conversion rates.
		$screened_to_closed = ( $screened_count > 0 ) ? $closed_count / $screened_count : 0.0;
		$sourced_to_closed  = ( $total_count > 0 ) ? $closed_count / $total_count : 0.0;

		// Format stage breakdown volumes.
		foreach ( $stage_breakdown as $stage => $data ) {
			$stage_breakdown[ $stage ]['volume'] = round( $data['volume'], 2 );
		}
		foreach ( $type_breakdown as $type => $data ) {
			$type_breakdown[ $type ]['volume'] = round( $data['volume'], 2 );
		}

		// Rank originators by closed volume.
		$originator_rankings = array();
		foreach ( $originator_stats as $name => $stats ) {
			$conversion            = ( $stats['total_deals'] > 0 ) ? $stats['closed_deals'] / $stats['total_deals'] : 0.0;
			$originator_rankings[] = array(
				'originator'      => $name,
				'total_deals'     => $stats['total_deals'],
				'total_volume'    => '$' . number_format( $stats['total_volume'], 0 ),
				'closed_deals'    => $stats['closed_deals'],
				'closed_volume'   => '$' . number_format( $stats['closed_volume'], 0 ),
				'conversion_rate' => round( $conversion * 100, 1 ) . '%',
			);
		}

		usort(
			$originator_rankings,
			function ( $a, $b ) {
				return (float) str_replace( array( '$', ',' ), '', $b['closed_volume'] )
				<=> (float) str_replace( array( '$', ',' ), '', $a['closed_volume'] );
			}
		);

		return array(
			'success' => true,
			'message' => __( 'Origination volume analysis complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'summary'             => array(
					'total_deals'       => $total_count,
					'total_volume'      => '$' . number_format( $total_volume, 0 ),
					'closed_deals'      => $closed_count,
					'closed_volume'     => '$' . number_format( $closed_volume, 0 ),
					'avg_deal_size'     => '$' . number_format( $avg_deal_size, 0 ),
					'avg_time_to_close' => round( $avg_time_to_close, 1 ) . ' days',
				),
				'conversion_rates'    => array(
					'sourced_to_closed'  => round( $sourced_to_closed * 100, 1 ) . '%',
					'screened_to_closed' => round( $screened_to_closed * 100, 1 ) . '%',
				),
				'stage_breakdown'     => $stage_breakdown,
				'property_breakdown'  => $type_breakdown,
				'originator_rankings' => $originator_rankings,
			),
		);
	}
}
