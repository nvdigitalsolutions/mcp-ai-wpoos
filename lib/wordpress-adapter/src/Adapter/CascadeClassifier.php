<?php
/**
 * WordPress adapter: ComplexityClassifierInterface implementation.
 *
 * Bridges the WordPress cascade seam (`wp_mcp_ai_cascade_classifier`
 * filter, Proposal 056 P1) into the framework-agnostic
 * ComplexityClassifierInterface so the OOS engine path (ChatOrchestrator →
 * CascadeRouter) shares one classifier with the legacy path.
 *
 * Fail-closed by design: when cascade routing is disabled or no listener
 * supplies a verdict, the adapter classifies every request as `complex` so
 * the CascadeRouter dispatches to the primary provider unchanged.
 *
 * @package Nvoos\WordPress
 * @since   1.4.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\ComplexityClassifierInterface;

class CascadeClassifier implements ComplexityClassifierInterface {

	/**
	 * Classify a message list into a routing tier.
	 *
	 * @param array<int, array<string, mixed>> $messages Chat messages.
	 * @param array<string, mixed>             $options  Request options.
	 * @return array{tier: 'simple'|'complex', confidence: float, reason: string}
	 */
	public function classify( array $messages, array $options = array() ): array {
		/**
		 * Filters whether cascade routing is active (shared with the legacy
		 * executor — one switch governs both paths).
		 *
		 * @param bool  $enabled  Whether cascading runs. Default false.
		 * @param array $messages Chat messages.
		 * @param array $options  Request options.
		 */
		if ( ! (bool) \apply_filters( 'wp_mcp_ai_cascade_enabled', false, $messages, $options ) ) {
			return array( 'tier' => 'complex', 'confidence' => 0.0, 'reason' => 'cascade-disabled' );
		}

		/**
		 * Filters the cascade classification (shared with the legacy
		 * executor). Listeners return
		 * `{ tier: 'simple'|'complex', confidence: 0..1, reason: string }`.
		 *
		 * @param array|null $verdict  Classification verdict or null.
		 * @param array      $messages Chat messages.
		 * @param array      $options  Request options.
		 */
		$verdict = \apply_filters( 'wp_mcp_ai_cascade_classifier', null, $messages, $options );

		if ( \is_array( $verdict ) && 'simple' === ( $verdict['tier'] ?? '' ) ) {
			return array(
				'tier'       => 'simple',
				'confidence' => \max( 0.0, \min( 1.0, (float) ( $verdict['confidence'] ?? 0.0 ) ) ),
				'reason'     => (string) ( $verdict['reason'] ?? '' ),
			);
		}

		return array( 'tier' => 'complex', 'confidence' => 0.0, 'reason' => 'no-cascade-classifier' );
	}
}
