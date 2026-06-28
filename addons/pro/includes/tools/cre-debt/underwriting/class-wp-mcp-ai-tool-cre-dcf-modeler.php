<?php
/**
 * CRE DCF Modeler — Full discounted cash flow valuation model
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
 * Full DCF model: projects NOI from a rent roll, grows it, discounts cash flows,
 * and computes terminal value to arrive at an as-is property valuation.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_DCF_Modeler implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_dcf_modeler';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE DCF Modeler', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Build a full Discounted Cash Flow model from a rent roll. Projects annual NOI with growth rates, discounts operating cash flows, calculates terminal/reversion value, and returns total property value. Accepts tenants, vacancy, opex, growth assumptions, hold period, exit cap rate, and discount rate.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'tenants'             => array(
					'type'        => 'array',
					'description' => __( 'Rent roll: array of tenant objects.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'              => array(
								'type'        => 'string',
								'description' => __( 'Tenant name.', 'mcp-ai-wpoos-pro' ),
							),
							'annual_rent'       => array(
								'type'        => 'number',
								'description' => __( 'Current annual rent.', 'mcp-ai-wpoos-pro' ),
							),
							'sf'                => array(
								'type'        => 'number',
								'description' => __( 'Square footage leased.', 'mcp-ai-wpoos-pro' ),
							),
							'lease_expiry_year' => array(
								'type'        => 'integer',
								'description' => __( 'Year the lease expires (e.g. 2028).', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
				'vacancy_rate'        => array(
					'type'        => 'number',
					'description' => __( 'Stabilized vacancy rate as decimal (e.g. 0.05 for 5%).', 'mcp-ai-wpoos-pro' ),
				),
				'operating_expenses'  => array(
					'type'        => 'number',
					'description' => __( 'Year-1 total operating expenses.', 'mcp-ai-wpoos-pro' ),
				),
				'revenue_growth_rate' => array(
					'type'        => 'number',
					'description' => __( 'Annual revenue growth rate as decimal (e.g. 0.03 for 3%).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0.03,
				),
				'expense_growth_rate' => array(
					'type'        => 'number',
					'description' => __( 'Annual expense growth rate as decimal.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0.02,
				),
				'hold_period'         => array(
					'type'        => 'integer',
					'description' => __( 'Investment hold period in years.', 'mcp-ai-wpoos-pro' ),
				),
				'exit_cap_rate'       => array(
					'type'        => 'number',
					'description' => __( 'Exit / terminal cap rate as decimal (e.g. 0.06).', 'mcp-ai-wpoos-pro' ),
				),
				'discount_rate'       => array(
					'type'        => 'number',
					'description' => __( 'Discount rate (required return) as decimal.', 'mcp-ai-wpoos-pro' ),
				),
				'selling_costs'       => array(
					'type'        => 'number',
					'description' => __( 'Selling costs as decimal (e.g. 0.02 for 2%). Defaults to 2%.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0.02,
				),
			),
			'required'   => array( 'tenants', 'vacancy_rate', 'operating_expenses', 'hold_period', 'exit_cap_rate', 'discount_rate' ),
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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$tenants        = $arguments['tenants'] ?? array();
		$vacancy_rate   = (float) ( $arguments['vacancy_rate'] ?? 0.05 );
		$opex           = (float) ( $arguments['operating_expenses'] ?? 0 );
		$revenue_growth = (float) ( $arguments['revenue_growth_rate'] ?? 0.03 );
		$expense_growth = (float) ( $arguments['expense_growth_rate'] ?? 0.02 );
		$hold_period    = (int) ( $arguments['hold_period'] ?? 10 );
		$exit_cap_rate  = (float) ( $arguments['exit_cap_rate'] ?? 0.06 );
		$discount_rate  = (float) ( $arguments['discount_rate'] ?? 0.08 );
		$selling_costs  = (float) ( $arguments['selling_costs'] ?? 0.02 );

		if ( empty( $tenants ) ) {
			return new WP_Error( 'invalid_input', __( 'At least one tenant is required.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $hold_period < 1 || $hold_period > 30 ) {
			return new WP_Error( 'invalid_input', __( 'Hold period must be between 1 and 30 years.', 'mcp-ai-wpoos-pro' ) );
		}

		// Compute Year-1 PGI from rent roll.
		$year1_pgi  = 0.0;
		$total_sf   = 0.0;
		$tenant_sum = array();
		foreach ( $tenants as $t ) {
			$annual_rent  = (float) ( $t['annual_rent'] ?? 0 );
			$sf           = (float) ( $t['sf'] ?? 0 );
			$year1_pgi   += $annual_rent;
			$total_sf    += $sf;
			$tenant_sum[] = array(
				'name'        => sanitize_text_field( $t['name'] ?? 'Unknown' ),
				'annual_rent' => round( $annual_rent, 2 ),
				'sf'          => round( $sf, 2 ),
				'rent_per_sf' => ( $sf > 0 ) ? round( $annual_rent / $sf, 2 ) : 0,
			);
		}

		// Project annual NOIs.
		$annual_nois = array();
		$yearly_proj = array();
		for ( $y = 0; $y < $hold_period; $y++ ) {
			$pgi_y  = $year1_pgi * pow( 1 + $revenue_growth, $y );
			$opex_y = $opex * pow( 1 + $expense_growth, $y );

			$noi_result = WP_MCP_AI_CRE_Debt_Calculator::calculate_noi(
				$pgi_y,
				$vacancy_rate,
				0.0,
				0.0,
				$opex_y
			);

			$annual_nois[] = $noi_result['noi'];
			$yearly_proj[] = array(
				'year' => $y + 1,
				'pgi'  => round( $pgi_y, 2 ),
				'opex' => round( $opex_y, 2 ),
				'noi'  => round( $noi_result['noi'], 2 ),
			);
		}

		// Run DCF via shared calculator.
		$dcf = WP_MCP_AI_CRE_Debt_Calculator::calculate_dcf(
			$annual_nois,
			$exit_cap_rate,
			$discount_rate,
			$selling_costs
		);

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		return array(
			'success' => true,
			'message' => __( 'DCF valuation complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'rent_roll_summary'    => $tenant_sum,
				'total_sf'             => round( $total_sf, 2 ),
				'year1_pgi'            => $calc::format_currency( $year1_pgi ),
				'year1_noi'            => $calc::format_currency( $annual_nois[0] ?? 0 ),
				'assumptions'          => array(
					'vacancy_rate'      => $calc::format_percentage( $vacancy_rate ),
					'revenue_growth'    => $calc::format_percentage( $revenue_growth ),
					'expense_growth'    => $calc::format_percentage( $expense_growth ),
					'exit_cap_rate'     => $calc::format_percentage( $exit_cap_rate ),
					'discount_rate'     => $calc::format_percentage( $discount_rate ),
					'selling_costs'     => $calc::format_percentage( $selling_costs ),
					'hold_period_years' => $hold_period,
				),
				'yearly_projections'   => $yearly_proj,
				'dcf_results'          => array(
					'pv_operating_cash_flows' => $calc::format_currency( $dcf['pv_cash_flows'] ),
					'terminal_value'          => $calc::format_currency( $dcf['terminal_value'] ),
					'net_terminal_value'      => $calc::format_currency( $dcf['net_terminal'] ),
					'pv_terminal_value'       => $calc::format_currency( $dcf['pv_terminal'] ),
					'total_property_value'    => $calc::format_currency( $dcf['total_value'] ),
				),
				'value_per_sf'         => ( $total_sf > 0 ) ? $calc::format_currency( $dcf['total_value'] / $total_sf ) : 'N/A',
				'implied_going_in_cap' => ( $dcf['total_value'] > 0 )
					? $calc::format_percentage( ( $annual_nois[0] ?? 0 ) / $dcf['total_value'] )
					: 'N/A',
			),
		);
	}
}
