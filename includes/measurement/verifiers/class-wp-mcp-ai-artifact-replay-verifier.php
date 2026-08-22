<?php
/**
 * Artifact Replay Verifier
 *
 * Deterministic verifier for artifact-failure-replay eval cases. Reads
 * per-case rules from the subject's `expected.rules` payload (produced by
 * {@see WP_MCP_AI_Artifact_Failure_Replay}) and delegates evaluation to an
 * internal {@see WP_MCP_AI_Rule_Verifier} instance.
 *
 * The baseline rule — non-empty output — guarantees that a generator which
 * errors or produces nothing can never pass a replay case. Site owners can
 * strengthen cases with their own rules via the
 * `wp_mcp_ai_artifact_replay_case_rules` filter, or swap in the LLM-judge
 * verifier for semantic discrimination.
 *
 * This verifier performs no LLM calls, so its independence profile is
 * permissive (mirrors the rule verifier).
 *
 * @package WP_MCP_AI
 * @since   1.9.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * @see WP_MCP_AI_Artifact_Failure_Replay Replay case producer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deterministic replay-case verifier.
 */
class WP_MCP_AI_Artifact_Replay_Verifier extends WP_MCP_AI_Verifier_Base {

	/**
	 * Verifier slug used by replay cases by default.
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const SLUG = 'artifact_replay';

	/**
	 * Constructor.
	 *
	 * @since 1.9.0
	 *
	 * @param string $slug Slug override (defaults to `artifact_replay`).
	 */
	public function __construct( $slug = self::SLUG ) {
		$this->slug  = '' !== sanitize_key( $slug ) ? sanitize_key( $slug ) : self::SLUG;
		$this->label = __( 'Artifact Replay Verifier', 'mcp-ai-wpoos' );
		$this->kind  = 'rule';
		// Deterministic, no LLM work — trivially independent.
		$this->independence_profile = array(
			'disallowed_providers' => array(),
			'disallowed_models'    => array(),
			'disallowed_tools'     => array(),
			'allowed_domains'      => array(),
		);
	}

	/**
	 * Verify a generator output against the case's replay rules.
	 *
	 * Rules are read from `$subject['expected']['rules']`. When absent, the
	 * baseline rule applies: the output value must be non-empty.
	 *
	 * @since 1.9.0
	 *
	 * @param array $subject Subject (value/input/expected).
	 * @param array $context Context.
	 * @return array<string,mixed>
	 */
	public function verify( array $subject, array $context = array() ) {
		$rules = array();

		if ( isset( $subject['expected'] ) && is_array( $subject['expected'] ) && isset( $subject['expected']['rules'] ) && is_array( $subject['expected']['rules'] ) ) {
			$rules = $subject['expected']['rules'];
		}

		if ( empty( $rules ) ) {
			$rules = array(
				array(
					'type'    => 'required',
					'path'    => 'value',
					'message' => __( 'Replay case requires non-empty output.', 'mcp-ai-wpoos' ),
				),
			);
		}

		$rule_verifier = new WP_MCP_AI_Rule_Verifier( 'artifact_replay_rules', $rules );

		return $rule_verifier->verify( $subject, $context );
	}
}
