<?php
/**
 * CMBS Rating Agency Analyzer — Estimate required subordination by rating level
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
 * Models a simplified rating agency methodology to estimate required subordination
 * levels for each rating (AAA through B). Considers WA LTV, DSCR, property type
 * concentration, geographic diversity, sponsor quality, and structural features.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CMBS_Rating_Agency_Analyzer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Base subordination levels as starting points.
	 *
	 * @var array
	 */
	private static $base_subordination = array(
		'AAA' => 0.30,
		'AA'  => 0.22,
		'A'   => 0.16,
		'BBB' => 0.10,
		'BB'  => 0.06,
		'B'   => 0.03,
	);

	/**
	 * Property type loss multipliers (relative to office baseline of 1.0).
	 *
	 * @var array
	 */
	private static $property_loss_multipliers = array(
		'multifamily' => 0.75,
		'industrial'  => 0.80,
		'retail'      => 1.10,
		'office'      => 1.00,
		'hotel'       => 1.35,
		'mixed_use'   => 0.95,
		'other'       => 1.15,
	);

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
		return 'cmbs_rating_agency_analyzer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CMBS Rating Agency Analyzer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Estimate required subordination levels for each rating (AAA through B) using a simplified rating agency methodology. Considers LTV, DSCR, property type mix, geographic diversity, and sponsor quality.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'pool_balance'               => array(
					'type'        => 'number',
					'description' => __( 'Total pool balance.', 'mcp-ai-wpoos-pro' ),
				),
				'wa_dscr'                    => array(
					'type'        => 'number',
					'description' => __( 'Weighted average DSCR of the pool.', 'mcp-ai-wpoos-pro' ),
				),
				'wa_ltv'                     => array(
					'type'        => 'number',
					'description' => __( 'Weighted average LTV as decimal (e.g. 0.65 for 65%).', 'mcp-ai-wpoos-pro' ),
				),
				'property_type_mix'          => array(
					'type'        => 'object',
					'description' => __( 'Property type mix as type => percentage (decimal). E.g. {"office": 0.30, "multifamily": 0.40}.', 'mcp-ai-wpoos-pro' ),
				),
				'geographic_diversity_score' => array(
					'type'        => 'integer',
					'description' => __( 'Geographic diversity score from 1 (highly concentrated) to 10 (highly diversified).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 10,
				),
				'sponsor_quality_score'      => array(
					'type'        => 'integer',
					'description' => __( 'Sponsor quality score from 1 (weak) to 10 (institutional).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 10,
				),
				'loan_structural_features'   => array(
					'type'        => 'array',
					'description' => __( 'Array of loan structural features present (e.g. lockbox, cash_sweep, reserves, interest_only).', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
			),
			'required'   => array( 'pool_balance', 'wa_dscr', 'wa_ltv' ),
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
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$pool_balance  = (float) ( $arguments['pool_balance'] ?? 0 );
		$wa_dscr       = (float) ( $arguments['wa_dscr'] ?? 0 );
		$wa_ltv        = (float) ( $arguments['wa_ltv'] ?? 0 );
		$type_mix      = $arguments['property_type_mix'] ?? array();
		$geo_score     = (int) ( $arguments['geographic_diversity_score'] ?? 5 );
		$sponsor_score = (int) ( $arguments['sponsor_quality_score'] ?? 5 );
		$structural    = $arguments['loan_structural_features'] ?? array();

		if ( $pool_balance <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'Pool balance must be positive.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $wa_dscr <= 0 || $wa_ltv <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'WA DSCR and WA LTV must be positive.', 'mcp-ai-wpoos-pro' ) );
		}

		$geo_score     = max( 1, min( 10, $geo_score ) );
		$sponsor_score = max( 1, min( 10, $sponsor_score ) );

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		// Sanitize structural features.
		$structural = array_map( 'sanitize_text_field', (array) $structural );

		// 1. LTV adjustment: higher LTV = higher required subordination.
		$ltv_adjustment = 0.0;
		if ( $wa_ltv > 0.75 ) {
			$ltv_adjustment = ( $wa_ltv - 0.75 ) * 0.5;
		} elseif ( $wa_ltv < 0.60 ) {
			$ltv_adjustment = ( $wa_ltv - 0.60 ) * 0.3;
		}

		// 2. DSCR adjustment: higher DSCR = lower required subordination.
		$dscr_adjustment = 0.0;
		if ( $wa_dscr < 1.20 ) {
			$dscr_adjustment = ( 1.20 - $wa_dscr ) * 0.25;
		} elseif ( $wa_dscr > 1.50 ) {
			$dscr_adjustment = -1 * ( $wa_dscr - 1.50 ) * 0.10;
		}

		// 3. Property type adjustment: weighted loss multiplier.
		$wa_loss_multiplier = 0.0;
		$total_type_weight  = 0.0;
		if ( ! empty( $type_mix ) && is_array( $type_mix ) ) {
			foreach ( $type_mix as $type => $pct ) {
				$type                = sanitize_text_field( $type );
				$pct                 = (float) $pct;
				$mult                = self::$property_loss_multipliers[ $type ] ?? 1.0;
				$wa_loss_multiplier += $mult * $pct;
				$total_type_weight  += $pct;
			}
			if ( $total_type_weight > 0 ) {
				$wa_loss_multiplier /= $total_type_weight;
			} else {
				$wa_loss_multiplier = 1.0;
			}
		} else {
			$wa_loss_multiplier = 1.0;
		}

		$property_adjustment = ( $wa_loss_multiplier - 1.0 ) * 0.15;

		// 4. Geographic diversity adjustment.
		$geo_adjustment = ( 5 - $geo_score ) * 0.005;

		// 5. Sponsor quality adjustment.
		$sponsor_adjustment = ( 5 - $sponsor_score ) * 0.003;

		// 6. Structural features adjustment.
		$structural_adjustment = 0.0;
		$positive_features     = array( 'lockbox', 'cash_sweep', 'reserves' );
		$negative_features     = array( 'interest_only', 'no_reserves' );

		foreach ( $structural as $feature ) {
			if ( in_array( $feature, $positive_features, true ) ) {
				$structural_adjustment -= 0.005;
			}
			if ( in_array( $feature, $negative_features, true ) ) {
				$structural_adjustment += 0.008;
			}
		}

		// Total adjustment.
		$total_adjustment = $ltv_adjustment + $dscr_adjustment + $property_adjustment + $geo_adjustment + $sponsor_adjustment + $structural_adjustment;

		// Calculate required subordination per rating.
		$subordination_levels = array();
		foreach ( self::$base_subordination as $rating => $base ) {
			$adjusted = max( 0, $base + $total_adjustment );
			// Scale adjustment: senior tranches get full adj, junior get proportional.
			$scale_factor = $base / self::$base_subordination['AAA'];
			$adjusted     = max( 0, $base + ( $total_adjustment * $scale_factor ) );

			$subordination_levels[] = array(
				'rating'                  => $rating,
				'base_subordination'      => $calc::format_percentage( $base ),
				'adjusted_subordination'  => $calc::format_percentage( $adjusted ),
				'credit_enhancement'      => $calc::format_percentage( $adjusted ),
				'max_tranche_attachment'  => $calc::format_percentage( 1.0 - $adjusted ),
				'implied_tranche_balance' => $calc::format_currency( $pool_balance * ( 1.0 - $adjusted ) ),
			);
		}

		// Adjustment breakdown.
		$adjustment_details = array(
			'ltv_adjustment'        => array(
				'factor'      => $calc::format_percentage( $ltv_adjustment ),
				'description' => sprintf(
					/* translators: %s: WA LTV percentage */
					__( 'LTV adjustment for WA LTV of %s.', 'mcp-ai-wpoos-pro' ),
					$calc::format_percentage( $wa_ltv )
				),
			),
			'dscr_adjustment'       => array(
				'factor'      => $calc::format_percentage( $dscr_adjustment ),
				'description' => sprintf(
					/* translators: %s: WA DSCR */
					__( 'DSCR adjustment for WA DSCR of %s.', 'mcp-ai-wpoos-pro' ),
					number_format( $wa_dscr, 2 ) . 'x'
				),
			),
			'property_adjustment'   => array(
				'factor'        => $calc::format_percentage( $property_adjustment ),
				'wa_multiplier' => round( $wa_loss_multiplier, 3 ),
			),
			'geographic_adjustment' => array(
				'factor' => $calc::format_percentage( $geo_adjustment ),
				'score'  => $geo_score,
			),
			'sponsor_adjustment'    => array(
				'factor' => $calc::format_percentage( $sponsor_adjustment ),
				'score'  => $sponsor_score,
			),
			'structural_adjustment' => array(
				'factor'   => $calc::format_percentage( $structural_adjustment ),
				'features' => $structural,
			),
			'total_adjustment'      => $calc::format_percentage( $total_adjustment ),
		);

		// Pool quality assessment.
		$quality_score  = 50; // Start at midpoint.
		$quality_score += min( 15, max( -15, ( $wa_dscr - 1.25 ) * 30 ) );
		$quality_score += min( 15, max( -15, ( 0.70 - $wa_ltv ) * 50 ) );
		$quality_score += ( $geo_score - 5 ) * 2;
		$quality_score += ( $sponsor_score - 5 ) * 2;
		$quality_score  = max( 0, min( 100, $quality_score ) );

		if ( $quality_score >= 80 ) {
			$quality_grade = 'A';
			$quality_label = __( 'Strong - Below average subordination likely', 'mcp-ai-wpoos-pro' );
		} elseif ( $quality_score >= 60 ) {
			$quality_grade = 'B+';
			$quality_label = __( 'Above Average - Moderate subordination expected', 'mcp-ai-wpoos-pro' );
		} elseif ( $quality_score >= 40 ) {
			$quality_grade = 'B';
			$quality_label = __( 'Average - Standard subordination levels', 'mcp-ai-wpoos-pro' );
		} elseif ( $quality_score >= 20 ) {
			$quality_grade = 'C';
			$quality_label = __( 'Below Average - Higher subordination required', 'mcp-ai-wpoos-pro' );
		} else {
			$quality_grade = 'D';
			$quality_label = __( 'Weak - Significantly elevated subordination needed', 'mcp-ai-wpoos-pro' );
		}

		$pool_quality = array(
			'score'      => round( $quality_score, 1 ),
			'grade'      => $quality_grade,
			'assessment' => $quality_label,
		);

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: quality grade, 2: AAA subordination */
				__( 'Rating analysis complete. Pool grade: %1$s. Est. AAA subordination: %2$s.', 'mcp-ai-wpoos-pro' ),
				$quality_grade,
				$subordination_levels[0]['adjusted_subordination']
			),
			'data'    => array(
				'subordination_levels' => $subordination_levels,
				'adjustment_details'   => $adjustment_details,
				'pool_quality'         => $pool_quality,
				'inputs'               => array(
					'pool_balance'    => $calc::format_currency( $pool_balance ),
					'wa_dscr'         => round( $wa_dscr, 2 ),
					'wa_ltv'          => $calc::format_percentage( $wa_ltv ),
					'geo_diversity'   => $geo_score . '/10',
					'sponsor_quality' => $sponsor_score . '/10',
				),
				'disclaimer'           => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			),
		);
	}
}
