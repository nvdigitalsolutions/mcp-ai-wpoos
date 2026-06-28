<?php
/**
 * CRE Loan Sizer — Multi-constraint loan sizing with amortization schedule
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
 * Sizes a loan against LTV, DSCR, and debt yield constraints, then generates
 * the resulting amortization schedule with IO/P&I periods and balloon.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Loan_Sizer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_loan_sizer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Loan Sizer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Size a commercial real estate loan against LTV, DSCR, and debt-yield constraints. Returns maximum loan amount, binding constraint, key metrics, and full amortization schedule with IO and P&I periods.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'property_value'   => array(
					'type'        => 'number',
					'description' => __( 'Appraised property value.', 'mcp-ai-wpoos-pro' ),
				),
				'noi'              => array(
					'type'        => 'number',
					'description' => __( 'Annual Net Operating Income.', 'mcp-ai-wpoos-pro' ),
				),
				'interest_rate'    => array(
					'type'        => 'number',
					'description' => __( 'Annual interest rate as decimal (e.g. 0.065 for 6.5%).', 'mcp-ai-wpoos-pro' ),
				),
				'amort_years'      => array(
					'type'        => 'number',
					'description' => __( 'Amortization period in years (e.g. 30).', 'mcp-ai-wpoos-pro' ),
				),
				'max_ltv'          => array(
					'type'        => 'number',
					'description' => __( 'Maximum LTV as decimal (e.g. 0.75 for 75%).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0.75,
				),
				'min_dscr'         => array(
					'type'        => 'number',
					'description' => __( 'Minimum DSCR (e.g. 1.25).', 'mcp-ai-wpoos-pro' ),
					'default'     => 1.25,
				),
				'min_debt_yield'   => array(
					'type'        => 'number',
					'description' => __( 'Minimum debt yield as decimal (e.g. 0.10 for 10%).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0.10,
				),
				'io_period_months' => array(
					'type'        => 'integer',
					'description' => __( 'Interest-only period in months (0 for none).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'loan_term_months' => array(
					'type'        => 'integer',
					'description' => __( 'Loan term in months (e.g. 120 for 10 years).', 'mcp-ai-wpoos-pro' ),
					'default'     => 120,
				),
			),
			'required'   => array( 'property_value', 'noi', 'interest_rate', 'amort_years' ),
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

		$property_value   = (float) ( $arguments['property_value'] ?? 0 );
		$noi              = (float) ( $arguments['noi'] ?? 0 );
		$interest_rate    = (float) ( $arguments['interest_rate'] ?? 0 );
		$amort_years      = (float) ( $arguments['amort_years'] ?? 30 );
		$max_ltv          = (float) ( $arguments['max_ltv'] ?? 0.75 );
		$min_dscr         = (float) ( $arguments['min_dscr'] ?? 1.25 );
		$min_debt_yield   = (float) ( $arguments['min_debt_yield'] ?? 0.10 );
		$io_months        = (int) ( $arguments['io_period_months'] ?? 0 );
		$loan_term_months = (int) ( $arguments['loan_term_months'] ?? 120 );
		$amort_months     = (int) ( $amort_years * 12 );

		if ( $property_value <= 0 || $noi <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'Property value and NOI must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		// Size loan against constraints.
		$sizing = $calc::size_loan(
			$property_value,
			$noi,
			$interest_rate,
			$amort_months,
			$max_ltv,
			$min_dscr,
			$min_debt_yield
		);

		$max_loan = $sizing['max_loan'];

		// Compute resulting metrics at sized amount.
		$ltv        = $calc::calculate_ltv( $max_loan, $property_value );
		$debt_yield = $calc::calculate_debt_yield( $noi, $max_loan );
		$monthly_pi = $calc::calculate_monthly_payment( $max_loan, $interest_rate, $amort_months );
		$annual_ds  = $monthly_pi * 12;
		$dscr       = $calc::calculate_dscr( $noi, $annual_ds );
		$equity     = $property_value - $max_loan;

		// Generate amortization schedule.
		$amort = $calc::generate_amortization_schedule(
			$max_loan,
			$interest_rate,
			$loan_term_months,
			$amort_months,
			$io_months
		);

		// Summarise schedule by year (first 12 months = year 1, etc.).
		$yearly_summary = array();
		$yr_principal   = 0.0;
		$yr_interest    = 0.0;
		$yr_payments    = 0.0;
		foreach ( $amort['schedule'] as $row ) {
			$yr_principal += $row['principal'];
			$yr_interest  += $row['interest'];
			$yr_payments  += $row['payment'];
			if ( 0 === $row['month'] % 12 || $loan_term_months === $row['month'] ) {
				$yearly_summary[] = array(
					'year'      => (int) ceil( $row['month'] / 12 ),
					'principal' => round( $yr_principal, 2 ),
					'interest'  => round( $yr_interest, 2 ),
					'payments'  => round( $yr_payments, 2 ),
					'balance'   => $row['balance'],
				);
				$yr_principal     = 0.0;
				$yr_interest      = 0.0;
				$yr_payments      = 0.0;
			}
		}

		return array(
			'success' => true,
			'message' => __( 'Loan sizing complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'sizing_result'     => array(
					'max_loan_amount'    => $calc::format_currency( $max_loan ),
					'ltv_constrained'    => $calc::format_currency( $sizing['ltv_loan'] ),
					'dscr_constrained'   => $calc::format_currency( $sizing['dscr_loan'] ),
					'dy_constrained'     => $calc::format_currency( $sizing['dy_loan'] ),
					'binding_constraint' => $sizing['binding_constraint'],
				),
				'resulting_metrics' => array(
					'ltv'                 => $calc::format_percentage( $ltv ),
					'dscr'                => round( $dscr, 2 ) . 'x',
					'debt_yield'          => $calc::format_percentage( $debt_yield ),
					'annual_debt_service' => $calc::format_currency( $annual_ds ),
					'equity_required'     => $calc::format_currency( $equity ),
				),
				'loan_terms'        => array(
					'interest_rate'   => $calc::format_percentage( $interest_rate ),
					'amort_period'    => $amort_years . ' years',
					'loan_term'       => ( $loan_term_months / 12 ) . ' years',
					'io_period'       => $io_months . ' months',
					'balloon_payment' => $calc::format_currency( $amort['balloon_payment'] ),
					'total_interest'  => $calc::format_currency( $amort['total_interest'] ),
				),
				'annual_schedule'   => $yearly_summary,
			),
		);
	}
}
