<?php
/**
 * Cached profession catalogue — version-keyed read-through decorator.
 *
 * @package  Nvoos\Core
 * @since    2.1.0
 * @license  MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Infrastructure\Profession;

use Nvoos\Core\Domain\Contract\CacheStoreInterface;
use Nvoos\Core\Domain\Contract\ProfessionRepositoryInterface;

/**
 * Caches catalogue reads under a key that embeds the catalogue version.
 *
 * Invalidation is generational: bumping the version changes the key, so every
 * entry written by the previous generation becomes unreachable in one
 * constant-time step — no purge, no key scan — and expires naturally on its
 * own TTL.
 *
 * Only non-empty answers are cached. A storage hiccup that returns an empty
 * catalogue, or a lookup for a slug that does not exist, must not be pinned
 * for the whole TTL.
 *
 * @since 2.1.0
 */
final class CachedProfessionRepository implements ProfessionRepositoryInterface {

	/**
	 * Key namespace. The version and the read suffix are appended.
	 */
	private const KEY_PREFIX = 'professions.catalogue.';

	/**
	 * @param ProfessionRepositoryInterface $inner Runtime adapter that owns the data.
	 * @param CacheStoreInterface           $cache Platform cache (transients, Redis, array).
	 * @param int                           $ttl   Seconds a cached answer stays valid; 0 disables caching.
	 */
	public function __construct(
		private readonly ProfessionRepositoryInterface $inner,
		private readonly CacheStoreInterface $cache,
		private readonly int $ttl = 86400,
	) {}

	/**
	 * {@inheritdoc}
	 *
	 * Passes through: the version is the inner adapter's cache epoch, not a
	 * value this decorator owns.
	 */
	public function catalogueVersion(): string {
		return $this->inner->catalogueVersion();
	}

	/**
	 * {@inheritdoc}
	 */
	public function getAll(): array {
		return $this->remember(
			'all',
			fn (): array => $this->inner->getAll()
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getBySlug( string $slug ): array {
		return $this->remember(
			'slug.' . $slug,
			fn (): array => $this->inner->getBySlug( $slug ),
			// A miss is cheap to repeat and unbounded in cardinality: caching it
			// would let arbitrary slugs fill the store.
			static fn ( array $value ): bool => ! empty( $value['found'] )
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getByCategory( string $category ): array {
		return $this->remember(
			'category.' . $category,
			fn (): array => $this->inner->getByCategory( $category )
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function loadKnowledgeBase( string $slug ): array {
		return $this->remember(
			'knowledge.' . $slug,
			fn (): array => $this->inner->loadKnowledgeBase( $slug )
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getRecommendedTools( string $slug ): array {
		return $this->remember(
			'tools.' . $slug,
			fn (): array => $this->inner->getRecommendedTools( $slug )
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getCategories(): array {
		return $this->remember(
			'categories',
			fn (): array => $this->inner->getCategories()
		);
	}

	/**
	 * Read through the cache, computing and storing on a miss.
	 *
	 * @param string        $suffix Cache-key suffix identifying the read.
	 * @param callable      $load   Produces the value on a miss.
	 * @param callable|null $store  Whether the computed value is worth caching.
	 *                              Defaults to "non-empty array".
	 * @return array
	 */
	private function remember( string $suffix, callable $load, ?callable $store = null ): array {
		if ( $this->ttl <= 0 ) {
			return $load();
		}

		$key = self::KEY_PREFIX . $this->catalogueVersion() . '.' . $suffix;
		$hit = $this->cache->getValue( $key );

		if ( is_array( $hit ) ) {
			return $hit;
		}

		$value = $load();

		$cacheable = null === $store ? array() !== $value : (bool) $store( $value );

		if ( $cacheable ) {
			$this->cache->setValue( $key, $value, $this->ttl );
		}

		return $value;
	}
}
