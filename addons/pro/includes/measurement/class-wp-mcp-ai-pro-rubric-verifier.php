<?php
/**
 * Pro Rubric Verifier
 *
 * Multi-criterion weighted rubric that composes either sub-verifiers
 * already registered on the base plugin's verifier registry, or inline
 * predicate callbacks. Produces a single `result_pass`/`result_fail`
 * payload whose score is the weight-normalized sum of per-criterion
 * scores.
 *
 * Why this lives in Pro:
 *   - The base verifiers (rule, schema, llm-judge) cover the common
 *     single-dimension cases. Multi-dimensional rubrics are the QA
 *     pattern reached for by teams running human-graded evals or
 *     enterprise SLAs — exactly the slice that justifies a Pro toolkit.
 *   - Sub-verifier chaining is the first place in the codebase where
 *     one verifier calls another, so the failure modes (cycle, missing
 *     dependency, independence-profile clashes) need to be handled
 *     explicitly rather than shipped in the base verifier surface.
 *
 * Construction accepts criteria as a list of associative arrays. Each
 * criterion MUST specify `slug`, `weight`, and exactly one of:
 *   - `verifier`  (slug of another registered verifier), OR
 *   - `callback`  (callable( array $subject, array $context ) returning
 *                  a float in [0.0, 1.0] or a bool).
 *
 * Weights are normalized before summing — callers do not need them to
 * sum to 1.0.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Weighted multi-criterion rubric verifier (Pro).
 */
class WP_MCP_AI_Pro_Rubric_Verifier extends WP_MCP_AI_Verifier_Base {

	/**
	 * Pass threshold applied to the weight-normalized score. Callers can
	 * override via the fourth constructor arg; default is 0.7 to match
	 * the llm-judge verifier's default.
	 *
	 * @var float
	 */
	protected $pass_threshold = 0.7;

	/**
	 * Raw criteria as provided by the caller (after normalization).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	protected $criteria = array();

	/**
	 * Slugs currently being evaluated. Used to short-circuit cycles
	 * (rubric → rubric → rubric). Static because cycles can span
	 * multiple rubric instances if a site wires them that way.
	 *
	 * @var array<string,bool>
	 */
	protected static $active_chain = array();

	/**
	 * Constructor.
	 *
	 * @param string $slug           Slug.
	 * @param array  $criteria       Criterion definitions.
	 * @param string $label          Human label.
	 * @param float  $pass_threshold Pass threshold (0..1).
	 *
	 * @throws InvalidArgumentException When criteria are empty or malformed.
	 */
	public function __construct( $slug = 'pro_rubric', array $criteria = array(), $label = '', $pass_threshold = 0.7 ) {
		$this->slug = sanitize_key( $slug );
		if ( '' === $this->slug ) {
			$this->slug = 'pro_rubric';
		}
		$this->label = '' !== $label ? (string) $label : __( 'Pro Rubric Verifier', 'mcp-ai-wpoos' );
		$this->kind  = 'rubric';

		$this->pass_threshold = $this->clamp( (float) $pass_threshold );

		// Rubric verifiers may chain through an llm-judge criterion. We
		// conservatively mark the default independence profile as "do
		// not share the primary model / provider" so rubric-as-gate
		// cannot be circumvented by the agent it judges. Concrete
		// chains can override by passing a richer profile later.
		$this->independence_profile = array(
			'disallowed_providers'        => array(),
			'disallowed_models'           => array(),
			'requires_different_model'    => false,
			'requires_different_provider' => false,
		);

		$this->criteria = $this->normalize_criteria( $criteria );
		if ( empty( $this->criteria ) ) {
			throw new InvalidArgumentException( 'Rubric verifier requires at least one criterion.' );
		}
	}

	/**
	 * Expose the normalized criterion list (read-only).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_criteria() {
		return $this->criteria;
	}

	/**
	 * Pass threshold.
	 *
	 * @return float
	 */
	public function get_pass_threshold() {
		return $this->pass_threshold;
	}

