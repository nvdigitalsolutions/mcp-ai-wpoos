<?php
/**
 * CRE Debt Yield Analyzer — Debt yield with stress scenarios
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
 * Calculates debt yield under base-case and multiple stress scenarios
 * (NOI haircuts) to evaluate loan risk at various down-side levels.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Debt_Yield_Analyzer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * {@inheritdoc}
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
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
		return 'cre_debt_yield_analyzer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Debt Yield Analyzer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Analyze debt yield under base case and multiple NOI stress scenarios. Provide NOI, loan amount, and an array of NOI adjustment percentages (e.g. -5%, -10%, -15%) to evaluate downside risk on debt yield.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Measuring loan downside risk via debt yield under NOI haircuts against a minimum acceptable threshold.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Loan sizing against LTV/DSCR constraints; use cre_loan_sizer. Full DSCR/LTV scenario runs; use cre_stress_test_modeler.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'cre_loan_sizer', 'cre_stress_test_modeler', 'cre_cap_rate_sensitivity' ),
			'notes'           => __( 'stress_scenarios are decimals like -0.10; output includes breakeven NOI and cushion versus the threshold.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'noi'               => array(
					'type'        => 'number',
					'description' => __( 'Annual Net Operating Income.', 'mcp-ai-wpoos-pro' ),
				),
				'loan_amount'       => array(
					'type'        => 'number',
					'description' => __( 'Loan amount.', 'mcp-ai-wpoos-pro' ),
				),
				'stress_scenarios'  => array(
					'type'        => 'array',
					'description' => __( 'Array of NOI adjustment percentages as decimals (e.g. [-0.05, -0.10, -0.15, -0.20]).', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'number',
					),
					'default'     => array( -0.05, -0.10, -0.15, -0.20 ),
				),
				'min_acceptable_dy' => array(
					'type'        => 'number',
					'description' => __( 'Minimum acceptable debt yield threshold as decimal (e.g. 0.10). Used for pass/fail assessment.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0.10,
				),
			),
			'required'   => array( 'noi', 'loan_amount' ),
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

		$noi       = (float) ( $arguments['noi'] ?? 0 );
		$loan      = (float) ( $arguments['loan_amount'] ?? 0 );
		$scenarios = $arguments['stress_scenarios'] ?? array( -0.05, -0.10, -0.15, -0.20 );
		$min_dy    = (float) ( $arguments['min_acceptable_dy'] ?? 0.10 );

		if ( $noi <= 0 || $loan <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'NOI and loan amount must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		// Base case.
		$base_dy = $calc::calculate_debt_yield( $noi, $loan );

		// Stress scenarios.
		$results = array();
		foreach ( $scenarios as $adj ) {
			$adj        = (float) $adj;
			$stress_noi = $noi * ( 1 + $adj );
			$stress_dy  = $calc::calculate_debt_yield( $stress_noi, $loan );
			$passes     = $stress_dy >= $min_dy;

			$results[] = array(
				'noi_adjustment'  => $calc::format_percentage( $adj ),
				'stressed_noi'    => $calc::format_currency( $stress_noi ),
				'debt_yield'      => $calc::format_percentage( $stress_dy ),
				'meets_threshold' => $passes,
				'cushion_bps'     => round( ( $stress_dy - $min_dy ) * 10000 ),
			);
		}

		// Breakeven NOI.
		$breakeven_noi = $loan * $min_dy;
		$noi_cushion   = $noi - $breakeven_noi;
		$cushion_pct   = ( $noi > 0 ) ? $noi_cushion / $noi : 0;

		return array(
			'success' => true,
			'message' => __( 'Debt yield analysis complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'base_case'      => array(
					'noi'             => $calc::format_currency( $noi ),
					'loan_amount'     => $calc::format_currency( $loan ),
					'debt_yield'      => $calc::format_percentage( $base_dy ),
					'meets_threshold' => $base_dy >= $min_dy,
				),
				'threshold'      => $calc::format_percentage( $min_dy ),
				'stress_results' => $results,
				'breakeven'      => array(
					'breakeven_noi'      => $calc::format_currency( $breakeven_noi ),
					'noi_cushion'        => $calc::format_currency( $noi_cushion ),
					'cushion_pct_of_noi' => $calc::format_percentage( $cushion_pct ),
					'max_noi_decline'    => $calc::format_percentage( -$cushion_pct ),
				),
			),
		);
	}
}
