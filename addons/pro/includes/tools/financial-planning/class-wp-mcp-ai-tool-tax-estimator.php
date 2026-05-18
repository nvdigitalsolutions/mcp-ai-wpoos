<?php
/**
 * Tax Estimator Tool
 *
 * Estimate annual federal tax liability based on income, deductions,
 * filing status, and tax credits for planning purposes.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for estimating tax liability.
 *
 * Supports:
 * - Federal tax calculation
 * - Standard/itemized deductions
 * - Multiple filing statuses
 * - Common tax credits
 * - Effective tax rate calculation
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Tax_Estimator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if financial planner toolkit is enabled.
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_financial_planner_toolkit'] );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_financial_planner_toolkit'] ) ) {
			return __( 'Financial planner toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Tax estimator tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'tax_estimator';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Tax Estimator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Estimate annual federal tax liability for planning purposes. Calculates taxes based on income, filing status, deductions, and credits. Provides effective tax rate and take-home pay estimates. NOT tax advice - consult a tax professional.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'gross_income'             => array(
					'type'        => 'number',
					'description' => __( 'Annual gross income', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'filing_status'            => array(
					'type'        => 'string',
					'description' => __( 'Tax filing status', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'single', 'married_joint', 'married_separate', 'head_of_household' ),
					'default'     => 'single',
				),
				'use_standard_deduction'   => array(
					'type'        => 'boolean',
					'description' => __( 'Use standard deduction (vs itemized)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'itemized_deductions'      => array(
					'type'        => 'number',
					'description' => __( 'Total itemized deductions', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'retirement_contributions' => array(
					'type'        => 'number',
					'description' => __( 'Pre-tax retirement contributions (401k, etc.)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'child_tax_credit'         => array(
					'type'        => 'integer',
					'description' => __( 'Number of qualifying children for child tax credit', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'other_credits'            => array(
					'type'        => 'number',
					'description' => __( 'Other tax credits', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'tax_year'                 => array(
					'type'        => 'integer',
					'description' => __( 'Tax year', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 2020,
					'maximum'     => 2030,
					'default'     => 2024,
				),
			),
			'required'   => array( 'gross_income' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'computation',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use the tax estimator.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$gross_income             = isset( $arguments['gross_income'] ) ? floatval( $arguments['gross_income'] ) : 0;
		$filing_status            = isset( $arguments['filing_status'] ) ? sanitize_text_field( $arguments['filing_status'] ) : 'single';
		$use_standard_deduction   = isset( $arguments['use_standard_deduction'] ) ? (bool) $arguments['use_standard_deduction'] : true;
		$itemized_deductions      = isset( $arguments['itemized_deductions'] ) ? floatval( $arguments['itemized_deductions'] ) : 0;
		$retirement_contributions = isset( $arguments['retirement_contributions'] ) ? floatval( $arguments['retirement_contributions'] ) : 0;
		$child_tax_credit_count   = isset( $arguments['child_tax_credit'] ) ? absint( $arguments['child_tax_credit'] ) : 0;
		$other_credits            = isset( $arguments['other_credits'] ) ? floatval( $arguments['other_credits'] ) : 0;

		if ( $gross_income <= 0 ) {
			return new WP_Error( 'invalid_income', __( 'Gross income must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}

		$standard_deductions = array(
			'single'            => 13850,
			'married_joint'     => 27700,
			'married_separate'  => 13850,
			'head_of_household' => 20800,
		);

		$standard_deduction = isset( $standard_deductions[ $filing_status ] ) ? $standard_deductions[ $filing_status ] : 13850;
		$deduction          = $use_standard_deduction ? $standard_deduction : max( $itemized_deductions, $standard_deduction );

		$adjusted_gross_income = $gross_income - $retirement_contributions;
		$taxable_income        = max( 0, $adjusted_gross_income - $deduction );

		$brackets_2024 = array(
			'single'        => array(
				array( 0, 11000, 0.10 ),
				array( 11000, 44725, 0.12 ),
				array( 44725, 95375, 0.22 ),
				array( 95375, 182100, 0.24 ),
				array( 182100, 231250, 0.32 ),
				array( 231250, 578125, 0.35 ),
				array( 578125, PHP_INT_MAX, 0.37 ),
			),
			'married_joint' => array(
				array( 0, 22000, 0.10 ),
				array( 22000, 89050, 0.12 ),
				array( 89050, 190750, 0.22 ),
				array( 190750, 364200, 0.24 ),
				array( 364200, 462500, 0.32 ),
				array( 462500, 693750, 0.35 ),
				array( 693750, PHP_INT_MAX, 0.37 ),
			),
		);

		if ( 'married_separate' === $filing_status ) {
			$brackets = $brackets_2024['single'];
		} elseif ( 'married_joint' === $filing_status ) {
			$brackets = $brackets_2024['married_joint'];
		} else {
			$brackets = $brackets_2024['single'];
		}

		$tax              = 0;
		$remaining_income = $taxable_income;
		foreach ( $brackets as $bracket ) {
			list( $lower, $upper, $rate ) = $bracket;
			$bracket_width                = $upper - $lower;
			$taxable_in_bracket           = min( $remaining_income, $bracket_width );
			if ( $taxable_in_bracket > 0 ) {
				$tax              += $taxable_in_bracket * $rate;
				$remaining_income -= $taxable_in_bracket;
			}
			if ( $remaining_income <= 0 ) {
				break;
			}
		}

		$child_tax_credit_amount = $child_tax_credit_count * 2000;
		$total_credits           = $child_tax_credit_amount + $other_credits;
		$tax_after_credits       = max( 0, $tax - $total_credits );

		$effective_rate = $gross_income > 0 ? ( $tax_after_credits / $gross_income ) * 100 : 0;
		$marginal_rate  = 0;
		foreach ( $brackets as $bracket ) {
			if ( $taxable_income > $bracket[0] && $taxable_income <= $bracket[1] ) {
				$marginal_rate = $bracket[2] * 100;
				break;
			}
		}

		$take_home_pay = $gross_income - $tax_after_credits;

		return array(
			'success'               => true,
			'gross_income'          => $gross_income,
			'adjusted_gross_income' => round( $adjusted_gross_income, 2 ),
			'taxable_income'        => round( $taxable_income, 2 ),
			'filing_status'         => $filing_status,
			'deduction'             => round( $deduction, 2 ),
			'tax_before_credits'    => round( $tax, 2 ),
			'total_credits'         => round( $total_credits, 2 ),
			'estimated_tax'         => round( $tax_after_credits, 2 ),
			'effective_rate'        => round( $effective_rate, 2 ),
			'marginal_rate'         => round( $marginal_rate, 2 ),
			'take_home_pay'         => round( $take_home_pay, 2 ),
			'disclaimer'            => __( 'ESTIMATE ONLY. This is a simplified federal tax estimate for planning purposes only and does not constitute tax advice. Does not include state/local taxes, AMT, FICA, or other taxes. Actual tax liability may differ. Consult a licensed tax professional for accurate tax planning.', 'mcp-ai-wpoos-pro' ),
			'message'               => sprintf(
				/* translators: 1: Tax amount, 2: Effective rate */
				__( 'Estimated federal tax: $%1$s (effective rate: %2$s%%).', 'mcp-ai-wpoos-pro' ),
				number_format( $tax_after_credits, 2 ),
				number_format( $effective_rate, 1 )
			),
		);
	}
}