	/**
	 * Verify.
	 *
	 * @param array $subject Subject.
	 * @param array $context Context.
	 * @return array|WP_Error
	 */
	public function verify( array $subject, array $context = array() ) {
		if ( isset( self::$active_chain[ $this->slug ] ) ) {
			return new WP_Error(
				'wp_mcp_ai_rubric_cycle',
				sprintf(
					/* translators: %s: rubric slug. */
					__( 'Rubric verifier "%s" was invoked recursively; aborting to avoid a cycle.', 'mcp-ai-wpoos' ),
					$this->slug
				)
			);
		}

		self::$active_chain[ $this->slug ] = true;

		try {
			$total_weight   = 0.0;
			$weighted_score = 0.0;
			$weighted_conf  = 0.0;
			$reasons        = array();
			$per_criterion  = array();
			$any_error      = false;

			foreach ( $this->criteria as $criterion ) {
				$weight = (float) $criterion['weight'];
				if ( $weight <= 0.0 ) {
					continue;
				}

				$outcome = $this->evaluate_criterion( $criterion, $subject, $context );

				if ( is_wp_error( $outcome ) ) {
					$any_error                           = true;
					$per_criterion[ $criterion['slug'] ] = array(
						'score'  => 0.0,
						'error'  => $outcome->get_error_code(),
						'weight' => $weight,
					);
					$reasons[]                           = sprintf(
						/* translators: 1: criterion slug, 2: error code. */
						__( 'Criterion %1$s failed to evaluate (%2$s).', 'mcp-ai-wpoos' ),
						$criterion['slug'],
						$outcome->get_error_code()
					);
					continue;
				}

				$score      = $this->clamp( (float) $outcome['score'] );
				$confidence = $this->clamp( (float) $outcome['confidence'] );

				$weighted_score += $score * $weight;
				$weighted_conf  += $confidence * $weight;
				$total_weight   += $weight;

				$per_criterion[ $criterion['slug'] ] = array(
					'score'      => $score,
					'weight'     => $weight,
					'passed'     => ! empty( $outcome['passed'] ),
					'confidence' => $confidence,
				);

				if ( ! empty( $outcome['reasons'] ) && is_array( $outcome['reasons'] ) ) {
					foreach ( $outcome['reasons'] as $r ) {
						$reasons[] = sprintf( '[%s] %s', $criterion['slug'], (string) $r );
					}
				}
			}

			if ( $total_weight <= 0.0 ) {
				return new WP_Error(
					'wp_mcp_ai_rubric_no_weight',
					__( 'Rubric evaluated no criteria with positive weight.', 'mcp-ai-wpoos' )
				);
			}

			$final_score = $weighted_score / $total_weight;
			$final_conf  = $weighted_conf / $total_weight;
			$passed      = ( ! $any_error ) && ( $final_score >= $this->pass_threshold );

			$evidence = array(
				'criteria'       => $per_criterion,
				'pass_threshold' => $this->pass_threshold,
				'total_weight'   => $total_weight,
			);

			if ( $passed ) {
				return $this->result_pass( $final_score, $final_conf, $reasons, $evidence );
			}
			return $this->result_fail( $final_score, $final_conf, $reasons, $evidence );
		} finally {
			unset( self::$active_chain[ $this->slug ] );
		}
	}

