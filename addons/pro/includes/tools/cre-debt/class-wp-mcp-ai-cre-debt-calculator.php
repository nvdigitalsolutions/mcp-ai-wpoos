<?php
/**
 * CRE Debt & Securitization Shared Calculator Engine
 *
 * Core financial calculation methods used across all CRE Debt toolkit modules.
 * Implements industry-standard formulas aligned with CREFC, MBA/CMB, ARGUS,
 * CCIM, and CFA/CAIA methodologies.
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
 * Shared calculator class for CRE Debt toolkit.
 *
 * Provides static methods for:
 * - Amortization schedule generation (IO + P&I + balloon)
 * - DSCR, LTV, debt yield calculations
 * - NPV, IRR, DCF
 * - Cap rate and NOI math
 * - Waterfall distribution modeling
 * - Loan sizing against multiple constraints
 *
 * @since 1.2.0
 */
class WP_MCP_AI_CRE_Debt_Calculator {

	/**
	 * Calculate monthly payment (P&I) for a fully amortizing loan.
	 *
	 * Formula: PMT = P * r / (1 - (1 + r)^-n)
	 *
	 * @since 1.2.0
	 *
	 * @param float $principal     Loan amount.
	 * @param float $annual_rate   Annual interest rate (decimal, e.g. 0.065 for 6.5%).
	 * @param int   $amort_months  Amortization period in months.
	 * @return float Monthly payment amount.
	 */
	public static function calculate_monthly_payment( float $principal, float $annual_rate, int $amort_months ): float {
		if ( $principal <= 0 || $amort_months <= 0 ) {
			return 0.0;
		}
		if ( $annual_rate <= 0 ) {
			return $principal / $amort_months;
		}
		$monthly_rate = $annual_rate / 12;
		$factor       = pow( 1 + $monthly_rate, $amort_months );
		return $principal * ( $monthly_rate * $factor ) / ( $factor - 1 );
	}

	/**
	 * Calculate interest-only monthly payment.
	 *
	 * @since 1.2.0
	 *
	 * @param float $principal   Loan amount.
	 * @param float $annual_rate Annual interest rate (decimal).
	 * @return float Monthly IO payment.
	 */
	public static function calculate_io_payment( float $principal, float $annual_rate ): float {
		return $principal * $annual_rate / 12;
	}

	/**
	 * Generate a full amortization schedule with IO period and balloon.
	 *
	 * @since 1.2.0
	 *
	 * @param float $loan_amount     Loan principal.
	 * @param float $annual_rate     Annual interest rate (decimal).
	 * @param int   $loan_term       Loan term in months.
	 * @param int   $amort_period    Amortization period in months.
	 * @param int   $io_period       Interest-only period in months.
	 * @return array{schedule: array, balloon_payment: float, total_interest: float, total_principal: float}
	 */
	public static function generate_amortization_schedule(
		float $loan_amount,
		float $annual_rate,
		int $loan_term,
		int $amort_period,
		int $io_period = 0
	): array {
		$schedule        = array();
		$balance         = $loan_amount;
		$monthly_rate    = $annual_rate / 12;
		$total_interest  = 0.0;
		$total_principal = 0.0;

		// Calculate P&I payment for amortizing portion.
		$pi_payment = self::calculate_monthly_payment( $loan_amount, $annual_rate, $amort_period );

		for ( $month = 1; $month <= $loan_term; $month++ ) {
			$interest = $balance * $monthly_rate;

			if ( $month <= $io_period ) {
				// IO period: interest only.
				$principal_paid = 0.0;
				$payment        = $interest;
				$period_type    = 'IO';
			} else {
				// P&I period.
				$payment        = $pi_payment;
				$principal_paid = $payment - $interest;
				$period_type    = 'P&I';

				// Ensure we don't overpay.
				if ( $principal_paid > $balance ) {
					$principal_paid = $balance;
					$payment        = $principal_paid + $interest;
				}
			}

			$balance         -= $principal_paid;
			$total_interest  += $interest;
			$total_principal += $principal_paid;

			$schedule[] = array(
				'month'       => $month,
				'period_type' => $period_type,
				'payment'     => round( $payment, 2 ),
				'principal'   => round( $principal_paid, 2 ),
				'interest'    => round( $interest, 2 ),
				'balance'     => round( max( 0, $balance ), 2 ),
			);
		}

		return array(
			'schedule'        => $schedule,
			'balloon_payment' => round( max( 0, $balance ), 2 ),
			'total_interest'  => round( $total_interest, 2 ),
			'total_principal' => round( $total_principal, 2 ),
		);
	}

