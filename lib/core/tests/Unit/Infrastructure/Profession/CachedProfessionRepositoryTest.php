<?php
/**
 * Cached profession catalogue decorator tests (090-g Phase 6).
 *
 * Pins the generational-invalidation contract: a version bump must make the
 * previous cache generation unreachable without a purge.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Infrastructure\Profession;

use Nvoos\Core\Domain\Contract\CacheStoreInterface;
use Nvoos\Core\Domain\Contract\ProfessionRepositoryInterface;
use Nvoos\Core\Infrastructure\Profession\CachedProfessionRepository;
use PHPUnit\Framework\TestCase;

/**
 * In-memory cache store: records writes so tests can assert what was cached.
 */
final class ArrayCacheStore implements CacheStoreInterface {

	/** @var array<string, mixed> */
	public array $values = array();

	/** @var string[] */
	public array $writes = array();

	public function getValue( string $key, mixed $default = null ): mixed {
		return array_key_exists( $key, $this->values ) ? $this->values[ $key ] : $default;
	}

	public function setValue( string $key, mixed $value, int $ttl = 3600 ): bool {
		$this->values[ $key ] = $value;
		$this->writes[]       = $key;

		return true;
	}

	public function deleteValue( string $key ): bool {
		unset( $this->values[ $key ] );

		return true;
	}

	public function increment( string $key, int $by = 1, int $ttl = 3600 ): int {
		$current          = (int) ( $this->values[ $key ] ?? 0 );
		$this->values[$key] = $current + $by;

		return $current + $by;
	}

	public function remember( string $key, int $ttl, callable $callback ): mixed {
		if ( array_key_exists( $key, $this->values ) ) {
			return $this->values[ $key ];
		}

		$value = $callback();

		$this->setValue( $key, $value, $ttl );

		return $value;
	}
}

/**
 * Stub catalogue whose data and version the test controls.
 */
final class StubProfessionRepository implements ProfessionRepositoryInterface {

	public int $reads = 0;

	public string $version = '1';

	/** @var array<string, array<string, mixed>> */
	public array $catalogue = array();

	public function catalogueVersion(): string {
		return $this->version;
	}

	public function getAll(): array {
		++$this->reads;

		$titles = array();

		foreach ( $this->catalogue as $slug => $profession ) {
			$titles[ $slug ] = (string) $profession['name'];
		}

		return $titles;
	}

	public function getBySlug( string $slug ): array {
		++$this->reads;

		return isset( $this->catalogue[ $slug ] )
			? array( 'found' => true, 'profession' => $this->catalogue[ $slug ] )
			: array( 'found' => false );
	}

	public function getByCategory( string $category ): array {
		++$this->reads;

		$match = array();

		foreach ( $this->catalogue as $slug => $profession ) {
			if ( $category === $profession['category'] ) {
				$match[ $slug ] = (string) $profession['name'];
			}
		}

		return $match;
	}

	public function loadKnowledgeBase( string $slug ): array {
		unset( $slug );
		++$this->reads;

		return array( 'knowledge' => array(), 'playbook' => array() );
	}

	public function getRecommendedTools( string $slug ): array {
		++$this->reads;

		return isset( $this->catalogue[ $slug ] ) ? (array) $this->catalogue[ $slug ]['default_tools'] : array();
	}

	public function getCategories(): array {
		++$this->reads;

		return array();
	}

	/**
	 * Seed one profession.
	 */
	public function add( string $slug, string $name, string $category = 'other' ): void {
		$this->catalogue[ $slug ] = array(
			'slug'          => $slug,
			'name'          => $name,
			'category'      => $category,
			'default_tools' => array( 'web_search' ),
		);
	}
}

final class CachedProfessionRepositoryTest extends TestCase {

	private StubProfessionRepository $inner;

	private ArrayCacheStore $cache;

	protected function setUp(): void {
		parent::setUp();

		$this->inner = new StubProfessionRepository();
		$this->cache = new ArrayCacheStore();
	}

	private function repository( int $ttl = 3600 ): CachedProfessionRepository {
		return new CachedProfessionRepository( $this->inner, $this->cache, $ttl );
	}

	public function test_a_read_is_cached_under_the_version_key(): void {
		$this->inner->add( 'accountant', 'Accountant' );

		$repo = $this->repository();

		$this->assertSame( array( 'accountant' => 'Accountant' ), $repo->getAll() );
		$this->assertSame( array( 'accountant' => 'Accountant' ), $repo->getAll() );

		$this->assertSame( 1, $this->inner->reads, 'second read must come from cache' );
		$this->assertArrayHasKey( 'professions.catalogue.1.all', $this->cache->values );
	}

	public function test_a_version_bump_makes_the_previous_generation_unreachable(): void {
		$this->inner->add( 'accountant', 'Accountant' );

		$repo = $this->repository();
		$repo->getAll();

		// The catalogue changes and the adapter bumps the version — no purge.
		$this->inner->add( 'lawyer', 'Lawyer' );
		$this->inner->version = '2';

		$this->assertSame(
			array( 'accountant' => 'Accountant', 'lawyer' => 'Lawyer' ),
			$repo->getAll()
		);
		$this->assertSame( 2, $this->inner->reads );
		$this->assertArrayHasKey( 'professions.catalogue.2.all', $this->cache->values );
	}

	public function test_empty_answers_are_not_cached(): void {
		$repo = $this->repository();

		$this->assertSame( array(), $repo->getAll() );
		$this->assertSame( array(), $repo->getAll() );

		$this->assertSame( 2, $this->inner->reads );
		$this->assertSame( array(), $this->cache->writes );
	}

	public function test_a_missing_profession_is_not_cached(): void {
		$repo = $this->repository();

		$this->assertSame( array( 'found' => false ), $repo->getBySlug( 'nope' ) );
		$this->assertSame( array( 'found' => false ), $repo->getBySlug( 'nope' ) );

		$this->assertSame( 2, $this->inner->reads );
		$this->assertSame( array(), $this->cache->writes );
	}

	public function test_a_found_profession_is_cached(): void {
		$this->inner->add( 'accountant', 'Accountant' );

		$repo   = $this->repository();
		$first  = $repo->getBySlug( 'accountant' );
		$second = $repo->getBySlug( 'accountant' );

		$this->assertTrue( $first['found'] );
		$this->assertSame( $first, $second );
		$this->assertSame( 1, $this->inner->reads );
		$this->assertSame( array( 'professions.catalogue.1.slug.accountant' ), $this->cache->writes );
	}

	public function test_ttl_zero_bypasses_the_cache(): void {
		$this->inner->add( 'accountant', 'Accountant' );

		$repo = $this->repository( 0 );

		$repo->getAll();
		$repo->getAll();

		$this->assertSame( 2, $this->inner->reads );
		$this->assertSame( array(), $this->cache->writes );
	}
}
