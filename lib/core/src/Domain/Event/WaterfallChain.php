<?php
/**
 * Waterfall chain builder — pure, framework-agnostic chain composition.
 *
 * Converts an ordered listener list into a single middleware chain where
 * each listener receives ($event, $next) and may short-circuit by
 * returning without calling $next(). The innermost $next is the caller's
 * fallback.
 *
 * Extracted from the dispatcher adapters so the chain semantics are
 * unit-testable without any platform code.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Event;

final class WaterfallChain {

	/**
	 * Compose listeners into a chain.
	 *
	 * @param callable[] $listeners Ordered listeners (highest priority first).
	 * @param callable   $fallback  Innermost handler: function( object $event ): mixed.
	 * @return callable  function( object $event ): mixed
	 */
	public static function build( array $listeners, callable $fallback ): callable {
		$chain = $fallback;

		foreach ( \array_reverse( $listeners ) as $listener ) {
			$next  = $chain;
			$chain = static function ( object $event ) use ( $listener, $next ): mixed {
				return $listener( $event, $next );
			};
		}

		return $chain;
	}
}
