<?php
/**
 * Damages Calculator Tool
 *
 * Calculates economic, non-economic, and punitive damages with present value
 * analysis using the shared Law Firm Calculator engine.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/class-wp-mcp-ai-law-firm-calculator.php';

/**
 * Calculates litigation damages with present value analysis.
 */
class WP_MCP_AI_Tool_LF_Damages_Calculator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_law_firm_toolkit'] );
	}

	/**
	 * Get the reason the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason(): string {
		return __( 'Law Firm toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'lf_damages_calculator';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Damages Calculator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Calculates economic, non-economic, and punitive damages with present value analysis. Uses industry-standard formulas for lost wages, medical expenses, and future damages.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'damages_type'         => array(
					'type'        => 'string',
					'description' => __( 'Type of damages to calculate.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'economic', 'non_economic', 'punitive', 'all' ),
				),
				'lost_wages_annual'    => array(
					'type'        => 'number',
					'description' => __( 'Annual lost wages amount.', 'mcp-ai-wpoos-pro' ),
				),
				'medical_expenses'     => array(
					'type'        => 'number',
					'description' => __( 'Past medical expenses incurred.', 'mcp-ai-wpoos-pro' ),
				),
				'future_medical'       => array(
					'type'        => 'number',
					'description' => __( 'Estimated future medical expenses.', 'mcp-ai-wpoos-pro' ),
				),
				'work_years_remaining' => array(
					'type'        => 'integer',
					'description' => __( 'Number of working years remaining.', 'mcp-ai-wpoos-pro' ),
				),
				'pain_multiplier'      => array(
					'type'        => 'number',
					'description' => __( 'Multiplier for pain and suffering (default 3).', 'mcp-ai-wpoos-pro' ),
					'default'     => 3,
				),
				'discount_rate'        => array(
					'type'        => 'number',
					'description' => __( 'Annual discount rate for present value calculations (default 0.04).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0.04,
				),
				'wage_growth_rate'     => array(
					'type'        => 'number',
					'description' => __( 'Annual wage growth rate (default 0.03).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0.03,
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only', 'cacheable' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$damages_type         = isset( $arguments['damages_type'] ) ? sanitize_text_field( $arguments['damages_type'] ) : 'all';
		$lost_wages_annual    = isset( $arguments['lost_wages_annual'] ) ? floatval( $arguments['lost_wages_annual'] ) : 0;
		$medical_expenses     = isset( $arguments['medical_expenses'] ) ? floatval( $arguments['medical_expenses'] ) : 0;
		$future_medical       = isset( $arguments['future_medical'] ) ? floatval( $arguments['future_medical'] ) : 0;
		$work_years_remaining = isset( $arguments['work_years_remaining'] ) ? absint( $arguments['work_years_remaining'] ) : 0;
		$pain_multiplier      = isset( $arguments['pain_multiplier'] ) ? floatval( $arguments['pain_multiplier'] ) : 3;
		$discount_rate        = isset( $arguments['discount_rate'] ) ? floatval( $arguments['discount_rate'] ) : 0.04;
		$wage_growth_rate     = isset( $arguments['wage_growth_rate'] ) ? floatval( $arguments['wage_growth_rate'] ) : 0.03;

		$pain_multiplier  = max( 1, min( 10, $pain_multiplier ) );
		$discount_rate    = max( 0, min( 0.20, $discount_rate ) );
		$wage_growth_rate = max( 0, min( 0.10, $wage_growth_rate ) );

		$economic_breakdown = array();
		$total_economic     = 0;
		$total_non_economic = 0;
		$total_punitive     = 0;
		$present_value_data = array();

		// Economic damages calculation.
		if ( 'economic' === $damages_type || 'all' === $damages_type ) {
			// Past medical expenses.
			if ( $medical_expenses > 0 ) {
				$economic_breakdown['past_medical'] = round( $medical_expenses, 2 );
				$total_economic                    += $medical_expenses;
			}

			// Future medical expenses (present value).
			if ( $future_medical > 0 ) {
				$pv_future_medical                       = WP_MCP_AI_Law_Firm_Calculator::calculate_present_value(
					$future_medical,
					$discount_rate,
					max( 1, $work_years_remaining > 0 ? $work_years_remaining : 5 )
				);
				$economic_breakdown['future_medical']    = round( $future_medical, 2 );
				$economic_breakdown['future_medical_pv'] = $pv_future_medical;
				$total_economic                         += $pv_future_medical;

				$present_value_data['future_medical'] = array(
					'nominal'       => round( $future_medical, 2 ),
					'present_value' => $pv_future_medical,
					'discount_rate' => $discount_rate,
				);
			}

			// Lost wages calculation using the calculator engine.
			if ( $lost_wages_annual > 0 && $work_years_remaining > 0 ) {
				$wages_result = WP_MCP_AI_Law_Firm_Calculator::calculate_damages(
					$lost_wages_annual,
					$work_years_remaining,
					$discount_rate,
					$wage_growth_rate
				);

				$economic_breakdown['lost_wages_annual']       = round( $lost_wages_annual, 2 );
				$economic_breakdown['lost_wages_total_pv']     = $wages_result['total_present_value'];
				$economic_breakdown['lost_wages_undiscounted'] = $wages_result['undiscounted_total'];
				$total_economic                               += $wages_result['total_present_value'];

				$present_value_data['lost_wages'] = array(
					'annual_amount'   => round( $lost_wages_annual, 2 ),
					'years_remaining' => $work_years_remaining,
					'present_value'   => $wages_result['total_present_value'],
					'undiscounted'    => $wages_result['undiscounted_total'],
					'growth_rate'     => $wage_growth_rate,
					'discount_rate'   => $discount_rate,
					'schedule'        => array_slice( $wages_result['schedule'], 0, 5 ),
				);
			}

			$economic_breakdown['total_economic'] = round( $total_economic, 2 );
		}

		// Non-economic damages calculation.
		if ( 'non_economic' === $damages_type || 'all' === $damages_type ) {
			$base_for_multiplier = $medical_expenses + $future_medical;
			if ( $base_for_multiplier > 0 ) {
				$total_non_economic = round( $base_for_multiplier * $pain_multiplier, 2 );
			} elseif ( $total_economic > 0 ) {
				$total_non_economic = round( $total_economic * ( $pain_multiplier * 0.5 ), 2 );
			}
		}

		// Punitive damages estimate.
		if ( 'punitive' === $damages_type || 'all' === $damages_type ) {
			$compensatory_base = $total_economic + $total_non_economic;
			if ( $compensatory_base > 0 ) {
				// Constitutional guideline: single-digit ratio.
				$total_punitive = round( $compensatory_base * min( $pain_multiplier, 9 ), 2 );
			}
		}

		$total_damages = round( $total_economic + $total_non_economic + $total_punitive, 2 );

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: total damages formatted */
				__( 'Total estimated damages: %1$s. ', 'mcp-ai-wpoos-pro' ),
				WP_MCP_AI_Law_Firm_Calculator::format_currency( $total_damages )
			) . self::DISCLAIMER,
			'data'       => array(
				'total_damages'          => $total_damages,
				'economic_breakdown'     => $economic_breakdown,
				'non_economic_damages'   => $total_non_economic,
				'punitive_damages'       => $total_punitive,
				'present_value_analysis' => $present_value_data,
				'parameters'             => array(
					'damages_type'         => $damages_type,
					'pain_multiplier'      => $pain_multiplier,
					'discount_rate'        => $discount_rate,
					'wage_growth_rate'     => $wage_growth_rate,
					'work_years_remaining' => $work_years_remaining,
				),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
