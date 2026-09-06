<?php
/**
 * Artifact Admission Gate — pre-commit gating of evolved artifact candidates.
 *
 * Verifier-as-Gatekeeper (VaG) applied to the Continual Harness: a candidate
 * artifact is admitted **before** it can be applied only when three
 * heterogeneous critics pass:
 *
 *   1. Structural validity   — per-type shape checks (non-empty, length caps,
 *      required fields) plus site-supplied validators.
 *   2. Behavioral harmlessness — deterministic secret/PII scan and the
 *      jailbreak/injection/diversion detector (Guardrails).
 *   3. Marginal gain        — the candidate must improve over the incumbent
 *      on the replayed failure cases (strict: improvements with zero
 *      regressions; net_gain: more improvements than regressions).
 *
 * The pre-commit requirement is load-bearing: post-hoc rollback cannot undo
 * contamination chains once a defective artifact has entered the decision
 * context (Shang et al., arXiv 2608.05810). Every critic failure rejects the
 * candidate; missing marginal-gain evidence follows the configured policy
 * (`skip` default, `reject` fails closed).
 *
 * @package WP_MCP_AI
 * @subpackage WP_MCP_AI/includes/harness
 * @since   1.9.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * @reference Shang, Xu, Sun, et al. (2026). "When Self-Evolution Backfires:
 *   Pre-Commit Gating against Skill Contamination in LLM Agents."
 *   arXiv:2608.05810.
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Artifact Admission Gate class.
 *
 * @since 1.9.0
 */
class ArtifactAdmissionGate {

	/**
	 * Candidate admitted.
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const DECISION_ADMIT = 'admit';

	/**
	 * Candidate rejected (at least one critic failed).
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const DECISION_REJECT = 'reject';

	/**
	 * No verdict — missing evidence (e.g. no marginal-gain data).
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const DECISION_SKIP = 'skip';

	/**
	 * Strict marginal-gain mode: improvements required, zero regressions.
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const MODE_STRICT = 'strict';

	/**
	 * Net-gain mode: more improvements than regressions.
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const MODE_NET_GAIN = 'net_gain';

	/**
	 * Default maximum character length for text artifacts.
	 *
	 * @since 1.9.0
	 * @var   int
	 */
	const DEFAULT_MAX_CHARS = 30000;

