<?php
/**
 * CRE Servicing Fee Calculator — Calculate CMBS and CRE loan servicing fees
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
 * Calculates CMBS and CRE loan servicing fees across master, primary, and special
 * servicing tiers. Includes workout and liquidation fee calculations with net
 * recovery analysis.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Servicing_Fee_Calculator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_servicing_fee_calculator';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Servicing Fee Calculator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Calculate CMBS and CRE loan servicing fees across master, primary, and special servicing tiers. Includes workout and liquidation fee calculations with net recovery analysis.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'loan_balance'              => array(
					'type'        => 'number',
					'description' => __( 'Outstanding loan balance.', 'mcp-ai-wpoos-pro' ),
				),
				'master_servicing_fee_bps'  => array(
					'type'        => 'number',
					'description' => __( 'Master servicing fee in basis points. Default 2.', 'mcp-ai-wpoos-pro' ),
					'default'     => 2,
				),
				'primary_servicing_fee_bps' => array(
					'type'        => 'number',
					'description' => __( 'Primary servicing fee in basis points. Default 7.5.', 'mcp-ai-wpoos-pro' ),
					'default'     => 7.5,
				),
				'special_servicing_fee_bps' => array(
					'type'        => 'number',
					'description' => __( 'Special servicing fee in basis points. Default 25.', 'mcp-ai-wpoos-pro' ),
					'default'     => 25,
				),
				'workout_fee_pct'           => array(
					'type'        => 'number',
					'description' => __( 'Workout fee as percentage of recovery (e.g. 1 for 1%). Default 1.', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
				),
				'liquidation_fee_pct'       => array(
					'type'        => 'number',
					'description' => __( 'Liquidation fee as percentage of recovery (e.g. 1 for 1%). Default 1.', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
				),
				'is_specially_serviced'     => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the loan is currently in special servicing. Default false.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'months_in_special'         => array(
					'type'        => 'integer',
					'description' => __( 'Number of months in special servicing. Default 0.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'recovery_amount'           => array(
					'type'        => 'number',
					'description' => __( 'Total recovery amount from workout or liquidation. Default 0.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
			),
			'required'   => array( 'loan_balance' ),
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

		$loan_balance              = (float) ( $arguments['loan_balance'] ?? 0 );
		$master_servicing_fee_bps  = (float) ( $arguments['master_servicing_fee_bps'] ?? 2 );
		$primary_servicing_fee_bps = (float) ( $arguments['primary_servicing_fee_bps'] ?? 7.5 );
		$special_servicing_fee_bps = (float) ( $arguments['special_servicing_fee_bps'] ?? 25 );
		$workout_fee_pct           = (float) ( $arguments['workout_fee_pct'] ?? 1 );
		$liquidation_fee_pct       = (float) ( $arguments['liquidation_fee_pct'] ?? 1 );
		$is_specially_serviced     = ! empty( $arguments['is_specially_serviced'] );
		$months_in_special         = absint( $arguments['months_in_special'] ?? 0 );
		$recovery_amount           = (float) ( $arguments['recovery_amount'] ?? 0 );

		if ( $loan_balance <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'loan_balance must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		// Master servicing fee (1 bps = 0.0001).
		$master_annual  = $loan_balance * $master_servicing_fee_bps / 10000;
		$master_monthly = $master_annual / 12;

		// Primary servicing fee.
		$primary_annual  = $loan_balance * $primary_servicing_fee_bps / 10000;
		$primary_monthly = $primary_annual / 12;

		// Special servicing fee.
		$special_annual              = 0.0;
		$special_monthly             = 0.0;
		$total_special_fees_incurred = 0.0;
		if ( $is_specially_serviced ) {
			$special_annual              = $loan_balance * $special_servicing_fee_bps / 10000;
			$special_monthly             = $special_annual / 12;
			$total_special_fees_incurred = $special_monthly * $months_in_special;
		}

		// Standard annual fees.
		$total_standard_annual   = $master_annual + $primary_annual;
		$total_annual_if_special = $total_standard_annual + $special_annual;

		// Workout and liquidation fees.
		$workout_fee     = 0.0;
		$liquidation_fee = 0.0;
		if ( $recovery_amount > 0 ) {
			$workout_fee = $recovery_amount * $workout_fee_pct / 100;
			if ( $is_specially_serviced ) {
				$liquidation_fee = $recovery_amount * $liquidation_fee_pct / 100;
			}
		}

		// Total servicing costs.
		$total_servicing_costs = 0.0;
		if ( $is_specially_serviced && $months_in_special > 0 ) {
			$total_servicing_costs = ( $master_monthly + $primary_monthly ) * $months_in_special
				+ $total_special_fees_incurred
				+ $workout_fee
				+ $liquidation_fee;
		}

		// Net recovery analysis.
		$net_recovery      = 0.0;
		$net_recovery_rate = 0.0;
		if ( $recovery_amount > 0 ) {
			$net_recovery      = $recovery_amount - $total_servicing_costs;
			$net_recovery_rate = $net_recovery / $loan_balance;
		}

		$data = array(
			'loan_balance'          => $calc::format_currency( $loan_balance ),
			'fee_breakdown'         => array(
				'master_servicing'  => array(
					'bps'     => $master_servicing_fee_bps,
					'annual'  => $calc::format_currency( $master_annual ),
					'monthly' => $calc::format_currency( $master_monthly ),
				),
				'primary_servicing' => array(
					'bps'     => $primary_servicing_fee_bps,
					'annual'  => $calc::format_currency( $primary_annual ),
					'monthly' => $calc::format_currency( $primary_monthly ),
				),
				'special_servicing' => array(
					'bps'                         => $special_servicing_fee_bps,
					'annual'                      => $calc::format_currency( $special_annual ),
					'monthly'                     => $calc::format_currency( $special_monthly ),
					'is_active'                   => $is_specially_serviced,
					'months_in_special'           => $months_in_special,
					'total_special_fees_incurred' => $calc::format_currency( $total_special_fees_incurred ),
				),
			),
			'annual_totals'         => array(
				'standard_fees'               => $calc::format_currency( $total_standard_annual ),
				'total_if_specially_serviced' => $calc::format_currency( $total_annual_if_special ),
			),
			'transaction_fees'      => array(
				'workout_fee'     => $calc::format_currency( $workout_fee ),
				'liquidation_fee' => $calc::format_currency( $liquidation_fee ),
			),
			'total_servicing_costs' => $calc::format_currency( $total_servicing_costs ),
		);

		if ( $recovery_amount > 0 ) {
			$data['recovery_analysis'] = array(
				'gross_recovery'    => $calc::format_currency( $recovery_amount ),
				'total_costs'       => $calc::format_currency( $total_servicing_costs ),
				'net_recovery'      => $calc::format_currency( $net_recovery ),
				'net_recovery_rate' => $calc::format_percentage( $net_recovery_rate ),
			);
		}

		$data['disclaimer'] = __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' );

		return array(
			'success'    => true,
			'message'    => __( 'Servicing fee analysis complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'       => $data,
			'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
