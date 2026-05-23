<?php
/**
 * Lead Scoring Calculator Tool
 *
 * Calculates a lead score for potential clients based on weighted factors.
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
 * Calculates lead scores based on weighted intake criteria.
 */
class WP_MCP_AI_Tool_LF_Lead_Scoring_Calculator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'lf_lead_scoring_calculator';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Lead Scoring Calculator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Calculates a lead score (0-100) for potential clients based on practice area, estimated case value, urgency, referral source, and client type.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'practice_area'        => array(
					'type'        => 'string',
					'description' => __( 'Area of law for the potential matter.', 'mcp-ai-wpoos-pro' ),
				),
				'estimated_case_value' => array(
					'type'        => 'number',
					'description' => __( 'Estimated monetary value of the case.', 'mcp-ai-wpoos-pro' ),
				),
				'urgency'              => array(
					'type'        => 'string',
					'description' => __( 'Urgency level of the matter.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'low', 'medium', 'high', 'critical' ),
				),
				'referral_source'      => array(
					'type'        => 'string',
					'description' => __( 'How the lead was referred (e.g., attorney_referral, website, advertising).', 'mcp-ai-wpoos-pro' ),
				),
				'client_type'          => array(
					'type'        => 'string',
					'description' => __( 'Type of client.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'individual', 'business' ),
				),
			),
			'required'   => array( 'practice_area' ),
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
		$case_value    = isset( $arguments['estimated_case_value'] ) ? floatval( $arguments['estimated_case_value'] ) : 0;
		$urgency       = isset( $arguments['urgency'] ) ? sanitize_text_field( $arguments['urgency'] ) : 'medium';
		$referral      = isset( $arguments['referral_source'] ) ? sanitize_text_field( $arguments['referral_source'] ) : '';
		$client_type   = isset( $arguments['client_type'] ) ? sanitize_text_field( $arguments['client_type'] ) : 'individual';

		$score = 0;

		// Practice area score (max 25).
		$area_scores = array(
			'litigation'      => 20,
			'corporate'       => 25,
			'real_estate'     => 22,
			'family'          => 15,
			'criminal'        => 12,
			'ip'              => 25,
			'immigration'     => 18,
			'bankruptcy'      => 16,
			'tax'             => 22,
			'employment'      => 20,
			'estate_planning' => 18,
		);
		$score      += $area_scores[ $practice_area ] ?? 15;

		// Case value score (max 30).
		$case_value_score = 0;
		if ( $case_value >= 1000000 ) {
			$case_value_score = 30;
		} elseif ( $case_value >= 500000 ) {
			$case_value_score = 25;
		} elseif ( $case_value >= 100000 ) {
			$case_value_score = 20;
		} elseif ( $case_value >= 50000 ) {
			$case_value_score = 15;
		} elseif ( $case_value >= 10000 ) {
			$case_value_score = 10;
		} elseif ( $case_value > 0 ) {
			$case_value_score = 5;
		}
		$score += $case_value_score;

		// Urgency score (max 20).
		$urgency_scores = array(
			'critical' => 20,
			'high'     => 15,
			'medium'   => 10,
			'low'      => 5,
		);
		$score         += $urgency_scores[ $urgency ] ?? 10;

		// Referral source score (max 15).
		$referral_scores = array(
			'attorney_referral' => 15,
			'existing_client'   => 14,
			'professional'      => 12,
			'website'           => 8,
			'advertising'       => 6,
			'social_media'      => 5,
			'walk_in'           => 4,
		);
		$score          += $referral_scores[ $referral ] ?? 7;

		// Client type score (max 10).
		$score += ( 'business' === $client_type ) ? 10 : 5;

		$score = min( 100, max( 0, $score ) );

		// Determine grade.
		if ( $score >= 80 ) {
			$grade          = 'A';
			$recommendation = __( 'High-priority lead. Recommend immediate follow-up and partner assignment.', 'mcp-ai-wpoos-pro' );
		} elseif ( $score >= 60 ) {
			$grade          = 'B';
			$recommendation = __( 'Good lead. Recommend timely follow-up within 24 hours.', 'mcp-ai-wpoos-pro' );
		} elseif ( $score >= 40 ) {
			$grade          = 'C';
			$recommendation = __( 'Average lead. Schedule a consultation to evaluate further.', 'mcp-ai-wpoos-pro' );
		} else {
			$grade          = 'D';
			$recommendation = __( 'Low-priority lead. Consider whether the matter aligns with firm capabilities.', 'mcp-ai-wpoos-pro' );
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: lead score, 2: grade */
				__( 'Lead score calculated: %1$d/100 (Grade %2$s). ', 'mcp-ai-wpoos-pro' ),
				$score,
				$grade
			) . self::DISCLAIMER,
			'data'       => array(
				'score'          => $score,
				'grade'          => $grade,
				'recommendation' => $recommendation,
				'breakdown'      => array(
					'practice_area' => $area_scores[ $practice_area ] ?? 15,
					'case_value'    => $case_value_score,
					'urgency'       => $urgency_scores[ $urgency ] ?? 10,
					'referral'      => $referral_scores[ $referral ] ?? 7,
					'client_type'   => ( 'business' === $client_type ) ? 10 : 5,
				),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
