<?php
/**
 * Retirement Calculator Tool
 *
 * Calculate retirement savings needs and projections based on current age,
 * retirement age, current savings, monthly contributions, and expected returns.
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
 * Tool for calculating retirement savings needs and projections.
 *
 * Supports:
 * - Retirement savings goal calculation
 * - Monthly contribution projections
 * - Compound interest calculations
 * - Inflation adjustments
 * - Multiple scenarios comparison
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Retirement_Calculator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		// Check if base version.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		// Check if financial planner toolkit is enabled.
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

		return __( 'Retirement calculator tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'retirement_calculator';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Retirement Calculator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Calculate retirement savings needs and projections. Estimates how much you need to save, projects future savings growth, and calculates monthly contributions needed to reach retirement goals. Includes inflation adjustments and compound interest calculations.', 'mcp-ai-wpoos-pro' );
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
				'current_age'             => array(
					'type'        => 'integer',
					'description' => __( 'Current age', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 18,
					'maximum'     => 80,
				),
				'retirement_age'          => array(
					'type'        => 'integer',
					'description' => __( 'Planned retirement age', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 50,
					'maximum'     => 80,
				),
				'current_savings'         => array(
					'type'        => 'number',
					'description' => __( 'Current retirement savings', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'monthly_contribution'    => array(
					'type'        => 'number',
					'description' => __( 'Monthly contribution to retirement savings', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'annual_return_rate'      => array(
					'type'        => 'number',
					'description' => __( 'Expected annual return rate (as percentage, e.g., 7 for 7%)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 20,
					'default'     => 7,
				),
				'inflation_rate'          => array(
					'type'        => 'number',
					'description' => __( 'Expected inflation rate (as percentage, e.g., 3 for 3%)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 10,
					'default'     => 3,
				),
				'desired_annual_income'   => array(
					'type'        => 'number',
					'description' => __( 'Desired annual retirement income (in today\'s dollars)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'life_expectancy'         => array(
					'type'        => 'integer',
					'description' => __( 'Expected life expectancy', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 60,
					'maximum'     => 110,
					'default'     => 85,
				),
				'include_social_security' => array(
					'type'        => 'boolean',
					'description' => __( 'Include social security in calculations', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'social_security_amount'  => array(
					'type'        => 'number',
					'description' => __( 'Estimated annual social security benefits', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
			),
			'required'   => array( 'current_age', 'retirement_age', 'current_savings', 'desired_annual_income' ),
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
				__( 'You do not have permission to use the retirement calculator.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if tool is available.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		// Validate and sanitize inputs.
		$current_age             = isset( $arguments['current_age'] ) ? absint( $arguments['current_age'] ) : 0;
		$retirement_age          = isset( $arguments['retirement_age'] ) ? absint( $arguments['retirement_age'] ) : 0;
		$current_savings         = isset( $arguments['current_savings'] ) ? floatval( $arguments['current_savings'] ) : 0;
		$monthly_contribution    = isset( $arguments['monthly_contribution'] ) ? floatval( $arguments['monthly_contribution'] ) : 0;
		$annual_return_rate      = isset( $arguments['annual_return_rate'] ) ? floatval( $arguments['annual_return_rate'] ) : 7;
		$inflation_rate          = isset( $arguments['inflation_rate'] ) ? floatval( $arguments['inflation_rate'] ) : 3;
		$desired_annual_income   = isset( $arguments['desired_annual_income'] ) ? floatval( $arguments['desired_annual_income'] ) : 0;
		$life_expectancy         = isset( $arguments['life_expectancy'] ) ? absint( $arguments['life_expectancy'] ) : 85;
		$include_social_security = isset( $arguments['include_social_security'] ) ? (bool) $arguments['include_social_security'] : false;
		$social_security_amount  = isset( $arguments['social_security_amount'] ) ? floatval( $arguments['social_security_amount'] ) : 0;

		// Validate inputs.
		if ( $current_age < 18 || $current_age > 80 ) {
			return new WP_Error(
				'invalid_current_age',
				__( 'Current age must be between 18 and 80.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( $retirement_age <= $current_age || $retirement_age > 80 ) {
			return new WP_Error(
				'invalid_retirement_age',
				__( 'Retirement age must be greater than current age and not exceed 80.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( $life_expectancy <= $retirement_age ) {
			return new WP_Error(
				'invalid_life_expectancy',
				__( 'Life expectancy must be greater than retirement age.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Calculate years to retirement and retirement years.
		$years_to_retirement = $retirement_age - $current_age;
		$retirement_years    = $life_expectancy - $retirement_age;

		// Convert rates to decimal.
		$annual_return_decimal = $annual_return_rate / 100;
		$inflation_decimal     = $inflation_rate / 100;
		$monthly_return        = $annual_return_decimal / 12;

		// Calculate future value of current savings.
		$future_value_current_savings = $current_savings * pow( 1 + $annual_return_decimal, $years_to_retirement );

		// Calculate future value of monthly contributions.
		$months_to_retirement       = $years_to_retirement * 12;
		$future_value_contributions = 0;
		if ( $monthly_contribution > 0 && $monthly_return > 0 ) {
			$future_value_contributions = $monthly_contribution * ( ( pow( 1 + $monthly_return, $months_to_retirement ) - 1 ) / $monthly_return );
		} elseif ( $monthly_contribution > 0 ) {
			$future_value_contributions = $monthly_contribution * $months_to_retirement;
		}

		// Total projected savings at retirement.
		$total_at_retirement = $future_value_current_savings + $future_value_contributions;

		// Adjust desired income for inflation.
		$inflation_adjusted_income = $desired_annual_income * pow( 1 + $inflation_decimal, $years_to_retirement );

		// Subtract social security if included.
		$net_income_needed = $inflation_adjusted_income;
		if ( $include_social_security ) {
			$net_income_needed = max( 0, $inflation_adjusted_income - $social_security_amount );
		}

		// Calculate required nest egg using 4% withdrawal rule.
		$required_nest_egg = $net_income_needed * 25;

		// Calculate shortfall/surplus.
		$shortfall = $required_nest_egg - $total_at_retirement;

		// Calculate additional monthly contribution needed.
		$additional_monthly_needed = 0;
		if ( $shortfall > 0 && $months_to_retirement > 0 && $monthly_return > 0 ) {
			$additional_monthly_needed = $shortfall / ( ( pow( 1 + $monthly_return, $months_to_retirement ) - 1 ) / $monthly_return );
		} elseif ( $shortfall > 0 && $months_to_retirement > 0 ) {
			$additional_monthly_needed = $shortfall / $months_to_retirement;
		}

		// Generate year-by-year projection.
		$projections = array();
		$balance     = $current_savings;
		for ( $year = 1; $year <= $years_to_retirement; $year++ ) {
			$balance       = ( $balance + ( $monthly_contribution * 12 ) ) * ( 1 + $annual_return_decimal );
			$projections[] = array(
				'year'    => $current_age + $year,
				'age'     => $current_age + $year,
				'balance' => round( $balance, 2 ),
			);
		}

		return array(
			'success'                   => true,
			'current_age'               => $current_age,
			'retirement_age'            => $retirement_age,
			'years_to_retirement'       => $years_to_retirement,
			'retirement_years'          => $retirement_years,
			'current_savings'           => $current_savings,
			'monthly_contribution'      => $monthly_contribution,
			'total_at_retirement'       => round( $total_at_retirement, 2 ),
			'required_nest_egg'         => round( $required_nest_egg, 2 ),
			'shortfall'                 => round( $shortfall, 2 ),
			'surplus'                   => round( -$shortfall, 2 ),
			'is_on_track'               => $shortfall <= 0,
			'additional_monthly_needed' => round( max( 0, $additional_monthly_needed ), 2 ),
			'inflation_adjusted_income' => round( $inflation_adjusted_income, 2 ),
			'desired_annual_income'     => $desired_annual_income,
			'annual_return_rate'        => $annual_return_rate,
			'inflation_rate'            => $inflation_rate,
			'projections'               => $projections,
			'disclaimer'                => __( 'This is an educational calculation only. Actual investment returns vary. Consult a licensed financial advisor for personalized advice.', 'mcp-ai-wpoos-pro' ),
			'message'                   => $shortfall <= 0
				? sprintf(
					/* translators: %s: Surplus amount */
					__( 'You are on track! Projected surplus of $%s at retirement.', 'mcp-ai-wpoos-pro' ),
					number_format( -$shortfall, 2 )
				)
				: sprintf(
					/* translators: 1: Shortfall amount, 2: Additional monthly contribution needed */
					__( 'You need an additional $%1$s. Increase monthly contribution by $%2$s to reach your goal.', 'mcp-ai-wpoos-pro' ),
					number_format( $shortfall, 2 ),
					number_format( $additional_monthly_needed, 2 )
				),
		);
	}
}
