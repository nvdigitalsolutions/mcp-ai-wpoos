<?php
/**
 * Taxonomy write semantics on the content store (issue #8191).
 *
 * `taxonomyInput` is a complete term set, not an addition: it replaces each
 * named taxonomy and leaves every other taxonomy alone. Before this, the
 * Laravel adapter appended while the WordPress adapter replaced, so the same
 * `update()` call left the two hosts in different states.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;

final class ContentStoreTaxonomyTest extends TestCase {

	use RefreshDatabase;

	private ContentStoreInterface $store;

	protected function setUp(): void {
		parent::setUp();

		$this->store = $this->app->make( ContentStoreInterface::class );

		// The adapter authorises every write through Gate; the actor is user 1,
		// which the base TestCase seeds.
		foreach ( array( 'read_content', 'edit_content', 'delete_content' ) as $ability ) {
			Gate::define( $ability, static fn ( $user = null ): bool => true );
		}
	}

	// ─── replace ───────────────────────────────────────────────────

	public function test_update_replaces_the_named_taxonomy(): void {
		$item = $this->createWith( array( 'category' => array( 'News', 'Tech' ) ) );

		$this->update( $item->id, array( 'category' => array( 'News' ) ) );

		// 'Tech' was not named, so it is gone — that is what replace means.
		$this->assertSame( array( 'News' ), $this->terms( $item->id, 'category' ) );
	}

	public function test_update_can_replace_with_an_entirely_different_set(): void {
		$item = $this->createWith( array( 'category' => array( 'News' ) ) );

		$this->update( $item->id, array( 'category' => array( 'Tech', 'Design' ) ) );

		$this->assertSame( array( 'Design', 'Tech' ), $this->terms( $item->id, 'category' ) );
	}

	public function test_update_keeps_a_term_that_is_in_both_sets(): void {
		$item = $this->createWith( array( 'category' => array( 'News', 'Tech' ) ) );

		$this->update( $item->id, array( 'category' => array( 'News', 'Design' ) ) );

		$this->assertSame( array( 'Design', 'News' ), $this->terms( $item->id, 'category' ) );
	}

	// ─── scoping ───────────────────────────────────────────────────

	/**
	 * Categories and tags share one pivot, so a naive `sync()` would clear the
	 * taxonomies the caller never mentioned.
	 */
	public function test_update_leaves_other_taxonomies_alone(): void {
		$item = $this->createWith(
			array(
				'category' => array( 'News' ),
				'post_tag' => array( 'alpha', 'beta' ),
			)
		);

		$this->update( $item->id, array( 'category' => array( 'Tech' ) ) );

		$this->assertSame( array( 'Tech' ), $this->terms( $item->id, 'category' ) );
		$this->assertSame( array( 'alpha', 'beta' ), $this->terms( $item->id, 'post_tag' ) );
	}

	public function test_update_leaves_an_absent_taxonomy_untouched(): void {
		$item = $this->createWith( array( 'category' => array( 'News' ) ) );

		$this->store->update(
			$item->id,
			new UpdateContentCommand(
				userId: 1,
				title: 'Renamed only',
			)
		);

		$this->assertSame( array( 'News' ), $this->terms( $item->id, 'category' ) );
	}

	public function test_two_taxonomies_in_one_command_both_apply(): void {
		$item = $this->createWith(
			array(
				'category' => array( 'News', 'Tech' ),
				'post_tag' => array( 'alpha' ),
			)
		);

		$this->update(
			$item->id,
			array(
				'category' => array( 'Design' ),
				'post_tag' => array( 'beta', 'gamma' ),
			)
		);

		$this->assertSame( array( 'Design' ), $this->terms( $item->id, 'category' ) );
		$this->assertSame( array( 'beta', 'gamma' ), $this->terms( $item->id, 'post_tag' ) );
	}

	// ─── clearing ──────────────────────────────────────────────────

	/**
	 * A taxonomy named with an empty list clears it, matching
	 * `wp_set_post_terms( $id, array(), $taxonomy )`.
	 */
	public function test_an_empty_list_clears_the_taxonomy(): void {
		$item = $this->createWith(
			array(
				'category' => array( 'News', 'Tech' ),
				'post_tag' => array( 'alpha' ),
			)
		);

		$this->update( $item->id, array( 'category' => array() ) );

		$this->assertSame( array(), $this->terms( $item->id, 'category' ) );
		$this->assertSame( array( 'alpha' ), $this->terms( $item->id, 'post_tag' ) );
	}

	public function test_an_empty_outer_array_changes_nothing(): void {
		$item = $this->createWith( array( 'category' => array( 'News' ) ) );

		$this->update( $item->id, array() );

		$this->assertSame( array( 'News' ), $this->terms( $item->id, 'category' ) );
	}

	// ─── create ────────────────────────────────────────────────────

	public function test_create_sets_the_whole_term_set(): void {
		$item = $this->createWith(
			array(
				'category' => array( 'News', 'Tech' ),
				'post_tag' => array( 'alpha' ),
			)
		);

		$this->assertSame( array( 'News', 'Tech' ), $this->terms( $item->id, 'category' ) );
		$this->assertSame( array( 'alpha' ), $this->terms( $item->id, 'post_tag' ) );
	}

	// ─── helpers ───────────────────────────────────────────────────

	/**
	 * @param array<string, string[]> $taxonomy Taxonomy → term names.
	 */
	private function createWith( array $taxonomy ): ContentItem {
		return $this->store->create(
			new CreateContentCommand(
				title: 'Taxonomy fixture',
				type: 'record',
				authorId: 1,
				content: 'body',
				taxonomyInput: $taxonomy,
			)
		);
	}

	/**
	 * @param array<string, string[]> $taxonomy Taxonomy → complete term set.
	 */
	private function update( int $id, array $taxonomy ): ContentItem {
		return $this->store->update(
			$id,
			new UpdateContentCommand(
				userId: 1,
				taxonomyInput: $taxonomy,
			)
		);
	}

	/**
	 * Term names for one taxonomy, sorted so the assertion is order-independent.
	 *
	 * @return string[]
	 */
	private function terms( int $id, string $taxonomy ): array {
		$all = $this->store->getTaxonomyTerms( $id );
		$names = array_map( 'strval', $all[ $taxonomy ] ?? array() );

		sort( $names );

		return $names;
	}
}
