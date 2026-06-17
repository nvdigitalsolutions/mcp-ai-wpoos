<?php
/**
 * CRE Fund Scenario Modeler — Model rate shocks, defaults, and prepayments across scenarios
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
 * Models multiple portfolio stress scenarios including rate shocks,
 * default rates, prepayment speeds, and loss severities with NAV impact ranking.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Fund_Scenario_Modeler implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_fund_scenario_modeler';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Fund Scenario Modeler', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Model multiple portfolio stress scenarios with rate shocks, default rates, prepayment speeds, and loss severities. Returns per-scenario impact analysis ranked by loss severity.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'portfolio_balance' => array(
					'type'        => 'number',
					'description' => __( 'Total portfolio balance.', 'mcp-ai-wpoos-pro' ),
				),
				'wa_rate'           => array(
					'type'        => 'number',
					'description' => __( 'Weighted average interest rate as decimal (e.g. 0.065).', 'mcp-ai-wpoos-pro' ),
				),
				'wa_dscr'           => array(
					'type'        => 'number',
					'description' => __( 'Weighted average DSCR.', 'mcp-ai-wpoos-pro' ),
				),
				'wa_ltv'            => array(
					'type'        => 'number',
					'description' => __( 'Weighted average LTV as decimal.', 'mcp-ai-wpoos-pro' ),
				),
				'num_loans'         => array(
					'type'        => 'integer',
					'description' => __( 'Number of loans in portfolio.', 'mcp-ai-wpoos-pro' ),
				),
				'scenarios'         => array(
					'type'        => 'array',
					'description' => __( 'Array of scenario objects to model.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'                => array(
								'type'        => 'string',
								'description' => __( 'Scenario name.', 'mcp-ai-wpoos-pro' ),
							),
							'rate_shock_bps'      => array(
								'type'        => 'integer',
								'description' => __( 'Interest rate shock in basis points.', 'mcp-ai-wpoos-pro' ),
								'default'     => 0,
							),
							'default_rate_pct'    => array(
								'type'        => 'number',
								'description' => __( 'Assumed default rate percentage.', 'mcp-ai-wpoos-pro' ),
								'default'     => 0,
							),
							'prepay_rate_pct'     => array(
								'type'        => 'number',
								'description' => __( 'Assumed prepayment rate percentage.', 'mcp-ai-wpoos-pro' ),
								'default'     => 0,
							),
							'loss_severity_pct'   => array(
								'type'        => 'number',
								'description' => __( 'Loss severity percentage on defaults.', 'mcp-ai-wpoos-pro' ),
								'default'     => 40,
							),
							'recovery_lag_months' => array(
								'type'        => 'integer',
								'description' => __( 'Expected months to recover on defaults.', 'mcp-ai-wpoos-pro' ),
								'default'     => 12,
							),
						),
						'required'   => array( 'name' ),
					),
				),
			),
			'required'   => array( 'portfolio_balance', 'wa_rate', 'scenarios' ),
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

		$balance   = (float) ( $arguments['portfolio_balance'] ?? 0 );
		$wa_rate   = (float) ( $arguments['wa_rate'] ?? 0 );
		$wa_dscr   = (float) ( $arguments['wa_dscr'] ?? 0 );
		$wa_ltv    = (float) ( $arguments['wa_ltv'] ?? 0 );
		$num_loans = (int) ( $arguments['num_loans'] ?? 0 );
		$scenarios = $arguments['scenarios'] ?? array();

		if ( $balance <= 0 ) {
			return new \WP_Error( 'invalid_input', __( 'Portfolio balance must be positive.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( empty( $scenarios ) || ! is_array( $scenarios ) ) {
			return new \WP_Error( 'invalid_input', __( 'At least one scenario is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		$results = array();

		foreach ( $scenarios as $sc ) {
			$name          = sanitize_text_field( $sc['name'] ?? __( 'Unnamed', 'mcp-ai-wpoos-pro' ) );
			$rate_shock    = (int) ( $sc['rate_shock_bps'] ?? 0 );
			$default_rate  = (float) ( $sc['default_rate_pct'] ?? 0 );
			$prepay_rate   = (float) ( $sc['prepay_rate_pct'] ?? 0 );
			$loss_severity = (float) ( $sc['loss_severity_pct'] ?? 40 );
			$recovery_lag  = (int) ( $sc['recovery_lag_months'] ?? 12 );

			// New weighted average rate after shock.
			$new_rate = $wa_rate + ( $rate_shock / 10000 );

			// Default impact.
			$defaults   = $balance * ( $default_rate / 100 );
			$losses     = $defaults * ( $loss_severity / 100 );
			$recoveries = $defaults - $losses;

			// Prepayment impact.
			$prepays = $balance * ( $prepay_rate / 100 );

			// Remaining portfolio.
			$remaining = $balance - $defaults - $prepays;
			$remaining = max( 0, $remaining );

			// NAV impact from losses.
			$nav_impact = -1.0 * $losses;

			// Annual interest income impact from rate shock.
			$income_before = $balance * $wa_rate;
			$income_after  = $remaining * $new_rate;
			$income_change = $income_after - $income_before;

			// DSCR impact estimate (if rate goes up, DSCR goes down proportionally).
			$new_dscr = $wa_dscr;
			if ( $wa_rate > 0 && $wa_dscr > 0 ) {
				$debt_service_change = ( $new_rate > 0 ) ? $wa_rate / $new_rate : 1.0;
				$new_dscr            = $wa_dscr * $debt_service_change;
			}

			// LTV impact estimate (losses reduce collateral value proportionally).
			$new_ltv = $wa_ltv;
			if ( $wa_ltv > 0 && $balance > 0 ) {
				$value_reduction = ( $defaults > 0 ) ? $losses / $balance : 0;
				$new_ltv         = $wa_ltv * ( 1 + $value_reduction );
				$new_ltv         = min( 1.0, $new_ltv );
			}

			$results[] = array(
				'scenario_name'     => $name,
				'rate_shock_bps'    => $rate_shock,
				'new_wa_rate'       => $calc::format_percentage( $new_rate ),
				'default_rate_pct'  => $default_rate . '%',
				'defaults'          => $calc::format_currency( $defaults ),
				'loss_severity_pct' => $loss_severity . '%',
				'losses'            => $calc::format_currency( $losses ),
				'recoveries'        => $calc::format_currency( $recoveries ),
				'recovery_lag'      => $recovery_lag . ' months',
				'prepay_rate_pct'   => $prepay_rate . '%',
				'prepays'           => $calc::format_currency( $prepays ),
				'remaining_balance' => $calc::format_currency( $remaining ),
				'nav_impact'        => $calc::format_currency( $nav_impact ),
				'income_change'     => $calc::format_currency( $income_change ),
				'stressed_dscr'     => round( $new_dscr, 2 ) . 'x',
				'stressed_ltv'      => $calc::format_percentage( $new_ltv ),
				'loss_amount_raw'   => round( $losses, 2 ),
			);
		}

		// Rank by severity (highest losses first).
		usort(
			$results,
			function ( $a, $b ) {
				return $b['loss_amount_raw'] <=> $a['loss_amount_raw'];
			}
		);

		// Add rank and remove raw field.
		$ranked = array();
		$rank   = 1;
		foreach ( $results as $r ) {
			unset( $r['loss_amount_raw'] );
			$r['severity_rank'] = $rank;
			$ranked[]           = $r;
			++$rank;
		}

		return array(
			'success'    => true,
			'message'    => __( 'Scenario analysis complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'       => array(
				'portfolio_baseline' => array(
					'balance'   => $calc::format_currency( $balance ),
					'wa_rate'   => $calc::format_percentage( $wa_rate ),
					'wa_dscr'   => ( $wa_dscr > 0 ) ? round( $wa_dscr, 2 ) . 'x' : __( 'N/A', 'mcp-ai-wpoos-pro' ),
					'wa_ltv'    => ( $wa_ltv > 0 ) ? $calc::format_percentage( $wa_ltv ) : __( 'N/A', 'mcp-ai-wpoos-pro' ),
					'num_loans' => $num_loans,
				),
				'num_scenarios'      => count( $ranked ),
				'scenarios'          => $ranked,
			),
			'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
