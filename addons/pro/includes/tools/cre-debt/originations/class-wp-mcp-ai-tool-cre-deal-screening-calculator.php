<?php
/**
 * CRE Deal Screening Calculator — Score deals on a 100-point scale for go/no-go decisions
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
 * Screens CRE deals on a 100-point scale across five dimensions:
 * LTV (25pts), DSCR (25pts), Debt Yield (20pts), Sponsor (15pts), Market (15pts).
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Deal_Screening_Calculator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_deal_screening_calculator';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Deal Screening Calculator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Score a CRE deal on a 100-point scale across LTV (25pts), DSCR (25pts), Debt Yield (20pts), Sponsor (15pts), and Market (15pts). Returns a go/no-go recommendation with detailed scoring breakdown.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'property_value'           => array(
					'type'        => 'number',
					'description' => __( 'Property appraised value.', 'mcp-ai-wpoos-pro' ),
				),
				'noi'                      => array(
					'type'        => 'number',
					'description' => __( 'Annual Net Operating Income.', 'mcp-ai-wpoos-pro' ),
				),
				'requested_loan_amount'    => array(
					'type'        => 'number',
					'description' => __( 'Requested loan amount.', 'mcp-ai-wpoos-pro' ),
				),
				'property_type'            => array(
					'type'        => 'string',
					'description' => __( 'Property type.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'office', 'retail', 'industrial', 'multifamily', 'hotel', 'other' ),
				),
				'market_tier'              => array(
					'type'        => 'string',
					'description' => __( 'Market tier classification.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'primary', 'secondary', 'tertiary' ),
				),
				'sponsor_experience_years' => array(
					'type'        => 'integer',
					'description' => __( 'Sponsor years of CRE experience.', 'mcp-ai-wpoos-pro' ),
				),
				'interest_rate'            => array(
					'type'        => 'number',
					'description' => __( 'Annual interest rate as decimal (e.g. 0.065).', 'mcp-ai-wpoos-pro' ),
				),
				'amort_months'             => array(
					'type'        => 'integer',
					'description' => __( 'Amortization period in months.', 'mcp-ai-wpoos-pro' ),
					'default'     => 360,
				),
			),
			'required'   => array( 'property_value', 'noi', 'requested_loan_amount', 'property_type', 'market_tier', 'sponsor_experience_years', 'interest_rate' ),
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

		$property_value = (float) ( $arguments['property_value'] ?? 0 );
		$noi            = (float) ( $arguments['noi'] ?? 0 );
		$loan_amount    = (float) ( $arguments['requested_loan_amount'] ?? 0 );
		$property_type  = sanitize_text_field( $arguments['property_type'] ?? 'other' );
		$market_tier    = sanitize_text_field( $arguments['market_tier'] ?? 'secondary' );
		$sponsor_years  = (int) ( $arguments['sponsor_experience_years'] ?? 0 );
		$interest_rate  = (float) ( $arguments['interest_rate'] ?? 0 );
		$amort_months   = (int) ( $arguments['amort_months'] ?? 360 );

		if ( $property_value <= 0 || $noi <= 0 || $loan_amount <= 0 || $interest_rate <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'Property value, NOI, loan amount, and interest rate must be positive.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		// Compute metrics.
		$ltv         = $calc::calculate_ltv( $loan_amount, $property_value );
		$debt_yield  = $calc::calculate_debt_yield( $noi, $loan_amount );
		$monthly_pmt = $calc::calculate_monthly_payment( $loan_amount, $interest_rate, $amort_months );
		$annual_ds   = $monthly_pmt * 12;
		$dscr        = $calc::calculate_dscr( $noi, $annual_ds );

		// Score LTV (25 pts) — lower is better.
		$ltv_score = $this->score_ltv( $ltv, $property_type );

		// Score DSCR (25 pts) — higher is better.
		$dscr_score = $this->score_dscr( $dscr );

		// Score Debt Yield (20 pts) — higher is better.
		$dy_score = $this->score_debt_yield( $debt_yield );

		// Score Sponsor (15 pts).
		$sponsor_score = $this->score_sponsor( $sponsor_years );

		// Score Market (15 pts).
		$market_score = $this->score_market( $market_tier, $property_type );

		$total_score = $ltv_score + $dscr_score + $dy_score + $sponsor_score + $market_score;

		// Recommendation.
		if ( $total_score >= 75 ) {
			$recommendation = 'GO';
			$comment        = __( 'Deal meets or exceeds lending criteria across all dimensions.', 'mcp-ai-wpoos-pro' );
		} elseif ( $total_score >= 55 ) {
			$recommendation = 'CONDITIONAL GO';
			$comment        = __( 'Deal is acceptable with mitigants for weaker scoring areas.', 'mcp-ai-wpoos-pro' );
		} elseif ( $total_score >= 40 ) {
			$recommendation = 'REVIEW';
			$comment        = __( 'Deal has material weaknesses requiring IC review and possible restructure.', 'mcp-ai-wpoos-pro' );
		} else {
			$recommendation = 'NO-GO';
			$comment        = __( 'Deal does not meet minimum lending standards.', 'mcp-ai-wpoos-pro' );
		}

		// Flags for individual weak scores.
		$flags = array();
		if ( $ltv_score <= 10 ) {
			$flags[] = __( 'High LTV risk — consider lower proceeds or additional collateral.', 'mcp-ai-wpoos-pro' );
		}
		if ( $dscr_score <= 10 ) {
			$flags[] = __( 'Low DSCR — NOI may be insufficient to service debt.', 'mcp-ai-wpoos-pro' );
		}
		if ( $dy_score <= 8 ) {
			$flags[] = __( 'Weak debt yield — limited downside protection.', 'mcp-ai-wpoos-pro' );
		}
		if ( $sponsor_score <= 5 ) {
			$flags[] = __( 'Inexperienced sponsor — consider guarantor requirements.', 'mcp-ai-wpoos-pro' );
		}
		if ( $market_score <= 5 ) {
			$flags[] = __( 'Tertiary market exposure — consider additional reserves.', 'mcp-ai-wpoos-pro' );
		}

		return array(
			'success' => true,
			'message' => __( 'Deal screening complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'metrics'        => array(
					'ltv'        => $calc::format_percentage( $ltv ),
					'dscr'       => round( $dscr, 2 ) . 'x',
					'debt_yield' => $calc::format_percentage( $debt_yield ),
					'annual_ds'  => $calc::format_currency( $annual_ds ),
				),
				'scoring'        => array(
					'ltv_score'     => $ltv_score . '/25',
					'dscr_score'    => $dscr_score . '/25',
					'dy_score'      => $dy_score . '/20',
					'sponsor_score' => $sponsor_score . '/15',
					'market_score'  => $market_score . '/15',
					'total_score'   => $total_score . '/100',
				),
				'recommendation' => $recommendation,
				'comment'        => $comment,
				'risk_flags'     => $flags,
			),
		);
	}

	/**
	 * Score LTV (0-25 pts). Lower LTV = higher score. Adjusted by property type.
	 *
	 * @param float  $ltv           LTV as decimal.
	 * @param string $property_type Property type.
	 * @return int
	 */
	private function score_ltv( float $ltv, string $property_type ): int {
		// Threshold shift for riskier property types.
		$risk_adj = 0.0;
		if ( in_array( $property_type, array( 'hotel', 'retail' ), true ) ) {
			$risk_adj = -0.05;
		} elseif ( 'multifamily' === $property_type ) {
			$risk_adj = 0.05;
		}

		$adj_ltv = $ltv - $risk_adj;

		if ( $adj_ltv <= 0.55 ) {
			return 25;
		}
		if ( $adj_ltv <= 0.60 ) {
			return 22;
		}
		if ( $adj_ltv <= 0.65 ) {
			return 19;
		}
		if ( $adj_ltv <= 0.70 ) {
			return 15;
		}
		if ( $adj_ltv <= 0.75 ) {
			return 10;
		}
		if ( $adj_ltv <= 0.80 ) {
			return 5;
		}
		return 0;
	}

	/**
	 * Score DSCR (0-25 pts). Higher DSCR = higher score.
	 *
	 * @param float $dscr DSCR ratio.
	 * @return int
	 */
	private function score_dscr( float $dscr ): int {
		if ( $dscr >= 1.60 ) {
			return 25;
		}
		if ( $dscr >= 1.45 ) {
			return 22;
		}
		if ( $dscr >= 1.30 ) {
			return 18;
		}
		if ( $dscr >= 1.20 ) {
			return 13;
		}
		if ( $dscr >= 1.10 ) {
			return 8;
		}
		if ( $dscr >= 1.00 ) {
			return 4;
		}
		return 0;
	}

	/**
	 * Score debt yield (0-20 pts). Higher DY = higher score.
	 *
	 * @param float $debt_yield Debt yield as decimal.
	 * @return int
	 */
	private function score_debt_yield( float $debt_yield ): int {
		if ( $debt_yield >= 0.12 ) {
			return 20;
		}
		if ( $debt_yield >= 0.10 ) {
			return 16;
		}
		if ( $debt_yield >= 0.08 ) {
			return 12;
		}
		if ( $debt_yield >= 0.07 ) {
			return 8;
		}
		if ( $debt_yield >= 0.06 ) {
			return 4;
		}
		return 0;
	}

	/**
	 * Score sponsor experience (0-15 pts).
	 *
	 * @param int $years Sponsor CRE experience in years.
	 * @return int
	 */
	private function score_sponsor( int $years ): int {
		if ( $years >= 15 ) {
			return 15;
		}
		if ( $years >= 10 ) {
			return 12;
		}
		if ( $years >= 5 ) {
			return 9;
		}
		if ( $years >= 3 ) {
			return 5;
		}
		if ( $years >= 1 ) {
			return 2;
		}
		return 0;
	}

	/**
	 * Score market tier (0-15 pts). Adjusted by property type liquidity.
	 *
	 * @param string $market_tier   Market tier.
	 * @param string $property_type Property type.
	 * @return int
	 */
	private function score_market( string $market_tier, string $property_type ): int {
		$base = 0;
		switch ( $market_tier ) {
			case 'primary':
				$base = 15;
				break;
			case 'secondary':
				$base = 10;
				break;
			case 'tertiary':
				$base = 5;
				break;
		}

		// Multifamily is more resilient across markets.
		if ( 'multifamily' === $property_type && $base < 15 ) {
			$base += 2;
		}
		// Hotel in tertiary is extra risky.
		if ( 'hotel' === $property_type && 'tertiary' === $market_tier ) {
			$base -= 2;
		}

		return max( 0, min( 15, $base ) );
	}
}