	/**
	 * Evaluate a candidate artifact against the three admission critics.
	 *
	 * @since 1.9.0
	 *
	 * @param string $artifact_type       Artifact type (prompt|role|skill|memory|profile).
	 * @param mixed  $candidate_artifact  Candidate payload.
	 * @param mixed  $incumbent_artifact  Incumbent payload.
	 * @param array  $verification        Phase B verification payload (optional;
	 *                                    absence disables the marginal-gain critic).
	 * @param int    $assistant_id        Assistant post ID (context for filters).
	 * @return array Decision payload with per-critic results.
	 */
	public static function evaluate( $artifact_type, $candidate_artifact, $incumbent_artifact, $verification = null, $assistant_id = 0 ) {
		$artifact_type = sanitize_key( (string) $artifact_type );
		$assistant_id  = absint( $assistant_id );

		$critics = array(
			'structural'    => self::critic_structural( $artifact_type, $candidate_artifact ),
			'harmlessness'  => self::critic_harmlessness( $candidate_artifact ),
			'marginal_gain' => self::critic_marginal_gain( $verification ),
		);

		/**
		 * Filters additional admission critics.
		 *
		 * Each critic is a callable receiving
		 * ( $artifact_type, $candidate, $incumbent, $assistant_id ) and
		 * returning true (pass), WP_Error (fail), or
		 * array{ passed: bool, reason: string }.
		 *
		 * @since 1.9.0
		 *
		 * @param array  $critics    Additional critics.
		 * @param string $artifact_type Artifact type.
		 * @param mixed  $candidate  Candidate payload.
		 * @param mixed  $incumbent  Incumbent payload.
		 */
		$extra_critics = (array) apply_filters( 'wp_mcp_ai_artifact_admission_critics', array(), $artifact_type, $candidate_artifact, $incumbent_artifact, $assistant_id );
		foreach ( $extra_critics as $index => $critic ) {
			if ( ! is_callable( $critic ) ) {
				continue;
			}
			$result = call_user_func( $critic, $artifact_type, $candidate_artifact, $incumbent_artifact, $assistant_id );
			if ( is_wp_error( $result ) ) {
				$critics[ 'custom_' . $index ] = array(
					'passed' => false,
					'reason' => $result->get_error_message(),
				);
			} elseif ( is_array( $result ) && isset( $result['passed'] ) ) {
				$critics[ 'custom_' . $index ] = array(
					'passed' => (bool) $result['passed'],
					'reason' => isset( $result['reason'] ) ? (string) $result['reason'] : '',
				);
			} else {
				$critics[ 'custom_' . $index ] = array(
					'passed' => (bool) $result,
					'reason' => '',
				);
			}
		}

		$reasons = array();
		foreach ( $critics as $key => $critic ) {
			if ( ! empty( $critic['passed'] ) ) {
				continue;
			}
			// No-evidence marginal gain is "no signal", not a failure — it is
			// surfaced as a reason but resolved by the no-evidence policy.
			if ( 'marginal_gain' === $key && empty( $critic['evidence'] ) ) {
				continue;
			}
			if ( ! empty( $critic['reason'] ) ) {
				$reasons[] = $critic['reason'];
			}
		}

		$any_failed = false;
		foreach ( $critics as $key => $critic ) {
			if ( 'marginal_gain' === $key && empty( $critic['evidence'] ) ) {
				continue;
			}
			if ( empty( $critic['passed'] ) ) {
				$any_failed = true;
				break;
			}
		}

		if ( $any_failed ) {
			$decision = self::DECISION_REJECT;
		} elseif ( ! empty( $critics['marginal_gain']['evidence'] ) ) {
			$decision = self::DECISION_ADMIT;
		} else {
			/**
			 * Filters the behavior when marginal-gain evidence is missing.
			 *
			 * 'skip' (default) defers to human review; 'reject' fails closed;
			 * 'admit' allows evidence-free candidates.
			 *
			 * @since 1.9.0
			 *
			 * @param string $policy        One of skip|reject|admit.
			 * @param string $artifact_type Artifact type.
			 * @param int    $assistant_id  Assistant post ID.
			 */
			$on_no_evidence = (string) apply_filters( 'wp_mcp_ai_artifact_admission_on_no_evidence', self::DECISION_SKIP, $artifact_type, $assistant_id );
			if ( 'admit' === $on_no_evidence ) {
				$decision = self::DECISION_ADMIT;
			} elseif ( 'reject' === $on_no_evidence ) {
				$decision  = self::DECISION_REJECT;
				$reasons[] = __( 'No marginal-gain evidence is available and the no-evidence policy fails closed.', 'nvoos-content-graph-ai-platform' );
			} else {
				$decision = self::DECISION_SKIP;
			}
		}

		$payload = array(
			'decision' => $decision,
			'critics'  => $critics,
			'reasons'  => $reasons,
		);

		/**
		 * Filters the final admission decision.
		 *
		 * @since 1.9.0
		 *
		 * @param array  $payload       Decision payload.
		 * @param string $artifact_type Artifact type.
		 * @param int    $assistant_id  Assistant post ID.
		 */
		return (array) apply_filters( 'wp_mcp_ai_artifact_admission_decision', $payload, $artifact_type, $assistant_id );
	}

	// -------------------------------------------------------------------------
	// Critics
	// -------------------------------------------------------------------------

	/**
	 * Structural validity critic.
	 *
	 * @since 1.9.0
	 *
	 * @param string $artifact_type Artifact type.
	 * @param mixed  $candidate     Candidate payload.
	 * @return array{passed:bool,reason:string}
	 */
	private static function critic_structural( $artifact_type, $candidate ) {
		if ( '' === $artifact_type ) {
			return array(
				'passed' => false,
				'reason' => __( 'Artifact type is required.', 'nvoos-content-graph-ai-platform' ),
			);
		}

		if ( 'prompt' === $artifact_type ) {
			$text = is_array( $candidate ) && isset( $candidate['prompt'] ) ? (string) $candidate['prompt'] : (string) $candidate;
			if ( '' === trim( $text ) ) {
				return array(
					'passed' => false,
					'reason' => __( 'The prompt artifact is empty.', 'nvoos-content-graph-ai-platform' ),
				);
			}

			/**
			 * Filters the maximum character length for text artifacts.
			 *
			 * @since 1.9.0
			 *
			 * @param int $max_chars Maximum characters. Default 30000.
			 */
			$max_chars = (int) apply_filters( 'wp_mcp_ai_artifact_admission_max_chars', self::DEFAULT_MAX_CHARS );
			if ( strlen( $text ) > max( 100, $max_chars ) ) {
				return array(
					'passed' => false,
					'reason' => __( 'The prompt artifact exceeds the maximum length.', 'nvoos-content-graph-ai-platform' ),
				);
			}

			return array(
				'passed' => true,
				'reason' => '',
			);
		}

		if ( 'skill' === $artifact_type ) {
			$name         = is_array( $candidate ) && isset( $candidate['name'] ) ? (string) $candidate['name'] : '';
			$instructions = is_array( $candidate ) && isset( $candidate['instructions'] ) ? (string) $candidate['instructions'] : '';
			if ( '' === trim( $name ) || '' === trim( $instructions ) ) {
				return array(
					'passed' => false,
					'reason' => __( 'Skill artifacts require a name and instructions.', 'nvoos-content-graph-ai-platform' ),
				);
			}

			return array(
				'passed' => true,
				'reason' => '',
			);
		}

		if ( empty( $candidate ) ) {
			return array(
				'passed' => false,
				'reason' => __( 'The artifact payload is empty.', 'nvoos-content-graph-ai-platform' ),
			);
		}

		return array(
			'passed' => true,
			'reason' => '',
		);
	}

