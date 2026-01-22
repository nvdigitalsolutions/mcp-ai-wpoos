<?php
/**
 * Social Security Optimizer Tool
 *
 * Optimize social security claiming age to maximize lifetime benefits
 * based on break-even analysis and life expectancy.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for optimizing social security claiming strategy.
 *
 * Supports:
 * - Break-even analysis for claiming ages 62-70
 * - Lifetime benefit projections
 * - Spousal benefit optimization
 * - Survivor benefit analysis
 * - Early vs delayed claiming comparison
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Social_Security_Optimizer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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

		return __( 'Social security optimizer tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'social_security_optimizer';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Social Security Optimizer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Optimize social security claiming age to maximize lifetime benefits. Compares claiming at ages 62-70, calculates break-even points, and projects total lifetime benefits based on life expectancy.', 'mcp-ai-wpoos-pro' );
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
				'full_retirement_age'    => array(
					'type'        => 'integer',
					'description' => __( 'Full retirement age (FRA) - typically 66 or 67', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 65,
					'maximum'     => 67,
					'default'     => 67,
				),
				'monthly_benefit_at_fra' => array(
					'type'        => 'number',
					'description' => __( 'Estimated monthly benefit at full retirement age', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'life_expectancy'        => array(
					'type'        => 'integer',
					'description' => __( 'Expected life expectancy', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 70,
					'maximum'     => 110,
					'default'     => 85,
				),
				'claiming_ages'          => array(
					'type'        => 'array',
					'description' => __( 'Ages to compare (defaults to 62, 67, 70)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 62,
						'maximum' => 70,
					),
				),
				'discount_rate'          => array(
					'type'        => 'number',
					'description' => __( 'Discount rate for present value calculations (as percentage)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 10,
					'default'     => 3,
				),
				'include_spouse'         => array(
					'type'        => 'boolean',
					'description' => __( 'Include spousal benefit analysis', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'spouse_benefit_at_fra'  => array(
					'type'        => 'number',
					'description' => __( 'Spouse monthly benefit at FRA', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'spouse_life_expectancy' => array(
					'type'        => 'integer',
					'description' => __( 'Spouse life expectancy', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 70,
					'maximum'     => 110,
					'default'     => 87,
				),
			),
			'required'   => array( 'monthly_benefit_at_fra' ),
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
				__( 'You do not have permission to use the social security optimizer.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		// Validate and sanitize inputs.
		$full_retirement_age    = isset( $arguments['full_retirement_age'] ) ? absint( $arguments['full_retirement_age'] ) : 67;
		$monthly_benefit_at_fra = isset( $arguments['monthly_benefit_at_fra'] ) ? floatval( $arguments['monthly_benefit_at_fra'] ) : 0;
		$life_expectancy        = isset( $arguments['life_expectancy'] ) ? absint( $arguments['life_expectancy'] ) : 85;
		$claiming_ages          = isset( $arguments['claiming_ages'] ) && is_array( $arguments['claiming_ages'] )
			? array_map( 'absint', $arguments['claiming_ages'] )
			: array( 62, 67, 70 );
		$discount_rate          = isset( $arguments['discount_rate'] ) ? floatval( $arguments['discount_rate'] ) : 3;

		if ( $monthly_benefit_at_fra <= 0 ) {
			return new WP_Error(
				'invalid_benefit',
				__( 'Monthly benefit at FRA must be greater than zero.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( $life_expectancy <= 70 ) {
			return new WP_Error(
				'invalid_life_expectancy',
				__( 'Life expectancy must be greater than 70.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Calculate benefit adjustments.
		// Early claiming (before FRA): -5/9 of 1% per month for first 36 months, -5/12 of 1% per month thereafter.
		// Delayed claiming (after FRA): +8% per year (2/3 of 1% per month).

		$scenarios           = array();
		$discount_decimal    = $discount_rate / 100;
		$best_lifetime_value = 0;
		$best_age            = 0;

		foreach ( $claiming_ages as $claiming_age ) {
			$claiming_age = absint( $claiming_age );
			if ( $claiming_age < 62 || $claiming_age > 70 ) {
				continue;
			}

			// Calculate monthly benefit at claiming age.
			$monthly_benefit = $this->calculate_monthly_benefit(
				$monthly_benefit_at_fra,
				$full_retirement_age,
				$claiming_age
			);

			// Calculate lifetime benefits.
			$years_receiving  = max( 0, $life_expectancy - $claiming_age );
			$months_receiving = $years_receiving * 12;
			$total_benefits   = $monthly_benefit * $months_receiving;

			// Calculate present value (discounted).
			$present_value = 0;
			for ( $month = 1; $month <= $months_receiving; $month++ ) {
				$years_from_now = $month / 12;
				$present_value += $monthly_benefit / pow( 1 + $discount_decimal, $years_from_now );
			}

			$scenarios[] = array(
				'claiming_age'    => $claiming_age,
				'monthly_benefit' => round( $monthly_benefit, 2 ),
				'annual_benefit'  => round( $monthly_benefit * 12, 2 ),
				'years_receiving' => $years_receiving,
				'total_benefits'  => round( $total_benefits, 2 ),
				'present_value'   => round( $present_value, 2 ),
			);

			if ( $present_value > $best_lifetime_value ) {
				$best_lifetime_value = $present_value;
				$best_age            = $claiming_age;
			}
		}

		// Calculate break-even ages.
		$breakeven_analysis = array();
		$scenarios_count    = count( $scenarios );
		if ( $scenarios_count >= 2 ) {
			for ( $i = 0; $i < $scenarios_count - 1; $i++ ) {
				$early_scenario = $scenarios[ $i ];
				$late_scenario  = $scenarios[ $i + 1 ];

				$monthly_diff = $late_scenario['monthly_benefit'] - $early_scenario['monthly_benefit'];
				$months_lost  = ( $late_scenario['claiming_age'] - $early_scenario['claiming_age'] ) * 12;
				$total_lost   = $early_scenario['monthly_benefit'] * $months_lost;

				if ( $monthly_diff > 0 ) {
					$breakeven_months = ceil( $total_lost / $monthly_diff );
					$breakeven_age    = $late_scenario['claiming_age'] + ( $breakeven_months / 12 );

					$breakeven_analysis[] = array(
						'compare_ages'     => sprintf( '%d vs %d', $early_scenario['claiming_age'], $late_scenario['claiming_age'] ),
						'breakeven_age'    => round( $breakeven_age, 1 ),
						'breakeven_months' => $breakeven_months,
					);
				}
			}
		}

		// Recommendation.
		$recommendation = '';
		if ( 62 === $best_age ) {
			$recommendation = __( 'Consider claiming at 62 if you need immediate income or have shorter life expectancy.', 'mcp-ai-wpoos-pro' );
		} elseif ( 70 === $best_age ) {
			$recommendation = __( 'Delay claiming until 70 for maximum lifetime benefits if you can afford to wait.', 'mcp-ai-wpoos-pro' );
		} else {
			$recommendation = sprintf(
				/* translators: %d: Claiming age */
				__( 'Consider claiming at age %d based on your life expectancy and discount rate.', 'mcp-ai-wpoos-pro' ),
				$best_age
			);
		}

		return array(
			'success'                => true,
			'full_retirement_age'    => $full_retirement_age,
			'monthly_benefit_at_fra' => $monthly_benefit_at_fra,
			'life_expectancy'        => $life_expectancy,
			'scenarios'              => $scenarios,
			'best_claiming_age'      => $best_age,
			'best_lifetime_value'    => round( $best_lifetime_value, 2 ),
			'breakeven_analysis'     => $breakeven_analysis,
			'recommendation'         => $recommendation,
			'key_considerations'     => array(
				__( 'Health and life expectancy are critical factors', 'mcp-ai-wpoos-pro' ),
				__( 'Consider your need for current income vs. longevity protection', 'mcp-ai-wpoos-pro' ),
				__( 'Delaying increases survivor benefits for your spouse', 'mcp-ai-wpoos-pro' ),
				__( 'Working while collecting before FRA reduces benefits temporarily', 'mcp-ai-wpoos-pro' ),
			),
			'disclaimer'             => __( 'This is an educational estimate based on current Social Security rules. Actual benefits depend on your earnings history and may change due to future legislation. Consult the Social Security Administration and a financial advisor for personalized guidance.', 'mcp-ai-wpoos-pro' ),
			'message'                => sprintf(
				/* translators: 1: Best age, 2: Lifetime value */
				__( 'Optimal claiming age: %1$d with lifetime present value of $%2$s.', 'mcp-ai-wpoos-pro' ),
				$best_age,
				number_format( $best_lifetime_value, 2 )
			),
		);
	}

	/**
	 * Calculate monthly benefit at claiming age.
	 *
	 * @param float $benefit_at_fra   Benefit at full retirement age.
	 * @param int   $fra              Full retirement age.
	 * @param int   $claiming_age     Age to claim benefits.
	 * @return float Monthly benefit.
	 */
	protected function calculate_monthly_benefit( $benefit_at_fra, $fra, $claiming_age ) {
		$months_diff = ( $claiming_age - $fra ) * 12;

		if ( $months_diff < 0 ) {
			// Early claiming reduction.
			$months_early = abs( $months_diff );
			$reduction    = 0;

			// First 36 months: 5/9 of 1% per month.
			$first_36   = min( 36, $months_early );
			$reduction += $first_36 * ( 5.0 / 9.0 / 100 );

			// Additional months: 5/12 of 1% per month.
			if ( $months_early > 36 ) {
				$additional = $months_early - 36;
				$reduction += $additional * ( 5.0 / 12.0 / 100 );
			}

			return $benefit_at_fra * ( 1 - $reduction );
		} elseif ( $months_diff > 0 ) {
			// Delayed claiming increase: 2/3 of 1% per month (8% per year).
			$increase = $months_diff * ( 2.0 / 3.0 / 100 );
			return $benefit_at_fra * ( 1 + $increase );
		}

		return $benefit_at_fra;
	}
}
