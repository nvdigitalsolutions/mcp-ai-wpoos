<?php
/**
 * LLM Judge Verifier
 *
 * Pluggable LLM-as-judge verifier. Core does NOT bundle any specific
 * provider SDK — instead, callers supply a judge callable via the constructor
 * or the `wp_mcp_ai_llm_judge_callable` filter. This keeps the base plugin
 * lean and allows admins to route judges through whichever provider they
 * trust for independence from the generator.
 *
 * The callable signature:
 *
 *   function ( array $subject, array $context ): array|WP_Error
 *
 * The callable MUST return an array with:
 *   - passed     bool
 *   - score      float (0..1)
 *   - confidence float (0..1)
 *   - reasons    array<string>
 *   - evidence   array
 *
 * If no callable is configured, the verifier abstains: it returns a result
 * that is neither pass nor fail (score 0.5, confidence 0.0, reasons
 * explaining that the judge is not configured). Abstention is surfaced as a
 * first-class metric per the plan's Goodhart safeguards.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LLM Judge Verifier.
 */
class WP_MCP_AI_LLM_Judge_Verifier extends WP_MCP_AI_Verifier_Base {

	/**
	 * Judge callable.
	 *
	 * @var callable|null
	 */
	protected $judge_callable = null;

	/**
	 * Constructor.
	 *
	 * The $independence argument MUST list at least one disallowed provider
	 * or model — a judge that shares provenance with the generator violates
	 * verifier's law. Callers who truly want a self-judge (e.g. for self-
	 * consistency) can pass an empty independence profile, but they should
	 * do so deliberately.
	 *
	 * @param string        $slug          Slug.
	 * @param callable|null $callable      Judge callable, or null to abstain.
	 * @param array         $independence  Independence profile.
	 */
	public function __construct( $slug = 'llm_judge', $callable = null, array $independence = array() ) {
		$this->slug                 = '' !== sanitize_key( $slug ) ? sanitize_key( $slug ) : 'llm_judge';
		$this->label                = __( 'LLM Judge Verifier', 'mcp-ai-wpoos' );
		$this->kind                 = 'llm_judge';
		$this->judge_callable       = is_callable( $callable ) ? $callable : null;
		$this->independence_profile = array_merge(
			array(
				'disallowed_providers' => array(),
				'disallowed_models'    => array(),
				'disallowed_tools'     => array(),
				'allowed_domains'      => array(),
			),
			$independence
		);
	}

	/**
	 * Set the judge callable at runtime.
	 *
	 * @param callable|null $callable Callable or null to clear.
	 * @return void
	 */
	public function set_judge_callable( $callable ) {
		$this->judge_callable = is_callable( $callable ) ? $callable : null;
	}

	/**
	 * Whether a judge callable is configured.
	 *
	 * @return bool
	 */
	public function has_judge_callable() {
		return null !== $this->judge_callable;
	}

	/**
	 * Verify the subject using the judge callable.
	 *
	 * @param array $subject Subject.
	 * @param array $context Context.
	 * @return array|WP_Error
	 */
	public function verify( array $subject, array $context = array() ) {
		/**
		 * Filters the judge callable, allowing site owners to supply an
		 * independent judge (different provider/model/family) without
		 * subclassing.
		 *
		 * @since 1.3.0
		 *
		 * @param callable|null $callable Current judge callable.
		 * @param string        $slug     Verifier slug.
		 * @param array         $subject  Subject.
		 * @param array         $context  Context.
		 */
		$callable = apply_filters(
			'wp_mcp_ai_llm_judge_callable',
			$this->judge_callable,
			$this->slug,
			$subject,
			$context
		);

		if ( ! is_callable( $callable ) ) {
			// Abstain rather than pretend to verify.
			return array(
				'passed'     => false,
				'score'      => 0.5,
				'confidence' => 0.0,
				'reasons'    => array( __( 'LLM judge is not configured; abstaining.', 'mcp-ai-wpoos' ) ),
				'evidence'   => array( 'abstained' => true ),
			);
		}

		$result = call_user_func( $callable, $subject, $context );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) ) {
			return new WP_Error(
				'wp_mcp_ai_llm_judge_invalid_return',
				__( 'LLM judge callable returned an invalid value.', 'mcp-ai-wpoos' )
			);
		}

		return $this->normalize_judge_result( $result );
	}

	/**
	 * Normalize a judge result so downstream consumers get a stable shape.
	 *
	 * @param array $result Raw result.
	 * @return array
	 */
	protected function normalize_judge_result( array $result ) {
		$passed     = ! empty( $result['passed'] );
		$score      = isset( $result['score'] ) ? $this->clamp( (float) $result['score'] ) : ( $passed ? 1.0 : 0.0 );
		$confidence = isset( $result['confidence'] ) ? $this->clamp( (float) $result['confidence'] ) : 0.5;
		$reasons    = array();
		if ( isset( $result['reasons'] ) && is_array( $result['reasons'] ) ) {
			foreach ( $result['reasons'] as $reason ) {
				if ( is_scalar( $reason ) ) {
					$reasons[] = (string) $reason;
				}
			}
		}
		$evidence = isset( $result['evidence'] ) && is_array( $result['evidence'] ) ? $result['evidence'] : array();

		return array(
			'passed'     => $passed,
			'score'      => $score,
			'confidence' => $confidence,
			'reasons'    => $reasons,
			'evidence'   => $evidence,
		);
	}
}