	/**
	 * Behavioral harmlessness critic.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $candidate Candidate payload.
	 * @return array{passed:bool,reason:string}
	 */
	private static function critic_harmlessness( $candidate ) {
		$text = self::artifact_to_text( $candidate );
		if ( '' === trim( $text ) ) {
			return array(
				'passed' => false,
				'reason' => __( 'The artifact has no content to screen.', 'nvoos-content-graph-ai-platform' ),
			);
		}

		// Deterministic secret/PII scan.
		if ( class_exists( __NAMESPACE__ . '\\PiiFilter' ) && PiiFilter::contains_secret( $text ) ) {
			return array(
				'passed' => false,
				'reason' => __( 'The artifact contains PII or secrets.', 'nvoos-content-graph-ai-platform' ),
			);
		}

		// Jailbreak / injection / diversion detection.
		if ( class_exists( __NAMESPACE__ . '\\Guardrails' ) && method_exists( __NAMESPACE__ . '\\Guardrails', 'analyze_message' ) ) {
			$analysis = Guardrails::analyze_message( $text, 'high' );
			if ( isset( $analysis['result'] ) && 'safe' !== $analysis['result'] ) {
				return array(
					'passed' => false,
					'reason' => sprintf(
						/* translators: 1: guardrail family, 2: severity */
						__( 'The artifact was flagged by the guardrails (%1$s, %2$s).', 'nvoos-content-graph-ai-platform' ),
						isset( $analysis['family'] ) ? $analysis['family'] : 'unknown',
						isset( $analysis['severity'] ) ? $analysis['severity'] : 'unknown'
					),
				);
			}
		}

		return array(
			'passed' => true,
			'reason' => '',
		);
	}

	/**
	 * Marginal-gain critic from the Phase B verification payload.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $verification Verification payload (may be null).
	 * @return array{passed:bool,reason:string,evidence:bool}
	 */
	private static function critic_marginal_gain( $verification ) {
		if ( ! is_array( $verification ) || ! isset( $verification['improved_cases'] ) || ! isset( $verification['regressed_cases'] ) ) {
			return array(
				'passed'   => false,
				'reason'   => __( 'No marginal-gain evidence was provided.', 'nvoos-content-graph-ai-platform' ),
				'evidence' => false,
			);
		}

		$improved  = (int) $verification['improved_cases'];
		$regressed = (int) $verification['regressed_cases'];

		/**
		 * Filters the marginal-gain mode.
		 *
		 * @since 1.9.0
		 *
		 * @param string $mode MODE_STRICT (default) or MODE_NET_GAIN.
		 */
		$mode = (string) apply_filters( 'wp_mcp_ai_artifact_admission_mode', self::MODE_STRICT );
		if ( self::MODE_NET_GAIN === $mode ) {
			$passed = $improved > $regressed;
			$reason = $passed
				? __( 'The candidate produced a net gain over the incumbent.', 'nvoos-content-graph-ai-platform' )
				: __( 'The candidate produced no net gain over the incumbent.', 'nvoos-content-graph-ai-platform' );
		} else {
			$passed = $improved > 0 && 0 === $regressed;
			$reason = $passed
				? __( 'The candidate improved on failure cases with zero regressions.', 'nvoos-content-graph-ai-platform' )
				: __( 'The candidate must improve on at least one failure case with zero regressions.', 'nvoos-content-graph-ai-platform' );
		}

		return array(
			'passed'   => $passed,
			'reason'   => $reason,
			'evidence' => true,
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Render an artifact payload as text.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $artifact Artifact payload.
	 * @return string Text representation.
	 */
	private static function artifact_to_text( $artifact ) {
		if ( is_string( $artifact ) ) {
			return $artifact;
		}

		if ( is_array( $artifact ) ) {
			foreach ( array( 'prompt', 'instructions', 'code' ) as $key ) {
				if ( isset( $artifact[ $key ] ) && is_string( $artifact[ $key ] ) ) {
					return $artifact[ $key ];
				}
			}
			return (string) wp_json_encode( $artifact );
		}

		return (string) $artifact;
	}
}
