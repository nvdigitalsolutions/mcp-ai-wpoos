<?php
/**
 * WordPress adapter: CacheStoreInterface implementation.
 *
 * Wraps WordPress transients and object cache functions behind the
 * PSR-6-extending CacheStoreInterface. It extends PSR-6 with simpler
 * transient-style convenience methods.
 *
 * @package Oos\WordPress
 * @since   1.0.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Oos\WordPress\Adapter;

use Oos\Core\Domain\Contract\CacheStoreInterface;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

class CacheStore implements CacheStoreInterface {

	/**
	 * Prefix added to all keys to avoid collisions.
	 */
	private const PREFIX = 'wp_mcp_ai_cache_';

	/**
	 * Whether to use the persistent object cache (wp_cache_*) over transients.
	 */
	private bool $useObjectCache;

	public function __construct( bool $useObjectCache = false ) {
		$this->useObjectCache = $useObjectCache && \wp_using_ext_object_cache();
	}

	// ─── CacheStoreInterface convenience methods ─────────────────────

	public function getValue( string $key, mixed $default = null ): mixed {
		$key = self::PREFIX . $key;

		if ( $this->useObjectCache ) {
			$found  = false;
			$result = \wp_cache_get( $key, 'wp_mcp_ai', false, $found );

			return $found ? $result : $default;
		}

		$result = \get_transient( $key );

		return false !== $result ? $result : $default;
	}

	public function setValue( string $key, mixed $value, int $ttl = 3600 ): bool {
		$key = self::PREFIX . $key;

		if ( $this->useObjectCache ) {
			return \wp_cache_set( $key, $value, 'wp_mcp_ai', $ttl );
		}

		return \set_transient( $key, $value, $ttl );
	}

	public function deleteValue( string $key ): bool {
		$key = self::PREFIX . $key;

		if ( $this->useObjectCache ) {
			return \wp_cache_delete( $key, 'wp_mcp_ai' );
		}

		return \delete_transient( $key );
	}

	public function increment( string $key, int $by = 1, int $ttl = 3600 ): int {
		$key = self::PREFIX . $key;

		$current = $this->getValue( $key, 0 );
		$newVal  = max( 0, (int) $current ) + $by;
		$this->setValue( $key, $newVal, $ttl );

		return $newVal;
	}

	public function remember( string $key, int $ttl, callable $callback ): mixed {
		$cached = $this->getValue( $key );

		if ( null !== $cached ) {
			return $cached;
		}

		$value = $callback();
		$this->setValue( $key, $value, $ttl );

		return $value;
	}

	// ─── PSR-6 CacheItemPoolInterface methods ─────────────────────────

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
				// PSR-6 requires this, but we defer TTL to save().
				return $this;
			}

			public function expiresAfter( \DateInterval|int|null $time ): self {
				// Deferred to save().
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
		return $this->getItem( $key )->isHit();
	}

	public function clear(): bool {
		// WordPress does not support clearing by group/prefix natively.
		// Transients can be cleared individually but not in bulk.
		return true;
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
		return $this->setValue( $item->getKey(), $item->get() );
	}

	public function saveDeferred( CacheItemInterface $item ): bool {
		// Deferred saves are not supported; save immediately.
		return $this->save( $item );
	}

	public function commit(): bool {
		// All saves are immediate.
		return true;
	}
}
