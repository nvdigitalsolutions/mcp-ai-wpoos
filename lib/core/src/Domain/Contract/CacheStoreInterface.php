<?php
/**
 * Cache store contract for the oOS AI orchestration core.
 *
 * Extends PSR-6 (Caching Interface) with simpler convenience methods
 * that match the transient/option-cache pattern used throughout the
 * existing plugin. Adapters for each platform implement the full
 * interface.
 *
 *  - WordPress: wraps get_transient / set_transient / wp_cache_*
 *  - Laravel:   wraps Cache facade / Redis / memcached
 *  - Standalone: wraps PSR-6 pool (Symfony Cache, etc.)
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Domain\Contract;

use Psr\Cache\CacheItemPoolInterface;

interface CacheStoreInterface extends CacheItemPoolInterface {

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