	/**
	 * Calculate Net Operating Income (NOI).
	 *
	 * NOI = Effective Gross Income - Operating Expenses
	 * EGI = Potential Gross Income - Vacancy - Concessions + Other Income
	 *
	 * @since 1.2.0
	 *
	 * @param float $potential_gross_income PGI (total potential rental income).
	 * @param float $vacancy_rate          Vacancy rate (decimal, e.g. 0.05 for 5%).
	 * @param float $concessions           Rent concessions/free rent.
	 * @param float $other_income          Other income (parking, laundry, etc.).
	 * @param float $operating_expenses    Total operating expenses.
	 * @return array{pgi: float, vacancy_loss: float, egi: float, noi: float}
	 */
	public static function calculate_noi(
		float $potential_gross_income,
		float $vacancy_rate,
		float $concessions,
		float $other_income,
		float $operating_expenses
	): array {
		$vacancy_loss = $potential_gross_income * $vacancy_rate;
		$egi          = $potential_gross_income - $vacancy_loss - $concessions + $other_income;
		$noi          = $egi - $operating_expenses;

		return array(
			'pgi'          => round( $potential_gross_income, 2 ),
			'vacancy_loss' => round( $vacancy_loss, 2 ),
			'concessions'  => round( $concessions, 2 ),
			'other_income' => round( $other_income, 2 ),
			'egi'          => round( $egi, 2 ),
			'opex'         => round( $operating_expenses, 2 ),
			'noi'          => round( $noi, 2 ),
		);
	}

	/**
	 * Calculate Debt Service Coverage Ratio (DSCR).
	 *
	 * DSCR = NOI / Annual Debt Service
	 *
	 * @since 1.2.0
	 *
	 * @param float $noi                Net Operating Income (annual).
	 * @param float $annual_debt_service Annual debt service (P&I or IO).
	 * @return float DSCR ratio.
	 */
	public static function calculate_dscr( float $noi, float $annual_debt_service ): float {
		if ( $annual_debt_service <= 0 ) {
			return 0.0;
		}
		return $noi / $annual_debt_service;
	}

	/**
	 * Calculate Loan-to-Value ratio (LTV).
	 *
	 * LTV = Loan Amount / Property Value
	 *
	 * @since 1.2.0
	 *
	 * @param float $loan_amount    Loan amount.
	 * @param float $property_value Property appraised value.
	 * @return float LTV as decimal (e.g. 0.75 for 75%).
	 */
	public static function calculate_ltv( float $loan_amount, float $property_value ): float {
		if ( $property_value <= 0 ) {
			return 0.0;
		}
		return $loan_amount / $property_value;
	}

	/**
	 * Calculate Debt Yield.
	 *
	 * Debt Yield = NOI / Loan Amount
	 *
	 * @since 1.2.0
	 *
	 * @param float $noi         Net Operating Income (annual).
	 * @param float $loan_amount Loan amount.
	 * @return float Debt yield as decimal.
	 */
	public static function calculate_debt_yield( float $noi, float $loan_amount ): float {
		if ( $loan_amount <= 0 ) {
			return 0.0;
		}
		return $noi / $loan_amount;
	}

	/**
	 * Calculate property value using direct capitalization.
	 *
	 * Value = NOI / Cap Rate
	 *
	 * @since 1.2.0
	 *
	 * @param float $noi      Net Operating Income.
	 * @param float $cap_rate Capitalization rate (decimal).
	 * @return float Property value.
	 */
	public static function calculate_value_direct_cap( float $noi, float $cap_rate ): float {
		if ( $cap_rate <= 0 ) {
			return 0.0;
		}
		return $noi / $cap_rate;
	}

