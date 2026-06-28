<?php
/**
 * CRE Leverage Return Analyzer — Leveraged vs unleveraged return comparison
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
 * Compares leveraged vs unleveraged returns: IRR, equity multiple, and
 * cash-on-cash yield for a CRE acquisition over a hold period.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Leverage_Return_Analyzer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_leverage_return_analyzer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Leverage Return Analyzer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Compare leveraged vs unleveraged investment returns. Takes purchase price, NOI, exit NOI/cap rate, hold years, and loan terms. Returns leveraged and unleveraged IRR, equity multiple, and year-by-year cash-on-cash returns.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'purchase_price'  => array(
					'type'        => 'number',
					'description' => __( 'Property acquisition price.', 'mcp-ai-wpoos-pro' ),
				),
				'noi'             => array(
					'type'        => 'number',
					'description' => __( 'Year-1 Net Operating Income.', 'mcp-ai-wpoos-pro' ),
				),
				'exit_noi'        => array(
					'type'        => 'number',
					'description' => __( 'Exit-year NOI (projected).', 'mcp-ai-wpoos-pro' ),
				),
				'exit_cap_rate'   => array(
					'type'        => 'number',
					'description' => __( 'Exit cap rate as decimal (e.g. 0.065).', 'mcp-ai-wpoos-pro' ),
				),
				'hold_years'      => array(
					'type'        => 'integer',
					'description' => __( 'Investment hold period in years.', 'mcp-ai-wpoos-pro' ),
				),
				'loan_amount'     => array(
					'type'        => 'number',
					'description' => __( 'Loan amount.', 'mcp-ai-wpoos-pro' ),
				),
				'interest_rate'   => array(
					'type'        => 'number',
					'description' => __( 'Annual interest rate as decimal.', 'mcp-ai-wpoos-pro' ),
				),
				'amort_months'    => array(
					'type'        => 'integer',
					'description' => __( 'Amortization period in months (e.g. 360).', 'mcp-ai-wpoos-pro' ),
					'default'     => 360,
				),
				'io_months'       => array(
					'type'        => 'integer',
					'description' => __( 'Interest-only period in months.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'noi_growth_rate' => array(
					'type'        => 'number',
					'description' => __( 'Annual NOI growth rate as decimal (e.g. 0.03 for 3%).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0.03,
				),
			),
			'required'   => array( 'purchase_price', 'noi', 'exit_noi', 'exit_cap_rate', 'hold_years', 'loan_amount', 'interest_rate' ),
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

		$price      = (float) ( $arguments['purchase_price'] ?? 0 );
		$noi        = (float) ( $arguments['noi'] ?? 0 );
		$exit_noi   = (float) ( $arguments['exit_noi'] ?? 0 );
		$exit_cap   = (float) ( $arguments['exit_cap_rate'] ?? 0 );
		$hold       = (int) ( $arguments['hold_years'] ?? 5 );
		$loan       = (float) ( $arguments['loan_amount'] ?? 0 );
		$rate       = (float) ( $arguments['interest_rate'] ?? 0 );
		$amort      = (int) ( $arguments['amort_months'] ?? 360 );
		$io_months  = (int) ( $arguments['io_months'] ?? 0 );
		$noi_growth = (float) ( $arguments['noi_growth_rate'] ?? 0.03 );

		if ( $price <= 0 || $noi <= 0 || $exit_cap <= 0 || $hold < 1 ) {
			return new WP_Error( 'invalid_input', __( 'Purchase price, NOI, exit cap rate, and hold period must be positive.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc   = WP_MCP_AI_CRE_Debt_Calculator::class;
		$equity = $price - $loan;

		if ( $equity <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'Equity (purchase price minus loan) must be positive.', 'mcp-ai-wpoos-pro' ) );
		}

		// Generate amortization schedule.
		$amort_schedule = $calc::generate_amortization_schedule(
			$loan,
			$rate,
			$hold * 12,
			$amort,
			$io_months
		);

		// Exit value.
		$exit_value       = $calc::calculate_value_direct_cap( $exit_noi, $exit_cap );
		$loan_balance_end = $amort_schedule['balloon_payment'];
		$net_sale         = $exit_value - $loan_balance_end;

		// Year-by-year cash flows.
		$unlev_cfs        = array( -$price ); // Year 0.
		$lev_cfs          = array( -$equity ); // Year 0.
		$yearly_detail    = array();
		$total_lev_dist   = 0.0;
		$total_unlev_dist = 0.0;

		$schedule_idx = 0;
		for ( $y = 1; $y <= $hold; $y++ ) {
			$year_noi = $noi * pow( 1 + $noi_growth, $y - 1 );

			// Sum 12 months of debt service.
			$year_ds = 0.0;
			for ( $m = 0; $m < 12; $m++ ) {
				if ( isset( $amort_schedule['schedule'][ $schedule_idx ] ) ) {
					$year_ds += $amort_schedule['schedule'][ $schedule_idx ]['payment'];
				}
				++$schedule_idx;
			}

			$unlev_cf = $year_noi;
			$lev_cf   = $year_noi - $year_ds;

			// Add sale proceeds in final year.
			if ( $y === $hold ) {
				$unlev_cf += $exit_value;
				$lev_cf   += $net_sale;
			}

			$unlev_cfs[] = $unlev_cf;
			$lev_cfs[]   = $lev_cf;

			$total_unlev_dist += ( $y === $hold ) ? $unlev_cf : $year_noi;
			$total_lev_dist   += ( $y === $hold ) ? $lev_cf : ( $year_noi - $year_ds );

			$coc = $calc::calculate_cash_on_cash( $year_noi - $year_ds, $equity );

			$yearly_detail[] = array(
				'year'         => $y,
				'noi'          => $calc::format_currency( $year_noi ),
				'debt_service' => $calc::format_currency( $year_ds ),
				'lev_cf'       => $calc::format_currency( $year_noi - $year_ds ),
				'unlev_cf'     => $calc::format_currency( $year_noi ),
				'cash_on_cash' => $calc::format_percentage( $coc ),
			);
		}

		// Add exit proceeds to total distributions.
		$total_unlev_dist += $exit_value;
		$total_lev_dist   += $net_sale;

		// IRR.
		$unlev_irr = $calc::calculate_irr( $unlev_cfs );
		$lev_irr   = $calc::calculate_irr( $lev_cfs );

		// Equity multiples.
		$unlev_em = $calc::calculate_equity_multiple( $total_unlev_dist, $price );
		$lev_em   = $calc::calculate_equity_multiple( $total_lev_dist, $equity );

		// Year-1 cash on cash.
		$year1_ds = 0.0;
		// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found
		for ( $m = 0; $m < 12 && $m < count( $amort_schedule['schedule'] ); $m++ ) {
			$year1_ds += $amort_schedule['schedule'][ $m ]['payment'];
		}
		$year1_coc = $calc::calculate_cash_on_cash( $noi - $year1_ds, $equity );

		return array(
			'success' => true,
			'message' => __( 'Leverage return analysis complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'deal_summary'      => array(
					'purchase_price'  => $calc::format_currency( $price ),
					'loan_amount'     => $calc::format_currency( $loan ),
					'equity_invested' => $calc::format_currency( $equity ),
					'ltv'             => $calc::format_percentage( $calc::calculate_ltv( $loan, $price ) ),
					'exit_value'      => $calc::format_currency( $exit_value ),
					'hold_period'     => $hold . ' years',
				),
				'return_comparison' => array(
					'leveraged'       => array(
						'irr'             => ( null !== $lev_irr ) ? $calc::format_percentage( $lev_irr ) : 'N/A',
						'equity_multiple' => round( $lev_em, 2 ) . 'x',
						'year1_coc'       => $calc::format_percentage( $year1_coc ),
					),
					'unleveraged'     => array(
						'irr'             => ( null !== $unlev_irr ) ? $calc::format_percentage( $unlev_irr ) : 'N/A',
						'equity_multiple' => round( $unlev_em, 2 ) . 'x',
						'year1_coc'       => $calc::format_percentage( $noi / $price ),
					),
					'leverage_spread' => ( null !== $lev_irr && null !== $unlev_irr )
						? round( ( $lev_irr - $unlev_irr ) * 10000 ) . ' bps'
						: 'N/A',
				),
				'yearly_detail'     => $yearly_detail,
			),
		);
	}
}
