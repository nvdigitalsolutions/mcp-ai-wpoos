<?php
/**
 * Tool — Score LEED v4 BD+C Certification.
 *
 * Computes LEED v4/v4.1 BD+C scoring across LT, SS, WE, EA, MR, EQ, IN and RP
 * categories with prerequisite checks and certification level
 * (Certified / Silver / Gold / Platinum). Backed by
 * `WP_MCP_AI_Architectural_Sustainability::score_leed_v4_bdc()`.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

/**
 * Score LEED v4 BD+C certification.
 */
class WP_MCP_AI_Tool_Score_Leed_V4_Certification implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/* WP_MCP_AI_AVAILABILITY_BLOCK */
	/**
	 * Whether this tool is available for registration.
	 *
	 * @since 1.4.0
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_architectural_design_toolkit'] );
	}

	/**
	 * Reason this tool is unavailable, if any.
	 *
	 * @since 1.4.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'Architectural Design toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'score_leed_v4_certification';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Score LEED v4 BD+C Certification', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Score a LEED v4/v4.1 BD+C: New Construction submission. Pass `awarded_credits` (credit-id => points) and `met_prerequisites` (prereq-id => bool). Returns the awarded certification level (Certified / Silver / Gold / Platinum), category totals, missing prerequisites, and any over-max or unknown credit IDs. Indicative — final certification requires GBCI review.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'awarded_credits'   => array(
					'type'                 => 'object',
					'description'          => __( 'Map of credit-id (e.g. EA_c2) to awarded points.', 'mcp-ai-wpoos-pro' ),
					'additionalProperties' => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
				),
				'met_prerequisites' => array(
					'type'                 => 'object',
					'description'          => __( 'Map of prerequisite-id (e.g. EA_p2) to boolean.', 'mcp-ai-wpoos-pro' ),
					'additionalProperties' => array( 'type' => 'boolean' ),
				),
				'include_catalog'   => array(
					'type'        => 'boolean',
					'description' => __( 'Include the full LEED v4 BD+C catalog in the response.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'awarded_credits' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-capability',
			'read-only',
			'cacheable',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to score LEED certification.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $arguments['awarded_credits'] ) || ! is_array( $arguments['awarded_credits'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'awarded_credits must be a map of credit-id to integer points.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Architectural_Sustainability' ) ) {
			return new WP_Error(
				'wp_mcp_ai_engine_missing',
				__( 'Architectural sustainability engine is unavailable.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Sanitise inputs.
		$awarded = array();
		foreach ( (array) $arguments['awarded_credits'] as $credit_id => $points ) {
			$credit_id = sanitize_text_field( (string) $credit_id );
			if ( '' === $credit_id ) {
				continue;
			}
			$awarded[ $credit_id ] = max( 0, intval( $points ) );
		}

		$prereqs = array();
		if ( isset( $arguments['met_prerequisites'] ) && is_array( $arguments['met_prerequisites'] ) ) {
			foreach ( $arguments['met_prerequisites'] as $prereq_id => $is_met ) {
				$prereq_id = sanitize_text_field( (string) $prereq_id );
				if ( '' === $prereq_id ) {
					continue;
				}
				$prereqs[ $prereq_id ] = (bool) $is_met;
			}
		}

		$score = WP_MCP_AI_Architectural_Sustainability::score_leed_v4_bdc( $awarded, $prereqs );
		if ( empty( $score['success'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_leed_score_failed',
				__( 'Unable to score LEED submission.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! empty( $arguments['include_catalog'] ) ) {
			$score['catalog'] = WP_MCP_AI_Architectural_Sustainability::get_leed_v4_bdc_catalog();
		}

		$score['method']     = __( 'LEED v4 / v4.1 BD+C: New Construction (USGBC).', 'mcp-ai-wpoos-pro' );
		$score['disclaimer'] = __( 'Indicative scoring only. Final certification requires GBCI review of documentation and credit interpretations.', 'mcp-ai-wpoos-pro' );

		/**
		 * Fires after a LEED scoring completes.
		 *
		 * @since 1.4.0
		 *
		 * @param array $score   Score result.
		 * @param array $args    Tool arguments.
		 * @param array $context Tool context.
		 */
		do_action( 'wp_mcp_ai_arch_leed_scored', $score, $arguments, $context );

		return $score;
	}
}
