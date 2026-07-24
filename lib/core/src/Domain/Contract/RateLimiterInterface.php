<?php
/**
 * Rate Limiter — domain contract.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

/**
 * Rate limiting for API operations.
 *
 * @since 2.0.0
 */
interface RateLimiterInterface
{
    /**
     * Check whether an operation is within the rate limit.
     *
     * @param string $key      Rate limit key (user_id, IP, endpoint, etc.).
     * @param int    $maxRequests Maximum requests allowed in the window.
     * @param int    $windowSeconds Time window in seconds.
     *
     * @return bool True if the operation is allowed.
     */
    public function isAllowed(string $key, int $maxRequests, int $windowSeconds): bool;

    /**
     * Record that an operation occurred (consumes one request from the limit).
     *
     * @param string $key      Rate limit key.
     * @param int    $windowSeconds Time window in seconds.
     */
    public function record(string $key, int $windowSeconds = 60): void;

    /**
     * Get the number of remaining requests in the current window.
     *
     * @param string $key      Rate limit key.
     * @param int    $maxRequests Maximum requests in the window.
     * @param int    $windowSeconds Time window in seconds.
     *
     * @return int Remaining requests.
     */
    public function remaining(string $key, int $maxRequests, int $windowSeconds): int;

    /**
     * Reset rate limit for a key.
     *
     * @param string $key Rate limit key.
     */
    public function reset(string $key): void;
}
