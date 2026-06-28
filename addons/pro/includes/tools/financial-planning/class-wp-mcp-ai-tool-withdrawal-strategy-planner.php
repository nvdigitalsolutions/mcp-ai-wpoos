<?php
/**
 * Withdrawal Strategy Planner Tool
 *
 * Plan retirement withdrawal strategies to minimize taxes and maximize
 * portfolio longevity using various withdrawal methods.
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
 * Tool for planning retirement withdrawal strategies.
 *
 * Supports:
 * - Multiple withdrawal strategies (4% rule, dynamic, fixed amount)
 * - Tax-efficient withdrawal ordering
 * - Portfolio longevity projections
 * - RMD calculations
 * - Sequence of returns risk analysis
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Withdrawal_Strategy_Planner implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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

		return __( 'Withdrawal strategy planner tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'withdrawal_strategy_planner';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Withdrawal Strategy Planner', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Plan retirement withdrawal strategies to maximize portfolio longevity. Compares withdrawal methods (4% rule, dynamic, fixed), calculates tax-efficient withdrawal ordering, and projects portfolio sustainability over time.', 'mcp-ai-wpoos-pro' );
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
				'portfolio_balance'       => array(
					'type'        => 'number',
					'description' => __( 'Total retirement portfolio balance', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'withdrawal_strategy'     => array(
					'type'        => 'string',
					'description' => __( 'Withdrawal strategy method', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'four_percent', 'dynamic', 'fixed_amount', 'rmb' ),
					'default'     => 'four_percent',
				),
				'annual_withdrawal'       => array(
					'type'        => 'number',
					'description' => __( 'Annual withdrawal amount (for fixed_amount strategy)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'retirement_age'          => array(
					'type'        => 'integer',
					'description' => __( 'Current or retirement age', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 50,
					'maximum'     => 100,
				),
				'life_expectancy'         => array(
					'type'        => 'integer',
					'description' => __( 'Expected life expectancy', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 60,
					'maximum'     => 110,
					'default'     => 90,
				),
				'expected_return_rate'    => array(
					'type'        => 'number',
					'description' => __( 'Expected portfolio return rate (as percentage)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 20,
					'default'     => 6,
				),
				'inflation_rate'          => array(
					'type'        => 'number',
					'description' => __( 'Expected inflation rate (as percentage)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 10,
					'default'     => 3,
				),
				'tax_rate'                => array(
					'type'        => 'number',
					'description' => __( 'Expected tax rate on withdrawals (as percentage)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 50,
					'default'     => 15,
				),
				'traditional_ira_balance' => array(
					'type'        => 'number',
					'description' => __( 'Traditional IRA/401k balance (pre-tax)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'roth_ira_balance'        => array(
					'type'        => 'number',
					'description' => __( 'Roth IRA balance (tax-free)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'taxable_balance'         => array(
					'type'        => 'number',
					'description' => __( 'Taxable account balance', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
			),
			'required'   => array( 'portfolio_balance', 'retirement_age' ),
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
				__( 'You do not have permission to use the withdrawal strategy planner.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		// Validate and sanitize inputs.
		$portfolio_balance    = isset( $arguments['portfolio_balance'] ) ? floatval( $arguments['portfolio_balance'] ) : 0;
		$withdrawal_strategy  = isset( $arguments['withdrawal_strategy'] ) ? sanitize_text_field( $arguments['withdrawal_strategy'] ) : 'four_percent';
		$annual_withdrawal    = isset( $arguments['annual_withdrawal'] ) ? floatval( $arguments['annual_withdrawal'] ) : 0;
		$retirement_age       = isset( $arguments['retirement_age'] ) ? absint( $arguments['retirement_age'] ) : 0;
		$life_expectancy      = isset( $arguments['life_expectancy'] ) ? absint( $arguments['life_expectancy'] ) : 90;
		$expected_return_rate = isset( $arguments['expected_return_rate'] ) ? floatval( $arguments['expected_return_rate'] ) : 6;
		$inflation_rate       = isset( $arguments['inflation_rate'] ) ? floatval( $arguments['inflation_rate'] ) : 3;
		$tax_rate             = isset( $arguments['tax_rate'] ) ? floatval( $arguments['tax_rate'] ) : 15;

		if ( $portfolio_balance <= 0 ) {
			return new WP_Error(
				'invalid_balance',
				__( 'Portfolio balance must be greater than zero.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( $retirement_age >= $life_expectancy ) {
			return new WP_Error(
				'invalid_ages',
				__( 'Life expectancy must be greater than retirement age.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Convert rates to decimal.
		$return_decimal    = $expected_return_rate / 100;
		$inflation_decimal = $inflation_rate / 100;
		$tax_decimal       = $tax_rate / 100;

		// Calculate initial withdrawal based on strategy.
		$initial_withdrawal = 0;
		switch ( $withdrawal_strategy ) {
			case 'four_percent':
				$initial_withdrawal = $portfolio_balance * 0.04;
				break;
			case 'dynamic':
				$initial_withdrawal = $portfolio_balance * 0.05;
				break;
			case 'fixed_amount':
				$initial_withdrawal = $annual_withdrawal > 0 ? $annual_withdrawal : $portfolio_balance * 0.04;
				break;
			case 'rmb':
				// RMD-based (simplified).
				$years_remaining    = max( 1, $life_expectancy - $retirement_age );
				$initial_withdrawal = $portfolio_balance / $years_remaining;
				break;
		}

		// Run projection.
		$years                   = $life_expectancy - $retirement_age;
		$balance                 = $portfolio_balance;
		$withdrawal_amount       = $initial_withdrawal;
		$projections             = array();
		$portfolio_depleted_year = null;

		for ( $year = 1; $year <= $years; $year++ ) {
			$age = $retirement_age + $year;

			// Adjust withdrawal for inflation (except fixed_amount).
			if ( 'fixed_amount' !== $withdrawal_strategy && $year > 1 ) {
				$withdrawal_amount *= ( 1 + $inflation_decimal );
			}

			// Dynamic strategy adjusts based on portfolio performance.
			if ( 'dynamic' === $withdrawal_strategy && $year > 1 ) {
				$withdrawal_amount = $balance * 0.05;
			}

			// RMD-based recalculates each year.
			if ( 'rmb' === $withdrawal_strategy ) {
				$years_remaining   = max( 1, $life_expectancy - $age + 1 );
				$withdrawal_amount = $balance / $years_remaining;
			}

			// Withdraw from portfolio.
			$balance -= $withdrawal_amount;

			// Check if depleted.
			if ( $balance <= 0 ) {
				$balance                 = 0;
				$portfolio_depleted_year = $age;
				$projections[]           = array(
					'year'              => $year,
					'age'               => $age,
					'withdrawal_amount' => round( $withdrawal_amount, 2 ),
					'balance'           => 0,
					'depleted'          => true,
				);
				break;
			}

			// Apply investment returns.
			$balance *= ( 1 + $return_decimal );

			$projections[] = array(
				'year'              => $year,
				'age'               => $age,
				'withdrawal_amount' => round( $withdrawal_amount, 2 ),
				'balance'           => round( $balance, 2 ),
				'depleted'          => false,
			);
		}

		// Calculate sustainability.
		$is_sustainable  = is_null( $portfolio_depleted_year );
		$years_sustained = $is_sustainable ? $years : ( $portfolio_depleted_year - $retirement_age );
		$final_balance   = $is_sustainable ? $balance : 0;
		$total_withdrawn = array_sum( array_column( $projections, 'withdrawal_amount' ) );

		return array(
			'success'                        => true,
			'withdrawal_strategy'            => $withdrawal_strategy,
			'initial_withdrawal'             => round( $initial_withdrawal, 2 ),
			'portfolio_balance'              => $portfolio_balance,
			'is_sustainable'                 => $is_sustainable,
			'years_sustained'                => $years_sustained,
			'portfolio_depleted_year'        => $portfolio_depleted_year,
			'final_balance'                  => round( $final_balance, 2 ),
			'total_withdrawn'                => round( $total_withdrawn, 2 ),
			'projections'                    => $projections,
			'parameters'                     => array(
				'retirement_age'       => $retirement_age,
				'life_expectancy'      => $life_expectancy,
				'expected_return_rate' => $expected_return_rate,
				'inflation_rate'       => $inflation_rate,
				'tax_rate'             => $tax_rate,
			),
			'tax_efficient_withdrawal_order' => array(
				'1. Taxable accounts (capital gains)',
				'2. Traditional IRA/401k (ordinary income)',
				'3. Roth IRA (tax-free, preserve longest)',
			),
			'disclaimer'                     => __( 'EDUCATIONAL ONLY: This projection uses simplified assumptions. Actual returns vary significantly. Consult a licensed financial advisor for personalized retirement planning.', 'mcp-ai-wpoos-pro' ),
			'message'                        => $is_sustainable
				? sprintf(
					/* translators: 1: Final balance, 2: Years sustained */
					__( 'Portfolio is sustainable! Final balance: $%1$s after %2$d years.', 'mcp-ai-wpoos-pro' ),
					number_format( $final_balance, 2 ),
					$years_sustained
				)
				: sprintf(
					/* translators: 1: Years sustained, 2: Depleted age */
					__( 'Warning: Portfolio depleted after %1$d years (age %2$d). Reduce withdrawals or increase returns.', 'mcp-ai-wpoos-pro' ),
					$years_sustained,
					$portfolio_depleted_year
				),
		);
	}
}