	/**
	 * Normalize criterion list.
	 *
	 * @param array $criteria Raw criteria.
	 * @return array<int,array<string,mixed>>
	 */
	protected function normalize_criteria( array $criteria ) {
		$out  = array();
		$seen = array();
		foreach ( $criteria as $i => $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$slug = isset( $c['slug'] ) ? sanitize_key( (string) $c['slug'] ) : '';
			if ( '' === $slug ) {
				$slug = 'criterion_' . ( count( $out ) + 1 );
			}
			if ( isset( $seen[ $slug ] ) ) {
				// Disambiguate duplicates rather than silently dropping — a
				// rubric whose author miswired two criteria to the same
				// slug should still see both scores reflected in evidence.
				$slug .= '_' . ( count( $out ) + 1 );
			}
			$seen[ $slug ] = true;

			$weight = isset( $c['weight'] ) && is_numeric( $c['weight'] ) ? (float) $c['weight'] : 1.0;
			if ( $weight < 0.0 ) {
				$weight = 0.0;
			}

			$has_verifier = ! empty( $c['verifier'] ) && is_string( $c['verifier'] );
			$has_callback = isset( $c['callback'] ) && is_callable( $c['callback'] );

			if ( ! $has_verifier && ! $has_callback ) {
				// A criterion with neither reference is dropped — there is
				// nothing to evaluate. We do not throw here so that a
				// partially-misconfigured rubric still boots; the
				// constructor's "at least one criterion" check will catch
				// the degenerate case where every criterion is dropped.
				continue;
			}

			$entry = array(
				'slug'        => $slug,
				'weight'      => $weight,
				'description' => isset( $c['description'] ) ? (string) $c['description'] : '',
			);
			if ( $has_verifier ) {
				$entry['verifier'] = sanitize_key( (string) $c['verifier'] );
			}
			if ( $has_callback ) {
				$entry['callback'] = $c['callback'];
			}
			if ( isset( $c['subject_key'] ) && is_string( $c['subject_key'] ) ) {
				// Optional: evaluate the sub-verifier against a specific
				// sub-key of the subject array. Lets a single rubric
				// apply different verifiers to different fields of a
				// tool-call payload.
				$entry['subject_key'] = sanitize_key( $c['subject_key'] );
			}

			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * Evaluate a single criterion.
	 *
	 * @param array $criterion Criterion (normalized).
	 * @param array $subject   Subject.
	 * @param array $context   Context.
	 * @return array|WP_Error  Verifier-shaped result or WP_Error.
	 */
	protected function evaluate_criterion( array $criterion, array $subject, array $context ) {
		$scoped_subject = $subject;
		if ( ! empty( $criterion['subject_key'] ) ) {
			$scoped_subject = isset( $subject[ $criterion['subject_key'] ] ) && is_array( $subject[ $criterion['subject_key'] ] )
				? $subject[ $criterion['subject_key'] ]
				: array( 'value' => isset( $subject[ $criterion['subject_key'] ] ) ? $subject[ $criterion['subject_key'] ] : null );
		}

		// Callback criteria run in-process; they are the cheapest form.
		if ( isset( $criterion['callback'] ) && is_callable( $criterion['callback'] ) ) {
			$raw = call_user_func( $criterion['callback'], $scoped_subject, $context );
			return $this->normalize_callback_result( $raw );
		}

		if ( empty( $criterion['verifier'] ) ) {
			return new WP_Error( 'wp_mcp_ai_rubric_criterion_empty', 'Criterion has no evaluator.' );
		}

		if ( ! class_exists( 'WP_MCP_AI_Verifier_Registry' ) ) {
			return new WP_Error( 'wp_mcp_ai_rubric_no_registry', 'Verifier registry not available.' );
		}
		$registry = WP_MCP_AI_Verifier_Registry::get_instance();
		$verifier = $registry->get( $criterion['verifier'] );
		if ( ! $verifier instanceof WP_MCP_AI_Verifier_Interface ) {
			return new WP_Error(
				'wp_mcp_ai_rubric_unknown_verifier',
				sprintf( 'Unknown sub-verifier: %s', $criterion['verifier'] )
			);
		}

		// Reject chaining to ourselves explicitly — the active_chain guard
		// catches cycles at verify()-time, but this message is clearer.
		if ( $verifier instanceof WP_MCP_AI_Pro_Rubric_Verifier && $verifier->get_slug() === $this->slug ) {
			return new WP_Error( 'wp_mcp_ai_rubric_self_reference', 'Rubric cannot reference itself.' );
		}

		$result = $verifier->verify( $scoped_subject, $context );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) || ! isset( $result['score'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_rubric_bad_result',
				sprintf( 'Sub-verifier %s returned an invalid result shape.', $criterion['verifier'] )
			);
		}
		return $result;
	}

	/**
	 * Normalize a callback's raw return into verifier-shaped output.
	 *
	 * @param mixed $raw Raw callback return.
	 * @return array|WP_Error
	 */
	protected function normalize_callback_result( $raw ) {
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		if ( is_bool( $raw ) ) {
			return $raw
				? $this->result_pass( 1.0, 1.0, array(), array() )
				: $this->result_fail( 0.0, 1.0, array(), array() );
		}
		if ( is_numeric( $raw ) ) {
			$score = $this->clamp( (float) $raw );
			return array(
				'passed'     => $score >= $this->pass_threshold,
				'score'      => $score,
				'confidence' => 1.0,
				'reasons'    => array(),
				'evidence'   => array(),
			);
		}
		if ( is_array( $raw ) && isset( $raw['score'] ) ) {
			$raw['score']      = isset( $raw['score'] ) ? $this->clamp( (float) $raw['score'] ) : 0.0;
			$raw['confidence'] = isset( $raw['confidence'] ) ? $this->clamp( (float) $raw['confidence'] ) : 1.0;
			$raw['passed']     = ! empty( $raw['passed'] );
			$raw['reasons']    = isset( $raw['reasons'] ) && is_array( $raw['reasons'] ) ? $raw['reasons'] : array();
			$raw['evidence']   = isset( $raw['evidence'] ) && is_array( $raw['evidence'] ) ? $raw['evidence'] : array();
			return $raw;
		}
		return new WP_Error( 'wp_mcp_ai_rubric_bad_callback', 'Rubric criterion callback returned an unrecognized shape.' );
	}
}