	/**
	 * Calculate Net Present Value (NPV) of a series of cash flows.
	 *
	 * @since 1.2.0
	 *
	 * @param array $cash_flows   Array of cash flows (period 0, 1, 2, ...).
	 * @param float $discount_rate Annual discount rate (decimal).
	 * @return float NPV.
	 */
	public static function calculate_npv( array $cash_flows, float $discount_rate ): float {
		$npv = 0.0;
		foreach ( $cash_flows as $period => $cf ) {
			$npv += $cf / pow( 1 + $discount_rate, $period );
		}
		return $npv;
	}

	/**
	 * Calculate Internal Rate of Return (IRR) using Newton's method.
	 *
	 * @since 1.2.0
	 *
	 * @param array $cash_flows   Array of cash flows (period 0 typically negative).
	 * @param float $guess        Initial guess for IRR (default 0.10).
	 * @param int   $max_iter     Maximum iterations (default 1000).
	 * @param float $tolerance    Convergence tolerance (default 1e-7).
	 * @return float|null IRR as decimal, or null if no convergence.
	 */
	public static function calculate_irr(
		array $cash_flows,
		float $guess = 0.10,
		int $max_iter = 1000,
		float $tolerance = 1e-7
	): ?float {
		if ( empty( $cash_flows ) ) {
			return null;
		}

		$rate = $guess;
		for ( $i = 0; $i < $max_iter; $i++ ) {
			$npv       = 0.0;
			$npv_prime = 0.0;

			foreach ( $cash_flows as $period => $cf ) {
				$factor = pow( 1 + $rate, $period );
				$npv   += $cf / $factor;
				if ( $period > 0 ) {
					$npv_prime -= $period * $cf / pow( 1 + $rate, $period + 1 );
				}
			}

			if ( abs( $npv_prime ) < 1e-15 ) {
				return null;
			}

			$new_rate = $rate - $npv / $npv_prime;

			if ( abs( $new_rate - $rate ) < $tolerance ) {
				return $new_rate;
			}

			$rate = $new_rate;
		}

		return null;
	}

	/**
	 * Size a loan against multiple constraints.
	 *
	 * Returns the minimum (most constraining) loan amount from:
	 * - LTV constraint: Loan = Value * Max LTV
	 * - DSCR constraint: Loan = NOI / (Min DSCR * Debt Service Constant)
	 * - Debt Yield constraint: Loan = NOI / Min Debt Yield
	 *
	 * @since 1.2.0
	 *
	 * @param float $property_value Property value.
	 * @param float $noi            Net Operating Income (annual).
	 * @param float $annual_rate    Annual interest rate (decimal).
	 * @param int   $amort_months   Amortization period in months.
	 * @param float $max_ltv        Maximum LTV (decimal, e.g. 0.75).
	 * @param float $min_dscr       Minimum DSCR (e.g. 1.25).
	 * @param float $min_debt_yield Minimum debt yield (decimal, e.g. 0.10).
	 * @return array{max_loan: float, ltv_loan: float, dscr_loan: float, dy_loan: float, binding_constraint: string}
	 */
	public static function size_loan(
		float $property_value,
		float $noi,
		float $annual_rate,
		int $amort_months,
		float $max_ltv = 0.75,
		float $min_dscr = 1.25,
		float $min_debt_yield = 0.10
	): array {
		// LTV constraint.
		$ltv_loan = $property_value * $max_ltv;

		// Debt service constant = Annual Payment / Loan Amount.
		// For $1 of loan: monthly pmt * 12.
		$monthly_rate = $annual_rate / 12;
		if ( $monthly_rate > 0 && $amort_months > 0 ) {
			$factor      = pow( 1 + $monthly_rate, $amort_months );
			$ds_constant = ( $monthly_rate * $factor / ( $factor - 1 ) ) * 12;
		} else {
			$ds_constant = 12.0 / max( $amort_months, 1 );
		}

		// DSCR constraint: NOI / (Min DSCR * DS Constant).
		$dscr_loan = ( $ds_constant > 0 ) ? $noi / ( $min_dscr * $ds_constant ) : 0.0;

		// Debt Yield constraint.
		$dy_loan = ( $min_debt_yield > 0 ) ? $noi / $min_debt_yield : 0.0;

		// Binding constraint is the most restrictive (lowest loan amount).
		$loans = array(
			'ltv'        => $ltv_loan,
			'dscr'       => $dscr_loan,
			'debt_yield' => $dy_loan,
		);

		$binding    = 'ltv';
		$min_amount = $ltv_loan;
		foreach ( $loans as $constraint => $amount ) {
			if ( $amount < $min_amount && $amount > 0 ) {
				$min_amount = $amount;
				$binding    = $constraint;
			}
		}

		return array(
			'max_loan'           => round( $min_amount, 2 ),
			'ltv_loan'           => round( $ltv_loan, 2 ),
			'dscr_loan'          => round( $dscr_loan, 2 ),
			'dy_loan'            => round( $dy_loan, 2 ),
			'binding_constraint' => $binding,
		);
	}

