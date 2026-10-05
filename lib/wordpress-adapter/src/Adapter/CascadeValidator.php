<?php
/**
 * WordPress adapter: ResponseValidatorInterface implementation.
 *
 * Bridges the WordPress cascade seam (`wp_mcp_ai_cascade_validator`
 * filter, Proposal 056 P1) into the framework-agnostic
 * ResponseValidatorInterface so the OOS engine path (ChatOrchestrator →
 * CascadeRouter) shares one validator with the legacy path.
 *
 * A deterministic default (error shapes, empty content) applies when no
 * listener overrides the verdict, so the base plugin works without Pro.
 *
 * @package Nvoos\WordPress
 * @since   1.4.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\ResponseValidatorInterface;

class CascadeValidator implements ResponseValidatorInterface {

	/**
	 * Validate a cheap-tier response against the request.
	 *
	 * @param mixed                            $response Provider response.
	 * @param array<int, array<string, mixed>> $messages Original chat messages.
	 * @param array<string, mixed>             $options  Request options.
	 * @return array{acceptable: bool, confidence: float, reason: string}
	 */
	public function validate( mixed $response, array $messages, array $options = array() ): array {
		$verdict = $this->defaultVerdict( $response );

		/**
		 * Filters the cascade validation verdict (shared with the legacy
		 * executor). A semantic judge can override `acceptable` and
		 * `confidence`; the deterministic default applies when listeners
		 * leave it unchanged.
		 *
		 * @param array $verdict   { acceptable, confidence, reason }.
		 * @param mixed $result    Cheap-tier provider result.
		 * @param array $messages  Chat messages.
		 * @param array $options   Request options.
		 */
		$verdict = \apply_filters( 'wp_mcp_ai_cascade_validator', $verdict, $response, $messages, $options );

		if ( ! \is_array( $verdict ) ) {
			return array( 'acceptable' => false, 'confidence' => 0.0, 'reason' => 'invalid-validator-verdict' );
		}

		return array(
			'acceptable' => ! empty( $verdict['acceptable'] ),
			'confidence' => \max( 0.0, \min( 1.0, (float) ( $verdict['confidence'] ?? 0.0 ) ) ),
			'reason'     => (string) ( $verdict['reason'] ?? '' ),
		);
	}

	/**
	 * Deterministic default verdict.
	 *
	 * Rejects error envelopes and empty content; otherwise accepts with a
	 * conservative default confidence.
	 *
	 * @param mixed $response Provider response.
	 * @return array{acceptable: bool, confidence: float, reason: string}
	 */
	private function defaultVerdict( mixed $response ): array {
		if ( $response instanceof \WP_Error ) {
			return array( 'acceptable' => false, 'confidence' => 0.0, 'reason' => 'provider error' );
		}

		if ( \is_array( $response ) && isset( $response['error'] ) ) {
			return array( 'acceptable' => false, 'confidence' => 0.0, 'reason' => 'error envelope' );
		}

		if ( '' === $this->extractContent( $response ) ) {
			return array( 'acceptable' => false, 'confidence' => 0.0, 'reason' => 'empty content' );
		}

		return array( 'acceptable' => true, 'confidence' => 0.9, 'reason' => 'deterministic default' );
	}

	/**
	 * Extract the text content from a provider result.
	 *
	 * @param mixed $response Provider response.
	 * @return string
	 */
	private function extractContent( mixed $response ): string {
		if ( \is_array( $response ) && isset( $response['choices'][0]['message']['content'] ) ) {
			$content = $response['choices'][0]['message']['content'];
			return \is_string( $content ) ? \trim( $content ) : '';
		}

		if ( \is_string( $response ) ) {
			return \trim( $response );
		}

		return '';
	}
}
