<?php
/**
 * Verifier Base Class
 *
 * Convenience base class implementing {@see Verifier}.
 * Concrete verifiers extend this class and typically only need to override
 * {@see VerifierBase::verify()}.
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
 * Abstract verifier base.
 */
abstract class VerifierBase implements Verifier {

	/**
	 * Unique slug. Must be overridden.
	 *
	 * @var string
	 */
	protected $slug = '';

	/**
	 * Human-readable label.
	 *
	 * @var string
	 */
	protected $label = '';

	/**
	 * Verifier kind. See {@see Verifier} docblock.
	 *
	 * @var string
	 */
	protected $kind = 'rule';

	/**
	 * Independence profile.
	 *
	 * @var array<string,mixed>
	 */
	protected $independence_profile = array(
		'disallowed_providers' => array(),
		'disallowed_models'    => array(),
		'disallowed_tools'     => array(),
		'allowed_domains'      => array(),
	);

	/**
	 * Get the verifier slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return $this->slug;
	}

	/**
	 * Get the verifier label.
	 *
	 * @return string
	 */
	public function get_label() {
		return '' !== $this->label ? $this->label : $this->slug;
	}

	/**
	 * Get the verifier kind.
	 *
	 * @return string
	 */
	public function get_kind() {
		return $this->kind;
	}

	/**
	 * Get the independence profile configuration.
	 *
	 * @return array<string,mixed>
	 */
	public function get_independence_profile() {
		return $this->independence_profile;
	}

	/**
	 * Build a canonical pass result array.
	 *
	 * @param float $score      Score [0.0, 1.0].
	 * @param float $confidence Confidence [0.0, 1.0].
	 * @param array $reasons    Reason strings.
	 * @param array $evidence   Evidence payload.
	 * @return array<string,mixed>
	 */
	protected function result_pass( $score = 1.0, $confidence = 1.0, array $reasons = array(), array $evidence = array() ) {
		return array(
			'passed'     => true,
			'score'      => $this->clamp( $score ),
			'confidence' => $this->clamp( $confidence ),
			'reasons'    => array_values( array_map( 'strval', $reasons ) ),
			'evidence'   => $evidence,
		);
	}

	/**
	 * Build a canonical fail result array.
	 *
	 * @param float $score      Score [0.0, 1.0].
	 * @param float $confidence Confidence [0.0, 1.0].
	 * @param array $reasons    Reason strings.
	 * @param array $evidence   Evidence payload.
	 * @return array<string,mixed>
	 */
	protected function result_fail( $score = 0.0, $confidence = 1.0, array $reasons = array(), array $evidence = array() ) {
		return array(
			'passed'     => false,
			'score'      => $this->clamp( $score ),
			'confidence' => $this->clamp( $confidence ),
			'reasons'    => array_values( array_map( 'strval', $reasons ) ),
			'evidence'   => $evidence,
		);
	}

	/**
	 * Clamp a value into [0.0, 1.0].
	 *
	 * @param float $value Value.
	 * @return float
	 */
	protected function clamp( $value ) {
		$value = (float) $value;
		if ( $value < 0.0 ) {
			return 0.0;
		}
		if ( $value > 1.0 ) {
			return 1.0;
		}
		return $value;
	}

	/**
	 * Default verify() must be overridden by concrete subclasses.
	 *
	 * @param array $subject Subject data.
	 * @param array $context Context.
	 * @return array<string,mixed>|WP_Error
	 */
	abstract public function verify( array $subject, array $context = array() );
}
