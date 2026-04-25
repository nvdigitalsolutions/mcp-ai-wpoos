<?php
/**
 * Example: Custom Eval Verifier
 *
 * Reference snippet showing the minimum viable subclass of
 * {@see WP_MCP_AI_Verifier_Base}. This file is shipped under
 * `assets/examples/measurement/` for documentation; it is not
 * autoloaded by the plugin. Copy into your own site-glue plugin or
 * mu-plugin to use it.
 *
 * Anti-Goodhart guardrails baked in:
 *   - Declares an `independence_profile` so the runner refuses to run
 *     this verifier as a judge against a candidate from the same
 *     provider/model.
 *   - Returns an explicit abstention (score = 0.5, confidence = 0)
 *     when the input is empty, instead of silently passing.
 *   - Uses `result_pass()` / `result_fail()` helpers so reasons /
 *     evidence are recorded consistently for the dashboard.
 *
 * @package WP_MCP_AI_Examples
 * @since   1.3.0
 * @license GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Verifier_Base' ) ) {
	return;
}

/**
 * Minimum-length verifier — passes when `$subject['text']` is at least
 * `min_chars` characters long after trimming whitespace.
 *
 * Replace the body of `verify()` with whatever assertion your suite
 * actually needs (regex match, JSON-schema validation, deterministic
 * fixture compare, etc).
 */
class WP_MCP_AI_Example_Min_Length_Verifier extends WP_MCP_AI_Verifier_Base {

	/**
	 * Slug used by eval cases to reference this verifier.
	 *
	 * @var string
	 */
	protected $slug = 'example_min_length';

	/**
	 * Human-readable label surfaced in the dashboard.
	 *
	 * @var string
	 */
	protected $label = 'Example: Minimum length';

	/**
	 * Verifier kind. `rule` means deterministic, no LLM judge.
	 *
	 * @var string
	 */
	protected $kind = 'rule';

	/**
	 * Inclusive minimum character count.
	 *
	 * @var int
	 */
	private $min_chars = 24;

	/**
	 * Verify a candidate response.
	 *
	 * @param array<string,mixed> $subject Verifier subject. Expected:
	 *                                     - `text` (string) candidate output
	 * @param array<string,mixed> $context Optional runner context.
	 * @return array<string,mixed>|WP_Error Canonical verifier result.
	 */
	public function verify( array $subject, array $context = array() ) {
		unset( $context );

		$text = isset( $subject['text'] ) ? trim( (string) $subject['text'] ) : '';
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );

		// Empty input → abstain rather than silently fail.
		if ( '' === $text ) {
			return array(
				'passed'     => false,
				'score'      => 0.5,
				'confidence' => 0.0,
				'reasons'    => array( 'Empty subject text; abstaining.' ),
				'evidence'   => array( 'length' => 0 ),
			);
		}

		if ( $len >= $this->min_chars ) {
			return $this->result_pass(
				1.0,
				1.0,
				array( sprintf( 'Length %d ≥ %d.', $len, $this->min_chars ) ),
				array( 'length' => $len, 'min_chars' => $this->min_chars )
			);
		}

		return $this->result_fail(
			(float) $len / max( 1, $this->min_chars ),
			1.0,
			array( sprintf( 'Length %d < %d.', $len, $this->min_chars ) ),
			array( 'length' => $len, 'min_chars' => $this->min_chars )
		);
	}
}

// Register the verifier so eval cases can reference it by slug.
add_action(
	'wp_mcp_ai_register_verifiers',
	static function ( $registry ) {
		if ( $registry instanceof WP_MCP_AI_Verifier_Registry ) {
			$registry->register( new WP_MCP_AI_Example_Min_Length_Verifier() );
		}
	}
);
