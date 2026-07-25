<?php
/**
 * WordPress Adapter — Rate Limiter.
 *
 * @package  Nvoos\WordPress
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\RateLimiterInterface;

/**
 * WordPress adapter for rate limiting using transients.
 *
 * @since 2.0.0
 */
final class RateLimiter implements RateLimiterInterface
{
    public function isAllowed(string $key, int $maxRequests, int $windowSeconds): bool
    {
        return $this->remaining($key, $maxRequests, $windowSeconds) > 0;
    }

    public function record(string $key, int $windowSeconds = 60): void
    {
        if (!\function_exists('get_transient') || !\function_exists('set_transient')) {
            return;
        }

        $cacheKey = 'wp_mcp_ai_rl_' . \md5($key);
        $current  = \get_transient($cacheKey);

        if ($current === false) {
            \set_transient($cacheKey, 1, $windowSeconds);
        } else {
            \set_transient($cacheKey, (int) $current + 1, $windowSeconds);
        }
    }

    public function remaining(string $key, int $maxRequests, int $windowSeconds): int
    {
        if (!\function_exists('get_transient')) {
            return $maxRequests;
        }

        $cacheKey = 'wp_mcp_ai_rl_' . \md5($key);
        $current  = \get_transient($cacheKey);

        if ($current === false) {
            return $maxRequests;
        }

        return \max(0, $maxRequests - (int) $current);
    }

    public function reset(string $key): void
    {
        if (\function_exists('delete_transient')) {
            \delete_transient('wp_mcp_ai_rl_' . \md5($key));
        }
    }
}
