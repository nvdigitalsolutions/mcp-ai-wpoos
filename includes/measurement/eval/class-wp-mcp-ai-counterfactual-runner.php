<?php
/**
 * Counterfactual Runner
 *
 * Counterfactual testing is the anti-Goodhart guardrail for evals: if a
 * verifier cannot distinguish a real candidate from a deliberately
 * degraded variant of that candidate, the verifier is not measuring
 * what the suite author thinks it is. Running eval suites without this
 * check is how sites end up shipping reward-hacked agents that score
 * highly on broken rubrics.
 *
 * This helper runs the same verifier against:
 *   1. The candidate subject produced by a generator.
 *   2. N variants produced by degrading or shuffling that subject.
 *
 * It records, for every case:
 *   - `candidate_score`   — verifier score for the candidate.
 *   - `variant_scores`    — scores for each variant.
 *   - `preferred`         — true when the candidate scores strictly
 *                           higher than every variant.
 *   - `flat`              — true when min/max variant scores are
 *                           within `flat_epsilon` of the candidate:
 *                           this is the canonical "verifier has no
 *                           signal" failure mode.
 *   - `reasons`           — human-readable summary for the runner
 *                           report (no prompt/output content).
 *
 * Degraders are pure functions over the subject value; they never
 * produce new network I/O, call the LLM, or mutate the candidate. The
 * base plugin ships three stock degraders (`shuffle_tokens`,
 * `drop_citations`, `truncate_to_prefix`); sites can register custom
 * degraders via the `wp_mcp_ai_counterfactual_degraders` filter.
 *
 * Privacy posture:
 *   - Candidate and variant values are held in-memory only, never
 *     written to the persistent store.
 *   - The returned report surfaces scores and flags — NOT the variant
 *     text. Callers that want to inspect variants must do so directly
 *     from a custom degrader.
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
 * Counterfactual runner.
 */
class WP_MCP_AI_Counterfactual_Runner {

	/**
	 * Default epsilon for "flat signal" detection. A verifier that
	 * scores the candidate and every variant within 1e-6 of each
	 * other is producing no discriminative signal.
	 */
	const DEFAULT_FLAT_EPSILON = 0.01;

	/**
	 * Verifier registry.
	 *
	 * @var WP_MCP_AI_Verifier_Registry
	 */
	private $verifiers;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_Verifier_Registry|null $verifiers Verifier registry.
	 */
	public function __construct( $verifiers = null ) {
		$this->verifiers = $verifiers instanceof WP_MCP_AI_Verifier_Registry
			? $verifiers
			: WP_MCP_AI_Verifier_Registry::get_instance();
	}

	/**
	 * Stock degraders. Each maps a subject `value` to a degraded
	 * variant `value`. They are deliberately small, deterministic,
	 * and free of external dependencies.
	 *
	 * @return array<string,callable>
	 */
	public static function stock_degraders() {
		$base = array(
			'shuffle_tokens'     => array( __CLASS__, 'degrade_shuffle_tokens' ),
			'drop_citations'     => array( __CLASS__, 'degrade_drop_citations' ),
			'truncate_to_prefix' => array( __CLASS__, 'degrade_truncate_to_prefix' ),
		);

		/**
		 * Filters the counterfactual degrader catalogue.
		 *
		 * Keys are stable slugs; values must be callables taking the
		 * subject value and returning a degraded value. Callables
		 * that throw or return the original value are tolerated but
		 * reduce the discriminative power of the check.
		 *
		 * @since 1.3.0
		 *
		 * @param array<string,callable> $degraders Stock degraders.
		 */
		$filtered = apply_filters( 'wp_mcp_ai_counterfactual_degraders', $base );
		return is_array( $filtered ) ? $filtered : $base;
	}

