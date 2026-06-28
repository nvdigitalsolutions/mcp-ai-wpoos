<?php
/**
 * CMBS Maturity Risk Analyzer — Assess refinancing feasibility for maturing loans
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
 * Analyzes maturity risk for CMBS loans: estimates refinancing feasibility at
 * current market rates, calculates projected DSCR at refi, required equity
 * infusion, payoff probability score, and extension likelihood for each loan.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CMBS_Maturity_Risk_Analyzer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Assumed market rates for refinancing by property type.
	 *
	 * @var array
	 */
	private static $market_rates = array(
		'multifamily' => 0.060,
		'industrial'  => 0.065,
		'office'      => 0.070,
		'retail'      => 0.072,
		'hotel'       => 0.078,
		'mixed_use'   => 0.068,
		'other'       => 0.072,
	);

	/**
	 * Minimum DSCR requirements by property type for refinancing.
	 *
	 * @var array
	 */
	private static $min_dscr_reqs = array(
		'multifamily' => 1.20,
		'industrial'  => 1.25,
		'office'      => 1.30,
		'retail'      => 1.30,
		'hotel'       => 1.40,
		'mixed_use'   => 1.25,
		'other'       => 1.30,
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
		return 'cmbs_maturity_risk_analyzer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CMBS Maturity Risk Analyzer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Analyze maturity risk for CMBS loans. For each maturing loan, estimates refinancing feasibility at current market rates, calculates new DSCR, required equity infusion, payoff probability score, and extension likelihood.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'loans' => array(
					'type'        => 'array',
					'description' => __( 'Array of maturing loan objects.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'          => array(
								'type'        => 'string',
								'description' => __( 'Loan or property name.', 'mcp-ai-wpoos-pro' ),
							),
							'balance'       => array(
								'type'        => 'number',
								'description' => __( 'Current loan balance.', 'mcp-ai-wpoos-pro' ),
							),
							'maturity_date' => array(
								'type'        => 'string',
								'description' => __( 'Maturity date in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ),
							),
							'current_rate'  => array(
								'type'        => 'number',
								'description' => __( 'Current interest rate as decimal.', 'mcp-ai-wpoos-pro' ),
							),
							'current_dscr'  => array(
								'type'        => 'number',
								'description' => __( 'Current DSCR.', 'mcp-ai-wpoos-pro' ),
							),
							'current_ltv'   => array(
								'type'        => 'number',
								'description' => __( 'Current LTV as decimal.', 'mcp-ai-wpoos-pro' ),
							),
							'property_type' => array(
								'type'        => 'string',
								'description' => __( 'Property type.', 'mcp-ai-wpoos-pro' ),
							),
							'current_noi'   => array(
								'type'        => 'number',
								'description' => __( 'Current annual NOI.', 'mcp-ai-wpoos-pro' ),
							),
						),
						'required'   => array( 'name', 'balance', 'maturity_date', 'current_rate', 'current_dscr', 'current_ltv', 'property_type', 'current_noi' ),
					),
				),
			),
			'required'   => array( 'loans' ),
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

		$loans = $arguments['loans'] ?? array();

		if ( empty( $loans ) || ! is_array( $loans ) ) {
			return new WP_Error( 'invalid_input', __( 'At least one loan is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;
		$now  = time();

		$results          = array();
		$total_balance    = 0.0;
		$at_risk_balance  = 0.0;
		$at_risk_count    = 0;
		$payoff_likely    = 0;
		$extension_likely = 0;
		$default_risk     = 0;

		foreach ( $loans as $loan ) {
			$name          = sanitize_text_field( $loan['name'] ?? 'Unnamed' );
			$balance       = (float) ( $loan['balance'] ?? 0 );
			$maturity_date = sanitize_text_field( $loan['maturity_date'] ?? '' );
			$current_rate  = (float) ( $loan['current_rate'] ?? 0 );
			$current_dscr  = (float) ( $loan['current_dscr'] ?? 0 );
			$current_ltv   = (float) ( $loan['current_ltv'] ?? 0 );
			$property_type = sanitize_text_field( $loan['property_type'] ?? 'other' );
			$current_noi   = (float) ( $loan['current_noi'] ?? 0 );

			if ( $balance <= 0 || $current_noi <= 0 ) {
				continue;
			}

			$total_balance += $balance;

			// Months to maturity.
			$mat_ts        = strtotime( $maturity_date );
			$months_to_mat = 0;
			if ( false !== $mat_ts && $mat_ts > $now ) {
				$months_to_mat = (int) ceil( ( $mat_ts - $now ) / ( 30.44 * 86400 ) );
			}

			// Market refinancing rate.
			$market_rate = self::$market_rates[ $property_type ] ?? 0.070;
			$min_dscr    = self::$min_dscr_reqs[ $property_type ] ?? 1.25;

			// Calculate projected debt service at market rate (30-year amortization standard).
			$refi_amort    = 360;
			$new_monthly   = $calc::calculate_monthly_payment( $balance, $market_rate, $refi_amort );
			$new_annual_ds = $new_monthly * 12;

			// Projected DSCR at market rate.
			$projected_dscr = ( $new_annual_ds > 0 ) ? $current_noi / $new_annual_ds : 0;

			// Max loan at market DSCR requirement.
			$max_refi_loan = 0.0;
			if ( $min_dscr > 0 ) {
				// Debt service constant.
				$mr = $market_rate / 12;
				if ( $mr > 0 ) {
					$factor        = pow( 1 + $mr, $refi_amort );
					$ds_constant   = ( $mr * $factor / ( $factor - 1 ) ) * 12;
					$max_refi_loan = ( $ds_constant > 0 ) ? $current_noi / ( $min_dscr * $ds_constant ) : 0;
				}
			}

			// Equity infusion required.
			$equity_gap = max( 0, $balance - $max_refi_loan );

			// Property value estimate from current LTV.
			$property_value = ( $current_ltv > 0 ) ? $balance / $current_ltv : 0;

			// Refi LTV check (max 75% for most CMBS).
			$max_ltv_loan = $property_value * 0.75;
			$ltv_gap      = max( 0, $balance - $max_ltv_loan );

			// Binding equity gap (max of DSCR gap and LTV gap).
			$total_equity_needed = max( $equity_gap, $ltv_gap );

			// Payoff probability score (0-100).
			$payoff_score = $this->calculate_payoff_score(
				$projected_dscr,
				$min_dscr,
				$current_ltv,
				$total_equity_needed,
				$balance,
				$months_to_mat
			);

			// Extension likelihood.
			$extension_score = $this->calculate_extension_likelihood(
				$payoff_score,
				$current_dscr,
				$current_noi,
				$new_annual_ds
			);

			// Risk categorization.
			if ( $payoff_score >= 70 ) {
				$risk_category = __( 'Low Risk - likely to refinance', 'mcp-ai-wpoos-pro' );
				++$payoff_likely;
			} elseif ( $payoff_score >= 40 ) {
				$risk_category = __( 'Moderate Risk - may need extension or equity', 'mcp-ai-wpoos-pro' );
				++$extension_likely;
				$at_risk_balance += $balance;
				++$at_risk_count;
			} else {
				$risk_category = __( 'High Risk - significant refinancing challenges', 'mcp-ai-wpoos-pro' );
				++$default_risk;
				$at_risk_balance += $balance;
				++$at_risk_count;
			}

			// Rate shock analysis.
			$rate_change     = $market_rate - $current_rate;
			$rate_change_bps = $rate_change * 10000;
			$ds_increase     = $new_annual_ds - ( $calc::calculate_monthly_payment( $balance, $current_rate, $refi_amort ) * 12 );

			$results[] = array(
				'loan_name'              => $name,
				'balance'                => $calc::format_currency( $balance ),
				'maturity_date'          => $maturity_date,
				'months_to_maturity'     => $months_to_mat,
				'current_rate'           => $calc::format_percentage( $current_rate ),
				'market_refi_rate'       => $calc::format_percentage( $market_rate ),
				'rate_shock_bps'         => round( $rate_change_bps ),
				'current_dscr'           => round( $current_dscr, 2 ),
				'projected_dscr_at_refi' => round( $projected_dscr, 2 ),
				'min_dscr_required'      => round( $min_dscr, 2 ),
				'dscr_passes'            => $projected_dscr >= $min_dscr,
				'current_ltv'            => $calc::format_percentage( $current_ltv ),
				'max_refi_loan'          => $calc::format_currency( $max_refi_loan ),
				'equity_infusion_needed' => $calc::format_currency( $total_equity_needed ),
				'equity_infusion_pct'    => $calc::format_percentage( ( $balance > 0 ) ? $total_equity_needed / $balance : 0 ),
				'annual_ds_increase'     => $calc::format_currency( $ds_increase ),
				'payoff_probability'     => round( $payoff_score ),
				'extension_likelihood'   => round( $extension_score ),
				'risk_category'          => $risk_category,
				'property_type'          => $property_type,
			);
		}

		// Sort by payoff probability ascending (most at-risk first).
		usort(
			$results,
			function ( $a, $b ) {
				return $a['payoff_probability'] <=> $b['payoff_probability'];
			}
		);

		$summary = array(
			'total_loans'            => count( $results ),
			'total_balance'          => $calc::format_currency( $total_balance ),
			'at_risk_count'          => $at_risk_count,
			'at_risk_balance'        => $calc::format_currency( $at_risk_balance ),
			'at_risk_pct'            => $calc::format_percentage( ( $total_balance > 0 ) ? $at_risk_balance / $total_balance : 0 ),
			'payoff_likely'          => $payoff_likely,
			'extension_likely'       => $extension_likely,
			'default_risk'           => $default_risk,
			'avg_payoff_probability' => ( count( $results ) > 0 )
				? round( array_sum( array_column( $results, 'payoff_probability' ) ) / count( $results ) )
				: 0,
		);

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: total loans, 2: at-risk count, 3: at-risk balance */
				__( 'Maturity risk analyzed: %1$d loans, %2$d at risk (%3$s).', 'mcp-ai-wpoos-pro' ),
				count( $results ),
				$at_risk_count,
				$summary['at_risk_balance']
			),
			'data'    => array(
				'summary'    => $summary,
				'loans'      => $results,
				'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Calculate payoff probability score (0-100).
	 *
	 * @param float $projected_dscr     DSCR at market rate.
	 * @param float $min_dscr           Minimum required DSCR.
	 * @param float $current_ltv        Current LTV.
	 * @param float $equity_needed      Equity infusion needed.
	 * @param float $balance            Loan balance.
	 * @param int   $months_to_maturity Months to maturity.
	 * @return float Score 0-100.
	 */
	private function calculate_payoff_score(
		float $projected_dscr,
		float $min_dscr,
		float $current_ltv,
		float $equity_needed,
		float $balance,
		int $months_to_maturity
	): float {
		$score = 50.0;

		// DSCR component (40 points).
		if ( $projected_dscr >= $min_dscr * 1.20 ) {
			$score += 40;
		} elseif ( $projected_dscr >= $min_dscr ) {
			$excess = ( $projected_dscr - $min_dscr ) / ( $min_dscr * 0.20 );
			$score += 20 + ( $excess * 20 );
		} elseif ( $projected_dscr >= $min_dscr * 0.80 ) {
			$shortfall = ( $min_dscr - $projected_dscr ) / ( $min_dscr * 0.20 );
			$score    -= $shortfall * 20;
		} else {
			$score -= 30;
		}

		// LTV component (30 points).
		if ( $current_ltv <= 0.60 ) {
			$score += 20;
		} elseif ( $current_ltv <= 0.75 ) {
			$score += 10;
		} elseif ( $current_ltv > 0.80 ) {
			$score -= 15;
		}

		// Equity gap component (20 points).
		if ( $balance > 0 ) {
			$gap_pct = $equity_needed / $balance;
			if ( $gap_pct <= 0 ) {
				$score += 10;
			} elseif ( $gap_pct <= 0.10 ) {
				$score += 5;
			} elseif ( $gap_pct <= 0.20 ) {
				$score -= 5;
			} else {
				$score -= 15;
			}
		}

		// Time component: more time = slightly better.
		if ( $months_to_maturity > 24 ) {
			$score += 5;
		} elseif ( $months_to_maturity < 6 ) {
			$score -= 5;
		}

		return max( 0, min( 100, $score ) );
	}

	/**
	 * Calculate extension likelihood (0-100).
	 *
	 * @param float $payoff_score    Payoff probability.
	 * @param float $current_dscr    Current DSCR.
	 * @param float $current_noi     Current NOI.
	 * @param float $new_annual_ds   New annual debt service.
	 * @return float Score 0-100.
	 */
	private function calculate_extension_likelihood(
		float $payoff_score,
		float $current_dscr,
		float $current_noi,
		float $new_annual_ds
	): float {
		// Extension is most likely for moderate-risk loans that still generate income.
		if ( $payoff_score >= 70 ) {
			// No need for extension if refi is likely.
			return max( 10, 100 - $payoff_score );
		}

		$score = 50.0;

		// Property still cash-flowing?
		if ( $current_dscr >= 1.0 ) {
			$score += 20;
		} elseif ( $current_dscr >= 0.80 ) {
			$score += 10;
		} else {
			$score -= 15;
		}

		// Can the property cover new debt service with some NOI growth?
		$coverage_at_market = ( $new_annual_ds > 0 ) ? $current_noi / $new_annual_ds : 0;
		if ( $coverage_at_market >= 0.90 ) {
			$score += 15;
		} elseif ( $coverage_at_market >= 0.75 ) {
			$score += 5;
		} else {
			$score -= 10;
		}

		// Very low payoff score = servicer may prefer foreclosure over extension.
		if ( $payoff_score < 20 ) {
			$score -= 15;
		}

		return max( 0, min( 100, $score ) );
	}
}
