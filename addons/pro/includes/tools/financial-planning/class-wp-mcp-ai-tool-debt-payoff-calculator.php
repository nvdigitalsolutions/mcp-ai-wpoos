<?php
/**
 * Debt Payoff Calculator Tool
 *
 * Calculate optimal debt payoff strategies using avalanche (highest interest)
 * or snowball (smallest balance) methods with detailed payment schedules.
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
 * Tool for calculating debt payoff strategies.
 *
 * Supports:
 * - Avalanche method (highest interest first)
 * - Snowball method (smallest balance first)
 * - Custom payment amounts
 * - Total interest comparison
 * - Payoff timeline projections
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Debt_Payoff_Calculator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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

		return __( 'Debt payoff calculator tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'debt_payoff_calculator';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Debt Payoff Calculator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Calculate debt payoff strategies using avalanche or snowball methods. Compare total interest paid, payoff timelines, and monthly payment schedules. Helps optimize debt elimination plans.', 'mcp-ai-wpoos-pro' );
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
				'debts'         => array(
					'type'        => 'array',
					'description' => __( 'List of debts to pay off', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'            => array(
								'type'        => 'string',
								'description' => __( 'Debt name or account', 'mcp-ai-wpoos-pro' ),
							),
							'balance'         => array(
								'type'        => 'number',
								'description' => __( 'Current balance', 'mcp-ai-wpoos-pro' ),
								'minimum'     => 0,
							),
							'interest_rate'   => array(
								'type'        => 'number',
								'description' => __( 'Annual interest rate (as percentage)', 'mcp-ai-wpoos-pro' ),
								'minimum'     => 0,
								'maximum'     => 100,
							),
							'minimum_payment' => array(
								'type'        => 'number',
								'description' => __( 'Minimum monthly payment', 'mcp-ai-wpoos-pro' ),
								'minimum'     => 0,
							),
						),
					),
				),
				'extra_payment' => array(
					'type'        => 'number',
					'description' => __( 'Extra monthly payment to apply', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'strategy'      => array(
					'type'        => 'string',
					'description' => __( 'Payoff strategy', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'avalanche', 'snowball', 'compare' ),
					'default'     => 'compare',
				),
			),
			'required'   => array( 'debts' ),
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
				__( 'You do not have permission to use the debt payoff calculator.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$debts         = isset( $arguments['debts'] ) && is_array( $arguments['debts'] ) ? $arguments['debts'] : array();
		$extra_payment = isset( $arguments['extra_payment'] ) ? floatval( $arguments['extra_payment'] ) : 0;
		$strategy      = isset( $arguments['strategy'] ) ? sanitize_text_field( $arguments['strategy'] ) : 'compare';

		if ( empty( $debts ) ) {
			return new WP_Error( 'empty_debts', __( 'At least one debt is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = array( 'success' => true );

		if ( 'compare' === $strategy ) {
			$avalanche = $this->calculate_payoff( $debts, $extra_payment, 'avalanche' );
			$snowball  = $this->calculate_payoff( $debts, $extra_payment, 'snowball' );

			$result['avalanche'] = $avalanche;
			$result['snowball']  = $snowball;

			$interest_savings = $snowball['total_interest'] - $avalanche['total_interest'];
			$time_savings     = $snowball['months_to_payoff'] - $avalanche['months_to_payoff'];

			$result['comparison'] = array(
				'interest_savings'    => round( $interest_savings, 2 ),
				'time_savings_months' => $time_savings,
				'recommended'         => $interest_savings > 100 ? 'avalanche' : 'snowball',
			);

			$result['message'] = sprintf(
				/* translators: 1: Interest savings, 2: Time savings in months */
				__( 'Avalanche method saves $%1$s in interest and %2$d months compared to snowball.', 'mcp-ai-wpoos-pro' ),
				number_format( $interest_savings, 2 ),
				$time_savings
			);
		} else {
			$result            = array_merge( $result, $this->calculate_payoff( $debts, $extra_payment, $strategy ) );
			$result['message'] = sprintf(
				/* translators: 1: Months, 2: Total interest */
				__( 'Payoff complete in %1$d months with $%2$s total interest.', 'mcp-ai-wpoos-pro' ),
				$result['months_to_payoff'],
				number_format( $result['total_interest'], 2 )
			);
		}

		return $result;
	}

	/**
	 * Calculate debt payoff schedule.
	 *
	 * @param array  $debts         Debts to pay off.
	 * @param float  $extra_payment Extra monthly payment.
	 * @param string $strategy      Strategy (avalanche or snowball).
	 * @return array Payoff schedule.
	 */
	protected function calculate_payoff( $debts, $extra_payment, $strategy ) {
		$debts_working = array();
		foreach ( $debts as $debt ) {
			$debts_working[] = array(
				'name'            => sanitize_text_field( $debt['name'] ),
				'balance'         => floatval( $debt['balance'] ),
				'interest_rate'   => floatval( $debt['interest_rate'] ),
				'minimum_payment' => floatval( $debt['minimum_payment'] ),
			);
		}

		if ( 'avalanche' === $strategy ) {
			usort(
				$debts_working,
				function ( $a, $b ) {
					return $b['interest_rate'] <=> $a['interest_rate'];
				}
			);
		} else {
			usort(
				$debts_working,
				function ( $a, $b ) {
					return $a['balance'] <=> $b['balance'];
				}
			);
		}

		$total_interest = 0;
		$months         = 0;
		$schedule       = array();

		while ( ! empty( $debts_working ) ) {
			++$months;
			$total_minimum   = array_sum( array_column( $debts_working, 'minimum_payment' ) );
			$available_extra = $extra_payment;

			foreach ( $debts_working as $key => &$debt ) {
				$monthly_rate    = ( $debt['interest_rate'] / 100 ) / 12;
				$interest_charge = $debt['balance'] * $monthly_rate;
				$total_interest += $interest_charge;

				$payment = $debt['minimum_payment'];
				if ( 0 === $key ) {
					$payment        += $available_extra;
					$available_extra = 0;
				}

				$principal       = $payment - $interest_charge;
				$debt['balance'] = max( 0, $debt['balance'] - $principal );

				if ( 0.0 === $debt['balance'] ) {
					$schedule[] = array(
						'month' => $months,
						'debt'  => $debt['name'],
						'event' => 'paid_off',
					);
					unset( $debts_working[ $key ] );
				}
			}

			$debts_working = array_values( $debts_working );

			if ( $months > 600 ) {
				break;
			}
		}

		return array(
			'strategy'         => $strategy,
			'months_to_payoff' => $months,
			'years_to_payoff'  => round( $months / 12, 1 ),
			'total_interest'   => round( $total_interest, 2 ),
			'payoff_schedule'  => $schedule,
		);
	}
}
