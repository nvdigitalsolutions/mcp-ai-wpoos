<?php
/**
 * CRE NOI Calculator — Net Operating Income computation
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
 * Calculates Net Operating Income with management fees, reserves, and full
 * EGI waterfall (PGI → vacancy → concessions → other income → opex → NOI).
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_NOI_Calculator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_noi_calculator';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE NOI Calculator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Calculate Net Operating Income (NOI) for a commercial property. Takes potential gross income, vacancy, concessions, other income, operating expenses, management fee percentage, replacement reserves per unit, and unit count. Returns full income waterfall from PGI through NOI with key ratios.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'potential_gross_income' => array(
					'type'        => 'number',
					'description' => __( 'Total potential gross rental income (annual).', 'mcp-ai-wpoos-pro' ),
				),
				'vacancy_rate'           => array(
					'type'        => 'number',
					'description' => __( 'Vacancy & credit loss rate as decimal (e.g. 0.05 for 5%).', 'mcp-ai-wpoos-pro' ),
				),
				'concessions'            => array(
					'type'        => 'number',
					'description' => __( 'Annual rent concessions / free rent.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'other_income'           => array(
					'type'        => 'number',
					'description' => __( 'Other income (parking, laundry, fees, etc.).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'operating_expenses'     => array(
					'type'        => 'number',
					'description' => __( 'Total annual operating expenses (before mgmt fee & reserves).', 'mcp-ai-wpoos-pro' ),
				),
				'management_fee_pct'     => array(
					'type'        => 'number',
					'description' => __( 'Management fee as percent of EGI (decimal, e.g. 0.04 for 4%).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'reserves_per_unit'      => array(
					'type'        => 'number',
					'description' => __( 'Annual replacement reserves per unit.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'num_units'              => array(
					'type'        => 'integer',
					'description' => __( 'Number of units (for reserves calculation).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
			),
			'required'   => array( 'potential_gross_income', 'vacancy_rate', 'operating_expenses' ),
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

		$pgi               = (float) ( $arguments['potential_gross_income'] ?? 0 );
		$vacancy_rate      = (float) ( $arguments['vacancy_rate'] ?? 0.05 );
		$concessions       = (float) ( $arguments['concessions'] ?? 0 );
		$other_income      = (float) ( $arguments['other_income'] ?? 0 );
		$opex              = (float) ( $arguments['operating_expenses'] ?? 0 );
		$mgmt_fee_pct      = (float) ( $arguments['management_fee_pct'] ?? 0 );
		$reserves_per_unit = (float) ( $arguments['reserves_per_unit'] ?? 0 );
		$num_units         = (int) ( $arguments['num_units'] ?? 0 );

		if ( $pgi <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'Potential gross income must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}

		// Compute EGI first to derive management fee.
		$vacancy_loss = $pgi * $vacancy_rate;
		$egi          = $pgi - $vacancy_loss - $concessions + $other_income;

		$management_fee = $egi * $mgmt_fee_pct;
		$reserves       = $reserves_per_unit * $num_units;
		$total_opex     = $opex + $management_fee + $reserves;

		// Use shared calculator for core NOI (pass total_opex so mgmt + reserves are included).
		$noi_result = WP_MCP_AI_CRE_Debt_Calculator::calculate_noi(
			$pgi,
			$vacancy_rate,
			$concessions,
			$other_income,
			$total_opex
		);

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		$opex_ratio = ( $egi > 0 ) ? $total_opex / $egi : 0;
		$noi_margin = ( $egi > 0 ) ? $noi_result['noi'] / $egi : 0;

		return array(
			'success' => true,
			'message' => __( 'NOI calculation complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'income_waterfall'     => array(
					'potential_gross_income' => $calc::format_currency( $pgi ),
					'less_vacancy_loss'      => $calc::format_currency( $vacancy_loss ),
					'less_concessions'       => $calc::format_currency( $concessions ),
					'plus_other_income'      => $calc::format_currency( $other_income ),
					'effective_gross_income' => $calc::format_currency( $egi ),
				),
				'expense_detail'       => array(
					'base_operating_expenses'  => $calc::format_currency( $opex ),
					'management_fee'           => $calc::format_currency( $management_fee ),
					'replacement_reserves'     => $calc::format_currency( $reserves ),
					'total_operating_expenses' => $calc::format_currency( $total_opex ),
				),
				'net_operating_income' => $calc::format_currency( $noi_result['noi'] ),
				'key_ratios'           => array(
					'vacancy_rate' => $calc::format_percentage( $vacancy_rate ),
					'opex_ratio'   => $calc::format_percentage( $opex_ratio ),
					'noi_margin'   => $calc::format_percentage( $noi_margin ),
					'mgmt_fee_pct' => $calc::format_percentage( $mgmt_fee_pct ),
				),
				'per_unit'             => ( $num_units > 0 ) ? array(
					'pgi_per_unit'  => $calc::format_currency( $pgi / $num_units ),
					'egi_per_unit'  => $calc::format_currency( $egi / $num_units ),
					'opex_per_unit' => $calc::format_currency( $total_opex / $num_units ),
					'noi_per_unit'  => $calc::format_currency( $noi_result['noi'] / $num_units ),
				) : null,
			),
		);
	}
}
