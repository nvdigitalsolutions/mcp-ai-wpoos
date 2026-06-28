<?php
/**
 * Investment Return Calculator Tool
 *
 * Calculate compound investment returns over time with contributions,
 * fees, inflation adjustments, and various compounding frequencies.
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
 * Tool for calculating investment returns.
 *
 * Supports:
 * - Compound interest calculations
 * - Regular contribution schedules
 * - Fee impact analysis
 * - Inflation adjustment
 * - Multiple compounding periods
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Investment_Return_Calculator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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

		return __( 'Investment return calculator tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'investment_return_calculator';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Investment Return Calculator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Calculate compound investment returns with regular contributions. Projects future value, total gains, and real returns after fees and inflation. Supports multiple compounding frequencies and contribution schedules.', 'mcp-ai-wpoos-pro' );
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
				'initial_investment'     => array(
					'type'        => 'number',
					'description' => __( 'Initial investment amount', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'regular_contribution'   => array(
					'type'        => 'number',
					'description' => __( 'Regular contribution amount', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'contribution_frequency' => array(
					'type'        => 'string',
					'description' => __( 'Contribution frequency', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'monthly', 'quarterly', 'annually' ),
					'default'     => 'monthly',
				),
				'annual_return_rate'     => array(
					'type'        => 'number',
					'description' => __( 'Expected annual return rate (as percentage)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => -100,
					'maximum'     => 100,
				),
				'years'                  => array(
					'type'        => 'integer',
					'description' => __( 'Investment time period in years', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 50,
				),
				'compounding_frequency'  => array(
					'type'        => 'string',
					'description' => __( 'How often returns compound', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'daily', 'monthly', 'quarterly', 'annually' ),
					'default'     => 'monthly',
				),
				'annual_fee_rate'        => array(
					'type'        => 'number',
					'description' => __( 'Annual fee rate (as percentage)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 10,
					'default'     => 0,
				),
				'inflation_rate'         => array(
					'type'        => 'number',
					'description' => __( 'Expected annual inflation rate (as percentage)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 20,
					'default'     => 3,
				),
			),
			'required'   => array( 'initial_investment', 'annual_return_rate', 'years' ),
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
				__( 'You do not have permission to use the investment calculator.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$initial_investment     = isset( $arguments['initial_investment'] ) ? floatval( $arguments['initial_investment'] ) : 0;
		$regular_contribution   = isset( $arguments['regular_contribution'] ) ? floatval( $arguments['regular_contribution'] ) : 0;
		$contribution_frequency = isset( $arguments['contribution_frequency'] ) ? sanitize_text_field( $arguments['contribution_frequency'] ) : 'monthly';
		$annual_return_rate     = isset( $arguments['annual_return_rate'] ) ? floatval( $arguments['annual_return_rate'] ) : 0;
		$years                  = isset( $arguments['years'] ) ? absint( $arguments['years'] ) : 0;
		$compounding_frequency  = isset( $arguments['compounding_frequency'] ) ? sanitize_text_field( $arguments['compounding_frequency'] ) : 'monthly';
		$annual_fee_rate        = isset( $arguments['annual_fee_rate'] ) ? floatval( $arguments['annual_fee_rate'] ) : 0;
		$inflation_rate         = isset( $arguments['inflation_rate'] ) ? floatval( $arguments['inflation_rate'] ) : 3;

		if ( $years < 1 ) {
			return new WP_Error( 'invalid_years', __( 'Years must be at least 1.', 'mcp-ai-wpoos-pro' ) );
		}

		$frequency_periods = array(
			'daily'     => 365,
			'monthly'   => 12,
			'quarterly' => 4,
			'annually'  => 1,
		);

		$contribution_periods = array(
			'monthly'   => 12,
			'quarterly' => 4,
			'annually'  => 1,
		);

		$n                      = isset( $frequency_periods[ $compounding_frequency ] ) ? $frequency_periods[ $compounding_frequency ] : 12;
		$contributions_per_year = isset( $contribution_periods[ $contribution_frequency ] ) ? $contribution_periods[ $contribution_frequency ] : 12;

		$net_return_rate   = $annual_return_rate - $annual_fee_rate;
		$r                 = $net_return_rate / 100;
		$inflation_decimal = $inflation_rate / 100;

		$balance             = $initial_investment;
		$total_contributions = $initial_investment;
		$year_by_year        = array();

		for ( $year = 1; $year <= $years; $year++ ) {
			$contributions_this_year = $regular_contribution * $contributions_per_year;
			$total_contributions    += $contributions_this_year;

			for ( $period = 0; $period < $n; $period++ ) {
				$balance                          = $balance * ( 1 + ( $r / $n ) );
				$contribution_periods_in_compound = $contributions_per_year / $n;
				if ( $contribution_periods_in_compound >= 1 && $period % ( $n / $contributions_per_year ) === 0 ) {
					$balance += $regular_contribution;
				}
			}

			$real_value = $balance / pow( 1 + $inflation_decimal, $year );

			$year_by_year[] = array(
				'year'          => $year,
				'balance'       => round( $balance, 2 ),
				'contributions' => round( $total_contributions, 2 ),
				'gains'         => round( $balance - $total_contributions, 2 ),
				'real_value'    => round( $real_value, 2 ),
			);
		}

		$final_balance    = $balance;
		$total_gains      = $final_balance - $total_contributions;
		$total_return_pct = $total_contributions > 0 ? ( $total_gains / $total_contributions ) * 100 : 0;
		$real_final_value = $final_balance / pow( 1 + $inflation_decimal, $years );
		$real_gains       = $real_final_value - $total_contributions;

		return array(
			'success'                => true,
			'initial_investment'     => $initial_investment,
			'regular_contribution'   => $regular_contribution,
			'contribution_frequency' => $contribution_frequency,
			'years'                  => $years,
			'annual_return_rate'     => $annual_return_rate,
			'annual_fee_rate'        => $annual_fee_rate,
			'net_return_rate'        => round( $net_return_rate, 2 ),
			'inflation_rate'         => $inflation_rate,
			'final_balance'          => round( $final_balance, 2 ),
			'total_contributions'    => round( $total_contributions, 2 ),
			'total_gains'            => round( $total_gains, 2 ),
			'total_return_pct'       => round( $total_return_pct, 2 ),
			'real_final_value'       => round( $real_final_value, 2 ),
			'real_gains'             => round( $real_gains, 2 ),
			'projections'            => $year_by_year,
			'disclaimer'             => __( 'EDUCATIONAL ONLY. This calculation is for educational purposes and uses hypothetical returns. Actual investment returns vary and are not guaranteed. Past performance does not predict future results. Consult a licensed financial advisor.', 'mcp-ai-wpoos-pro' ),
			'message'                => sprintf(
				/* translators: 1: Final balance, 2: Years, 3: Total gains */
				__( 'After %2$d years: $%1$s total value with $%3$s in gains.', 'mcp-ai-wpoos-pro' ),
				number_format( $final_balance, 2 ),
				$years,
				number_format( $total_gains, 2 )
			),
		);
	}
}
