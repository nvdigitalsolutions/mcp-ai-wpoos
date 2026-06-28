<?php
/**
 * CRE CLO Modeler — Model a CRE CLO structure with OC/IC tests and reinvestment
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
 * Models a CRE CLO deal: tranche sizing from collateral balance, OC/IC
 * coverage ratio testing, reinvestment capacity analysis, risk retention
 * calculation, excess spread, and deal profitability.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_CLO_Modeler implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_clo_modeler';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE CLO Modeler', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Model a CRE CLO structure with tranche sizing, OC/IC coverage tests, reinvestment period analysis, risk retention, and deal profitability.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'collateral_balance'         => array(
					'type'        => 'number',
					'description' => __( 'Total collateral balance.', 'mcp-ai-wpoos-pro' ),
				),
				'reinvestment_period_months' => array(
					'type'        => 'integer',
					'description' => __( 'Reinvestment period in months (typically 24-36).', 'mcp-ai-wpoos-pro' ),
				),
				'non_call_period_months'     => array(
					'type'        => 'integer',
					'description' => __( 'Non-call period in months.', 'mcp-ai-wpoos-pro' ),
				),
				'total_term_months'          => array(
					'type'        => 'integer',
					'description' => __( 'Total deal term in months.', 'mcp-ai-wpoos-pro' ),
				),
				'advance_rate_pct'           => array(
					'type'        => 'number',
					'description' => __( 'Total advance rate as decimal (e.g. 0.80 for 80%).', 'mcp-ai-wpoos-pro' ),
				),
				'oc_test_trigger'            => array(
					'type'        => 'number',
					'description' => __( 'OC test trigger level as decimal (e.g. 1.20 = 120%).', 'mcp-ai-wpoos-pro' ),
				),
				'ic_test_trigger'            => array(
					'type'        => 'number',
					'description' => __( 'IC test trigger level as decimal (e.g. 1.20 = 120%).', 'mcp-ai-wpoos-pro' ),
				),
				'aaa_pct'                    => array(
					'type'        => 'number',
					'description' => __( 'AAA tranche as pct of liabilities (e.g. 0.60).', 'mcp-ai-wpoos-pro' ),
				),
				'aa_pct'                     => array(
					'type'        => 'number',
					'description' => __( 'AA tranche as pct of liabilities.', 'mcp-ai-wpoos-pro' ),
				),
				'a_pct'                      => array(
					'type'        => 'number',
					'description' => __( 'A tranche as pct of liabilities.', 'mcp-ai-wpoos-pro' ),
				),
				'bbb_pct'                    => array(
					'type'        => 'number',
					'description' => __( 'BBB tranche as pct of liabilities.', 'mcp-ai-wpoos-pro' ),
				),
				'risk_retention_pct'         => array(
					'type'        => 'number',
					'description' => __( 'Risk retention as pct of deal (e.g. 0.05 for 5%).', 'mcp-ai-wpoos-pro' ),
				),
				'wa_coupon'                  => array(
					'type'        => 'number',
					'description' => __( 'Weighted average coupon on collateral as decimal.', 'mcp-ai-wpoos-pro' ),
				),
				'wa_spread_bps'              => array(
					'type'        => 'number',
					'description' => __( 'Weighted average spread on collateral in bps.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'collateral_balance', 'reinvestment_period_months', 'total_term_months', 'advance_rate_pct', 'wa_coupon' ),
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

		$collateral      = (float) ( $arguments['collateral_balance'] ?? 0 );
		$reinvest_months = (int) ( $arguments['reinvestment_period_months'] ?? 24 );
		$noncall_months  = (int) ( $arguments['non_call_period_months'] ?? 12 );
		$total_months    = (int) ( $arguments['total_term_months'] ?? 60 );
		$advance_rate    = (float) ( $arguments['advance_rate_pct'] ?? 0.80 );
		$oc_trigger      = (float) ( $arguments['oc_test_trigger'] ?? 1.20 );
		$ic_trigger      = (float) ( $arguments['ic_test_trigger'] ?? 1.20 );
		$aaa_pct         = (float) ( $arguments['aaa_pct'] ?? 0.60 );
		$aa_pct          = (float) ( $arguments['aa_pct'] ?? 0.15 );
		$a_pct           = (float) ( $arguments['a_pct'] ?? 0.10 );
		$bbb_pct         = (float) ( $arguments['bbb_pct'] ?? 0.05 );
		$rr_pct          = (float) ( $arguments['risk_retention_pct'] ?? 0.05 );
		$wa_coupon       = (float) ( $arguments['wa_coupon'] ?? 0 );
		$wa_spread_bps   = (float) ( $arguments['wa_spread_bps'] ?? 250 );

		if ( $collateral <= 0 || $wa_coupon <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'Collateral balance and WA coupon must be positive.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $advance_rate <= 0 || $advance_rate > 1 ) {
			return new WP_Error( 'invalid_input', __( 'Advance rate must be between 0 and 1.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		// Total liabilities (rated notes).
		$total_liabilities = $collateral * $advance_rate;
		$equity            = $collateral - $total_liabilities;

		// Tranche sizing.
		$remaining_pct = 1.0 - $aaa_pct - $aa_pct - $a_pct - $bbb_pct;
		if ( $remaining_pct < 0 ) {
			$remaining_pct = 0;
		}

		$tranches = array();

		$tranche_specs = array(
			array(
				'rating'     => 'AAA',
				'pct'        => $aaa_pct,
				'spread_bps' => 140,
			),
			array(
				'rating'     => 'AA',
				'pct'        => $aa_pct,
				'spread_bps' => 200,
			),
			array(
				'rating'     => 'A',
				'pct'        => $a_pct,
				'spread_bps' => 275,
			),
			array(
				'rating'     => 'BBB',
				'pct'        => $bbb_pct,
				'spread_bps' => 400,
			),
		);

		if ( $remaining_pct > 0 ) {
			$tranche_specs[] = array(
				'rating'     => 'BB/Unrated',
				'pct'        => $remaining_pct,
				'spread_bps' => 600,
			);
		}

		$total_cost  = 0.0;
		$wa_cost_bps = 0.0;
		foreach ( $tranche_specs as $spec ) {
			$t_balance   = $total_liabilities * $spec['pct'];
			$t_cost      = $t_balance * $spec['spread_bps'] / 10000;
			$total_cost += $t_cost;

			$tranches[] = array(
				'rating'          => $spec['rating'],
				'balance'         => $calc::format_currency( $t_balance ),
				'balance_raw'     => round( $t_balance, 2 ),
				'pct_liabilities' => $calc::format_percentage( $spec['pct'] ),
				'spread_bps'      => $spec['spread_bps'],
				'annual_cost'     => $calc::format_currency( $t_cost ),
			);

			$wa_cost_bps += $spec['spread_bps'] * $spec['pct'];
		}

		// OC / IC tests.
		$oc_ratio         = ( $total_liabilities > 0 ) ? $collateral / $total_liabilities : 0;
		$annual_income    = $collateral * $wa_coupon;
		$annual_debt_cost = $total_cost;
		$ic_ratio         = ( $annual_debt_cost > 0 ) ? $annual_income / $annual_debt_cost : 0;

		$oc_pass = $oc_ratio >= $oc_trigger;
		$ic_pass = $ic_ratio >= $ic_trigger;

		$oc_cushion = $oc_ratio - $oc_trigger;
		$ic_cushion = $ic_ratio - $ic_trigger;

		// Reinvestment capacity.
		$reinvest_capacity       = $collateral * 0.30; // Approx 30% of pool may repay during RI period.
		$reinvest_months_display = $reinvest_months;

		// Risk retention.
		$rr_amount       = $collateral * $rr_pct;
		$rr_type_options = array(
			'vertical'   => array(
				'description' => __( 'Vertical: 5% of each tranche', 'mcp-ai-wpoos-pro' ),
				'amount'      => $calc::format_currency( $collateral * 0.05 ),
			),
			'horizontal' => array(
				'description' => __( 'Horizontal: First-loss equity piece', 'mcp-ai-wpoos-pro' ),
				'amount'      => $calc::format_currency( $rr_amount ),
			),
			'l_shaped'   => array(
				'description' => __( 'L-Shaped: Combination of vertical and horizontal', 'mcp-ai-wpoos-pro' ),
				'amount'      => $calc::format_currency( $rr_amount ),
			),
		);

		// Excess spread and profitability.
		$excess_spread     = $annual_income - $annual_debt_cost;
		$excess_spread_pct = ( $collateral > 0 ) ? $excess_spread / $collateral : 0;
		$equity_cash_flow  = $excess_spread;
		$equity_yield      = ( $equity > 0 ) ? $equity_cash_flow / $equity : 0;
		$net_spread        = $wa_coupon - ( $wa_cost_bps / 10000 );

		$deal_economics = array(
			'collateral_balance'    => $calc::format_currency( $collateral ),
			'total_liabilities'     => $calc::format_currency( $total_liabilities ),
			'equity'                => $calc::format_currency( $equity ),
			'advance_rate'          => $calc::format_percentage( $advance_rate ),
			'wa_collateral_coupon'  => $calc::format_percentage( $wa_coupon ),
			'wa_liability_cost_bps' => round( $wa_cost_bps, 1 ),
			'annual_income'         => $calc::format_currency( $annual_income ),
			'annual_debt_cost'      => $calc::format_currency( $annual_debt_cost ),
			'annual_excess_spread'  => $calc::format_currency( $excess_spread ),
			'excess_spread_pct'     => $calc::format_percentage( $excess_spread_pct ),
			'net_spread_bps'        => round( $net_spread * 10000, 1 ),
			'equity_yield'          => $calc::format_percentage( $equity_yield ),
		);

		$coverage_tests = array(
			'oc_test' => array(
				'ratio'   => round( $oc_ratio, 4 ),
				'trigger' => $oc_trigger,
				'pass'    => $oc_pass,
				'cushion' => round( $oc_cushion, 4 ),
			),
			'ic_test' => array(
				'ratio'   => round( $ic_ratio, 2 ),
				'trigger' => $ic_trigger,
				'pass'    => $ic_pass,
				'cushion' => round( $ic_cushion, 2 ),
			),
		);

		$structure = array(
			'reinvestment_period'   => $reinvest_months_display . ' months',
			'non_call_period'       => $noncall_months . ' months',
			'total_term'            => $total_months . ' months',
			'reinvestment_capacity' => $calc::format_currency( $reinvest_capacity ),
		);

		// Remove raw balances from tranche output.
		$tranches_clean = array_map(
			function ( $t ) {
				unset( $t['balance_raw'] );
				return $t;
			},
			$tranches
		);

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: collateral balance, 2: OC pass/fail, 3: IC pass/fail */
				__( 'CRE CLO modeled: %1$s collateral. OC test: %2$s. IC test: %3$s.', 'mcp-ai-wpoos-pro' ),
				$calc::format_currency( $collateral ),
				$oc_pass ? __( 'PASS', 'mcp-ai-wpoos-pro' ) : __( 'FAIL', 'mcp-ai-wpoos-pro' ),
				$ic_pass ? __( 'PASS', 'mcp-ai-wpoos-pro' ) : __( 'FAIL', 'mcp-ai-wpoos-pro' )
			),
			'data'    => array(
				'tranches'       => $tranches_clean,
				'deal_economics' => $deal_economics,
				'coverage_tests' => $coverage_tests,
				'structure'      => $structure,
				'risk_retention' => $rr_type_options,
				'disclaimer'     => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			),
		);
	}
}