	/**
	 * Calculate a DCF valuation with terminal/reversion value.
	 *
	 * @since 1.2.0
	 *
	 * @param array $annual_nois   Array of projected annual NOIs (year 1, 2, ...).
	 * @param float $exit_cap_rate Exit/terminal cap rate (decimal).
	 * @param float $discount_rate Discount rate (decimal).
	 * @param float $selling_costs Selling costs as percentage (decimal, e.g. 0.02).
	 * @return array{pv_cash_flows: float, terminal_value: float, pv_terminal: float, total_value: float, yearly_detail: array}
	 */
	public static function calculate_dcf(
		array $annual_nois,
		float $exit_cap_rate,
		float $discount_rate,
		float $selling_costs = 0.02
	): array {
		$pv_cash_flows = 0.0;
		$yearly_detail = array();
		$num_years     = count( $annual_nois );

		foreach ( $annual_nois as $year => $noi ) {
			$period         = $year + 1;
			$pv             = $noi / pow( 1 + $discount_rate, $period );
			$pv_cash_flows += $pv;

			$yearly_detail[] = array(
				'year' => $period,
				'noi'  => round( $noi, 2 ),
				'pv'   => round( $pv, 2 ),
			);
		}

		// Terminal value based on Year N+1 NOI (last NOI grown by one year).
		$terminal_noi   = end( $annual_nois );
		$terminal_value = ( $exit_cap_rate > 0 ) ? $terminal_noi / $exit_cap_rate : 0.0;
		$net_terminal   = $terminal_value * ( 1 - $selling_costs );
		$pv_terminal    = $net_terminal / pow( 1 + $discount_rate, $num_years );
		$total_value    = $pv_cash_flows + $pv_terminal;

		return array(
			'pv_cash_flows'  => round( $pv_cash_flows, 2 ),
			'terminal_value' => round( $terminal_value, 2 ),
			'net_terminal'   => round( $net_terminal, 2 ),
			'pv_terminal'    => round( $pv_terminal, 2 ),
			'total_value'    => round( $total_value, 2 ),
			'yearly_detail'  => $yearly_detail,
		);
	}

