<?php
/**
 * IRA Roth Comparison Tool
 *
 * Compare Traditional IRA vs Roth IRA tax benefits and long-term growth
 * based on current and expected future tax rates.
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
 * Tool for comparing Traditional IRA and Roth IRA accounts.
 *
 * Supports:
 * - Tax benefit comparison
 * - After-tax value comparison
 * - Current vs future tax rate scenarios
 * - Contribution limit tracking
 * - Break-even analysis
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_IRA_Roth_Comparison implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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

		return __( 'IRA/Roth comparison tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'ira_roth_comparison';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'IRA vs Roth IRA Comparison', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Compare Traditional IRA vs Roth IRA tax benefits. Analyzes current vs future tax rates, calculates after-tax values at retirement, and recommends the better option based on your tax situation and timeline.', 'mcp-ai-wpoos-pro' );
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
				'annual_contribution' => array(
					'type'        => 'number',
					'description' => __( 'Annual IRA contribution amount', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 10000,
				),
				'years_to_retirement' => array(
					'type'        => 'integer',
					'description' => __( 'Years until retirement', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 50,
				),
				'current_tax_rate'    => array(
					'type'        => 'number',
					'description' => __( 'Current marginal tax rate (as percentage, e.g., 24 for 24%)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 50,
				),
				'retirement_tax_rate' => array(
					'type'        => 'number',
					'description' => __( 'Expected tax rate in retirement (as percentage)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 50,
				),
				'annual_return_rate'  => array(
					'type'        => 'number',
					'description' => __( 'Expected annual return rate (as percentage, e.g., 7 for 7%)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 20,
					'default'     => 7,
				),
				'current_age'         => array(
					'type'        => 'integer',
					'description' => __( 'Current age', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 18,
					'maximum'     => 80,
				),
				'income_level'        => array(
					'type'        => 'number',
					'description' => __( 'Current annual income', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
			),
			'required'   => array( 'annual_contribution', 'years_to_retirement', 'current_tax_rate', 'retirement_tax_rate' ),
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
		// Check permissions.
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use the IRA comparison tool.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		// Validate and sanitize inputs.
		$annual_contribution = isset( $arguments['annual_contribution'] ) ? floatval( $arguments['annual_contribution'] ) : 0;
		$years_to_retirement = isset( $arguments['years_to_retirement'] ) ? absint( $arguments['years_to_retirement'] ) : 0;
		$current_tax_rate    = isset( $arguments['current_tax_rate'] ) ? floatval( $arguments['current_tax_rate'] ) : 0;
		$retirement_tax_rate = isset( $arguments['retirement_tax_rate'] ) ? floatval( $arguments['retirement_tax_rate'] ) : 0;
		$annual_return_rate  = isset( $arguments['annual_return_rate'] ) ? floatval( $arguments['annual_return_rate'] ) : 7;
		$current_age         = isset( $arguments['current_age'] ) ? absint( $arguments['current_age'] ) : 0;

		if ( $annual_contribution <= 0 ) {
			return new WP_Error(
				'invalid_contribution',
				__( 'Annual contribution must be greater than zero.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( $years_to_retirement <= 0 ) {
			return new WP_Error(
				'invalid_years',
				__( 'Years to retirement must be greater than zero.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Convert rates to decimal.
		$current_tax_decimal    = $current_tax_rate / 100;
		$retirement_tax_decimal = $retirement_tax_rate / 100;
		$return_decimal         = $annual_return_rate / 100;

		// Traditional IRA calculation.
		// Upfront tax savings.
		$traditional_tax_savings = $annual_contribution * $current_tax_decimal;

		// Future value of contributions.
		$traditional_future_value = $annual_contribution * ( ( pow( 1 + $return_decimal, $years_to_retirement ) - 1 ) / $return_decimal ) * ( 1 + $return_decimal );

		// After-tax value (pay taxes in retirement).
		$traditional_after_tax = $traditional_future_value * ( 1 - $retirement_tax_decimal );

		// Roth IRA calculation.
		// Pay taxes now on contribution.
		$roth_after_tax_contribution = $annual_contribution * ( 1 - $current_tax_decimal );

		// Future value (tax-free growth).
		$roth_future_value = $roth_after_tax_contribution * ( ( pow( 1 + $return_decimal, $years_to_retirement ) - 1 ) / $return_decimal ) * ( 1 + $return_decimal );

		// After-tax value (no taxes in retirement).
		$roth_after_tax = $roth_future_value;

		// Comparison.
		$difference        = $roth_after_tax - $traditional_after_tax;
		$better_option     = $difference > 0 ? 'roth' : 'traditional';
		$percentage_better = $traditional_after_tax > 0 ? abs( $difference ) / $traditional_after_tax * 100 : 0;

		// Break-even tax rate.
		$breakeven_retirement_rate = $current_tax_rate;

		// Recommendation logic.
		$recommendation = '';
		if ( $retirement_tax_rate > $current_tax_rate ) {
			$recommendation = __( 'Roth IRA is recommended: You expect to be in a higher tax bracket in retirement.', 'mcp-ai-wpoos-pro' );
		} elseif ( $retirement_tax_rate < $current_tax_rate ) {
			$recommendation = __( 'Traditional IRA is recommended: You expect to be in a lower tax bracket in retirement.', 'mcp-ai-wpoos-pro' );
		} else {
			$recommendation = __( 'Both options are equivalent at the same tax rate. Consider Roth for tax-free withdrawals.', 'mcp-ai-wpoos-pro' );
		}

		return array(
			'success'         => true,
			'traditional_ira' => array(
				'annual_contribution'   => $annual_contribution,
				'upfront_tax_savings'   => round( $traditional_tax_savings, 2 ),
				'future_value'          => round( $traditional_future_value, 2 ),
				'after_tax_value'       => round( $traditional_after_tax, 2 ),
				'taxes_paid_retirement' => round( $traditional_future_value - $traditional_after_tax, 2 ),
			),
			'roth_ira'        => array(
				'annual_contribution'    => $annual_contribution,
				'after_tax_contribution' => round( $roth_after_tax_contribution, 2 ),
				'upfront_tax_paid'       => round( $annual_contribution - $roth_after_tax_contribution, 2 ),
				'future_value'           => round( $roth_future_value, 2 ),
				'after_tax_value'        => round( $roth_after_tax, 2 ),
				'taxes_paid_retirement'  => 0,
			),
			'comparison'      => array(
				'better_option'      => $better_option,
				'difference'         => round( abs( $difference ), 2 ),
				'percentage_better'  => round( $percentage_better, 2 ),
				'breakeven_tax_rate' => round( $breakeven_retirement_rate, 2 ),
			),
			'parameters'      => array(
				'years_to_retirement' => $years_to_retirement,
				'current_tax_rate'    => $current_tax_rate,
				'retirement_tax_rate' => $retirement_tax_rate,
				'annual_return_rate'  => $annual_return_rate,
			),
			'recommendation'  => $recommendation,
			'disclaimer'      => __( 'This is an educational comparison only. Actual results vary based on individual circumstances, future tax law changes, and investment performance. Consult a licensed financial advisor and tax professional for personalized advice.', 'mcp-ai-wpoos-pro' ),
			'message'         => sprintf(
				/* translators: 1: Better option, 2: Difference amount, 3: Percentage */
				__( '%1$s IRA is better by $%2$s (%3$s%%) based on your tax rates.', 'mcp-ai-wpoos-pro' ),
				ucfirst( $better_option ),
				number_format( abs( $difference ), 2 ),
				number_format( $percentage_better, 2 )
			),
		);
	}
}
