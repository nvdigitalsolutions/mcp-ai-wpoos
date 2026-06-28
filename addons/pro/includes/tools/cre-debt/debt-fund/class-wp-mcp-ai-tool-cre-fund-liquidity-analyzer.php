<?php
/**
 * CRE Fund Liquidity Analyzer — 12-month cash-flow projection with liquidity warnings
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
 * Builds a 12-month forward cash-flow projection from expected payoffs,
 * fundings, warehouse availability, and flags months with negative liquidity.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Fund_Liquidity_Analyzer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_fund_liquidity_analyzer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Fund Liquidity Analyzer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Build a 12-month forward liquidity projection from cash on hand, expected payoffs (sources), expected fundings (uses), and warehouse availability. Flags months with negative cash.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'cash_on_hand'                 => array(
					'type'        => 'number',
					'description' => __( 'Current cash on hand.', 'mcp-ai-wpoos-pro' ),
				),
				'unfunded_commitments'         => array(
					'type'        => 'number',
					'description' => __( 'Total unfunded LP commitments available to call.', 'mcp-ai-wpoos-pro' ),
				),
				'expected_payoffs'             => array(
					'type'        => 'array',
					'description' => __( 'Array of expected loan payoff objects (sources of cash).', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'          => array(
								'type'        => 'string',
								'description' => __( 'Loan name.', 'mcp-ai-wpoos-pro' ),
							),
							'amount'        => array(
								'type'        => 'number',
								'description' => __( 'Expected payoff amount.', 'mcp-ai-wpoos-pro' ),
							),
							'expected_date' => array(
								'type'        => 'string',
								'description' => __( 'Expected payoff date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
							),
						),
						'required'   => array( 'name', 'amount', 'expected_date' ),
					),
				),
				'expected_fundings'            => array(
					'type'        => 'array',
					'description' => __( 'Array of expected funding objects (uses of cash).', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'          => array(
								'type'        => 'string',
								'description' => __( 'Loan or commitment name.', 'mcp-ai-wpoos-pro' ),
							),
							'amount'        => array(
								'type'        => 'number',
								'description' => __( 'Expected funding amount.', 'mcp-ai-wpoos-pro' ),
							),
							'expected_date' => array(
								'type'        => 'string',
								'description' => __( 'Expected funding date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
							),
						),
						'required'   => array( 'name', 'amount', 'expected_date' ),
					),
				),
				'warehouse_availability'       => array(
					'type'        => 'number',
					'description' => __( 'Available capacity on warehouse lines.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'reinvestment_period_end_date' => array(
					'type'        => 'string',
					'description' => __( 'End of reinvestment period (YYYY-MM-DD). New investments not expected after this date.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'cash_on_hand', 'unfunded_commitments', 'expected_payoffs', 'expected_fundings' ),
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
			return new \WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new \WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$cash            = (float) ( $arguments['cash_on_hand'] ?? 0 );
		$unfunded        = (float) ( $arguments['unfunded_commitments'] ?? 0 );
		$payoffs         = $arguments['expected_payoffs'] ?? array();
		$fundings        = $arguments['expected_fundings'] ?? array();
		$warehouse_avail = (float) ( $arguments['warehouse_availability'] ?? 0 );
		$reinvest_end    = sanitize_text_field( $arguments['reinvestment_period_end_date'] ?? '' );

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		// Build month keys for next 12 months.
		$today      = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		$month_keys = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$dt = clone $today;
			$dt->modify( "+{$i} months" );
			$month_keys[] = $dt->format( 'Y-m' );
		}

		// Initialize monthly buckets.
		$monthly = array();
		foreach ( $month_keys as $mk ) {
			$monthly[ $mk ] = array(
				'sources'        => 0.0,
				'uses'           => 0.0,
				'source_details' => array(),
				'use_details'    => array(),
			);
		}

		// Slot payoffs into months.
		foreach ( $payoffs as $p ) {
			$date   = sanitize_text_field( $p['expected_date'] ?? '' );
			$amount = (float) ( $p['amount'] ?? 0 );
			$name   = sanitize_text_field( $p['name'] ?? '' );
			$ym     = substr( $date, 0, 7 );
			if ( isset( $monthly[ $ym ] ) ) {
				$monthly[ $ym ]['sources']         += $amount;
				$monthly[ $ym ]['source_details'][] = array(
					'name'   => $name,
					'amount' => $amount,
				);
			}
		}

		// Slot fundings into months.
		foreach ( $fundings as $f ) {
			$date   = sanitize_text_field( $f['expected_date'] ?? '' );
			$amount = (float) ( $f['amount'] ?? 0 );
			$name   = sanitize_text_field( $f['name'] ?? '' );
			$ym     = substr( $date, 0, 7 );
			if ( isset( $monthly[ $ym ] ) ) {
				$monthly[ $ym ]['uses']         += $amount;
				$monthly[ $ym ]['use_details'][] = array(
					'name'   => $name,
					'amount' => $amount,
				);
			}
		}

		// Build projection.
		$projection     = array();
		$cumulative     = $cash;
		$deficit_months = array();
		$total_sources  = 0.0;
		$total_uses     = 0.0;
		$min_cash       = $cash;
		$min_cash_month = $month_keys[0] ?? '';

		foreach ( $month_keys as $mk ) {
			$sources        = $monthly[ $mk ]['sources'];
			$uses           = $monthly[ $mk ]['uses'];
			$net            = $sources - $uses;
			$cumulative    += $net;
			$total_sources += $sources;
			$total_uses    += $uses;

			$is_negative = ( $cumulative < 0 );
			if ( $is_negative ) {
				$deficit_months[] = $mk;
			}
			if ( $cumulative < $min_cash ) {
				$min_cash       = $cumulative;
				$min_cash_month = $mk;
			}

			$projection[] = array(
				'month'           => $mk,
				'sources'         => $calc::format_currency( $sources ),
				'uses'            => $calc::format_currency( $uses ),
				'net'             => $calc::format_currency( $net ),
				'cumulative_cash' => $calc::format_currency( $cumulative ),
				'status'          => $is_negative ? 'deficit' : 'positive',
				'source_details'  => $monthly[ $mk ]['source_details'],
				'use_details'     => $monthly[ $mk ]['use_details'],
			);
		}

		// Total liquidity available (cash + unfunded + warehouse).
		$total_liquidity = $cash + $unfunded + $warehouse_avail;

		// Reinvestment period status.
		$reinvest_active = true;
		if ( ! empty( $reinvest_end ) ) {
			$end_dt          = new \DateTime( $reinvest_end, new \DateTimeZone( 'UTC' ) );
			$reinvest_active = ( $today <= $end_dt );
		}

		return array(
			'success'    => true,
			'message'    => __( 'Liquidity projection complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'       => array(
				'starting_cash'          => $calc::format_currency( $cash ),
				'unfunded_commitments'   => $calc::format_currency( $unfunded ),
				'warehouse_available'    => $calc::format_currency( $warehouse_avail ),
				'total_liquidity'        => $calc::format_currency( $total_liquidity ),
				'total_expected_sources' => $calc::format_currency( $total_sources ),
				'total_expected_uses'    => $calc::format_currency( $total_uses ),
				'net_12_month'           => $calc::format_currency( $total_sources - $total_uses ),
				'ending_cash'            => $calc::format_currency( $cumulative ),
				'min_cash_position'      => $calc::format_currency( $min_cash ),
				'min_cash_month'         => $min_cash_month,
				'deficit_months'         => $deficit_months,
				'has_deficit'            => ! empty( $deficit_months ),
				'reinvestment_active'    => $reinvest_active,
				'reinvestment_end'       => $reinvest_end ? $reinvest_end : __( 'N/A', 'mcp-ai-wpoos-pro' ),
				'monthly_projection'     => $projection,
			),
			'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
