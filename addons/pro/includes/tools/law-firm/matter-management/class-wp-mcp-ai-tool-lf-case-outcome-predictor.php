<?php
/**
 * Case Outcome Predictor Tool
 *
 * Provides heuristic-based case outcome predictions using practice area, value,
 * jurisdiction, complexity, and liability strength.
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

/**
 * Predicts case outcomes using scoring heuristics.
 */
class WP_MCP_AI_Tool_LF_Case_Outcome_Predictor implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'lf_case_outcome_predictor';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Case Outcome Predictor', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Provides heuristic-based case outcome predictions based on practice area, estimated case value, jurisdiction, complexity, and liability strength. Returns predicted outcome, confidence level, estimated duration, and value range.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'practice_area'       => array(
					'type'        => 'string',
					'description' => __( 'Area of law for the case.', 'mcp-ai-wpoos-pro' ),
				),
				'case_value_estimate' => array(
					'type'        => 'number',
					'description' => __( 'Estimated monetary value of the case.', 'mcp-ai-wpoos-pro' ),
				),
				'jurisdiction'        => array(
					'type'        => 'string',
					'description' => __( 'Jurisdiction (e.g., state abbreviation or federal).', 'mcp-ai-wpoos-pro' ),
				),
				'case_complexity'     => array(
					'type'        => 'string',
					'description' => __( 'Complexity level of the case.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'simple', 'moderate', 'complex' ),
				),
				'liability_strength'  => array(
					'type'        => 'string',
					'description' => __( 'Strength of the liability position.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'weak', 'moderate', 'strong' ),
				),
			),
			'required'   => array( 'practice_area', 'case_value_estimate' ),
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

		$practice_area = isset( $arguments['practice_area'] ) ? sanitize_text_field( $arguments['practice_area'] ) : '';
		$case_value    = isset( $arguments['case_value_estimate'] ) ? floatval( $arguments['case_value_estimate'] ) : 0;
		$jurisdiction  = isset( $arguments['jurisdiction'] ) ? sanitize_text_field( $arguments['jurisdiction'] ) : 'federal';
		$complexity    = isset( $arguments['case_complexity'] ) ? sanitize_text_field( $arguments['case_complexity'] ) : 'moderate';
		$liability     = isset( $arguments['liability_strength'] ) ? sanitize_text_field( $arguments['liability_strength'] ) : 'moderate';

		if ( empty( $practice_area ) || $case_value <= 0 ) {
			return new WP_Error( 'missing_required', __( 'Practice area and case value estimate are required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Liability strength score (0-40).
		$liability_scores = array(
			'strong'   => 40,
			'moderate' => 25,
			'weak'     => 10,
		);
		$score            = $liability_scores[ $liability ] ?? 25;

		// Complexity adjustment (-15 to +15).
		$complexity_adj = array(
			'simple'   => 15,
			'moderate' => 0,
			'complex'  => -15,
		);
		$score         += $complexity_adj[ $complexity ] ?? 0;

		// Case value factor (0-25).
		if ( $case_value >= 1000000 ) {
			$score += 15;
		} elseif ( $case_value >= 100000 ) {
			$score += 25;
		} elseif ( $case_value >= 10000 ) {
			$score += 20;
		} else {
			$score += 10;
		}

		// Practice area settlement likelihood (0-20).
		$settlement_rates = array(
			'personal_injury'     => 20,
			'medical_malpractice' => 12,
			'breach_of_contract'  => 18,
			'property_damage'     => 17,
			'employment'          => 16,
			'family'              => 15,
			'corporate'           => 14,
			'ip'                  => 13,
			'criminal'            => 5,
			'immigration'         => 8,
		);
		$score           += $settlement_rates[ $practice_area ] ?? 14;

		$score = min( 100, max( 0, $score ) );

		// Determine prediction.
		if ( $score >= 75 ) {
			$predicted_outcome = __( 'Favorable settlement or judgment likely', 'mcp-ai-wpoos-pro' );
			$confidence        = 'high';
		} elseif ( $score >= 50 ) {
			$predicted_outcome = __( 'Moderate chance of favorable outcome; settlement recommended', 'mcp-ai-wpoos-pro' );
			$confidence        = 'medium';
		} elseif ( $score >= 25 ) {
			$predicted_outcome = __( 'Uncertain outcome; early resolution advisable', 'mcp-ai-wpoos-pro' );
			$confidence        = 'low';
		} else {
			$predicted_outcome = __( 'Unfavorable outlook; consider case viability', 'mcp-ai-wpoos-pro' );
			$confidence        = 'very_low';
		}

		// Duration estimate in months.
		$duration_map   = array(
			'simple'   => array( 3, 12 ),
			'moderate' => array( 6, 24 ),
			'complex'  => array( 12, 48 ),
		);
		$duration_range = $duration_map[ $complexity ] ?? array( 6, 24 );

		// Value range estimate.
		$value_low  = round( $case_value * 0.3, 2 );
		$value_high = round( $case_value * 1.2, 2 );
		if ( 'strong' === $liability ) {
			$value_low  = round( $case_value * 0.5, 2 );
			$value_high = round( $case_value * 1.5, 2 );
		} elseif ( 'weak' === $liability ) {
			$value_low  = round( $case_value * 0.1, 2 );
			$value_high = round( $case_value * 0.7, 2 );
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: predicted outcome, 2: confidence */
				__( 'Prediction: %1$s (Confidence: %2$s). ', 'mcp-ai-wpoos-pro' ),
				$predicted_outcome,
				$confidence
			) . self::DISCLAIMER,
			'data'       => array(
				'predicted_outcome'  => $predicted_outcome,
				'confidence'         => $confidence,
				'score'              => $score,
				'estimated_duration' => array(
					'min_months' => $duration_range[0],
					'max_months' => $duration_range[1],
				),
				'value_range'        => array(
					'low'  => $value_low,
					'high' => $value_high,
				),
				'factors'            => array(
					'practice_area'      => $practice_area,
					'case_value'         => $case_value,
					'complexity'         => $complexity,
					'liability_strength' => $liability,
					'jurisdiction'       => $jurisdiction,
				),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
