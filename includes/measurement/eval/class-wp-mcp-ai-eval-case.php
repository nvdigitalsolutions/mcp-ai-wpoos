<?php
/**
 * Eval Case
 *
 * A single test case inside an eval suite. Intentionally a plain value
 * object: readable, immutable after construction, and trivially JSON-
 * serializable for eval report storage.
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
 * Eval Case.
 */
class WP_MCP_AI_Eval_Case {

	/**
	 * Case slug (unique within a suite).
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Human-readable label.
	 *
	 * @var string
	 */
	private $label;

	/**
	 * Generator input (prompt, tool args, etc.) — the exact shape is
	 * domain-specific and interpreted by the generator callable.
	 *
	 * @var array<string,mixed>
	 */
	private $input;

	/**
	 * Expected-output reference, passed to the verifier as `$subject['expected']`.
	 *
	 * @var mixed
	 */
	private $expected;

	/**
	 * Verifier slug to run against the generator output.
	 *
	 * @var string
	 */
	private $verifier_slug;

	/**
	 * Static arguments passed alongside the generator output to the verifier.
	 *
	 * @var array<string,mixed>
	 */
	private $verifier_args;

	/**
	 * Case metadata (tags, difficulty, source links — NOT PII).
	 *
	 * @var array<string,mixed>
	 */
	private $metadata;

	/**
	 * Stated confidence a generator SHOULD emit for this case, or `null` if
	 * not applicable. Used only by reward functions that score calibration.
	 *
	 * @var float|null
	 */
	private $target_confidence;

	/**
	 * Constructor.
	 *
	 * @param array $args Case args.
	 * @throws InvalidArgumentException When slug or verifier_slug is missing.
	 */
	public function __construct( array $args ) {
		$slug = isset( $args['slug'] ) ? sanitize_key( $args['slug'] ) : '';
		if ( '' === $slug ) {
			throw new InvalidArgumentException( 'Eval case requires a non-empty slug.' );
		}
		$verifier_slug = isset( $args['verifier_slug'] ) ? sanitize_key( $args['verifier_slug'] ) : '';
		if ( '' === $verifier_slug ) {
			throw new InvalidArgumentException( 'Eval case requires a verifier_slug.' );
		}

		$this->slug              = $slug;
		$this->label             = isset( $args['label'] ) ? (string) $args['label'] : $slug;
		$this->input             = isset( $args['input'] ) && is_array( $args['input'] ) ? $args['input'] : array();
		$this->expected          = array_key_exists( 'expected', $args ) ? $args['expected'] : null;
		$this->verifier_slug     = $verifier_slug;
		$this->verifier_args     = isset( $args['verifier_args'] ) && is_array( $args['verifier_args'] ) ? $args['verifier_args'] : array();
		$this->metadata          = isset( $args['metadata'] ) && is_array( $args['metadata'] ) ? $args['metadata'] : array();
		$this->target_confidence = isset( $args['target_confidence'] ) && is_numeric( $args['target_confidence'] )
			? (float) $args['target_confidence']
			: null;
	}

	/**
	 * Get slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return $this->slug;
	}

	/**
	 * Get label.
	 *
	 * @return string
	 */
	public function get_label() {
		return $this->label;
	}

	/**
	 * Get input.
	 *
	 * @return array
	 */
	public function get_input() {
		return $this->input;
	}

	/**
	 * Get expected value.
	 *
	 * @return mixed
	 */
	public function get_expected() {
		return $this->expected;
	}

	/**
	 * Get verifier slug.
	 *
	 * @return string
	 */
	public function get_verifier_slug() {
		return $this->verifier_slug;
	}

	/**
	 * Get verifier args.
	 *
	 * @return array
	 */
	public function get_verifier_args() {
		return $this->verifier_args;
	}

	/**
	 * Get metadata.
	 *
	 * @return array
	 */
	public function get_metadata() {
		return $this->metadata;
	}

	/**
	 * Get target confidence, or null if not specified.
	 *
	 * @return float|null
	 */
	public function get_target_confidence() {
		return $this->target_confidence;
	}

	/**
	 * Serialize to array for reports.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'slug'              => $this->slug,
			'label'             => $this->label,
			'verifier_slug'     => $this->verifier_slug,
			'metadata'          => $this->metadata,
			'target_confidence' => $this->target_confidence,
		);
	}
}
