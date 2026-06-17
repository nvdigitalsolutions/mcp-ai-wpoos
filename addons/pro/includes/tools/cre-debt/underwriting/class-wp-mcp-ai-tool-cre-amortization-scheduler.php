<?php
/**
 * CRE Amortization Scheduler — Full loan amortization with prepayment analysis
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
 * Generates a detailed amortization schedule with IO period, balloon maturity,
 * and optional prepayment cost analysis (defeasance or yield maintenance).
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Amortization_Scheduler implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_amortization_scheduler';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Amortization Scheduler', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Generate a full loan amortization schedule with IO period, P&I amortization, balloon payment, and optional prepayment cost analysis (defeasance or yield maintenance).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'loan_amount'         => array(
					'type'        => 'number',
					'description' => __( 'Original loan amount.', 'mcp-ai-wpoos-pro' ),
				),
				'interest_rate'       => array(
					'type'        => 'number',
					'description' => __( 'Annual interest rate as decimal (e.g. 0.055).', 'mcp-ai-wpoos-pro' ),
				),
				'loan_term_months'    => array(
					'type'        => 'integer',
					'description' => __( 'Loan term in months (e.g. 120 for 10 years).', 'mcp-ai-wpoos-pro' ),
				),
				'amort_period_months' => array(
					'type'        => 'integer',
					'description' => __( 'Amortization period in months (e.g. 360 for 30 years).', 'mcp-ai-wpoos-pro' ),
				),
				'io_period_months'    => array(
					'type'        => 'integer',
					'description' => __( 'Interest-only period in months (0 for full amortization).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'prepayment_type'     => array(
					'type'        => 'string',
					'description' => __( 'Prepayment protection type.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'none', 'defeasance', 'yield_maintenance' ),
					'default'     => 'none',
				),
				'treasury_rate'       => array(
					'type'        => 'number',
					'description' => __( 'Current treasury rate for prepayment calculation (decimal). Required when prepayment_type is not "none".', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'transaction_costs'   => array(
					'type'        => 'number',
					'description' => __( 'Transaction costs for defeasance (e.g. 50000).', 'mcp-ai-wpoos-pro' ),
					'default'     => 50000,
				),
			),
			'required'   => array( 'loan_amount', 'interest_rate', 'loan_term_months', 'amort_period_months' ),
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
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$loan_amount  = (float) ( $arguments['loan_amount'] ?? 0 );
		$rate         = (float) ( $arguments['interest_rate'] ?? 0 );
		$term_months  = (int) ( $arguments['loan_term_months'] ?? 120 );
		$amort_months = (int) ( $arguments['amort_period_months'] ?? 360 );
		$io_months    = (int) ( $arguments['io_period_months'] ?? 0 );
		$prepay_type  = sanitize_text_field( $arguments['prepayment_type'] ?? 'none' );
		$tsy_rate     = (float) ( $arguments['treasury_rate'] ?? 0 );
		$txn_costs    = (float) ( $arguments['transaction_costs'] ?? 50000 );

		if ( $loan_amount <= 0 || $rate < 0 || $term_months <= 0 || $amort_months <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'Loan amount, term, and amortization period must be positive.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		$amort = $calc::generate_amortization_schedule(
			$loan_amount,
			$rate,
			$term_months,
			$amort_months,
			$io_months
		);

		// Yearly summary.
		$yearly = array();
		$yr_p   = 0.0;
		$yr_i   = 0.0;
		$yr_pmt = 0.0;
		foreach ( $amort['schedule'] as $row ) {
			$yr_p   += $row['principal'];
			$yr_i   += $row['interest'];
			$yr_pmt += $row['payment'];
			if ( 0 === $row['month'] % 12 || $term_months === $row['month'] ) {
				$yearly[] = array(
					'year'        => (int) ceil( $row['month'] / 12 ),
					'principal'   => round( $yr_p, 2 ),
					'interest'    => round( $yr_i, 2 ),
					'payments'    => round( $yr_pmt, 2 ),
					'end_balance' => $row['balance'],
				);
				$yr_p     = 0.0;
				$yr_i     = 0.0;
				$yr_pmt   = 0.0;
			}
		}

		// Prepayment analysis at mid-point of loan term.
		$prepayment_analysis = null;
		if ( 'none' !== $prepay_type ) {
			$midpoint_month   = (int) floor( $term_months / 2 );
			$remaining_months = $term_months - $midpoint_month;
			$midpoint_balance = $amort['schedule'][ $midpoint_month - 1 ]['balance'] ?? $loan_amount;

			if ( 'defeasance' === $prepay_type ) {
				$cost                = $calc::calculate_defeasance_cost(
					$midpoint_balance,
					$rate,
					$remaining_months,
					$tsy_rate,
					$txn_costs
				);
				$prepayment_analysis = array(
					'type'               => 'defeasance',
					'analysis_month'     => $midpoint_month,
					'remaining_months'   => $remaining_months,
					'loan_balance'       => $calc::format_currency( $midpoint_balance ),
					'treasury_portfolio' => $calc::format_currency( $cost['treasury_portfolio'] ),
					'defeasance_premium' => $calc::format_currency( $cost['defeasance_premium'] ),
					'transaction_costs'  => $calc::format_currency( $cost['transaction_costs'] ),
					'total_cost'         => $calc::format_currency( $cost['total_cost'] ),
				);
			} else {
				$ym_cost             = $calc::calculate_yield_maintenance(
					$midpoint_balance,
					$rate,
					$remaining_months,
					$tsy_rate
				);
				$prepayment_analysis = array(
					'type'                   => 'yield_maintenance',
					'analysis_month'         => $midpoint_month,
					'remaining_months'       => $remaining_months,
					'loan_balance'           => $calc::format_currency( $midpoint_balance ),
					'yield_maintenance_cost' => $calc::format_currency( $ym_cost ),
					'cost_as_pct_balance'    => $calc::format_percentage( ( $midpoint_balance > 0 ) ? $ym_cost / $midpoint_balance : 0 ),
				);
			}
		}

		$monthly_pi = $calc::calculate_monthly_payment( $loan_amount, $rate, $amort_months );
		$monthly_io = $calc::calculate_io_payment( $loan_amount, $rate );

		return array(
			'success' => true,
			'message' => __( 'Amortization schedule generated. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'loan_summary'        => array(
					'loan_amount'    => $calc::format_currency( $loan_amount ),
					'interest_rate'  => $calc::format_percentage( $rate ),
					'loan_term'      => $term_months . ' months (' . round( $term_months / 12, 1 ) . ' years)',
					'amort_period'   => $amort_months . ' months (' . round( $amort_months / 12, 1 ) . ' years)',
					'io_period'      => $io_months . ' months',
					'monthly_io_pmt' => $calc::format_currency( $monthly_io ),
					'monthly_pi_pmt' => $calc::format_currency( $monthly_pi ),
				),
				'totals'              => array(
					'total_interest'  => $calc::format_currency( $amort['total_interest'] ),
					'total_principal' => $calc::format_currency( $amort['total_principal'] ),
					'balloon_payment' => $calc::format_currency( $amort['balloon_payment'] ),
				),
				'annual_schedule'     => $yearly,
				'prepayment_analysis' => $prepayment_analysis,
			),
		);
	}
}
