<?php
/**
 * CRE Market Comp Analyzer — Analyze comparable property sales against a subject property
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
 * Compares a subject property to comparable sales, computing cap rate spreads,
 * price/SF differentials, and comp quality scores.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Market_Comp_Analyzer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_market_comp_analyzer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Market Comp Analyzer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Analyze comparable property sales against a subject property. Calculates average/median cap rates, price/SF, NOI/SF, premium/discount analysis, and comp quality scores.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'subject_property_type' => array(
					'type'        => 'string',
					'description' => __( 'Subject property type.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'office', 'retail', 'industrial', 'multifamily', 'hotel', 'other' ),
				),
				'subject_sf'            => array(
					'type'        => 'number',
					'description' => __( 'Subject property square footage.', 'mcp-ai-wpoos-pro' ),
				),
				'subject_noi'           => array(
					'type'        => 'number',
					'description' => __( 'Subject property annual NOI.', 'mcp-ai-wpoos-pro' ),
				),
				'subject_price'         => array(
					'type'        => 'number',
					'description' => __( 'Subject property price or value.', 'mcp-ai-wpoos-pro' ),
				),
				'comparables'           => array(
					'type'        => 'array',
					'description' => __( 'Array of comparable properties.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'           => array(
								'type'        => 'string',
								'description' => __( 'Comp name/address.', 'mcp-ai-wpoos-pro' ),
							),
							'price'          => array(
								'type'        => 'number',
								'description' => __( 'Sale price.', 'mcp-ai-wpoos-pro' ),
							),
							'sf'             => array(
								'type'        => 'number',
								'description' => __( 'Square footage.', 'mcp-ai-wpoos-pro' ),
							),
							'noi'            => array(
								'type'        => 'number',
								'description' => __( 'Annual NOI.', 'mcp-ai-wpoos-pro' ),
							),
							'cap_rate'       => array(
								'type'        => 'number',
								'description' => __( 'Cap rate as decimal.', 'mcp-ai-wpoos-pro' ),
							),
							'sale_date'      => array(
								'type'        => 'string',
								'description' => __( 'Sale date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
							),
							'distance_miles' => array(
								'type'        => 'number',
								'description' => __( 'Distance from subject in miles.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
			),
			'required'   => array( 'subject_property_type', 'subject_sf', 'subject_noi', 'subject_price', 'comparables' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only', 'cacheable' );
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

		$property_type = sanitize_text_field( $arguments['subject_property_type'] ?? 'other' );
		$subject_sf    = (float) ( $arguments['subject_sf'] ?? 0 );
		$subject_noi   = (float) ( $arguments['subject_noi'] ?? 0 );
		$subject_price = (float) ( $arguments['subject_price'] ?? 0 );
		$comps_raw     = $arguments['comparables'] ?? array();

		if ( $subject_sf <= 0 || $subject_noi <= 0 || $subject_price <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'Subject SF, NOI, and price must be positive.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( empty( $comps_raw ) || ! is_array( $comps_raw ) ) {
			return new WP_Error( 'invalid_input', __( 'At least one comparable is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$subject_cap_rate = $subject_noi / $subject_price;
		$subject_psf      = $subject_price / $subject_sf;
		$subject_noi_psf  = $subject_noi / $subject_sf;

		// Process comparables.
		$comp_details = array();
		$cap_rates    = array();
		$prices_psf   = array();
		$noi_psf_arr  = array();

		foreach ( $comps_raw as $comp ) {
			$c_name     = sanitize_text_field( $comp['name'] ?? 'Unknown' );
			$c_price    = (float) ( $comp['price'] ?? 0 );
			$c_sf       = (float) ( $comp['sf'] ?? 0 );
			$c_noi      = (float) ( $comp['noi'] ?? 0 );
			$c_cap      = (float) ( $comp['cap_rate'] ?? 0 );
			$c_date     = sanitize_text_field( $comp['sale_date'] ?? '' );
			$c_distance = (float) ( $comp['distance_miles'] ?? 0 );

			if ( $c_price <= 0 || $c_sf <= 0 ) {
				continue;
			}

			// Derive cap rate from NOI/price if not provided.
			if ( $c_cap <= 0 && $c_noi > 0 ) {
				$c_cap = $c_noi / $c_price;
			}

			$c_psf     = $c_price / $c_sf;
			$c_noi_psf = ( $c_noi > 0 ) ? $c_noi / $c_sf : 0.0;

			$cap_rates[]  = $c_cap;
			$prices_psf[] = $c_psf;
			if ( $c_noi_psf > 0 ) {
				$noi_psf_arr[] = $c_noi_psf;
			}

			// Comp quality score (0-100): proximity, recency, size similarity.
			$quality = $this->calculate_comp_quality( $c_sf, $subject_sf, $c_distance, $c_date );

			$comp_details[] = array(
				'name'          => $c_name,
				'price'         => '$' . number_format( $c_price, 0 ),
				'sf'            => number_format( $c_sf, 0 ),
				'price_per_sf'  => '$' . number_format( $c_psf, 2 ),
				'cap_rate'      => round( $c_cap * 100, 2 ) . '%',
				'noi_per_sf'    => '$' . number_format( $c_noi_psf, 2 ),
				'sale_date'     => $c_date,
				'distance'      => $c_distance . ' mi',
				'quality_score' => $quality,
			);
		}

		if ( empty( $cap_rates ) ) {
			return new WP_Error( 'invalid_input', __( 'No valid comparables with positive price and SF.', 'mcp-ai-wpoos-pro' ) );
		}

		// Compute statistics.
		$avg_cap     = array_sum( $cap_rates ) / count( $cap_rates );
		$median_cap  = $this->median( $cap_rates );
		$avg_psf     = array_sum( $prices_psf ) / count( $prices_psf );
		$median_psf  = $this->median( $prices_psf );
		$avg_noi_psf = ! empty( $noi_psf_arr ) ? array_sum( $noi_psf_arr ) / count( $noi_psf_arr ) : 0.0;

		// Premium/discount analysis.
		$cap_spread  = $subject_cap_rate - $avg_cap;
		$psf_premium = ( $avg_psf > 0 ) ? ( $subject_psf - $avg_psf ) / $avg_psf : 0.0;

		// Implied value from comp avg cap rate.
		$implied_value = ( $avg_cap > 0 ) ? $subject_noi / $avg_cap : 0.0;
		$value_diff    = $implied_value - $subject_price;

		// Average quality score.
		$quality_scores = array_column( $comp_details, 'quality_score' );
		$avg_quality    = ! empty( $quality_scores ) ? array_sum( $quality_scores ) / count( $quality_scores ) : 0;

		return array(
			'success' => true,
			'message' => __( 'Market comp analysis complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'subject'          => array(
					'property_type' => $property_type,
					'price'         => '$' . number_format( $subject_price, 0 ),
					'sf'            => number_format( $subject_sf, 0 ),
					'price_per_sf'  => '$' . number_format( $subject_psf, 2 ),
					'cap_rate'      => round( $subject_cap_rate * 100, 2 ) . '%',
					'noi_per_sf'    => '$' . number_format( $subject_noi_psf, 2 ),
				),
				'comp_statistics'  => array(
					'num_comps'        => count( $comp_details ),
					'avg_cap_rate'     => round( $avg_cap * 100, 2 ) . '%',
					'median_cap_rate'  => round( $median_cap * 100, 2 ) . '%',
					'avg_price_per_sf' => '$' . number_format( $avg_psf, 2 ),
					'median_price_psf' => '$' . number_format( $median_psf, 2 ),
					'avg_noi_per_sf'   => '$' . number_format( $avg_noi_psf, 2 ),
				),
				'premium_discount' => array(
					'cap_rate_spread'  => round( $cap_spread * 100, 0 ) . ' bps',
					'cap_rate_comment' => ( $cap_spread > 0 )
						? __( 'Subject trades at wider cap rate (discount)', 'mcp-ai-wpoos-pro' )
						: __( 'Subject trades at tighter cap rate (premium)', 'mcp-ai-wpoos-pro' ),
					'psf_premium'      => round( $psf_premium * 100, 2 ) . '%',
					'implied_value'    => '$' . number_format( $implied_value, 0 ),
					'value_difference' => '$' . number_format( $value_diff, 0 ),
				),
				'comp_quality'     => array(
					'avg_quality_score' => round( $avg_quality ),
					'rating'            => ( $avg_quality >= 80 ) ? 'Excellent' : ( ( $avg_quality >= 60 ) ? 'Good' : ( ( $avg_quality >= 40 ) ? 'Fair' : 'Weak' ) ),
				),
				'comparables'      => $comp_details,
			),
		);
	}

	/**
	 * Calculate comp quality score (0-100).
	 *
	 * @param float  $comp_sf     Comp square footage.
	 * @param float  $subject_sf  Subject square footage.
	 * @param float  $distance    Distance in miles.
	 * @param string $sale_date   Sale date (YYYY-MM-DD).
	 * @return int Quality score 0-100.
	 */
	private function calculate_comp_quality( float $comp_sf, float $subject_sf, float $distance, string $sale_date ): int {
		$score = 0;

		// Size similarity (up to 40 pts).
		$size_ratio = ( $subject_sf > 0 ) ? $comp_sf / $subject_sf : 0;
		$size_diff  = abs( 1.0 - $size_ratio );
		if ( $size_diff <= 0.10 ) {
			$score += 40;
		} elseif ( $size_diff <= 0.25 ) {
			$score += 30;
		} elseif ( $size_diff <= 0.50 ) {
			$score += 20;
		} elseif ( $size_diff <= 1.00 ) {
			$score += 10;
		}

		// Proximity (up to 30 pts).
		if ( $distance <= 0.5 ) {
			$score += 30;
		} elseif ( $distance <= 1.0 ) {
			$score += 25;
		} elseif ( $distance <= 3.0 ) {
			$score += 20;
		} elseif ( $distance <= 5.0 ) {
			$score += 15;
		} elseif ( $distance <= 10.0 ) {
			$score += 10;
		} else {
			$score += 5;
		}

		// Recency (up to 30 pts).
		if ( ! empty( $sale_date ) ) {
			$sale_ts = strtotime( $sale_date );
			$now_ts  = time();
			$months  = ( $sale_ts > 0 ) ? ( $now_ts - $sale_ts ) / ( 30 * DAY_IN_SECONDS ) : 999;

			if ( $months <= 3 ) {
				$score += 30;
			} elseif ( $months <= 6 ) {
				$score += 25;
			} elseif ( $months <= 12 ) {
				$score += 20;
			} elseif ( $months <= 18 ) {
				$score += 15;
			} elseif ( $months <= 24 ) {
				$score += 10;
			} else {
				$score += 5;
			}
		} else {
			$score += 10;
		}

		return min( 100, $score );
	}

	/**
	 * Calculate median of an array of numbers.
	 *
	 * @param array $values Numeric array.
	 * @return float Median value.
	 */
	private function median( array $values ): float {
		if ( empty( $values ) ) {
			return 0.0;
		}
		sort( $values );
		$count = count( $values );
		$mid   = (int) floor( $count / 2 );
		if ( 0 === $count % 2 ) {
			return ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2;
		}
		return $values[ $mid ];
	}
}
