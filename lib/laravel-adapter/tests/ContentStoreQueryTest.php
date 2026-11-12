<?php
/**
 * ContentStore query fidelity tests (090-F Phase 1).
 *
 * Covers the WP_Query-shaped `metaQuery` / `taxQuery` clauses and the
 * order-by mapping. Before this, both clause lists were accepted and silently
 * ignored, so a filtered query returned every row.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.2.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentCollection;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Laravel\Models\NvoosPost;
use Nvoos\Laravel\Models\NvoosTerm;

final class ContentStoreQueryTest extends TestCase {

	use RefreshDatabase;

	private ContentStoreInterface $store;

	protected function setUp(): void {
		parent::setUp();

		$this->store = $this->app->make( ContentStoreInterface::class );
	}

	// ─── metaQuery ─────────────────────────────────────────────────

	public function test_meta_equals_returns_only_matching_rows(): void {
		$this->make( 'High', array( 'priority' => 'high' ) );
		$this->make( 'Low', array( 'priority' => 'low' ) );
		$this->make( 'None', array() );

		$result = $this->runQuery(
			array(
				array(
					'key'   => 'priority',
					'value' => 'high',
				),
			)
		);

		$this->assertSame( array( 'High' ), $this->titles( $result ) );
		$this->assertSame( 1, $result->total );
	}

	/**
	 * WP_Query's documented quirk: `!=` also matches rows without the key.
	 */
	public function test_meta_not_equals_includes_rows_without_the_key(): void {
		$this->make( 'High', array( 'priority' => 'high' ) );
		$this->make( 'Low', array( 'priority' => 'low' ) );
		$this->make( 'None', array() );

		$result = $this->runQuery(
			array(
				array(
					'key'     => 'priority',
					'value'   => 'high',
					'compare' => '!=',
				),
			)
		);

		$this->assertSame( array( 'Low', 'None' ), $this->titles( $result ) );
		$this->assertSame( 2, $result->total );
	}

	public function test_meta_in_filters_to_the_value_list(): void {
		$this->make( 'High', array( 'priority' => 'high' ) );
		$this->make( 'Low', array( 'priority' => 'low' ) );
		$this->make( 'None', array() );

		$result = $this->runQuery(
			array(
				array(
					'key'     => 'priority',
					'value'   => array( 'high', 'low' ),
					'compare' => 'IN',
				),
			)
		);

		$this->assertSame( array( 'High', 'Low' ), $this->titles( $result ) );
	}

	/**
	 * `NOT IN` follows SQL semantics: a missing key does not satisfy it.
	 */
	public function test_meta_not_in_excludes_rows_without_the_key(): void {
		$this->make( 'High', array( 'priority' => 'high' ) );
		$this->make( 'Low', array( 'priority' => 'low' ) );
		$this->make( 'None', array() );

		$result = $this->runQuery(
			array(
				array(
					'key'     => 'priority',
					'value'   => array( 'high' ),
					'compare' => 'NOT IN',
				),
			)
		);

		$this->assertSame( array( 'Low' ), $this->titles( $result ) );
	}

	public function test_meta_exists_and_not_exists(): void {
		$this->make( 'Has', array( 'priority' => 'high' ) );
		$this->make( 'Missing', array() );

		$exists = $this->runQuery(
			array(
				array(
					'key'     => 'priority',
					'compare' => 'EXISTS',
				),
			)
		);

		$missing = $this->runQuery(
			array(
				array(
					'key'     => 'priority',
					'compare' => 'NOT EXISTS',
				),
			)
		);

		$this->assertSame( array( 'Has' ), $this->titles( $exists ) );
		$this->assertSame( array( 'Missing' ), $this->titles( $missing ) );
	}

	public function test_meta_between_is_inclusive(): void {
		$this->make( 'Low', array( 'score' => 10 ) );
		$this->make( 'Mid', array( 'score' => 50 ) );
		$this->make( 'High', array( 'score' => 90 ) );

		$result = $this->runQuery(
			array(
				array(
					'key'     => 'score',
					'value'   => array( 20, 80 ),
					'compare' => 'BETWEEN',
				),
			)
		);

		$this->assertSame( array( 'Mid' ), $this->titles( $result ) );
	}

	public function test_meta_relation_or_matches_either_clause(): void {
		$this->make( 'ByPriority', array( 'priority' => 'high' ) );
		$this->make( 'ByFlag', array( 'flag' => 'yes' ) );
		$this->make( 'Neither', array( 'priority' => 'low', 'flag' => 'no' ) );

		$result = $this->runQuery(
			array(
				'relation' => 'OR',
				array(
					'key'   => 'priority',
					'value' => 'high',
				),
				array(
					'key'   => 'flag',
					'value' => 'yes',
				),
			)
		);

		$this->assertSame( array( 'ByPriority', 'ByFlag' ), $this->titles( $result ) );
	}

	public function test_unsupported_meta_compare_fails_loudly(): void {
		$this->make( 'Anything', array( 'priority' => 'high' ) );

		$this->expectException( \InvalidArgumentException::class );

		$this->runQuery(
			array(
				array(
					'key'     => 'priority',
					'value'   => 'high',
					'compare' => 'REGEXP',
				),
			)
		);
	}

	public function test_invalid_meta_key_fails_loudly(): void {
		$this->make( 'Anything', array( 'priority' => 'high' ) );

		$this->expectException( \InvalidArgumentException::class );

		$this->runQuery(
			array(
				array(
					'key'   => 'priority) OR 1=1 --',
					'value' => 'high',
				),
			)
		);
	}

	// ─── taxQuery ──────────────────────────────────────────────────

	public function test_tax_in_matches_by_slug(): void {
		$high = NvoosTerm::create( array( 'name' => 'High', 'taxonomy' => 'priority', 'slug' => 'high' ) );
		$low  = NvoosTerm::create( array( 'name' => 'Low', 'taxonomy' => 'priority', 'slug' => 'low' ) );

		$this->attach( $this->make( 'High' )->id, array( $high->id ) );
		$this->attach( $this->make( 'Low' )->id, array( $low->id ) );
		$this->make( 'Untagged' );

		$result = $this->runQuery(
			array(),
			array(
				array(
					'taxonomy' => 'priority',
					'field'    => 'slug',
					'terms'    => array( 'high' ),
				),
			)
		);

		$this->assertSame( array( 'High' ), $this->titles( $result ) );
	}

	public function test_tax_and_requires_every_term(): void {
		$high   = NvoosTerm::create( array( 'name' => 'High', 'taxonomy' => 'priority', 'slug' => 'high' ) );
		$urgent = NvoosTerm::create( array( 'name' => 'Urgent', 'taxonomy' => 'flag', 'slug' => 'urgent' ) );

		$this->attach( $this->make( 'Both' )->id, array( $high->id, $urgent->id ) );
		$this->attach( $this->make( 'OnlyHigh' )->id, array( $high->id ) );

		$result = $this->runQuery(
			array(),
			array(
				array(
					'taxonomy' => 'priority',
					'field'    => 'slug',
					'terms'    => array( 'high' ),
					'operator' => 'AND',
				),
				array(
					'taxonomy' => 'flag',
					'field'    => 'slug',
					'terms'    => array( 'urgent' ),
					'operator' => 'AND',
				),
			)
		);

		$this->assertSame( array( 'Both' ), $this->titles( $result ) );
	}

	public function test_tax_not_in_includes_untagged_rows(): void {
		$high = NvoosTerm::create( array( 'name' => 'High', 'taxonomy' => 'priority', 'slug' => 'high' ) );
		$low  = NvoosTerm::create( array( 'name' => 'Low', 'taxonomy' => 'priority', 'slug' => 'low' ) );

		$this->attach( $this->make( 'High' )->id, array( $high->id ) );
		$this->attach( $this->make( 'Low' )->id, array( $low->id ) );
		$this->make( 'Untagged' );

		$result = $this->runQuery(
			array(),
			array(
				array(
					'taxonomy' => 'priority',
					'field'    => 'slug',
					'terms'    => array( 'high' ),
					'operator' => 'NOT IN',
				),
			)
		);

		$this->assertSame( array( 'Low', 'Untagged' ), $this->titles( $result ) );
	}

	// ─── ordering + pagination ─────────────────────────────────────

	/**
	 * `ContentQuery` speaks `date`, not the table's `created_at`.
	 */
	public function test_order_by_date_maps_to_created_at(): void {
		$first  = $this->make( 'First' );
		$second = $this->make( 'Second' );

		NvoosPost::where( 'id', $first->id )->update( array( 'created_at' => '2026-01-01 00:00:00' ) );
		NvoosPost::where( 'id', $second->id )->update( array( 'created_at' => '2026-02-01 00:00:00' ) );

		$result = $this->runQuery( array(), array(), 'date', 'ASC' );

		$this->assertSame( array( 'First', 'Second' ), $this->titles( $result ) );
	}

	public function test_per_page_is_clamped_to_the_documented_range(): void {
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->make( 'Row ' . $i );
		}

		$result = $this->runQuery( array(), array(), 'date', 'ASC', 500 );

		$this->assertSame( 200, $result->perPage );
		$this->assertCount( 3, $result->items );
	}

	/**
	 * WP_Query's `post_status => 'any'` means "no status filter".
	 */
	public function test_statuses_any_disables_the_status_filter(): void {
		$this->make( 'Published' );
		$this->make( 'Draft', array(), 'draft' );

		$result = $this->runQuery( perPage: 20, statuses: array( 'any' ) );

		$this->assertSame( 2, $result->total );
	}

	/**
	 * WP_Query's `s` searches title, excerpt and content.
	 */
	public function test_search_matches_the_excerpt(): void {
		$hit   = $this->make( 'Unrelated title' );
		$other = $this->make( 'Another title' );

		NvoosPost::where( 'id', $hit->id )->update( array( 'excerpt' => 'needle in the excerpt' ) );
		NvoosPost::where( 'id', $other->id )->update( array( 'excerpt' => 'nothing here' ) );

		$result = $this->store->query(
			new ContentQuery(
				types: array( 'record' ),
				statuses: array( 'publish' ),
				search: 'needle',
				orderBy: 'date',
				order: 'ASC',
			)
		);

		$this->assertSame( array( 'Unrelated title' ), $this->titles( $result ) );
	}

	public function test_unsupported_order_field_fails_loudly(): void {
		$this->make( 'Anything' );

		$this->expectException( \InvalidArgumentException::class );

		$this->runQuery( array(), array(), 'rand' );
	}

	public function test_negative_per_page_fails_loudly(): void {
		$this->make( 'Anything' );

		$this->expectException( \InvalidArgumentException::class );

		$this->runQuery( array(), array(), 'date', 'ASC', -1 );
	}

	// ─── helpers ───────────────────────────────────────────────────

	private function make( string $title, array $meta = array(), string $status = 'publish' ): ContentItem {
		return $this->store->create(
			new CreateContentCommand(
				title: $title,
				type: 'record',
				authorId: 0,
				status: $status,
				content: 'body',
				meta: $meta,
			)
		);
	}

	/**
	 * @param int[] $termIds Term IDs.
	 */
	private function attach( int $postId, array $termIds ): void {
		$post = NvoosPost::findOrFail( $postId );
		$post->terms()->attach( $termIds );
	}

	/**
	 * @return string[]
	 */
	private function titles( ContentCollection $collection ): array {
		return array_map(
			static fn ( ContentItem $item ): string => $item->title,
			$collection->items
		);
	}

	/**
	 * @param array<int|string, mixed> $metaQuery Meta clauses.
	 * @param array<int|string, mixed> $taxQuery  Tax clauses.
	 */
	private function runQuery(
		array $metaQuery = array(),
		array $taxQuery = array(),
		string $orderBy = 'date',
		string $order = 'ASC',
		int $perPage = 20,
		array $statuses = array( 'publish' )
	): ContentCollection {
		return $this->store->query(
			new ContentQuery(
				types: array( 'record' ),
				statuses: $statuses,
				metaQuery: $metaQuery,
				taxQuery: $taxQuery,
				orderBy: $orderBy,
				order: $order,
				perPage: $perPage,
			)
		);
	}
}