	/**
	 * Calculate equity waterfall distribution.
	 *
	 * Supports a multi-tier promote structure common in CRE debt funds.
	 *
	 * @since 1.2.0
	 *
	 * @param float $distributable_amount Amount available for distribution.
	 * @param float $lp_commitment        Total LP commitment.
	 * @param float $gp_commitment        Total GP commitment (co-invest).
	 * @param float $preferred_return     Preferred return rate (decimal).
	 * @param array $promote_tiers        Array of tiers: [ ['hurdle' => 0.12, 'gp_share' => 0.20], ... ].
	 * @return array Distribution breakdown by tier.
	 */
	public static function calculate_waterfall(
		float $distributable_amount,
		float $lp_commitment,
		float $gp_commitment,
		float $preferred_return,
		array $promote_tiers = array()
	): array {
		$total_commitment = $lp_commitment + $gp_commitment;
		$lp_share         = ( $total_commitment > 0 ) ? $lp_commitment / $total_commitment : 0.0;
		$gp_share         = 1.0 - $lp_share;
		$remaining        = $distributable_amount;
		$tiers            = array();

		// Tier 1: Return of capital.
		$roc        = min( $remaining, $total_commitment );
		$remaining -= $roc;
		$tiers[]    = array(
			'tier'      => 'Return of Capital',
			'amount'    => round( $roc, 2 ),
			'lp_amount' => round( $roc * $lp_share, 2 ),
			'gp_amount' => round( $roc * $gp_share, 2 ),
		);

		// Tier 2: Preferred return.
		$pref_amount = $total_commitment * $preferred_return;
		$pref_paid   = min( $remaining, $pref_amount );
		$remaining  -= $pref_paid;
		$tiers[]     = array(
			'tier'      => 'Preferred Return',
			'amount'    => round( $pref_paid, 2 ),
			'lp_amount' => round( $pref_paid * $lp_share, 2 ),
			'gp_amount' => round( $pref_paid * $gp_share, 2 ),
		);

		// Tier 3+: Promote tiers.
		$prev_hurdle = $preferred_return;
		foreach ( $promote_tiers as $tier ) {
			$hurdle = $tier['hurdle'] ?? 0.0;
			$gp_pct = $tier['gp_share'] ?? 0.20;
			$lp_pct = 1.0 - $gp_pct;

			$tier_amount = $total_commitment * ( $hurdle - $prev_hurdle );
			$tier_paid   = min( $remaining, max( 0, $tier_amount ) );
			$remaining  -= $tier_paid;

			$tiers[] = array(
				'tier'      => sprintf( 'Promote (>%s%% IRR)', round( $prev_hurdle * 100, 1 ) ),
				'hurdle'    => $hurdle,
				'amount'    => round( $tier_paid, 2 ),
				'lp_amount' => round( $tier_paid * $lp_pct, 2 ),
				'gp_amount' => round( $tier_paid * $gp_pct, 2 ),
			);

			$prev_hurdle = $hurdle;
		}

		// Residual above all hurdles.
		if ( $remaining > 0 ) {
			$final_gp = ! empty( $promote_tiers ) ? end( $promote_tiers )['gp_share'] ?? 0.20 : $gp_share;
			$final_lp = 1.0 - $final_gp;
			$tiers[]  = array(
				'tier'      => 'Residual',
				'amount'    => round( $remaining, 2 ),
				'lp_amount' => round( $remaining * $final_lp, 2 ),
				'gp_amount' => round( $remaining * $final_gp, 2 ),
			);
		}

		$total_to_lp = 0.0;
		$total_to_gp = 0.0;
		foreach ( $tiers as $t ) {
			$total_to_lp += $t['lp_amount'];
			$total_to_gp += $t['gp_amount'];
		}

		return array(
			'total_distributed' => round( $distributable_amount, 2 ),
			'total_to_lp'       => round( $total_to_lp, 2 ),
			'total_to_gp'       => round( $total_to_gp, 2 ),
			'tiers'             => $tiers,
		);
	}

	/**
	 * Calculate defeasance cost estimate.
	 *
	 * @since 1.2.0
	 *
	 * @param float $loan_balance      Current loan balance.
	 * @param float $loan_rate         Loan coupon rate (decimal).
	 * @param int   $remaining_months  Months remaining to maturity.
	 * @param float $treasury_rate     Current treasury rate for matching (decimal).
	 * @param float $transaction_costs Estimated transaction costs.
	 * @return array{defeasance_cost: float, treasury_portfolio: float, total_cost: float}
	 */
	public static function calculate_defeasance_cost(
		float $loan_balance,
		float $loan_rate,
		int $remaining_months,
		float $treasury_rate,
		float $transaction_costs = 50000.0
	): array {
		// Simplified defeasance: PV of remaining debt service at treasury rate.
		$monthly_loan_rate = $loan_rate / 12;
		$monthly_tsy_rate  = $treasury_rate / 12;

		// Monthly debt service on existing loan.
		$monthly_payment = self::calculate_monthly_payment( $loan_balance, $loan_rate, $remaining_months );

		// PV of remaining payments at treasury rate = cost of defeasance portfolio.
		$pv_at_treasury = 0.0;
		if ( $monthly_tsy_rate > 0 ) {
			$factor         = pow( 1 + $monthly_tsy_rate, $remaining_months );
			$pv_at_treasury = $monthly_payment * ( $factor - 1 ) / ( $monthly_tsy_rate * $factor );
		} else {
			$pv_at_treasury = $monthly_payment * $remaining_months;
		}

		$defeasance_premium = max( 0, $pv_at_treasury - $loan_balance );
		$total_cost         = $defeasance_premium + $transaction_costs;

		return array(
			'loan_balance'       => round( $loan_balance, 2 ),
			'treasury_portfolio' => round( $pv_at_treasury, 2 ),
			'defeasance_premium' => round( $defeasance_premium, 2 ),
			'transaction_costs'  => round( $transaction_costs, 2 ),
			'total_cost'         => round( $total_cost, 2 ),
		);
	}

