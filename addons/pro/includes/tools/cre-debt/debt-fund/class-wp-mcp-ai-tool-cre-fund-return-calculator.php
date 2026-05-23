<?php
/**
 * CRE Fund Return Calculator — Calculate fund-level IRR, equity multiples, and performance metrics
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
 * Calculates gross/net IRR, equity multiples (DPI, RVPI, TVPI),
 * and other fund-level return metrics from cash-flow data.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Fund_Return_Calculator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_fund_return_calculator';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Fund Return Calculator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Calculate fund-level return metrics including gross/net IRR, equity multiple, DPI, RVPI, and TVPI from cash-flow data, commitments, distributions, and current NAV.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'cash_flows'         => array(
					'type'        => 'array',
					'description' => __( 'Array of periodic cash-flow objects (negative = outflow / capital call, positive = distribution).', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'period' => array(
								'type'        => 'integer',
								'description' => __( 'Period index (0-based).', 'mcp-ai-wpoos-pro' ),
							),
							'amount' => array(
								'type'        => 'number',
								'description' => __( 'Cash-flow amount (negative = outflow, positive = inflow).', 'mcp-ai-wpoos-pro' ),
							),
						),
						'required'   => array( 'period', 'amount' ),
					),
				),
				'total_committed'    => array(
					'type'        => 'number',
					'description' => __( 'Total capital committed to the fund.', 'mcp-ai-wpoos-pro' ),
				),
				'total_called'       => array(
					'type'        => 'number',
					'description' => __( 'Total capital called to date.', 'mcp-ai-wpoos-pro' ),
				),
				'total_distributed'  => array(
					'type'        => 'number',
					'description' => __( 'Total distributions paid to date.', 'mcp-ai-wpoos-pro' ),
				),
				'current_nav'        => array(
					'type'        => 'number',
					'description' => __( 'Current net asset value of the fund.', 'mcp-ai-wpoos-pro' ),
				),
				'management_fee_pct' => array(
					'type'        => 'number',
					'description' => __( 'Annual management fee percentage (e.g. 1.5 for 1.5%).', 'mcp-ai-wpoos-pro' ),
					'default'     => 1.5,
				),
				'fund_expenses'      => array(
					'type'        => 'number',
					'description' => __( 'Total fund expenses to deduct for net return calculation.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
			),
			'required'   => array( 'cash_flows', 'total_committed', 'total_called', 'total_distributed', 'current_nav' ),
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
			return new \WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new \WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$raw_flows         = $arguments['cash_flows'] ?? array();
		$total_committed   = (float) ( $arguments['total_committed'] ?? 0 );
		$total_called      = (float) ( $arguments['total_called'] ?? 0 );
		$total_distributed = (float) ( $arguments['total_distributed'] ?? 0 );
		$current_nav       = (float) ( $arguments['current_nav'] ?? 0 );
		$mgmt_fee_pct      = (float) ( $arguments['management_fee_pct'] ?? 1.5 );
		$fund_expenses     = (float) ( $arguments['fund_expenses'] ?? 0 );

		if ( empty( $raw_flows ) || ! is_array( $raw_flows ) ) {
			return new \WP_Error( 'invalid_input', __( 'At least one cash flow entry is required.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $total_called <= 0 ) {
			return new \WP_Error( 'invalid_input', __( 'Total called capital must be positive.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		// Build indexed cash-flow array for IRR calculation.
		$cf_indexed = array();
		foreach ( $raw_flows as $cf ) {
			$period = (int) ( $cf['period'] ?? 0 );
			$amount = (float) ( $cf['amount'] ?? 0 );
			if ( isset( $cf_indexed[ $period ] ) ) {
				$cf_indexed[ $period ] += $amount;
			} else {
				$cf_indexed[ $period ] = $amount;
			}
		}
		ksort( $cf_indexed );

		// Gross IRR from raw cash flows.
		$gross_irr = $calc::calculate_irr( $cf_indexed );

		// Build net cash flows by deducting management fees and expenses.
		$num_periods     = count( $cf_indexed );
		$annual_fee_rate = $mgmt_fee_pct / 100;
		$per_period_fee  = ( $num_periods > 0 ) ? ( $total_committed * $annual_fee_rate + $fund_expenses ) / max( $num_periods, 1 ) : 0;

		$net_cf = array();
		foreach ( $cf_indexed as $period => $amount ) {
			// Reduce positive flows (distributions) by fee allocation; increase negatives (calls) by fee.
			if ( $amount > 0 ) {
				$net_cf[ $period ] = $amount - $per_period_fee;
			} else {
				$net_cf[ $period ] = $amount - $per_period_fee;
			}
		}

		$net_irr = $calc::calculate_irr( $net_cf );

		// Multiples.
		$dpi             = ( $total_called > 0 ) ? $total_distributed / $total_called : 0;
		$rvpi            = ( $total_called > 0 ) ? $current_nav / $total_called : 0;
		$tvpi            = $dpi + $rvpi;
		$equity_multiple = $tvpi;

		$unfunded   = max( 0, $total_committed - $total_called );
		$pct_called = ( $total_committed > 0 ) ? $total_called / $total_committed : 0;
		$total_fees = $total_committed * $annual_fee_rate * ( $num_periods > 0 ? $num_periods : 1 );

		return array(
			'success'    => true,
			'message'    => __( 'Fund return metrics calculated. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'       => array(
				'gross_irr'         => ( null !== $gross_irr ) ? $calc::format_percentage( $gross_irr ) : __( 'N/A', 'mcp-ai-wpoos-pro' ),
				'net_irr'           => ( null !== $net_irr ) ? $calc::format_percentage( $net_irr ) : __( 'N/A', 'mcp-ai-wpoos-pro' ),
				'equity_multiple'   => round( $equity_multiple, 2 ) . 'x',
				'dpi'               => round( $dpi, 2 ) . 'x',
				'rvpi'              => round( $rvpi, 2 ) . 'x',
				'tvpi'              => round( $tvpi, 2 ) . 'x',
				'total_committed'   => $calc::format_currency( $total_committed ),
				'total_called'      => $calc::format_currency( $total_called ),
				'pct_called'        => $calc::format_percentage( $pct_called ),
				'unfunded'          => $calc::format_currency( $unfunded ),
				'total_distributed' => $calc::format_currency( $total_distributed ),
				'current_nav'       => $calc::format_currency( $current_nav ),
				'total_value'       => $calc::format_currency( $total_distributed + $current_nav ),
				'estimated_fees'    => $calc::format_currency( $total_fees + $fund_expenses ),
			),
			'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
