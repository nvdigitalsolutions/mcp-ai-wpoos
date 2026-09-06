<?php
/**
 * Verifier Interface
 *
 * Contract for pluggable verifiers in the measurement subsystem. Verifiers
 * score outputs orthogonally to the generator (verifier's law): implementations
 * MUST NOT reuse the same prompt, tool, or model family that produced the
 * output being verified. This independence is declared via
 * {@see Verifier::get_independence_profile()} and enforced
 * by the verifier registry.
 *
 * @package NvoosContentGraphAiPlatform
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Measurement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface implemented by every measurement verifier.
 *
 * Verifier kinds (not enforced in PHP 7.4):
 *   - rule           deterministic rule/schema checks (no LLM call)
 *   - schema         structural/JSON-schema verification
 *   - llm_judge      LLM-based judge (must differ from generator)
 *   - external_peer  cross-verification against a federated peer
 *   - human          human-in-the-loop review queue
 */
interface Verifier {

	/**
	 * Unique slug used to look up the verifier.
	 *
	 * @return string
	 */
	public function get_slug();

	/**
	 * Human-readable label.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Verifier kind string (see class docblock).
	 *
	 * @return string
	 */
	public function get_kind();

	/**
	 * Independence profile used by the registry to prevent self-grading.
	 *
	 * Expected keys:
	 *   - disallowed_providers  array<string>
	 *   - disallowed_models     array<string>
	 *   - disallowed_tools      array<string>
	 *   - allowed_domains       array<string>  Optional allow-list.
	 *
	 * @return array<string,mixed>
	 */
	public function get_independence_profile();

	/**
	 * Verify an output.
	 *
	 * The $subject array contains the output to be verified plus minimal
	 * context. Implementations MUST NOT mutate $subject. Implementations MUST
	 * NOT raise exceptions for routine verification failures — return a
	 * WP_Error only when the verifier itself cannot run.
	 *
	 * Successful return shape:
	 *   array(
	 *     'passed'     => bool,
	 *     'score'      => float (0.0 - 1.0),
	 *     'confidence' => float (0.0 - 1.0),
	 *     'reasons'    => array<string>,
	 *     'evidence'   => array<string,mixed>,
	 *   )
	 *
	 * @param array $subject Output and context to verify.
	 * @param array $context Optional pipeline context (assistant_id, model...).
	 * @return array<string,mixed>|WP_Error
	 */
	public function verify( array $subject, array $context = array() );
}
