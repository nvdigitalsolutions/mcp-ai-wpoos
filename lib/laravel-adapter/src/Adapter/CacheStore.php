<?php
/**
 * Laravel adapter: CacheStoreInterface implementation.
 *
 * Wraps Laravel's Cache facade (supporting Redis, memcached, file,
 * database, array, and DynamoDB drivers) behind the PSR-6-extending
 * CacheStoreInterface. Provides both the full PSR-6 pool and the
 * simpler transient-style convenience methods used throughout the
 * oOS core.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\CacheStoreInterface;
use Psr\Cache\CacheItemInterface;
use Illuminate\Support\Facades\Cache;

class CacheStore implements CacheStoreInterface {

	/**
	 * Laravel cache store name (e.g. 'redis', 'memcached', 'file').
	 *
	 * When empty, uses the application default.
	 */
	private string $storeName;

	/**
	 * Prefix added to all keys to avoid collisions with other cache consumers.
	 */
	private const PREFIX = 'nvoos_cache_';

	/**
	 * @param string $storeName  Laravel cache store to use ('redis', 'memcached', etc.).
	 *                           Defaults to the application default when empty.
	 */
	public function __construct( string $storeName = '' ) {
		$this->storeName = $storeName;
	}

	/**
	 * Get the Cache store instance.
	 */
	private function store(): \Illuminate\Contracts\Cache\Store {
		return '' !== $this->storeName
			? Cache::store( $this->storeName )->getStore()
			: Cache::store()->getStore();
	}

	/**
	 * Apply the adapter prefix to a cache key.
	 */
	private function prefixedKey( string $key ): string {
		return self::PREFIX . $key;
	}

	// ─── CacheStoreInterface convenience methods ─────────────────────

	/**
	 * Get a cached value with a default fallback.
	 *
	 * @param string $key      Cache key (auto-prefixed).
	 * @param mixed  $default  Value returned on cache miss.
	 * @return mixed
	 */
	public function getValue( string $key, mixed $default = null ): mixed {
		return Cache::store( $this->storeName )->get(
			$this->prefixedKey( $key ),
			$default,
		);
	}

	/**
	 * Set a cached value with a TTL in seconds.
	 *
	 * @param string $key   Cache key (auto-prefixed).
	 * @param mixed  $value Value to cache.
	 * @param int    $ttl   Time-to-live in seconds.
	 * @return bool
	 */
	public function setValue( string $key, mixed $value, int $ttl = 3600 ): bool {
		return Cache::store( $this->storeName )->put(
			$this->prefixedKey( $key ),
			$value,
			$ttl,
		);
	}

	/**
	 * Delete a cached value.
	 *
	 * @param string $key  Cache key (auto-prefixed).
	 * @return bool
	 */
	public function deleteValue( string $key ): bool {
		return Cache::store( $this->storeName )->forget(
			$this->prefixedKey( $key ),
		);
	}

	/**
	 * Atomically increment a numeric cache value.
	 *
	 * If the key does not exist, Laravel initialises it to 0 before
	 * incrementing.
	 *
	 * @param string $key  Cache key (auto-prefixed).
	 * @param int    $by   Amount to increment by.
	 * @param int    $ttl  Not used — Laravel preserves existing TTL on increment.
	 * @return int  The new value after incrementing.
	 */
	public function increment( string $key, int $by = 1, int $ttl = 3600 ): int {
		return Cache::store( $this->storeName )->increment(
			$this->prefixedKey( $key ),
			$by,
		);
	}

	/**
	 * Remember a value in cache, computing it lazily on cache miss.
	 *
	 * @template T
	 * @param string     $key       Cache key (auto-prefixed).
	 * @param int        $ttl       Time-to-live in seconds.
	 * @param callable(): T $callback  Called on cache miss; result is cached.
	 * @return T
	 */
	public function remember( string $key, int $ttl, callable $callback ): mixed {
		return Cache::store( $this->storeName )->remember(
			$this->prefixedKey( $key ),
			$ttl,
			$callback,
		);
	}

	// ─── PSR-6 CacheItemPoolInterface methods ─────────────────────────

	/**
	 * Get a single cache item (PSR-6).
	 *
	 * @param string $key  Cache key (auto-prefixed).
	 * @return CacheItemInterface
	 */
	public function getItem( string $key ): CacheItemInterface {
		return new class($key, $this->getValue( $key, null ), $this) implements CacheItemInterface {
			private bool $hit;

			public function __construct(
				private string $key,
				private mixed $value,
				private CacheStoreInterface $store,
			) {
				$this->hit = null !== $value;
			}

			public function getKey(): string {
				return $this->key;
			}

			public function get(): mixed {
				return $this->value;
			}

			public function isHit(): bool {
				return $this->hit;
			}

			public function set( mixed $value ): self {
				$this->value = $value;
				$this->hit   = true;
				return $this;
			}

			public function expiresAt( ?\DateTimeInterface $expiration ): self {
				return $this;
			}

			public function expiresAfter( \DateInterval|int|null $time ): self {
				return $this;
			}
		};
	}

	/**
	 * Get multiple cache items at once (PSR-6).
	 *
	 * @param string[] $keys  Cache keys (auto-prefixed).
	 * @return iterable<string, CacheItemInterface>
	 */
	public function getItems( array $keys = array() ): iterable {
		$items = array();
		foreach ( $keys as $key ) {
			$items[ $key ] = $this->getItem( $key );
		}
		return $items;
	}

	/**
	 * Check if a cache key exists (PSR-6).
	 *
	 * @param string $key  Cache key (auto-prefixed).
	 * @return bool
	 */
	public function hasItem( string $key ): bool {
		return Cache::store( $this->storeName )->has(
			$this->prefixedKey( $key ),
		);
	}

	/**
	 * Clear the entire cache store (PSR-6).
	 *
	 * Destructive — clears all keys in the configured store, not just
	 * the oOS prefix. Platform-appropriate scoping should use a dedicated
	 * cache store for oOS.
	 *
	 * @return bool
	 */
	public function clear(): bool {
		return Cache::store( $this->storeName )->flush();
	}

	/**
	 * Delete a single cache item (PSR-6).
	 *
	 * @param string $key  Cache key (auto-prefixed).
	 * @return bool
	 */
	public function deleteItem( string $key ): bool {
		return $this->deleteValue( $key );
	}

	/**
	 * Delete multiple cache items (PSR-6).
	 *
	 * @param string[] $keys  Cache keys (auto-prefixed).
	 * @return bool  True if all items were deleted.
	 */
	public function deleteItems( array $keys ): bool {
		$success = true;
		foreach ( $keys as $key ) {
			if ( ! $this->deleteValue( $key ) ) {
				$success = false;
			}
		}
		return $success;
	}

	/**
	 * Save a cache item immediately (PSR-6).
	 *
	 * @param CacheItemInterface $item  The item to persist.
	 * @return bool
	 */
	public function save( CacheItemInterface $item ): bool {
		return $this->setValue( $item->getKey(), $item->get() );
	}

	/**
	 * Save a cache item for later commit (PSR-6).
	 *
	 * Deferred saves are not supported — saves immediately.
	 *
	 * @param CacheItemInterface $item  The item to persist.
	 * @return bool
	 */
	public function saveDeferred( CacheItemInterface $item ): bool {
		return $this->save( $item );
	}

	/**
	 * Commit deferred cache items (PSR-6).
	 *
	 * No-op since all saves are immediate.
	 *
	 * @return bool
	 */
	public function commit(): bool {
		return true;
	}
}
