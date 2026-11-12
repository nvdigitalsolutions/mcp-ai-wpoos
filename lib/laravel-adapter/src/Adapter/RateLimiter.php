<?php
/**
 * Laravel adapter: rate limiter.
 *
 * Wraps the framework RateLimiter facade so core services can enforce
 * per-key limits without importing Laravel. `isAllowed()` is a read-only
 * check; callers consume a slot with `record()`.
 *
 * @package Nvoos\Laravel
 * @since   1.2.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Nvoos\Core\Domain\Contract\RateLimiterInterface;

class RateLimiter implements RateLimiterInterface {

	/**
	 * {@inheritDoc}
	 */
	public function isAllowed( string $key, int $maxRequests, int $windowSeconds ): bool {
		return ! RateLimiterFacade::tooManyAttempts( $key, max( 1, $maxRequests ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function record( string $key, int $windowSeconds = 60 ): void {
		RateLimiterFacade::hit( $key, max( 1, $windowSeconds ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function remaining( string $key, int $maxRequests, int $windowSeconds ): int {
		return RateLimiterFacade::remaining( $key, max( 1, $maxRequests ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function reset( string $key ): void {
		RateLimiterFacade::clear( $key );
	}
}
