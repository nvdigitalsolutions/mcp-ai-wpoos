<?php
/**
 * CRE Hold/Sell Analyzer — Compare hold vs. sell scenarios for CRE disposition decisions
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
 * Compares hold vs. sell scenarios for a CRE asset by modeling remaining
 * hold period returns against capital redeployment opportunities. Calculates
 * marginal return on equity to support disposition decisions.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Hold_Sell_Analyzer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_hold_sell_analyzer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Hold/Sell Analyzer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Compare hold vs. sell scenarios for a CRE asset by modeling remaining hold period returns against capital redeployment opportunities. Calculates marginal return on equity to support disposition decisions.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'current_value'               => array(
					'type'        => 'number',
					'description' => __( 'Current estimated market value of the property.', 'mcp-ai-wpoos-pro' ),
				),
				'current_noi'                 => array(
					'type'        => 'number',
					'description' => __( 'Current annual net operating income.', 'mcp-ai-wpoos-pro' ),
				),
				'projected_noi_growth_pct'    => array(
					'type'        => 'number',
					'description' => __( 'Annual NOI growth percentage (e.g. 2 for 2%). Default 2.', 'mcp-ai-wpoos-pro' ),
					'default'     => 2,
				),
				'hold_years_remaining'        => array(
					'type'        => 'integer',
					'description' => __( 'Number of remaining years in the hold period.', 'mcp-ai-wpoos-pro' ),
				),
				'exit_cap_rate'               => array(
					'type'        => 'number',
					'description' => __( 'Exit capitalization rate as decimal (e.g. 0.06 for 6%).', 'mcp-ai-wpoos-pro' ),
				),
				'selling_costs_pct'           => array(
					'type'        => 'number',
					'description' => __( 'Selling costs as percentage of sale price (e.g. 2 for 2%). Default 2.', 'mcp-ai-wpoos-pro' ),
					'default'     => 2,
				),
				'reinvestment_return_pct'     => array(
					'type'        => 'number',
					'description' => __( 'Annual return on redeployed capital as percentage (e.g. 12 for 12%). Default 12.', 'mcp-ai-wpoos-pro' ),
					'default'     => 12,
				),
				'original_investment'         => array(
					'type'        => 'number',
					'description' => __( 'Total original equity investment.', 'mcp-ai-wpoos-pro' ),
				),
				'total_distributions_to_date' => array(
					'type'        => 'number',
					'description' => __( 'Total cash distributions received to date.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'current_value', 'current_noi', 'hold_years_remaining', 'exit_cap_rate', 'original_investment', 'total_distributions_to_date' ),
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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$current_value      = (float) ( $arguments['current_value'] ?? 0 );
		$current_noi        = (float) ( $arguments['current_noi'] ?? 0 );
		$noi_growth_pct     = (float) ( $arguments['projected_noi_growth_pct'] ?? 2 );
		$hold_years         = absint( $arguments['hold_years_remaining'] ?? 0 );
		$exit_cap_rate      = (float) ( $arguments['exit_cap_rate'] ?? 0 );
		$selling_costs_pct  = (float) ( $arguments['selling_costs_pct'] ?? 2 );
		$reinvest_return    = (float) ( $arguments['reinvestment_return_pct'] ?? 12 );
		$original_invest    = (float) ( $arguments['original_investment'] ?? 0 );
		$total_dist_to_date = (float) ( $arguments['total_distributions_to_date'] ?? 0 );

		if ( $current_value <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'current_value must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $hold_years <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'hold_years_remaining must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $exit_cap_rate <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'exit_cap_rate must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $original_invest <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'original_investment must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc       = WP_MCP_AI_CRE_Debt_Calculator::class;
		$noi_growth = $noi_growth_pct / 100;

		// --- HOLD SCENARIO ---
		$projected_noi     = array();
		$sum_remaining_noi = 0.0;
		$hold_cash_flows   = array( -$current_value );

		for ( $n = 1; $n <= $hold_years; $n++ ) {
			$noi_year_n         = $current_noi * pow( 1 + $noi_growth, $n );
			$projected_noi[]    = array(
				'year' => $n,
				'noi'  => $calc::format_currency( $noi_year_n ),
			);
			$sum_remaining_noi += $noi_year_n;

			if ( $n < $hold_years ) {
				$hold_cash_flows[] = $noi_year_n;
			} else {
				// Final year: NOI + net exit proceeds.
				$exit_noi          = $noi_year_n;
				$exit_value        = $exit_noi / $exit_cap_rate;
				$net_exit_proceeds = $exit_value * ( 1 - $selling_costs_pct / 100 );
				$hold_cash_flows[] = $noi_year_n + $net_exit_proceeds;
			}
		}

		$hold_irr              = $calc::calculate_irr( $hold_cash_flows );
		$remaining_equity_mult = ( $current_value > 0 ) ? ( $sum_remaining_noi + $net_exit_proceeds ) / $current_value : 0;
		$total_equity_mult     = ( $original_invest > 0 ) ? ( $total_dist_to_date + $sum_remaining_noi + $net_exit_proceeds ) / $original_invest : 0;

		// --- SELL SCENARIO ---
		$net_sell_proceeds       = $current_value * ( 1 - $selling_costs_pct / 100 );
		$total_return_to_date    = $total_dist_to_date + $net_sell_proceeds;
		$equity_multiple_at_sale = ( $original_invest > 0 ) ? $total_return_to_date / $original_invest : 0;

		$reinvest_future_value = $net_sell_proceeds * pow( 1 + $reinvest_return / 100, $hold_years );
		$reinvest_total_return = $reinvest_future_value - $net_sell_proceeds;

		// --- COMPARISON ---
		$marginal_hold_value     = ( $sum_remaining_noi + $net_exit_proceeds ) - $net_sell_proceeds;
		$marginal_reinvest_value = $reinvest_total_return;
		$recommendation          = ( $marginal_hold_value > $marginal_reinvest_value ) ? 'Hold' : 'Sell & Redeploy';

		return array(
			'success'    => true,
			'message'    => __( 'Hold/Sell analysis complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'       => array(
				'hold_scenario' => array(
					'projected_noi'             => $projected_noi,
					'exit_value'                => $calc::format_currency( $exit_value ),
					'net_exit_proceeds'         => $calc::format_currency( $net_exit_proceeds ),
					'sum_remaining_noi'         => $calc::format_currency( $sum_remaining_noi ),
					'hold_irr'                  => $calc::format_percentage( $hold_irr ),
					'remaining_equity_multiple' => round( $remaining_equity_mult, 2 ) . 'x',
					'total_equity_multiple'     => round( $total_equity_mult, 2 ) . 'x',
				),
				'sell_scenario' => array(
					'net_sell_proceeds'         => $calc::format_currency( $net_sell_proceeds ),
					'total_return_to_date'      => $calc::format_currency( $total_return_to_date ),
					'equity_multiple_at_sale'   => round( $equity_multiple_at_sale, 2 ) . 'x',
					'reinvestment_return_pct'   => $calc::format_percentage( $reinvest_return / 100 ),
					'reinvestment_future_value' => $calc::format_currency( $reinvest_future_value ),
					'reinvestment_total_return' => $calc::format_currency( $reinvest_total_return ),
				),
				'comparison'    => array(
					'marginal_hold_value'     => $calc::format_currency( $marginal_hold_value ),
					'marginal_reinvest_value' => $calc::format_currency( $marginal_reinvest_value ),
					'recommendation'          => $recommendation,
				),
			),
			'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
