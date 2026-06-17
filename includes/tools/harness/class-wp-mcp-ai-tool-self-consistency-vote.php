<?php
/**
 * Tool: self_consistency_vote — Layer B test-time-compute primitive.
 *
 * Given N candidate answers, returns the modal answer along with an
 * agreement ratio. The cheapest version of best-of-N + verifier from the
 * test-time-compute literature (Snell et al. 2024; Wang et al. 2022 —
 * Self-Consistency).
 *
 * @package WP_MCP_AI
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Majority-vote across candidate answers.
 */
class WP_MCP_AI_Tool_Self_Consistency_Vote implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'self_consistency_vote';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Self-Consistency Vote', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Pick the modal answer across N candidate solutions and report an agreement ratio. Use after sampling the same prompt multiple times to estimate confidence in the final answer.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'candidates' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'List of candidate answers to vote across.',
				),
			),
			'required'   => array( 'candidates' ),
		);
	}

	public function get_required_capability() {
		return 'edit_posts';
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		$candidates = array();
		if ( isset( $arguments['candidates'] ) && is_array( $arguments['candidates'] ) ) {
			foreach ( $arguments['candidates'] as $cand ) {
				if ( is_string( $cand ) ) {
					$candidates[] = $cand;
				} elseif ( is_scalar( $cand ) ) {
					$candidates[] = (string) $cand;
				}
			}
		}

		if ( empty( $candidates ) ) {
			return new WP_Error( 'wp_mcp_ai_self_consistency_no_candidates', __( 'At least one candidate is required.', 'mcp-ai-wpoos' ) );
		}

		// Hard cap to prevent ridiculous payloads.
		if ( count( $candidates ) > 64 ) {
			$candidates = array_slice( $candidates, 0, 64 );
		}

		$result = WP_MCP_AI_Reasoning_Trace::self_consistency_vote( $candidates );
		return array(
			'success' => true,
			'result'  => $result,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'read-only', 'local-only', 'idempotent' );
	}
}
