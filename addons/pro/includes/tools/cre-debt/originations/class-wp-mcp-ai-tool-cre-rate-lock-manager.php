<?php
/**
 * CRE Rate Lock Manager — Analyze rate lock economics, extension costs, and mark-to-market
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
 * Evaluates rate lock economics including lock value/cost, break-even analysis,
 * extension pricing, and mark-to-market impact.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Rate_Lock_Manager implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_rate_lock_manager';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Rate Lock Manager', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Analyze rate lock economics including lock value, break-even rate movement, extension costs, hedge economics, and mark-to-market impact for CRE loan originations.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'loan_amount'                => array(
					'type'        => 'number',
					'description' => __( 'Loan amount.', 'mcp-ai-wpoos-pro' ),
				),
				'locked_rate'                => array(
					'type'        => 'number',
					'description' => __( 'Locked interest rate as decimal (e.g. 0.055).', 'mcp-ai-wpoos-pro' ),
				),
				'current_market_rate'        => array(
					'type'        => 'number',
					'description' => __( 'Current market rate as decimal (e.g. 0.060).', 'mcp-ai-wpoos-pro' ),
				),
				'lock_period_days'           => array(
					'type'        => 'integer',
					'description' => __( 'Rate lock period in days.', 'mcp-ai-wpoos-pro' ),
					'default'     => 45,
				),
				'extension_cost_bps_per_day' => array(
					'type'        => 'number',
					'description' => __( 'Extension cost in basis points per day.', 'mcp-ai-wpoos-pro' ),
					'default'     => 1.0,
				),
				'hedge_cost_bps'             => array(
					'type'        => 'number',
					'description' => __( 'Hedge cost in basis points.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
			),
			'required'   => array( 'loan_amount', 'locked_rate', 'current_market_rate' ),
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
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$loan_amount    = (float) ( $arguments['loan_amount'] ?? 0 );
		$locked_rate    = (float) ( $arguments['locked_rate'] ?? 0 );
		$market_rate    = (float) ( $arguments['current_market_rate'] ?? 0 );
		$lock_days      = (int) ( $arguments['lock_period_days'] ?? 45 );
		$ext_cost_bps   = (float) ( $arguments['extension_cost_bps_per_day'] ?? 1.0 );
		$hedge_cost_bps = (float) ( $arguments['hedge_cost_bps'] ?? 0 );

		if ( $loan_amount <= 0 || $locked_rate <= 0 || $market_rate <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'Loan amount, locked rate, and market rate must be positive.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		// Rate differential (positive = lock is favorable / "in the money").
		$rate_diff_bps    = ( $market_rate - $locked_rate ) * 10000;
		$rate_diff_annual = ( $market_rate - $locked_rate ) * $loan_amount;

		// Mark-to-market: PV of rate differential over a 10-year typical term.
		$term_years        = 10;
		$mtm_annual_saving = $rate_diff_annual;
		$mtm_value         = 0.0;
		if ( abs( $market_rate ) > 0 ) {
			for ( $y = 1; $y <= $term_years; $y++ ) {
				$mtm_value += $mtm_annual_saving / pow( 1 + $market_rate, $y );
			}
		}

		// Lock status.
		if ( $rate_diff_bps > 0 ) {
			$lock_status = __( 'In-the-money (lock is favorable)', 'mcp-ai-wpoos-pro' );
		} elseif ( $rate_diff_bps < 0 ) {
			$lock_status = __( 'Out-of-the-money (market has improved)', 'mcp-ai-wpoos-pro' );
		} else {
			$lock_status = __( 'At-the-money', 'mcp-ai-wpoos-pro' );
		}

		// Extension economics.
		$ext_cost_daily = ( $ext_cost_bps / 10000 ) * $loan_amount;
		$ext_7_days     = $ext_cost_daily * 7;
		$ext_15_days    = $ext_cost_daily * 15;
		$ext_30_days    = $ext_cost_daily * 30;

		// Break-even: how many bps of rate movement offsets extension cost for 15-day extension.
		$breakeven_ext_15 = ( $loan_amount > 0 ) ? ( $ext_15_days / $loan_amount ) * 10000 : 0.0;

		// Break-even rate movement: rate must move this much to offset lock cost.
		$lock_cost_total = 0.0;
		if ( $hedge_cost_bps > 0 ) {
			$lock_cost_total = ( $hedge_cost_bps / 10000 ) * $loan_amount;
		}

		// Hedge analysis.
		$hedge_cost_dollars  = ( $hedge_cost_bps / 10000 ) * $loan_amount;
		$breakeven_rate_move = ( $loan_amount > 0 && $term_years > 0 )
			? ( $hedge_cost_dollars / ( $loan_amount * $term_years ) )
			: 0.0;

		// Extension scenario analysis.
		$extension_scenarios = array();
		foreach ( array( 7, 15, 30, 45 ) as $ext_days ) {
			$ext_cost              = $ext_cost_daily * $ext_days;
			$total_lock            = $lock_days + $ext_days;
			$extension_scenarios[] = array(
				'extension_days'  => $ext_days,
				'total_lock_days' => $total_lock,
				'extension_cost'  => '$' . number_format( $ext_cost, 0 ),
				'cost_as_bps'     => round( ( $ext_cost / $loan_amount ) * 10000, 1 ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Rate lock analysis complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'lock_summary'        => array(
					'loan_amount'         => $calc::format_currency( $loan_amount ),
					'locked_rate'         => $calc::format_percentage( $locked_rate ),
					'current_market_rate' => $calc::format_percentage( $market_rate ),
					'rate_differential'   => round( $rate_diff_bps, 1 ) . ' bps',
					'lock_period'         => $lock_days . ' days',
					'lock_status'         => $lock_status,
				),
				'mark_to_market'      => array(
					'annual_rate_savings' => $calc::format_currency( $rate_diff_annual ),
					'mtm_value_10yr_pv'   => $calc::format_currency( $mtm_value ),
					'mtm_direction'       => ( $mtm_value >= 0 ) ? __( 'Gain', 'mcp-ai-wpoos-pro' ) : __( 'Loss', 'mcp-ai-wpoos-pro' ),
				),
				'extension_economics' => array(
					'daily_extension_cost' => $calc::format_currency( $ext_cost_daily ),
					'cost_bps_per_day'     => round( $ext_cost_bps, 2 ) . ' bps',
					'scenarios'            => $extension_scenarios,
				),
				'hedge_analysis'      => array(
					'hedge_cost'          => $calc::format_currency( $hedge_cost_dollars ),
					'hedge_cost_bps'      => $hedge_cost_bps . ' bps',
					'breakeven_rate_move' => round( $breakeven_rate_move * 10000, 1 ) . ' bps',
				),
				'breakeven'           => array(
					'ext_15_day_breakeven_bps' => round( $breakeven_ext_15, 1 ) . ' bps',
				),
			),
		);
	}
}
