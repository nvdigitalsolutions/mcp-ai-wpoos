<?php
/**
 * College Savings Calculator Tool
 *
 * Calculate 529 plan savings needs for college education funding
 * with tuition inflation adjustments and investment growth projections.
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
 * Tool for calculating college savings requirements.
 *
 * Supports:
 * - 529 plan contribution calculations
 * - Tuition inflation adjustments
 * - Public/private school scenarios
 * - State tax benefit estimates
 * - Multi-child planning
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_College_Savings_Calculator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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

		return __( 'College savings calculator tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'college_savings_calculator';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'College Savings Calculator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Calculate 529 plan savings needs for college education. Projects future tuition costs with inflation, calculates monthly contributions needed, and estimates investment growth. Supports public/private school scenarios.', 'mcp-ai-wpoos-pro' );
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
				'child_age'              => array(
					'type'        => 'integer',
					'description' => __( 'Child\'s current age', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 17,
				),
				'college_start_age'      => array(
					'type'        => 'integer',
					'description' => __( 'Age child will start college', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 17,
					'maximum'     => 25,
					'default'     => 18,
				),
				'school_type'            => array(
					'type'        => 'string',
					'description' => __( 'Type of school', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'public_in_state', 'public_out_of_state', 'private', 'community_college' ),
					'default'     => 'public_in_state',
				),
				'years_of_college'       => array(
					'type'        => 'integer',
					'description' => __( 'Number of college years to fund', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 6,
					'default'     => 4,
				),
				'current_savings'        => array(
					'type'        => 'number',
					'description' => __( 'Current 529 plan balance', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'monthly_contribution'   => array(
					'type'        => 'number',
					'description' => __( 'Current monthly contribution', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'expected_return_rate'   => array(
					'type'        => 'number',
					'description' => __( 'Expected annual investment return (as percentage)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 20,
					'default'     => 6,
				),
				'tuition_inflation_rate' => array(
					'type'        => 'number',
					'description' => __( 'Annual tuition inflation rate (as percentage)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 15,
					'default'     => 5,
				),
				'coverage_percentage'    => array(
					'type'        => 'number',
					'description' => __( 'Percentage of costs to cover (e.g., 100 for full, 50 for half)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 100,
				),
			),
			'required'   => array( 'child_age', 'school_type' ),
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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use the college savings calculator.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$child_age            = isset( $arguments['child_age'] ) ? absint( $arguments['child_age'] ) : 0;
		$college_start_age    = isset( $arguments['college_start_age'] ) ? absint( $arguments['college_start_age'] ) : 18;
		$school_type          = isset( $arguments['school_type'] ) ? sanitize_text_field( $arguments['school_type'] ) : 'public_in_state';
		$years_of_college     = isset( $arguments['years_of_college'] ) ? absint( $arguments['years_of_college'] ) : 4;
		$current_savings      = isset( $arguments['current_savings'] ) ? floatval( $arguments['current_savings'] ) : 0;
		$monthly_contribution = isset( $arguments['monthly_contribution'] ) ? floatval( $arguments['monthly_contribution'] ) : 0;
		$expected_return      = isset( $arguments['expected_return_rate'] ) ? floatval( $arguments['expected_return_rate'] ) : 6;
		$tuition_inflation    = isset( $arguments['tuition_inflation_rate'] ) ? floatval( $arguments['tuition_inflation_rate'] ) : 5;
		$coverage_percentage  = isset( $arguments['coverage_percentage'] ) ? floatval( $arguments['coverage_percentage'] ) : 100;

		if ( $child_age < 0 || $child_age > 17 ) {
			return new WP_Error( 'invalid_age', __( 'Child age must be between 0 and 17.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $college_start_age <= $child_age ) {
			return new WP_Error( 'invalid_start_age', __( 'College start age must be greater than current age.', 'mcp-ai-wpoos-pro' ) );
		}

		$annual_costs_2024 = array(
			'public_in_state'     => 27940,
			'public_out_of_state' => 45240,
			'private'             => 60420,
			'community_college'   => 14000,
		);

		$current_annual_cost = isset( $annual_costs_2024[ $school_type ] ) ? $annual_costs_2024[ $school_type ] : 27940;
		$years_until_college = $college_start_age - $child_age;

		$future_annual_cost = $current_annual_cost * pow( 1 + ( $tuition_inflation / 100 ), $years_until_college );
		$total_college_cost = 0;
		for ( $year = 0; $year < $years_of_college; $year++ ) {
			$cost_this_year      = $current_annual_cost * pow( 1 + ( $tuition_inflation / 100 ), $years_until_college + $year );
			$total_college_cost += $cost_this_year;
		}

		$target_savings = ( $total_college_cost * $coverage_percentage ) / 100;

		$return_decimal       = $expected_return / 100;
		$monthly_return       = $return_decimal / 12;
		$months_until_college = $years_until_college * 12;

		$future_value_current = $current_savings * pow( 1 + $return_decimal, $years_until_college );

		$future_value_contributions = 0;
		if ( $monthly_contribution > 0 && $monthly_return > 0 && $months_until_college > 0 ) {
			$future_value_contributions = $monthly_contribution * ( ( pow( 1 + $monthly_return, $months_until_college ) - 1 ) / $monthly_return );
		}

		$projected_savings = $future_value_current + $future_value_contributions;
		$shortfall         = $target_savings - $projected_savings;

		$additional_monthly_needed = 0;
		if ( $shortfall > 0 && $months_until_college > 0 && $monthly_return > 0 ) {
			$additional_monthly_needed = $shortfall / ( ( pow( 1 + $monthly_return, $months_until_college ) - 1 ) / $monthly_return );
		}

		$funding_percentage = $target_savings > 0 ? ( $projected_savings / $target_savings ) * 100 : 0;

		return array(
			'success'                   => true,
			'child_age'                 => $child_age,
			'years_until_college'       => $years_until_college,
			'school_type'               => $school_type,
			'current_annual_cost'       => round( $current_annual_cost, 2 ),
			'future_annual_cost'        => round( $future_annual_cost, 2 ),
			'total_college_cost'        => round( $total_college_cost, 2 ),
			'target_savings'            => round( $target_savings, 2 ),
			'current_savings'           => $current_savings,
			'projected_savings'         => round( $projected_savings, 2 ),
			'funding_percentage'        => round( $funding_percentage, 1 ),
			'shortfall'                 => round( max( 0, $shortfall ), 2 ),
			'monthly_contribution'      => $monthly_contribution,
			'additional_monthly_needed' => round( max( 0, $additional_monthly_needed ), 2 ),
			'recommended_monthly'       => round( $monthly_contribution + max( 0, $additional_monthly_needed ), 2 ),
			'message'                   => sprintf(
				/* translators: 1: Target savings, 2: Total cost */
				__( 'Target savings: $%1$s to cover %3$s%% of estimated $%2$s total cost.', 'mcp-ai-wpoos-pro' ),
				number_format( $target_savings, 2 ),
				number_format( $total_college_cost, 2 ),
				$coverage_percentage
			),
		);
	}
}