	/**
	 * Run a counterfactual check for a single candidate.
	 *
	 * @param string                     $verifier_slug Verifier slug registered on the base registry.
	 * @param array<string,mixed>        $subject       Subject array (expects `value`, may also provide `input`, `expected`).
	 * @param array<int,string|callable> $variants     List of degrader slugs or inline callables.
	 * @param array<string,mixed>        $options       Options: `verifier_context`, `provider_context`, `flat_epsilon`.
	 * @return array<string,mixed>                     Report.
	 */
	public function run( $verifier_slug, array $subject, array $variants, array $options = array() ) {
		$verifier_slug    = sanitize_key( (string) $verifier_slug );
		$verifier_context = isset( $options['verifier_context'] ) && is_array( $options['verifier_context'] ) ? $options['verifier_context'] : array();
		$provider_context = isset( $options['provider_context'] ) && is_array( $options['provider_context'] ) ? $options['provider_context'] : array();
		$flat_epsilon     = isset( $options['flat_epsilon'] ) ? max( 0.0, (float) $options['flat_epsilon'] ) : self::DEFAULT_FLAT_EPSILON;

		$candidate_result = $this->verifiers->run( $verifier_slug, $subject, $verifier_context, $provider_context );
		if ( is_wp_error( $candidate_result ) ) {
			return array(
				'preferred'       => false,
				'flat'            => false,
				'error'           => $candidate_result->get_error_code(),
				'candidate_score' => 0.0,
				'variant_scores'  => array(),
				'reasons'         => array( 'candidate verification failed' ),
			);
		}

		$candidate_score = isset( $candidate_result['score'] ) ? (float) $candidate_result['score'] : 0.0;

		$degrader_catalogue = self::stock_degraders();
		$variant_scores     = array();
		$reasons            = array();

		foreach ( $variants as $idx => $variant_spec ) {
			$degrader = $this->resolve_degrader( $variant_spec, $degrader_catalogue );
			if ( null === $degrader ) {
				$reasons[] = sprintf( 'skipped unknown degrader at position %d', (int) $idx );
				continue;
			}

			$variant_value = $this->apply_degrader( $degrader['callable'], $subject );
			if ( null === $variant_value ) {
				$reasons[] = sprintf( 'degrader "%s" produced no value', (string) $degrader['slug'] );
				continue;
			}

			$variant_subject          = $subject;
			$variant_subject['value'] = $variant_value;

			$variant_result = $this->verifiers->run( $verifier_slug, $variant_subject, $verifier_context, $provider_context );
			if ( is_wp_error( $variant_result ) ) {
				$reasons[] = sprintf( 'variant "%s" errored (%s)', (string) $degrader['slug'], $variant_result->get_error_code() );
				continue;
			}

			$variant_scores[ (string) $degrader['slug'] ] = isset( $variant_result['score'] ) ? (float) $variant_result['score'] : 0.0;
		}

		$max_variant_score = 0.0;
		$min_variant_score = 0.0;
		$has_variants      = ! empty( $variant_scores );
		if ( $has_variants ) {
			$max_variant_score = max( $variant_scores );
			$min_variant_score = min( $variant_scores );
		}

		$preferred = $has_variants && ( $candidate_score > ( $max_variant_score + $flat_epsilon ) );
		$flat      = $has_variants && ( abs( $candidate_score - $max_variant_score ) <= $flat_epsilon )
			&& ( abs( $candidate_score - $min_variant_score ) <= $flat_epsilon );

		if ( ! $has_variants ) {
			$reasons[] = 'no variants evaluated';
		} elseif ( $flat ) {
			$reasons[] = 'candidate and variants are within flat_epsilon (verifier has no discriminative signal)';
		} elseif ( ! $preferred ) {
			$reasons[] = sprintf( 'candidate did not score strictly above variants (candidate=%.3f, max_variant=%.3f)', $candidate_score, $max_variant_score );
		}

		return array(
			'preferred'       => $preferred,
			'flat'            => $flat,
			'candidate_score' => $candidate_score,
			'variant_scores'  => $variant_scores,
			'reasons'         => $reasons,
		);
	}

	/**
	 * Resolve a variant specification into a callable.
	 *
	 * @param mixed                  $variant_spec A slug string or a callable.
	 * @param array<string,callable> $catalogue    Known degraders.
	 * @return array{slug:string,callable:callable}|null
	 */
	private function resolve_degrader( $variant_spec, array $catalogue ) {
		if ( is_string( $variant_spec ) && isset( $catalogue[ $variant_spec ] ) && is_callable( $catalogue[ $variant_spec ] ) ) {
			return array(
				'slug'     => $variant_spec,
				'callable' => $catalogue[ $variant_spec ],
			);
		}
		if ( is_callable( $variant_spec ) ) {
			return array(
				'slug'     => 'inline',
				'callable' => $variant_spec,
			);
		}
		return null;
	}

	/**
	 * Apply a degrader to a subject.
	 *
	 * @param callable $fn      Degrader.
	 * @param array    $subject Subject.
	 * @return mixed|null         Degraded value or null on failure.
	 */
	private function apply_degrader( $fn, array $subject ) {
		$value = array_key_exists( 'value', $subject ) ? $subject['value'] : null;
		try {
			return call_user_func( $fn, $value, $subject );
		} catch ( Exception $e ) {
			return null;
		}
	}

	// -------------------------------------------------------------------
	// Stock degraders
	// -------------------------------------------------------------------

	/**
	 * Shuffle whitespace-delimited tokens. For arrays of strings, the
	 * order of elements is reversed. For non-string/non-array values,
	 * returns the original value unchanged (the runner will mark this
	 * as a failed degrader if the verifier then scores identically).
	 *
	 * @param mixed $value Subject value.
	 * @return mixed
	 */
	public static function degrade_shuffle_tokens( $value ) {
		if ( is_string( $value ) ) {
			$parts = preg_split( '/\s+/', trim( $value ) );
			if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
				return $value;
			}
			return implode( ' ', array_reverse( $parts ) );
		}
		if ( is_array( $value ) ) {
			return array_reverse( $value );
		}
		return $value;
	}

	/**
	 * Drop citation-like substrings. Removes markdown reference links,
	 * `[1]`-style numeric citations, and URLs. Intended for prose;
	 * non-strings pass through.
	 *
	 * @param mixed $value Subject value.
	 * @return mixed
	 */
	public static function degrade_drop_citations( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		$out = $value;
		// Markdown reference links.
		$out = preg_replace( '/\[[^\]]*\]\([^)]*\)/', '', $out );
		// Numeric citations like [1] or [12].
		$out = preg_replace( '/\[\d+\]/', '', (string) $out );
		// Bare URLs.
		$out = preg_replace( '#https?://\S+#', '', (string) $out );
		return null === $out ? $value : trim( (string) $out );
	}

	/**
	 * Truncate strings to their first 25% by length (min 1 char). For
	 * arrays, keeps the first quarter of elements. Non-strings/arrays
	 * pass through.
	 *
	 * @param mixed $value Subject value.
	 * @return mixed
	 */
	public static function degrade_truncate_to_prefix( $value ) {
		if ( is_string( $value ) ) {
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
			if ( $len < 4 ) {
				return '' === $value ? $value : substr( $value, 0, 1 );
			}
			$cut = (int) floor( $len * 0.25 );
			if ( $cut < 1 ) {
				$cut = 1;
			}
			return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $cut ) : substr( $value, 0, $cut );
		}
		if ( is_array( $value ) ) {
			$n = count( $value );
			if ( $n < 4 ) {
				return array_slice( $value, 0, 1 );
			}
			return array_slice( $value, 0, (int) floor( $n * 0.25 ) );
		}
		return $value;
	}
}
