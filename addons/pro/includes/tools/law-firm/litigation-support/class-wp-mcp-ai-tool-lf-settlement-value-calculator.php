<?php
/**
 * Settlement Value Calculator Tool
 *
 * Calculates recommended settlement value ranges using expected value analysis,
 * liability probability, trial costs, and time-value discounting.
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
 * Calculates settlement value ranges using expected value and risk analysis.
 */
class WP_MCP_AI_Tool_LF_Settlement_Value_Calculator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'lf_settlement_value_calculator';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Settlement Value Calculator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Calculates recommended settlement value ranges using expected value analysis, liability probability, trial cost estimates, and time-value discounting.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'claim_type'            => array(
					'type'        => 'string',
					'description' => __( 'Type of legal claim (e.g., personal_injury, breach_of_contract).', 'mcp-ai-wpoos-pro' ),
				),
				'economic_damages'      => array(
					'type'        => 'number',
					'description' => __( 'Total economic damages amount.', 'mcp-ai-wpoos-pro' ),
				),
				'non_economic_damages'  => array(
					'type'        => 'number',
					'description' => __( 'Total non-economic damages amount.', 'mcp-ai-wpoos-pro' ),
				),
				'liability_probability' => array(
					'type'        => 'number',
					'description' => __( 'Probability of prevailing on liability (0 to 1).', 'mcp-ai-wpoos-pro' ),
				),
				'trial_cost_estimate'   => array(
					'type'        => 'number',
					'description' => __( 'Estimated cost to take the case to trial.', 'mcp-ai-wpoos-pro' ),
				),
				'time_to_trial_months'  => array(
					'type'        => 'integer',
					'description' => __( 'Estimated months until trial.', 'mcp-ai-wpoos-pro' ),
				),
				'discount_rate'         => array(
					'type'        => 'number',
					'description' => __( 'Annual discount rate for present value calculation (default 0.05).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0.05,
				),
			),
			'required'   => array( 'claim_type', 'economic_damages', 'liability_probability' ),
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
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$claim_type            = isset( $arguments['claim_type'] ) ? sanitize_text_field( $arguments['claim_type'] ) : '';
		$economic_damages      = isset( $arguments['economic_damages'] ) ? floatval( $arguments['economic_damages'] ) : 0;
		$non_economic_damages  = isset( $arguments['non_economic_damages'] ) ? floatval( $arguments['non_economic_damages'] ) : 0;
		$liability_probability = isset( $arguments['liability_probability'] ) ? floatval( $arguments['liability_probability'] ) : 0;
		$trial_cost_estimate   = isset( $arguments['trial_cost_estimate'] ) ? floatval( $arguments['trial_cost_estimate'] ) : 0;
		$time_to_trial_months  = isset( $arguments['time_to_trial_months'] ) ? absint( $arguments['time_to_trial_months'] ) : 12;
		$discount_rate         = isset( $arguments['discount_rate'] ) ? floatval( $arguments['discount_rate'] ) : 0.05;

		if ( empty( $claim_type ) || $economic_damages <= 0 ) {
			return new WP_Error( 'missing_required', __( 'Claim type and economic damages are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$liability_probability = max( 0, min( 1, $liability_probability ) );
		$discount_rate         = max( 0, min( 0.25, $discount_rate ) );

		$total_damages = $economic_damages + $non_economic_damages;

		// Expected value at trial.
		$expected_value_gross = round( $total_damages * $liability_probability, 2 );
		$expected_value_net   = round( $expected_value_gross - $trial_cost_estimate, 2 );

		// Present value discount for time to trial.
		$years_to_trial      = $time_to_trial_months / 12;
		$present_value_total = WP_MCP_AI_Law_Firm_Calculator::calculate_present_value(
			$expected_value_net,
			$discount_rate,
			max( 1, (int) ceil( $years_to_trial ) )
		);

		// Settlement range — low end is conservative, high end factors in trial upside.
		$range_low  = round( max( 0, $present_value_total * 0.75 ), 2 );
		$range_high = round( $expected_value_gross * 0.95, 2 );
		if ( $range_low > $range_high ) {
			$range_high = $range_low * 1.3;
		}

		// Risk assessment.
		if ( $liability_probability >= 0.75 ) {
			$risk_level = 'low';
			$risk_note  = __( 'Strong liability position supports higher settlement demands.', 'mcp-ai-wpoos-pro' );
		} elseif ( $liability_probability >= 0.50 ) {
			$risk_level = 'moderate';
			$risk_note  = __( 'Moderate liability position suggests settlement is advisable.', 'mcp-ai-wpoos-pro' );
		} elseif ( $liability_probability >= 0.25 ) {
			$risk_level = 'high';
			$risk_note  = __( 'Weak liability position increases risk of adverse outcome at trial.', 'mcp-ai-wpoos-pro' );
		} else {
			$risk_level = 'very_high';
			$risk_note  = __( 'Very weak liability position — early resolution strongly recommended.', 'mcp-ai-wpoos-pro' );
		}

		// Cost-benefit analysis.
		$settlement_savings = round( $trial_cost_estimate + ( $total_damages * ( 1 - $liability_probability ) ), 2 );

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: low range formatted, 2: high range formatted */
				__( 'Recommended settlement range: %1$s to %2$s. ', 'mcp-ai-wpoos-pro' ),
				WP_MCP_AI_Law_Firm_Calculator::format_currency( $range_low ),
				WP_MCP_AI_Law_Firm_Calculator::format_currency( $range_high )
			) . self::DISCLAIMER,
			'data'       => array(
				'claim_type'        => $claim_type,
				'recommended_range' => array(
					'low'  => $range_low,
					'high' => $range_high,
				),
				'expected_value'    => array(
					'gross'         => $expected_value_gross,
					'net_of_costs'  => $expected_value_net,
					'present_value' => $present_value_total,
				),
				'risk_assessment'   => array(
					'level'                 => $risk_level,
					'liability_probability' => $liability_probability,
					'note'                  => $risk_note,
				),
				'analysis_factors'  => array(
					'total_damages'        => $total_damages,
					'economic_damages'     => $economic_damages,
					'non_economic_damages' => $non_economic_damages,
					'trial_cost_estimate'  => $trial_cost_estimate,
					'time_to_trial_months' => $time_to_trial_months,
					'discount_rate'        => $discount_rate,
					'settlement_savings'   => $settlement_savings,
				),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
