<?php
/**
 * Craft adapter: CacheStoreInterface implementation.
 *
 * Wraps Craft's Yii cache component (supporting Redis, database, file,
 * APC, and memcached) behind the PSR-6-extending CacheStoreInterface.
 *
 * @package Nvoos\Craft
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Craft\Adapter;

use Craft;
use Nvoos\Core\Domain\Contract\CacheStoreInterface;
use Psr\Cache\CacheItemInterface;

class CacheStore implements CacheStoreInterface {

	/**
	 * Prefix added to all keys to avoid collisions.
	 */
	private const PREFIX = 'nvoos_cache_';

	/**
	 * Default TTL in seconds for items without an explicit TTL.
	 */
	private int $defaultTtl;

	/**
	 * @param int $defaultTtl  Default TTL in seconds.
	 */
	public function __construct( int $defaultTtl = 3600 ) {
		$this->defaultTtl = $defaultTtl;
	}

	/**
	 * Apply the adapter prefix to a cache key.
	 */
	private function prefixedKey( string $key ): string {
		return self::PREFIX . $key;
	}

	// ─── CacheStoreInterface convenience methods ─────────────────────

	public function getValue( string $key, mixed $default = null ): mixed {
		$value = Craft::$app->cache->get( $this->prefixedKey( $key ) );

		return false !== $value ? $value : $default;
	}

	public function setValue( string $key, mixed $value, int $ttl = 3600 ): bool {
		return Craft::$app->cache->set(
			$this->prefixedKey( $key ),
			$value,
			$ttl,
		);
	}

	public function deleteValue( string $key ): bool {
		return Craft::$app->cache->delete( $this->prefixedKey( $key ) );
	}

	public function increment( string $key, int $by = 1, int $ttl = 3600 ): int {
		$prefixed = $this->prefixedKey( $key );
		$current  = (int) ( Craft::$app->cache->get( $prefixed ) ?: 0 );
		$newVal   = $current + $by;
		Craft::$app->cache->set( $prefixed, $newVal, $ttl );

		return $newVal;
	}

	public function remember( string $key, int $ttl, callable $callback ): mixed {
		$prefixed = $this->prefixedKey( $key );
		$cached   = Craft::$app->cache->get( $prefixed );

		if ( false !== $cached ) {
			return $cached;
		}

		$value = $callback();
		Craft::$app->cache->set( $prefixed, $value, $ttl );

		return $value;
	}

	// ─── PSR-6 CacheItemPoolInterface methods ─────────────────────────

	public function getItem( string $key ): CacheItemInterface {
		$value = Craft::$app->cache->get( $this->prefixedKey( $key ) );

		return new class($key, false !== $value ? $value : null, $this) implements CacheItemInterface {
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

	public function getItems( array $keys = array() ): iterable {
		$items = array();
		foreach ( $keys as $key ) {
			$items[ $key ] = $this->getItem( $key );
		}
		return $items;
	}

	public function hasItem( string $key ): bool {
		return false !== Craft::$app->cache->get( $this->prefixedKey( $key ) );
	}

	public function clear(): bool {
		return Craft::$app->cache->flush();
	}

	public function deleteItem( string $key ): bool {
		return $this->deleteValue( $key );
	}

	public function deleteItems( array $keys ): bool {
		$success = true;
		foreach ( $keys as $key ) {
			if ( ! $this->deleteValue( $key ) ) {
				$success = false;
			}
		}
		return $success;
	}

	public function save( CacheItemInterface $item ): bool {
		return $this->setValue( $item->getKey(), $item->get(), $this->defaultTtl );
	}

	public function saveDeferred( CacheItemInterface $item ): bool {
		return $this->save( $item );
	}

	public function commit(): bool {
		return true;
	}
}