	/**
	 * Calculate yield maintenance cost.
	 *
	 * Yield maintenance = PV of (coupon rate - treasury rate) * remaining balance.
	 *
	 * @since 1.2.0
	 *
	 * @param float $loan_balance     Current loan balance.
	 * @param float $loan_rate        Loan coupon rate (decimal).
	 * @param int   $remaining_months Months remaining.
	 * @param float $treasury_rate    Current treasury rate (decimal).
	 * @return float Yield maintenance cost.
	 */
	public static function calculate_yield_maintenance(
		float $loan_balance,
		float $loan_rate,
		int $remaining_months,
		float $treasury_rate
	): float {
		$rate_diff    = max( 0, $loan_rate - $treasury_rate );
		$monthly_diff = $loan_balance * $rate_diff / 12;

		if ( $treasury_rate > 0 ) {
			$monthly_tsy = $treasury_rate / 12;
			$factor      = pow( 1 + $monthly_tsy, $remaining_months );
			$pv          = $monthly_diff * ( $factor - 1 ) / ( $monthly_tsy * $factor );
		} else {
			$pv = $monthly_diff * $remaining_months;
		}

		return round( max( 0, $pv ), 2 );
	}

	/**
	 * Calculate equity multiple (MOIC).
	 *
	 * Equity Multiple = Total Distributions / Total Invested Capital
	 *
	 * @since 1.2.0
	 *
	 * @param float $total_distributions Total cash returned.
	 * @param float $total_invested      Total equity invested.
	 * @return float Equity multiple.
	 */
	public static function calculate_equity_multiple( float $total_distributions, float $total_invested ): float {
		if ( $total_invested <= 0 ) {
			return 0.0;
		}
		return $total_distributions / $total_invested;
	}

	/**
	 * Calculate cash-on-cash return.
	 *
	 * CoC = Annual Cash Flow / Total Cash Invested
	 *
	 * @since 1.2.0
	 *
	 * @param float $annual_cash_flow Annual before-tax cash flow.
	 * @param float $equity_invested  Total equity invested.
	 * @return float Cash-on-cash return as decimal.
	 */
	public static function calculate_cash_on_cash( float $annual_cash_flow, float $equity_invested ): float {
		if ( $equity_invested <= 0 ) {
			return 0.0;
		}
		return $annual_cash_flow / $equity_invested;
	}

	/**
	 * Format a number as currency string.
	 *
	 * @since 1.2.0
	 *
	 * @param float  $amount   Amount to format.
	 * @param string $currency Currency symbol (default '$').
	 * @return string Formatted currency string.
	 */
	public static function format_currency( float $amount, string $currency = '$' ): string {
		$formatted = number_format( abs( $amount ), 2 );
		$prefix    = ( $amount < 0 ) ? '-' : '';
		return $prefix . $currency . $formatted;
	}

	/**
	 * Format a decimal as percentage string.
	 *
	 * @since 1.2.0
	 *
	 * @param float $decimal  Decimal value (e.g. 0.065).
	 * @param int   $decimals Number of decimal places (default 2).
	 * @return string Formatted percentage (e.g. "6.50%").
	 */
	public static function format_percentage( float $decimal, int $decimals = 2 ): string {
		return number_format( $decimal * 100, $decimals ) . '%';
	}
}
