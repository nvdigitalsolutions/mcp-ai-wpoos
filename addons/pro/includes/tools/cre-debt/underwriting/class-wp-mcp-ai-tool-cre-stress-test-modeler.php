<?php
/**
 * CRE Stress Test Modeler — Independent downside scenario analysis
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
 * Runs independent stress tests on a loan deal: vacancy shock, rate increase,
 * opex inflation, and cap rate expansion. Returns DSCR, LTV, debt yield,
 * and value impact under each scenario.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Stress_Test_Modeler implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_stress_test_modeler';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Stress Test Modeler', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Run independent stress tests on a CRE loan: vacancy shock, interest rate increase, operating expense inflation, and cap rate expansion. Returns DSCR, LTV, debt yield, and property value under each scenario compared to base case.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'base_noi'               => array(
					'type'        => 'number',
					'description' => __( 'Base-case annual NOI.', 'mcp-ai-wpoos-pro' ),
				),
				'loan_amount'            => array(
					'type'        => 'number',
					'description' => __( 'Loan amount.', 'mcp-ai-wpoos-pro' ),
				),
				'interest_rate'          => array(
					'type'        => 'number',
					'description' => __( 'Current annual interest rate as decimal.', 'mcp-ai-wpoos-pro' ),
				),
				'amort_months'           => array(
					'type'        => 'integer',
					'description' => __( 'Amortization period in months.', 'mcp-ai-wpoos-pro' ),
				),
				'property_value'         => array(
					'type'        => 'number',
					'description' => __( 'Current appraised property value.', 'mcp-ai-wpoos-pro' ),
				),
				'vacancy_shock_pct'      => array(
					'type'        => 'number',
					'description' => __( 'Vacancy shock: additional vacancy as decimal (e.g. 0.10 for 10% extra vacancy on PGI).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0.10,
				),
				'rate_increase_bps'      => array(
					'type'        => 'integer',
					'description' => __( 'Interest rate increase in basis points (e.g. 200 for +2%).', 'mcp-ai-wpoos-pro' ),
					'default'     => 200,
				),
				'opex_inflation_pct'     => array(
					'type'        => 'number',
					'description' => __( 'Operating expense inflation as decimal (e.g. 0.10 for 10% higher opex).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0.10,
				),
				'cap_rate_expansion_bps' => array(
					'type'        => 'integer',
					'description' => __( 'Cap rate expansion in basis points (e.g. 100 for +1%).', 'mcp-ai-wpoos-pro' ),
					'default'     => 100,
				),
			),
			'required'   => array( 'base_noi', 'loan_amount', 'interest_rate', 'amort_months', 'property_value' ),
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

		$base_noi  = (float) ( $arguments['base_noi'] ?? 0 );
		$loan      = (float) ( $arguments['loan_amount'] ?? 0 );
		$rate      = (float) ( $arguments['interest_rate'] ?? 0 );
		$amort     = (int) ( $arguments['amort_months'] ?? 360 );
		$value     = (float) ( $arguments['property_value'] ?? 0 );
		$vac_shock = (float) ( $arguments['vacancy_shock_pct'] ?? 0.10 );
		$rate_bps  = (int) ( $arguments['rate_increase_bps'] ?? 200 );
		$opex_infl = (float) ( $arguments['opex_inflation_pct'] ?? 0.10 );
		$cap_bps   = (int) ( $arguments['cap_rate_expansion_bps'] ?? 100 );

		if ( $base_noi <= 0 || $loan <= 0 || $value <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'NOI, loan amount, and property value must be positive.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		// Helper to compute metrics for a given NOI, rate, and value.
		$compute_metrics = function ( float $noi, float $int_rate, float $prop_value ) use ( $calc, $loan, $amort ): array {
			$monthly_pmt = $calc::calculate_monthly_payment( $loan, $int_rate, $amort );
			$annual_ds   = $monthly_pmt * 12;
			$dscr        = $calc::calculate_dscr( $noi, $annual_ds );
			$ltv         = $calc::calculate_ltv( $loan, $prop_value );
			$dy          = $calc::calculate_debt_yield( $noi, $loan );

			return array(
				'noi'          => $calc::format_currency( $noi ),
				'debt_service' => $calc::format_currency( $annual_ds ),
				'dscr'         => round( $dscr, 2 ) . 'x',
				'ltv'          => $calc::format_percentage( $ltv ),
				'debt_yield'   => $calc::format_percentage( $dy ),
				'value'        => $calc::format_currency( $prop_value ),
			);
		};

		// Base case.
		$base_cap_rate = ( $value > 0 ) ? $base_noi / $value : 0;
		$base_metrics  = $compute_metrics( $base_noi, $rate, $value );

		// Scenario 1: Vacancy shock — NOI drops by vac_shock * (NOI + opex proxy).
		// Simplified: reduce NOI proportionally.
		$vac_noi                 = $base_noi * ( 1 - $vac_shock );
		$vac_value               = ( $base_cap_rate > 0 ) ? $vac_noi / $base_cap_rate : 0;
		$vac_metrics             = $compute_metrics( $vac_noi, $rate, $vac_value );
		$vac_metrics['scenario'] = sprintf(
			/* translators: %s: vacancy shock percentage */
			__( 'Vacancy shock (+%s additional vacancy)', 'mcp-ai-wpoos-pro' ),
			$calc::format_percentage( $vac_shock )
		);

		// Scenario 2: Rate increase.
		$new_rate                 = $rate + ( $rate_bps / 10000 );
		$rate_metrics             = $compute_metrics( $base_noi, $new_rate, $value );
		$rate_metrics['scenario'] = sprintf(
			/* translators: %s: rate increase in BPS */
			__( 'Interest rate increase (+%d bps to %s)', 'mcp-ai-wpoos-pro' ),
			$rate_bps,
			$calc::format_percentage( $new_rate )
		);

		// Scenario 3: OpEx inflation — reduces NOI by opex_infl * (PGI-NOI portion).
		// Simplified: reduce NOI by opex inflation proportion of operating costs.
		$opex_estimate            = $value * $base_cap_rate > 0 ? $base_noi * $opex_infl : $base_noi * $opex_infl;
		$opex_noi                 = $base_noi - $opex_estimate;
		$opex_value               = ( $base_cap_rate > 0 ) ? $opex_noi / $base_cap_rate : 0;
		$opex_metrics             = $compute_metrics( $opex_noi, $rate, $opex_value );
		$opex_metrics['scenario'] = sprintf(
			/* translators: %s: opex inflation percentage */
			__( 'Operating expense inflation (+%s increase)', 'mcp-ai-wpoos-pro' ),
			$calc::format_percentage( $opex_infl )
		);

		// Scenario 4: Cap rate expansion — value drops.
		$stressed_cap            = $base_cap_rate + ( $cap_bps / 10000 );
		$cap_value               = ( $stressed_cap > 0 ) ? $base_noi / $stressed_cap : 0;
		$cap_metrics             = $compute_metrics( $base_noi, $rate, $cap_value );
		$cap_metrics['scenario'] = sprintf(
			/* translators: %1$d: BPS expansion, %2$s: new cap rate */
			__( 'Cap rate expansion (+%1$d bps to %2$s)', 'mcp-ai-wpoos-pro' ),
			$cap_bps,
			$calc::format_percentage( $stressed_cap )
		);

		return array(
			'success' => true,
			'message' => __( 'Stress test analysis complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'base_case' => array_merge( array( 'scenario' => __( 'Base Case', 'mcp-ai-wpoos-pro' ) ), $base_metrics ),
				'scenarios' => array(
					$vac_metrics,
					$rate_metrics,
					$opex_metrics,
					$cap_metrics,
				),
			),
		);
	}
}
