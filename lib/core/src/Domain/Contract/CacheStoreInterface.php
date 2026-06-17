<?php
/**
 * Cache store contract for the oOS AI orchestration core.
 *
 * Domain-owned contract with a transient-style convenience API.
 * Platform adapters implement this interface directly — no PSR-6
 * or Symfony dependency in the core.
 *
 *  - WordPress: wraps get_transient / set_transient / wp_cache_*
 *  - Laravel:   wraps Cache facade / Redis / memcached
 *  - Standalone: wraps PSR-6 pool or Symfony Cache
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface CacheStoreInterface {

	/**
	 * Get a cached value with a default fallback.
	 *
	 * Simpler API than the full PSR-6 get/save workflow — ideal for
	 * the transient-style pattern used throughout the plugin.
	 *
	 * @return mixed  Cached value, or $default on cache miss.
	 */
	public function getValue( string $key, mixed $default = null ): mixed;

	/**
	 * Set a cached value with a TTL in seconds.
	 *
	 * @return bool  True on success, false on failure.
	 */
	public function setValue( string $key, mixed $value, int $ttl = 3600 ): bool;

	/**
	 * Delete a cached value.
	 *
	 * @return bool  True on success, false on failure.
	 */
	public function deleteValue( string $key ): bool;

	/**
	 * Atomically increment a numeric cache value.
	 *
	 * Used for rate limit counters and usage tracking. If the key
	 * does not exist, it is initialized to 0 before incrementing.
	 *
	 * @return int  The new value after incrementing.
	 */
	public function increment( string $key, int $by = 1, int $ttl = 3600 ): int;

	/**
	 * Remember a value in cache, computing it lazily on cache miss.
	 *
	 * If the key exists, returns the cached value without calling
	 * $callback. If the key does not exist, calls $callback,
	 * caches the result for $ttl seconds, and returns it.
	 *
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	public function remember( string $key, int $ttl, callable $callback ): mixed;
}
