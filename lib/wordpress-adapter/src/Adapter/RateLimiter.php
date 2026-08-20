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
 * In addition to the contract methods, the adapter keeps an enumerable
 * index of active rate-limit keys (option `wp_mcp_ai_rl_index`) and fires
 * the `wp_mcp_ai_rate_limit_exceeded` action when a window is exhausted.
 * This allows WordPress-side code (e.g. the restriction registry) to flag
 * restricted users and reset their windows on unblock.
 *
 * @since 2.0.0
 */
final class RateLimiter implements RateLimiterInterface
{
    /**
     * Option key for the enumerable active-key index.
     */
    private const INDEX_OPTION = 'wp_mcp_ai_rl_index';

    public function isAllowed(string $key, int $maxRequests, int $windowSeconds): bool
    {
        $allowed = $this->remaining($key, $maxRequests, $windowSeconds) > 0;

        if (!$allowed && \function_exists('do_action')) {
            /**
             * Fires when a rate-limit window is exhausted.
             *
             * @since 2.0.0
             *
             * @param string $key           Raw rate-limit key (e.g. "chat:1:42").
             * @param int    $maxRequests   Requests allowed per window.
             * @param int    $windowSeconds Window length in seconds.
             */
            \do_action('wp_mcp_ai_rate_limit_exceeded', $key, $maxRequests, $windowSeconds);
        }

        return $allowed;
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

        $this->indexKey($key, $windowSeconds);
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

        $this->unindexKey($key);
    }

    /**
     * Enumerate all raw rate-limit keys with live windows.
     *
     * @return array<int, string> Raw rate-limit keys.
     */
    public function enumerateKeys(): array
    {
        $keys = array();
        foreach ($this->prunedIndex() as $entry) {
            $keys[] = $entry['key'];
        }
        return $keys;
    }

    /**
     * Reset every rate-limit window whose raw key shares a prefix.
     *
     * Used by the restriction registry to clear all "chat:{userId}:" windows
     * for a user when an administrator lifts a rate-limit restriction.
     *
     * @param string $prefix Raw key prefix.
     */
    public function resetForPrefix(string $prefix): void
    {
        foreach ($this->prunedIndex() as $entry) {
            if (0 === \strpos((string) $entry['key'], $prefix)) {
                $this->reset((string) $entry['key']);
            }
        }
    }

    /**
     * Add a raw key to the enumerable index.
     *
     * @param string $key           Raw rate-limit key.
     * @param int    $windowSeconds Window length in seconds.
     */
    private function indexKey(string $key, int $windowSeconds): void
    {
        if (!\function_exists('get_option') || !\function_exists('update_option')) {
            return;
        }

        $index = $this->prunedIndex();

        $index[\md5($key)] = array(
            'key'     => $key,
            'expires' => \time() + \max(1, $windowSeconds),
        );

        \update_option(self::INDEX_OPTION, $index, false);
    }

    /**
     * Remove a raw key from the enumerable index.
     *
     * @param string $key Raw rate-limit key.
     */
    private function unindexKey(string $key): void
    {
        if (!\function_exists('get_option') || !\function_exists('update_option')) {
            return;
        }

        $index = $this->prunedIndex();
        unset($index[\md5($key)]);

        \update_option(self::INDEX_OPTION, $index, false);
    }

    /**
     * Read the index and drop expired entries.
     *
     * @return array<string, array{key: string, expires: int}> Live index.
     */
    private function prunedIndex(): array
    {
        if (!\function_exists('get_option')) {
            return array();
        }

        $index = \get_option(self::INDEX_OPTION, array());
        if (!\is_array($index)) {
            return array();
        }

        $now     = \time();
        $changed = false;
        $live    = array();

        foreach ($index as $hash => $entry) {
            if (!\is_array($entry) || !isset($entry['key'])) {
                $changed = true;
                continue;
            }

            $expires = isset($entry['expires']) ? (int) $entry['expires'] : 0;
            if ($expires > 0 && $expires < $now) {
                $changed = true;
                continue;
            }

            $live[$hash] = array(
                'key'     => (string) $entry['key'],
                'expires' => $expires,
            );
        }

        if ($changed && \function_exists('update_option')) {
            \update_option(self::INDEX_OPTION, $live, false);
        }

        return $live;
    }
}
