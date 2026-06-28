<?php
/**
 * CMBS Defeasance Calculator — Calculate defeasance and yield maintenance costs
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
 * Calculates defeasance cost, yield maintenance cost, and provides a side-by-side
 * comparison using the shared CRE Debt calculator. Includes treasury portfolio
 * sizing and total cost breakdowns.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CMBS_Defeasance_Calculator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cmbs_defeasance_calculator';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CMBS Defeasance Calculator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Calculate defeasance cost and optionally compare to yield maintenance for a CMBS loan. Includes treasury portfolio sizing, premium calculation, and total cost breakdown.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'loan_balance'                 => array(
					'type'        => 'number',
					'description' => __( 'Current outstanding loan balance.', 'mcp-ai-wpoos-pro' ),
				),
				'coupon_rate'                  => array(
					'type'        => 'number',
					'description' => __( 'Loan coupon rate as decimal (e.g. 0.055 for 5.5%).', 'mcp-ai-wpoos-pro' ),
				),
				'remaining_months'             => array(
					'type'        => 'integer',
					'description' => __( 'Number of months remaining to maturity.', 'mcp-ai-wpoos-pro' ),
				),
				'treasury_rate'                => array(
					'type'        => 'number',
					'description' => __( 'Current matching treasury rate as decimal.', 'mcp-ai-wpoos-pro' ),
				),
				'transaction_costs'            => array(
					'type'        => 'number',
					'description' => __( 'Estimated transaction costs (legal, consultants, etc.). Default: $50,000.', 'mcp-ai-wpoos-pro' ),
				),
				'yield_maintenance_comparison' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include yield maintenance comparison.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'loan_balance', 'coupon_rate', 'remaining_months', 'treasury_rate' ),
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

		$loan_balance      = (float) ( $arguments['loan_balance'] ?? 0 );
		$coupon_rate       = (float) ( $arguments['coupon_rate'] ?? 0 );
		$remaining_months  = (int) ( $arguments['remaining_months'] ?? 0 );
		$treasury_rate     = (float) ( $arguments['treasury_rate'] ?? 0 );
		$transaction_costs = (float) ( $arguments['transaction_costs'] ?? 50000 );
		$compare_ym        = ! empty( $arguments['yield_maintenance_comparison'] );

		if ( $loan_balance <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'Loan balance must be positive.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $coupon_rate <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'Coupon rate must be positive.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $remaining_months <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'Remaining months must be positive.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		// Calculate defeasance cost using shared calculator.
		$defeasance = $calc::calculate_defeasance_cost(
			$loan_balance,
			$coupon_rate,
			$remaining_months,
			$treasury_rate,
			$transaction_costs
		);

		// Calculate monthly debt service for context.
		$monthly_payment        = $calc::calculate_monthly_payment( $loan_balance, $coupon_rate, $remaining_months );
		$remaining_debt_service = $monthly_payment * $remaining_months;

		// Defeasance as percentage of balance.
		$defeasance_pct = ( $loan_balance > 0 ) ? $defeasance['total_cost'] / $loan_balance : 0;

		// Rate differential analysis.
		$rate_diff     = $coupon_rate - $treasury_rate;
		$rate_diff_bps = $rate_diff * 10000;

		$defeasance_result = array(
			'loan_balance'           => $calc::format_currency( $loan_balance ),
			'coupon_rate'            => $calc::format_percentage( $coupon_rate ),
			'treasury_rate'          => $calc::format_percentage( $treasury_rate ),
			'rate_differential'      => $calc::format_percentage( $rate_diff ),
			'rate_differential_bps'  => round( $rate_diff_bps, 1 ),
			'remaining_months'       => $remaining_months,
			'remaining_years'        => round( $remaining_months / 12, 1 ),
			'monthly_payment'        => $calc::format_currency( $monthly_payment ),
			'treasury_portfolio'     => $calc::format_currency( $defeasance['treasury_portfolio'] ),
			'defeasance_premium'     => $calc::format_currency( $defeasance['defeasance_premium'] ),
			'transaction_costs'      => $calc::format_currency( $defeasance['transaction_costs'] ),
			'total_defeasance_cost'  => $calc::format_currency( $defeasance['total_cost'] ),
			'cost_as_pct_of_balance' => $calc::format_percentage( $defeasance_pct ),
		);

		$data = array(
			'defeasance' => $defeasance_result,
		);

		// Yield maintenance comparison.
		if ( $compare_ym ) {
			$ym_cost = $calc::calculate_yield_maintenance(
				$loan_balance,
				$coupon_rate,
				$remaining_months,
				$treasury_rate
			);

			$ym_total = $ym_cost + $transaction_costs;
			$ym_pct   = ( $loan_balance > 0 ) ? $ym_total / $loan_balance : 0;

			$ym_result = array(
				'yield_maintenance_penalty' => $calc::format_currency( $ym_cost ),
				'transaction_costs'         => $calc::format_currency( $transaction_costs ),
				'total_ym_cost'             => $calc::format_currency( $ym_total ),
				'cost_as_pct_of_balance'    => $calc::format_percentage( $ym_pct ),
			);

			$savings = $defeasance['total_cost'] - $ym_total;
			$cheaper = ( $ym_total < $defeasance['total_cost'] ) ? 'yield_maintenance' : 'defeasance';

			$comparison = array(
				'defeasance_total'        => $calc::format_currency( $defeasance['total_cost'] ),
				'yield_maintenance_total' => $calc::format_currency( $ym_total ),
				'savings_with_cheaper'    => $calc::format_currency( abs( $savings ) ),
				'recommended_method'      => $cheaper,
				'recommendation'          => ( 'yield_maintenance' === $cheaper )
					? __( 'Yield maintenance is less expensive for this scenario.', 'mcp-ai-wpoos-pro' )
					: __( 'Defeasance is less expensive for this scenario.', 'mcp-ai-wpoos-pro' ),
			);

			$data['yield_maintenance'] = $ym_result;
			$data['comparison']        = $comparison;
		}

		// Sensitivity analysis: defeasance cost at different treasury rates.
		$sensitivity = array();
		$rate_shifts = array( -0.01, -0.005, 0, 0.005, 0.01, 0.02 );
		foreach ( $rate_shifts as $shift ) {
			$shifted_rate  = max( 0.001, $treasury_rate + $shift );
			$shifted_def   = $calc::calculate_defeasance_cost(
				$loan_balance,
				$coupon_rate,
				$remaining_months,
				$shifted_rate,
				$transaction_costs
			);
			$sensitivity[] = array(
				'treasury_rate' => $calc::format_percentage( $shifted_rate ),
				'shift_bps'     => round( $shift * 10000 ),
				'total_cost'    => $calc::format_currency( $shifted_def['total_cost'] ),
			);
		}

		$data['rate_sensitivity'] = $sensitivity;
		$data['disclaimer']       = __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: total defeasance cost, 2: percentage of balance */
				__( 'Defeasance cost: %1$s (%2$s of balance).', 'mcp-ai-wpoos-pro' ),
				$defeasance_result['total_defeasance_cost'],
				$defeasance_result['cost_as_pct_of_balance']
			),
			'data'    => $data,
		);
	}
}
