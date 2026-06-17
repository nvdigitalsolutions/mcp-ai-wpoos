<?php
/**
 * Pension Analyzer Tool
 *
 * Analyze pension payout options (lump sum vs annuity) to determine
 * the best choice based on life expectancy and investment returns.
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
 * Tool for analyzing pension payout options.
 *
 * Supports:
 * - Lump sum vs annuity comparison
 * - Present value calculations
 * - Break-even analysis
 * - Survivor benefit considerations
 * - Investment return scenarios
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Pension_Analyzer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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

		return __( 'Pension analyzer tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'pension_analyzer';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Pension Analyzer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Analyze pension payout options to determine the best choice. Compares lump sum vs annuity payments, calculates present values, break-even ages, and considers survivor benefits and investment scenarios.', 'mcp-ai-wpoos-pro' );
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
				'lump_sum_offer'             => array(
					'type'        => 'number',
					'description' => __( 'Lump sum payout offer', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'monthly_annuity'            => array(
					'type'        => 'number',
					'description' => __( 'Monthly annuity payment', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'current_age'                => array(
					'type'        => 'integer',
					'description' => __( 'Current age', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 50,
					'maximum'     => 80,
				),
				'life_expectancy'            => array(
					'type'        => 'integer',
					'description' => __( 'Expected life expectancy', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 60,
					'maximum'     => 110,
					'default'     => 85,
				),
				'discount_rate'              => array(
					'type'        => 'number',
					'description' => __( 'Discount rate for present value (as percentage)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 10,
					'default'     => 4,
				),
				'expected_investment_return' => array(
					'type'        => 'number',
					'description' => __( 'Expected investment return on lump sum (as percentage)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 20,
					'default'     => 6,
				),
				'annuity_type'               => array(
					'type'        => 'string',
					'description' => __( 'Type of annuity', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'single_life', 'joint_survivor_100', 'joint_survivor_50' ),
					'default'     => 'single_life',
				),
				'spouse_age'                 => array(
					'type'        => 'integer',
					'description' => __( 'Spouse age (for joint annuities)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 40,
					'maximum'     => 90,
				),
				'cola_adjustment'            => array(
					'type'        => 'number',
					'description' => __( 'Annual cost of living adjustment (COLA) on annuity (as percentage)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 5,
					'default'     => 0,
				),
			),
			'required'   => array( 'lump_sum_offer', 'monthly_annuity', 'current_age' ),
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
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use the pension analyzer.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		// Validate and sanitize inputs.
		$lump_sum_offer             = isset( $arguments['lump_sum_offer'] ) ? floatval( $arguments['lump_sum_offer'] ) : 0;
		$monthly_annuity            = isset( $arguments['monthly_annuity'] ) ? floatval( $arguments['monthly_annuity'] ) : 0;
		$current_age                = isset( $arguments['current_age'] ) ? absint( $arguments['current_age'] ) : 0;
		$life_expectancy            = isset( $arguments['life_expectancy'] ) ? absint( $arguments['life_expectancy'] ) : 85;
		$discount_rate              = isset( $arguments['discount_rate'] ) ? floatval( $arguments['discount_rate'] ) : 4;
		$expected_investment_return = isset( $arguments['expected_investment_return'] ) ? floatval( $arguments['expected_investment_return'] ) : 6;
		$cola_adjustment            = isset( $arguments['cola_adjustment'] ) ? floatval( $arguments['cola_adjustment'] ) : 0;

		if ( $lump_sum_offer <= 0 || $monthly_annuity <= 0 ) {
			return new WP_Error(
				'invalid_amounts',
				__( 'Both lump sum and monthly annuity must be greater than zero.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( $current_age >= $life_expectancy ) {
			return new WP_Error(
				'invalid_ages',
				__( 'Life expectancy must be greater than current age.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Convert rates to decimal.
		$discount_decimal = $discount_rate / 100;
		$return_decimal   = $expected_investment_return / 100;
		$cola_decimal     = $cola_adjustment / 100;

		// Calculate present value of annuity.
		$years_receiving        = $life_expectancy - $current_age;
		$months_receiving       = $years_receiving * 12;
		$annuity_present_value  = 0;
		$annuity_total_received = 0;
		$monthly_payment        = $monthly_annuity;

		for ( $month = 1; $month <= $months_receiving; $month++ ) {
			// Apply COLA adjustment annually.
			if ( $month > 1 && 1 === $month % 12 && $cola_decimal > 0 ) {
				$monthly_payment *= ( 1 + $cola_decimal );
			}

			$years_from_now          = $month / 12;
			$annuity_present_value  += $monthly_payment / pow( 1 + $discount_decimal, $years_from_now );
			$annuity_total_received += $monthly_payment;
		}

		// Calculate future value of lump sum if invested.
		$lump_sum_future_value = $lump_sum_offer * pow( 1 + $return_decimal, $years_receiving );

		// Calculate break-even age.
		$breakeven_months   = 0;
		$cumulative_annuity = 0;
		for ( $month = 1; $month <= $months_receiving; $month++ ) {
			$cumulative_annuity += $monthly_annuity;
			if ( $cumulative_annuity >= $lump_sum_offer ) {
				$breakeven_months = $month;
				break;
			}
		}
		$breakeven_age = $current_age + ( $breakeven_months / 12 );

		// Calculate implied interest rate of annuity.
		// This is the rate at which the present value of annuity equals lump sum.
		$implied_rate = $this->calculate_implied_rate( $lump_sum_offer, $monthly_annuity, $months_receiving );

		// Determine recommendation.
		$better_option = '';
		$reason        = '';

		if ( $annuity_present_value > $lump_sum_offer * 1.1 ) {
			$better_option = 'annuity';
			$reason        = __( 'The annuity has significantly higher present value than the lump sum offer.', 'mcp-ai-wpoos-pro' );
		} elseif ( $lump_sum_offer > $annuity_present_value * 1.1 ) {
			$better_option = 'lump_sum';
			$reason        = __( 'The lump sum offer is significantly higher than the present value of the annuity.', 'mcp-ai-wpoos-pro' );
		} elseif ( $expected_investment_return > $implied_rate * 1.5 ) {
			$better_option = 'lump_sum';
			$reason        = __( 'Your expected investment return is significantly higher than the annuity\'s implied rate.', 'mcp-ai-wpoos-pro' );
		} else {
			$better_option = 'annuity';
			$reason        = __( 'The annuity provides guaranteed income and protects against longevity risk.', 'mcp-ai-wpoos-pro' );
		}

		return array(
			'success'           => true,
			'lump_sum_analysis' => array(
				'offered_amount'    => $lump_sum_offer,
				'future_value'      => round( $lump_sum_future_value, 2 ),
				'investment_return' => $expected_investment_return,
			),
			'annuity_analysis'  => array(
				'monthly_payment'       => $monthly_annuity,
				'annual_payment'        => round( $monthly_annuity * 12, 2 ),
				'total_received'        => round( $annuity_total_received, 2 ),
				'present_value'         => round( $annuity_present_value, 2 ),
				'implied_interest_rate' => round( $implied_rate, 2 ),
				'cola_adjustment'       => $cola_adjustment,
			),
			'comparison'        => array(
				'value_difference'      => round( abs( $annuity_present_value - $lump_sum_offer ), 2 ),
				'percentage_difference' => round( abs( $annuity_present_value - $lump_sum_offer ) / $lump_sum_offer * 100, 2 ),
				'breakeven_age'         => round( $breakeven_age, 1 ),
				'breakeven_years'       => round( $breakeven_months / 12, 1 ),
			),
			'recommendation'    => array(
				'better_option' => $better_option,
				'reason'        => $reason,
			),
			'considerations'    => array(
				__( 'Health and life expectancy', 'mcp-ai-wpoos-pro' ),
				__( 'Need for guaranteed income vs. investment flexibility', 'mcp-ai-wpoos-pro' ),
				__( 'Spouse/beneficiary needs', 'mcp-ai-wpoos-pro' ),
				__( 'Other retirement income sources', 'mcp-ai-wpoos-pro' ),
				__( 'Investment experience and risk tolerance', 'mcp-ai-wpoos-pro' ),
			),
			'parameters'        => array(
				'current_age'     => $current_age,
				'life_expectancy' => $life_expectancy,
				'discount_rate'   => $discount_rate,
			),
			'disclaimer'        => __( 'This is an educational analysis only. Pension decisions are irrevocable and complex. Consult a licensed financial advisor and consider tax implications before making a decision.', 'mcp-ai-wpoos-pro' ),
			'message'           => sprintf(
				/* translators: 1: Better option, 2: Value difference */
				__( 'Recommendation: Choose %1$s (difference of $%2$s in present value).', 'mcp-ai-wpoos-pro' ),
				'lump_sum' === $better_option ? __( 'lump sum', 'mcp-ai-wpoos-pro' ) : __( 'annuity', 'mcp-ai-wpoos-pro' ),
				number_format( abs( $annuity_present_value - $lump_sum_offer ), 2 )
			),
		);
	}

	/**
	 * Calculate implied interest rate of annuity.
	 *
	 * Uses iterative approximation to find the rate.
	 *
	 * @param float $lump_sum         Lump sum amount.
	 * @param float $monthly_payment  Monthly payment.
	 * @param int   $months           Number of months.
	 * @return float Implied annual rate as percentage.
	 */
	protected function calculate_implied_rate( $lump_sum, $monthly_payment, $months ) {
		// Use iterative method to find rate.
		$low       = 0;
		$high      = 0.20; // 20% annual rate.
		$tolerance = 0.0001;

		for ( $i = 0; $i < 100; $i++ ) {
			$mid          = ( $low + $high ) / 2;
			$pv           = 0;
			$monthly_rate = $mid / 12;

			for ( $month = 1; $month <= $months; $month++ ) {
				$pv += $monthly_payment / pow( 1 + $monthly_rate, $month );
			}

			if ( abs( $pv - $lump_sum ) < $tolerance ) {
				return $mid * 100;
			}

			if ( $pv > $lump_sum ) {
				$low = $mid;
			} else {
				$high = $mid;
			}
		}

		return $low * 100;
	}
}
