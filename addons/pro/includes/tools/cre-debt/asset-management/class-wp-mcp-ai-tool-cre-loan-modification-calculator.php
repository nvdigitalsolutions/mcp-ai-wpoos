<?php
/**
 * CRE Loan Modification Calculator — Calculate financial impact of loan modifications
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
 * Calculates the financial impact of loan modifications including rate changes,
 * term extensions, principal forbearance, and A/B note splits. Compares original
 * vs modified debt service and DSCR.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Loan_Modification_Calculator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_loan_modification_calculator';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Loan Modification Calculator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Calculate the financial impact of loan modifications including rate changes, term extensions, principal forbearance, and A/B note splits. Compares original vs modified debt service and DSCR.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'original_balance'      => array(
					'type'        => 'number',
					'description' => __( 'Original outstanding loan balance.', 'mcp-ai-wpoos-pro' ),
				),
				'original_rate'         => array(
					'type'        => 'number',
					'description' => __( 'Original annual interest rate as decimal (e.g. 0.055 for 5.5%).', 'mcp-ai-wpoos-pro' ),
				),
				'original_term_months'  => array(
					'type'        => 'integer',
					'description' => __( 'Original loan term in months.', 'mcp-ai-wpoos-pro' ),
				),
				'remaining_months'      => array(
					'type'        => 'integer',
					'description' => __( 'Remaining months on the original loan.', 'mcp-ai-wpoos-pro' ),
				),
				'noi'                   => array(
					'type'        => 'number',
					'description' => __( 'Annual net operating income.', 'mcp-ai-wpoos-pro' ),
				),
				'modified_rate'         => array(
					'type'        => 'number',
					'description' => __( 'Modified annual interest rate as decimal. Defaults to original_rate if not provided.', 'mcp-ai-wpoos-pro' ),
				),
				'extended_months'       => array(
					'type'        => 'integer',
					'description' => __( 'Additional months to extend the loan term. Default 0.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'principal_forbearance' => array(
					'type'        => 'number',
					'description' => __( 'Amount of principal forbearance. Default 0.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'ab_note_split'         => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to model an A/B note split. Default false.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'a_note_pct'            => array(
					'type'        => 'number',
					'description' => __( 'A-note percentage of modified balance (e.g. 70 for 70%). Default 70.', 'mcp-ai-wpoos-pro' ),
					'default'     => 70,
				),
			),
			'required'   => array( 'original_balance', 'original_rate', 'original_term_months', 'remaining_months', 'noi' ),
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
	public function execute( array $arguments = array(), array $context = array() ): array|\WP_Error {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$original_balance      = (float) ( $arguments['original_balance'] ?? 0 );
		$original_rate         = (float) ( $arguments['original_rate'] ?? 0 );
		$original_term_months  = absint( $arguments['original_term_months'] ?? 0 );
		$remaining_months      = absint( $arguments['remaining_months'] ?? 0 );
		$noi                   = (float) ( $arguments['noi'] ?? 0 );
		$modified_rate         = isset( $arguments['modified_rate'] ) ? (float) $arguments['modified_rate'] : null;
		$extended_months       = absint( $arguments['extended_months'] ?? 0 );
		$principal_forbearance = (float) ( $arguments['principal_forbearance'] ?? 0 );
		$ab_note_split         = ! empty( $arguments['ab_note_split'] );
		$a_note_pct            = (float) ( $arguments['a_note_pct'] ?? 70 );

		if ( $original_balance <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'original_balance must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $original_rate <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'original_rate must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $original_term_months <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'original_term_months must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $remaining_months <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'remaining_months must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		// Original analysis (IO for commercial simplicity).
		$original_monthly_payment = $calc::calculate_io_payment( $original_balance, $original_rate );
		$original_annual_ds       = $original_monthly_payment * 12;
		$original_dscr            = $calc::calculate_dscr( $noi, $original_annual_ds );

		// Modified analysis.
		$effective_rate           = ( null !== $modified_rate ) ? $modified_rate : $original_rate;
		$modified_balance         = $original_balance - $principal_forbearance;
		$new_term                 = $remaining_months + $extended_months;
		$modified_monthly_payment = $calc::calculate_io_payment( $modified_balance, $effective_rate );
		$modified_annual_ds       = $modified_monthly_payment * 12;
		$modified_dscr            = $calc::calculate_dscr( $noi, $modified_annual_ds );

		// Modification concession.
		$monthly_savings         = $original_monthly_payment - $modified_monthly_payment;
		$total_savings_over_term = $monthly_savings * $new_term;
		$rate_concession         = $original_rate - $effective_rate;

		// NPV of concession: monthly_savings * (1 - (1+r/12)^-n) / (r/12).
		$npv_concession = 0.0;
		if ( $original_rate > 0 && $new_term > 0 ) {
			$monthly_rate   = $original_rate / 12;
			$npv_concession = $monthly_savings * ( 1 - pow( 1 + $monthly_rate, -$new_term ) ) / $monthly_rate;
		}

		$data = array(
			'original_loan'       => array(
				'balance'         => $calc::format_currency( $original_balance ),
				'rate'            => $calc::format_percentage( $original_rate ),
				'term_months'     => $original_term_months,
				'remaining'       => $remaining_months,
				'monthly_payment' => $calc::format_currency( $original_monthly_payment ),
				'annual_ds'       => $calc::format_currency( $original_annual_ds ),
				'dscr'            => round( $original_dscr, 2 ) . 'x',
			),
			'modified_loan'       => array(
				'balance'         => $calc::format_currency( $modified_balance ),
				'rate'            => $calc::format_percentage( $effective_rate ),
				'new_term'        => $new_term,
				'monthly_payment' => $calc::format_currency( $modified_monthly_payment ),
				'annual_ds'       => $calc::format_currency( $modified_annual_ds ),
				'dscr'            => round( $modified_dscr, 2 ) . 'x',
			),
			'modification_terms'  => array(
				'rate_concession'       => $calc::format_percentage( $rate_concession ),
				'term_extension_months' => $extended_months,
				'principal_forbearance' => $calc::format_currency( $principal_forbearance ),
			),
			'concession_analysis' => array(
				'monthly_savings'         => $calc::format_currency( $monthly_savings ),
				'total_savings_over_term' => $calc::format_currency( $total_savings_over_term ),
				'npv_of_concession'       => $calc::format_currency( $npv_concession ),
			),
			'before_after'        => array(
				'monthly_payment_change' => $calc::format_currency( $modified_monthly_payment - $original_monthly_payment ),
				'annual_ds_change'       => $calc::format_currency( $modified_annual_ds - $original_annual_ds ),
				'dscr_change'            => round( $modified_dscr - $original_dscr, 2 ) . 'x',
			),
		);

		// A/B note split analysis.
		if ( $ab_note_split ) {
			$a_note_balance          = $modified_balance * $a_note_pct / 100;
			$b_note_balance          = $modified_balance - $a_note_balance;
			$a_note_payment          = $calc::calculate_io_payment( $a_note_balance, $effective_rate );
			$b_note_payment          = 0.0;
			$b_note_accrued_interest = $b_note_balance * $effective_rate;
			$total_modified_payment  = $a_note_payment;
			$a_note_dscr             = $calc::calculate_dscr( $noi, $a_note_payment * 12 );

			$data['ab_note_split'] = array(
				'a_note'                         => array(
					'balance'         => $calc::format_currency( $a_note_balance ),
					'pct'             => $calc::format_percentage( $a_note_pct / 100 ),
					'monthly_payment' => $calc::format_currency( $a_note_payment ),
					'dscr'            => round( $a_note_dscr, 2 ) . 'x',
				),
				'b_note'                         => array(
					'balance'                 => $calc::format_currency( $b_note_balance ),
					'pct'                     => $calc::format_percentage( ( 100 - $a_note_pct ) / 100 ),
					'monthly_payment'         => $calc::format_currency( $b_note_payment ),
					'annual_accrued_interest' => $calc::format_currency( $b_note_accrued_interest ),
					'status'                  => __( 'Deferred/non-performing until A-note is paid.', 'mcp-ai-wpoos-pro' ),
				),
				'total_modified_monthly_payment' => $calc::format_currency( $total_modified_payment ),
			);
		}

		$data['disclaimer'] = __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' );

		return array(
			'success'    => true,
			'message'    => __( 'Loan modification analysis complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'       => $data,
			'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
