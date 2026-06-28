<?php
/**
 * CRE Loan Quote Generator — Generate complete term sheets with all key metrics
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
 * Generates a comprehensive loan quote / term sheet including LTV, DSCR,
 * debt yield, payment schedules, and total cost of capital analysis.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Loan_Quote_Generator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_loan_quote_generator';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Loan Quote Generator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Generate a complete term sheet / loan quote with LTV, DSCR, debt yield, monthly payments, origination costs, prepayment terms, and total cost of capital.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Loan amount.', 'mcp-ai-wpoos-pro' ),
				),
				'property_value'      => array(
					'type'        => 'number',
					'description' => __( 'Property appraised value.', 'mcp-ai-wpoos-pro' ),
				),
				'noi'                 => array(
					'type'        => 'number',
					'description' => __( 'Annual Net Operating Income.', 'mcp-ai-wpoos-pro' ),
				),
				'property_type'       => array(
					'type'        => 'string',
					'description' => __( 'Property type.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'office', 'retail', 'industrial', 'multifamily', 'hotel', 'other' ),
				),
				'loan_term_months'    => array(
					'type'        => 'integer',
					'description' => __( 'Loan term in months.', 'mcp-ai-wpoos-pro' ),
					'default'     => 120,
				),
				'amort_months'        => array(
					'type'        => 'integer',
					'description' => __( 'Amortization period in months.', 'mcp-ai-wpoos-pro' ),
					'default'     => 360,
				),
				'io_months'           => array(
					'type'        => 'integer',
					'description' => __( 'Interest-only period in months.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'interest_rate'       => array(
					'type'        => 'number',
					'description' => __( 'All-in annual interest rate as decimal (e.g. 0.065).', 'mcp-ai-wpoos-pro' ),
				),
				'spread_bps'          => array(
					'type'        => 'number',
					'description' => __( 'Spread over index in basis points (e.g. 200 for 2%).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'index_rate'          => array(
					'type'        => 'number',
					'description' => __( 'Index rate as decimal if using spread (e.g. 0.045 for SOFR).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'origination_fee_pct' => array(
					'type'        => 'number',
					'description' => __( 'Origination fee as percentage (e.g. 1.0 for 1%).', 'mcp-ai-wpoos-pro' ),
					'default'     => 1.0,
				),
				'exit_fee_pct'        => array(
					'type'        => 'number',
					'description' => __( 'Exit/disposition fee as percentage.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'prepayment_type'     => array(
					'type'        => 'string',
					'description' => __( 'Prepayment protection type.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'none', 'lockout', 'defeasance', 'yield_maintenance', 'step_down' ),
					'default'     => 'none',
				),
				'recourse'            => array(
					'type'        => 'string',
					'description' => __( 'Recourse type.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'full', 'partial', 'non_recourse' ),
					'default'     => 'non_recourse',
				),
				'lender_name'         => array(
					'type'        => 'string',
					'description' => __( 'Lender or quoting institution name.', 'mcp-ai-wpoos-pro' ),
					'default'     => '',
				),
			),
			'required'   => array( 'loan_amount', 'property_value', 'noi', 'interest_rate' ),
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

		$loan_amount     = (float) ( $arguments['loan_amount'] ?? 0 );
		$property_value  = (float) ( $arguments['property_value'] ?? 0 );
		$noi             = (float) ( $arguments['noi'] ?? 0 );
		$property_type   = sanitize_text_field( $arguments['property_type'] ?? 'other' );
		$term_months     = (int) ( $arguments['loan_term_months'] ?? 120 );
		$amort_months    = (int) ( $arguments['amort_months'] ?? 360 );
		$io_months       = (int) ( $arguments['io_months'] ?? 0 );
		$interest_rate   = (float) ( $arguments['interest_rate'] ?? 0 );
		$spread_bps      = (float) ( $arguments['spread_bps'] ?? 0 );
		$index_rate      = (float) ( $arguments['index_rate'] ?? 0 );
		$orig_fee_pct    = (float) ( $arguments['origination_fee_pct'] ?? 1.0 );
		$exit_fee_pct    = (float) ( $arguments['exit_fee_pct'] ?? 0 );
		$prepayment_type = sanitize_text_field( $arguments['prepayment_type'] ?? 'none' );
		$recourse        = sanitize_text_field( $arguments['recourse'] ?? 'non_recourse' );
		$lender_name     = sanitize_text_field( $arguments['lender_name'] ?? '' );

		if ( $loan_amount <= 0 || $property_value <= 0 || $noi <= 0 || $interest_rate <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'Loan amount, property value, NOI, and interest rate must be positive.', 'mcp-ai-wpoos-pro' ) );
		}

		// If spread is provided, compute all-in rate.
		$all_in_rate = $interest_rate;
		if ( $spread_bps > 0 && $index_rate > 0 ) {
			$all_in_rate = $index_rate + ( $spread_bps / 10000 );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		// Core metrics.
		$ltv        = $calc::calculate_ltv( $loan_amount, $property_value );
		$debt_yield = $calc::calculate_debt_yield( $noi, $loan_amount );

		// Payment calculations.
		$monthly_pi = $calc::calculate_monthly_payment( $loan_amount, $all_in_rate, $amort_months );
		$monthly_io = $calc::calculate_io_payment( $loan_amount, $all_in_rate );
		$annual_ds  = ( $io_months > 0 ) ? $monthly_io * 12 : $monthly_pi * 12;
		$dscr       = $calc::calculate_dscr( $noi, $annual_ds );

		// Amortization schedule.
		$amort = $calc::generate_amortization_schedule(
			$loan_amount,
			$all_in_rate,
			$term_months,
			$amort_months,
			$io_months
		);

		// Fees.
		$origination_fee = $loan_amount * ( $orig_fee_pct / 100 );
		$exit_fee        = $loan_amount * ( $exit_fee_pct / 100 );

		// Total cost of capital.
		$total_interest = $amort['total_interest'];
		$total_fees     = $origination_fee + $exit_fee;
		$total_cost     = $total_interest + $total_fees;

		// Net loan proceeds.
		$net_proceeds = $loan_amount - $origination_fee;

		// Equity required.
		$equity_required = $property_value - $loan_amount;

		// Debt service constant.
		$ds_constant = ( $loan_amount > 0 ) ? ( $monthly_pi * 12 ) / $loan_amount : 0.0;

		// Recourse labels.
		$recourse_labels = array(
			'full'         => __( 'Full Recourse', 'mcp-ai-wpoos-pro' ),
			'partial'      => __( 'Partial Recourse (bad-boy carve-outs)', 'mcp-ai-wpoos-pro' ),
			'non_recourse' => __( 'Non-Recourse (standard carve-outs)', 'mcp-ai-wpoos-pro' ),
		);

		$prepayment_labels = array(
			'none'              => __( 'Open / No Prepayment Penalty', 'mcp-ai-wpoos-pro' ),
			'lockout'           => __( 'Hard Lockout', 'mcp-ai-wpoos-pro' ),
			'defeasance'        => __( 'Defeasance', 'mcp-ai-wpoos-pro' ),
			'yield_maintenance' => __( 'Yield Maintenance', 'mcp-ai-wpoos-pro' ),
			'step_down'         => __( 'Step-Down (5/4/3/2/1)', 'mcp-ai-wpoos-pro' ),
		);

		return array(
			'success' => true,
			'message' => __( 'Loan quote generated. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'term_sheet'      => array(
					'lender'         => $lender_name ? $lender_name : __( 'N/A', 'mcp-ai-wpoos-pro' ),
					'property_type'  => $property_type,
					'loan_amount'    => $calc::format_currency( $loan_amount ),
					'property_value' => $calc::format_currency( $property_value ),
					'noi'            => $calc::format_currency( $noi ),
					'all_in_rate'    => $calc::format_percentage( $all_in_rate ),
					'index_rate'     => ( $index_rate > 0 ) ? $calc::format_percentage( $index_rate ) : __( 'Fixed', 'mcp-ai-wpoos-pro' ),
					'spread'         => ( $spread_bps > 0 ) ? $spread_bps . ' bps' : __( 'N/A', 'mcp-ai-wpoos-pro' ),
					'loan_term'      => round( $term_months / 12, 1 ) . ' years',
					'amortization'   => round( $amort_months / 12, 1 ) . ' years',
					'io_period'      => $io_months . ' months',
					'recourse'       => $recourse_labels[ $recourse ] ?? $recourse,
					'prepayment'     => $prepayment_labels[ $prepayment_type ] ?? $prepayment_type,
				),
				'key_metrics'     => array(
					'ltv'         => $calc::format_percentage( $ltv ),
					'dscr'        => round( $dscr, 2 ) . 'x',
					'debt_yield'  => $calc::format_percentage( $debt_yield ),
					'ds_constant' => $calc::format_percentage( $ds_constant ),
				),
				'payment_detail'  => array(
					'monthly_io'      => $calc::format_currency( $monthly_io ),
					'monthly_pi'      => $calc::format_currency( $monthly_pi ),
					'annual_ds_io'    => $calc::format_currency( $monthly_io * 12 ),
					'annual_ds_pi'    => $calc::format_currency( $monthly_pi * 12 ),
					'balloon_payment' => $calc::format_currency( $amort['balloon_payment'] ),
				),
				'cost_of_capital' => array(
					'origination_fee' => $calc::format_currency( $origination_fee ),
					'exit_fee'        => $calc::format_currency( $exit_fee ),
					'total_interest'  => $calc::format_currency( $total_interest ),
					'total_fees'      => $calc::format_currency( $total_fees ),
					'total_cost'      => $calc::format_currency( $total_cost ),
					'net_proceeds'    => $calc::format_currency( $net_proceeds ),
				),
				'equity_required' => $calc::format_currency( $equity_required ),
			),
		);
	}
}
