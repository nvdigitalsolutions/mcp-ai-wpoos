<?php
/**
 * Laravel adapter: merges the tenant overlay into the base profession catalogue.
 *
 * THE TWO TIERS (Proposal 149 §170)
 *
 * The base is code-owned reference data — a versioned directory of JSON
 * documents, or a licensed catalogue pointed at by `oos.professions.path`. The
 * overlay is the tenant-owned half: the rows `save_profession` writes. Neither
 * tier is authoritative for everything, so a reader gets one merged view:
 *
 *  - **the overlay wins per slug.** A tenant that edited a profession expects
 *    its edit on every surface that renders it, not only on the page that
 *    wrote it. Shadowing the base row is the standard two-tier rule.
 *  - **a base row the tenant never touched is untouched.** There is no copy of
 *    it in the overlay, so a catalogue upgrade reaches every estate that never
 *    edited that profession.
 *  - **a slug in neither tier is still absent.** The merge cannot invent a row,
 *    which is what keeps `getBySlug()`'s `found` flag meaningful.
 *
 * COPY-ON-WRITE, AND WHAT IT COSTS
 *
 * The overlay store seeds a new overlay row from the base record before applying
 * the caller's fields, so an update that omits `description` keeps the base
 * description instead of blanking it — WordPress's "omitted !== cleared" rule.
 * The cost is that an edited profession stops receiving base catalogue updates:
 * the estate took ownership of that row at the first edit. That is the honest
 * trade for not silently discarding the tenant's own edits on the next deploy,
 * and it is the behaviour WordPress has too, because there the CPT row *is* the
 * whole record.
 *
 * READ-ONLY BY DESIGN
 *
 * This class implements `ProfessionRepositoryInterface` only. Persisting is
 * `ProfessionWriteInterface`'s job, bound separately, so a host that provides no
 * overlay store simply gets the base — and `SaveProfessionTool` cannot be built
 * there. `ProfessionRepositoryTest::test_the_laravel_catalogue_is_read_only`
 * pins that: the repository a consumer resolves is never a writer.
 *
 * @package Nvoos\Laravel
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Profession;

use Nvoos\Core\Domain\Contract\ProfessionOverlayInterface;
use Nvoos\Core\Domain\Contract\ProfessionRepositoryInterface;
use Nvoos\Laravel\Adapter\ProfessionRepository;

class OverlayProfessionRepository implements ProfessionRepositoryInterface {

	/**
	 * @param ProfessionRepositoryInterface   $base    Code-owned catalogue.
	 * @param ProfessionOverlayInterface|null $overlay Tenant-owned records, or null on a
	 *                                                host with no write path.
	 */
	public function __construct(
		private readonly ProfessionRepositoryInterface $base,
		private readonly ?ProfessionOverlayInterface $overlay = null,
	) {
	}

	/**
	 * {@inheritdoc}
	 *
	 * The overlay's fingerprint is appended to the base version, so a write
	 * changes the cache key and the previous generation expires on its own TTL.
	 * A host with no overlay reports the base version unchanged.
	 */
	public function catalogueVersion(): string {
		if ( null === $this->overlay ) {
			return $this->base->catalogueVersion();
		}

		return $this->base->catalogueVersion() . '|' . $this->overlay->overlayVersion();
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, string> Slug => title, ordered by title.
	 */
	public function getAll(): array {
		$titles = array();

		foreach ( $this->base->getAll() as $slug => $title ) {
			$titles[ (string) $slug ] = (string) $title;
		}

		// Overlay after base: the same key replaces, which is the shadowing rule.
		foreach ( $this->overlays() as $slug => $record ) {
			$titles[ $slug ] = (string) ( $record['name'] ?? $slug );
		}

		uasort(
			$titles,
			static fn ( string $a, string $b ): int => strcasecmp( $a, $b )
		);

		return $titles;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array{found: bool, profession?: array<string, mixed>}
	 */
	public function getBySlug( string $slug ): array {
		$key     = ProfessionRepository::key( $slug );
		$overlay = $this->overlays();

		if ( '' !== $key && isset( $overlay[ $key ] ) ) {
			return array(
				'found'      => true,
				'profession' => $overlay[ $key ],
			);
		}

		return $this->base->getBySlug( $slug );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, string> Slug => title, ordered by title.
	 */
	public function getByCategory( string $category ): array {
		$needle = ProfessionRepository::key( $category );
		$titles = array();

		foreach ( $this->base->getByCategory( $category ) as $slug => $title ) {
			$titles[ (string) $slug ] = (string) $title;
		}

		// A base row the overlay shadows has left this category if the tenant
		// moved it: drop the base entry before re-adding from the overlay.
		foreach ( $this->overlays() as $slug => $record ) {
			unset( $titles[ $slug ] );

			if ( $needle === (string) ( $record['category'] ?? '' ) ) {
				$titles[ $slug ] = (string) ( $record['name'] ?? $slug );
			}
		}

		uasort(
			$titles,
			static fn ( string $a, string $b ): int => strcasecmp( $a, $b )
		);

		return $titles;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Delegated rather than merged. Both the WordPress and the Laravel base
	 * return empty arrays here: knowledge lives inline in the record as
	 * `knowledge_base`, so an overlay row's knowledge is already returned by
	 * `getBySlug()`. Inventing a playbook shape here would be a second contract.
	 *
	 * @return array{knowledge: array, playbook: array}
	 */
	public function loadKnowledgeBase( string $slug ): array {
		return $this->base->loadKnowledgeBase( $slug );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return string[]
	 */
	public function getRecommendedTools( string $slug ): array {
		$key     = ProfessionRepository::key( $slug );
		$overlay = $this->overlays();

		if ( '' !== $key && isset( $overlay[ $key ] ) ) {
			return $overlay[ $key ]['default_tools'];
		}

		return $this->base->getRecommendedTools( $slug );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public function getCategories(): array {
		$categories = $this->base->getCategories();

		foreach ( $this->overlays() as $slug => $record ) {
			// Shadowing may have changed the category, so remove the base
			// placement before adding the overlay's.
			foreach ( array_keys( $categories ) as $category ) {
				unset( $categories[ $category ][ $slug ] );
			}

			$category = (string) ( $record['category'] ?? 'other' );

			$categories[ $category ][ $slug ] = $record;
		}

		// Drop categories the shadowing emptied, then order by slug.
		$categories = array_filter(
			$categories,
			static fn ( array $group ): bool => array() !== $group
		);

		ksort( $categories );

		return $categories;
	}

	/**
	 * Overlay records, read once per call.
	 *
	 * Not memoised on the instance: the overlay is the tier that changes, and a
	 * decorator that cached it would keep serving the pre-write catalogue for
	 * the life of an Octane worker.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function overlays(): array {
		return null === $this->overlay ? array() : $this->overlay->overlays();
	}
}
