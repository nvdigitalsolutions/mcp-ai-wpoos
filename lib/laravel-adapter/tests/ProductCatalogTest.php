<?php
/**
 * ProductCatalog adapter: round-trip, the contract's write rule, and portability.
 *
 * Proposal 164 section 164.114. This is the first implementation of
 * `ProductCatalogInterface`, and it runs on the SQLite connection the adapter
 * suite uses, so several cases here are as much about "no MySQL-only SQL" as
 * about behaviour.
 *
 * Money is always built with `fromMinorUnits()`: the adapter's job is to carry
 * an integer through storage, and constructing fixtures from a decimal string
 * would test `Money` rather than the adapter (and has produced wrong fixtures
 * before).
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nvoos\Core\Domain\Contract\ProductCatalogInterface;
use Nvoos\Core\Domain\Entity\Product;
use Nvoos\Core\Domain\Entity\ProductCollection;
use Nvoos\Core\Domain\Entity\ProductQuery;
use Nvoos\Core\Domain\Entity\ProductVariant;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Domain\Error\ValidationException;
use Nvoos\Core\Domain\ValueObject\Money;
use Nvoos\Core\Domain\ValueObject\OptionSelection;
use Nvoos\Core\Domain\ValueObject\ProductOption;
use Nvoos\Core\Domain\ValueObject\ProductStatus;
use Nvoos\Laravel\Adapter\ProductCatalog;

final class ProductCatalogTest extends TestCase {

	use RefreshDatabase;

	/**
	 * The bound adapter under test.
	 */
	private function catalog(): ProductCatalogInterface {
		return $this->app->make( ProductCatalogInterface::class );
	}

	/**
	 * Product names from a result set, in the order the adapter returned them.
	 *
	 * @param ProductCollection $collection Result set.
	 *
	 * @return list<string>
	 */
	private function names( ProductCollection $collection ): array {
		return array_values(
			array_map(
				static fn ( Product $product ): string => $product->name,
				$collection->items
			)
		);
	}

	public function test_the_container_binds_the_contract_to_this_adapter(): void {
		$this->assertInstanceOf( ProductCatalog::class, $this->catalog() );
	}

	public function test_create_and_find_round_trip(): void {
		$catalog = $this->catalog();

		$created = $catalog->save(
			new Product(
				id: null,
				name: 'Classic Tee',
				variants: array(
					new ProductVariant(
						id: null,
						sku: 'TEE-RED-S',
						price: Money::fromMinorUnits( 1999, 'USD' ),
						options: new OptionSelection( array( 'Size' => 'S', 'Colour' => 'Red' ) ),
						stock: 7,
						trackInventory: true,
						position: 0,
					),
					new ProductVariant(
						id: null,
						sku: 'TEE-BLUE-M',
						price: Money::fromMinorUnits( 2450, 'USD' ),
						options: new OptionSelection( array( 'Size' => 'M', 'Colour' => 'Blue' ) ),
						compareAtPrice: Money::fromMinorUnits( 2900, 'USD' ),
						cost: Money::fromMinorUnits( 1000, 'USD' ),
						barcode: '0123456789012',
						stock: null,
						trackInventory: false,
						position: 1,
						imageUrl: 'https://example.test/tee.png',
						externalRef: 'woocommerce:variation:2001',
					),
				),
				options: array(
					new ProductOption( 'Size', array( 'S', 'M' ) ),
					new ProductOption( 'Colour', array( 'Red', 'Blue' ) ),
				),
				description: 'A heavyweight cotton tee.',
				slug: 'classic-tee',
				status: ProductStatus::Published,
				vendor: 'NVoos',
				productType: 'Apparel',
				tags: array( 'cotton', 'summer' ),
				externalRef: 'woocommerce:product:101',
			)
		);

		$this->assertNotNull( $created->id );
		$this->assertGreaterThan( 0, $created->id );
		$this->assertCount( 2, $created->variants );

		$found = $catalog->find( $created->id );

		$this->assertNotNull( $found );
		$this->assertSame( 'Classic Tee', $found->name );
		$this->assertSame( 'classic-tee', $found->slug );
		$this->assertSame( 'A heavyweight cotton tee.', $found->description );
		$this->assertSame( ProductStatus::Published, $found->status );
		$this->assertSame( 'NVoos', $found->vendor );
		$this->assertSame( 'Apparel', $found->productType );
		$this->assertSame( array( 'cotton', 'summer' ), $found->tags );
		$this->assertSame( 'woocommerce:product:101', $found->externalRef );

		// The `datetime` cast returns Carbon, which is not the immutable type the
		// entity declares; a naive adapter is a TypeError on every row.
		$this->assertInstanceOf( \DateTimeImmutable::class, $found->createdAt );

		$this->assertSame(
			array( 'Size', 'Colour' ),
			array_map(
				static fn ( ProductOption $option ): string => $option->name,
				$found->options
			)
		);

		$this->assertCount( 2, $found->variants );

		$first = $found->variants[0];

		$this->assertNotNull( $first->id );
		$this->assertSame( $found->id, $first->productId );
		$this->assertSame( 'TEE-RED-S', $first->sku );
		$this->assertSame( 1999, $first->price->minorUnits );
		$this->assertSame( 'USD', $first->price->currency );
		$this->assertSame( 'S', $first->options->value( 'Size' ) );
		$this->assertSame( 'Red', $first->options->value( 'Colour' ) );
		$this->assertSame( 0, $first->position );
		$this->assertSame( 7, $first->stock );
		$this->assertTrue( $first->trackInventory );
		$this->assertTrue( $first->isInStock() );

		$second = $found->variants[1];

		$this->assertSame( 1, $second->position );
		$this->assertSame( 2450, $second->price->minorUnits );
		$this->assertSame( 2900, $second->compareAtPrice?->minorUnits );
		$this->assertSame( 1000, $second->cost?->minorUnits );
		$this->assertSame( '0123456789012', $second->barcode );
		$this->assertNull( $second->stock );
		$this->assertFalse( $second->trackInventory );
		$this->assertSame( 'https://example.test/tee.png', $second->imageUrl );
		$this->assertSame( 'woocommerce:variation:2001', $second->externalRef );

		// An untracked variant is sellable and contributes no stock.
		$this->assertTrue( $second->isInStock() );
		$this->assertSame( 7, $found->totalStock() );

		$range = $found->priceRange();
		$this->assertNotNull( $range );
		$this->assertSame( 1999, $range['min']->minorUnits );
		$this->assertSame( 2450, $range['max']->minorUnits );

		$this->assertSame( array(), $found->optionErrors() );
	}

	public function test_an_update_leaves_the_stored_variant_list_untouched(): void {
		$catalog = $this->catalog();

		$created = $catalog->save(
			new Product(
				id: null,
				name: 'Before',
				variants: array(
					new ProductVariant(
						id: null,
						sku: 'KEEP-1',
						price: Money::fromMinorUnits( 500, 'USD' ),
						options: new OptionSelection( array() ),
					),
				),
			)
		);

		$createdAt = $created->createdAt?->format( 'Y-m-d H:i:s' );

		$updated = $catalog->save(
			new Product(
				id: $created->id,
				name: 'After',
				// A payload that knows nothing about the stored variant. It must not
				// create this one, and must not delete the original.
				variants: array(
					new ProductVariant(
						id: null,
						sku: 'INTRUDER-1',
						price: Money::fromMinorUnits( 900, 'USD' ),
						options: new OptionSelection( array() ),
					),
				),
			)
		);

		$this->assertSame( 'After', $updated->name );
		$this->assertCount( 1, $updated->variants );
		$this->assertSame( 'KEEP-1', $updated->variants[0]->sku );
		$this->assertNull( $catalog->findBySku( 'INTRUDER-1' ) );

		// Creation time is history; an update does not rewrite it.
		$this->assertSame( $createdAt, $updated->createdAt?->format( 'Y-m-d H:i:s' ) );
	}

	public function test_an_update_naming_a_missing_product_is_refused(): void {
		$this->expectException( NotFoundException::class );

		$this->catalog()->save(
			new Product( id: 999999, name: 'Ghost' )
		);
	}

	public function test_a_duplicate_external_reference_is_refused(): void {
		$catalog = $this->catalog();

		$first = $catalog->save(
			new Product(
				id: null,
				name: 'First',
				externalRef: 'erp:1',
			)
		);

		// Re-saving the same product under its own reference is the migration's
		// idempotent case, not a collision.
		$again = $catalog->save( new Product( id: $first->id, name: 'First', externalRef: 'erp:1' ) );
		$this->assertSame( $first->id, $again->id );

		$this->expectException( ValidationException::class );

		$catalog->save(
			new Product(
				id: null,
				name: 'Second',
				externalRef: 'erp:1',
			)
		);
	}

	public function test_a_duplicate_sku_on_one_product_is_refused(): void {
		$catalog = $this->catalog();

		$product = $catalog->save(
			new Product(
				id: null,
				name: 'Dupes',
				variants: array(
					new ProductVariant(
						id: null,
						sku: 'DUP-1',
						price: Money::fromMinorUnits( 100, 'USD' ),
						options: new OptionSelection( array() ),
					),
				),
			)
		);

		$this->expectException( ValidationException::class );

		$catalog->saveVariant(
			(int) $product->id,
			new ProductVariant(
				id: null,
				sku: 'DUP-1',
				price: Money::fromMinorUnits( 200, 'USD' ),
				options: new OptionSelection( array() ),
			)
		);
	}

	public function test_a_duplicate_sku_differing_only_by_case_is_refused(): void {
		$catalog = $this->catalog();

		$product = $catalog->save(
			new Product(
				id: null,
				name: 'Case',
				variants: array(
					new ProductVariant(
						id: null,
						sku: 'CASE-1',
						price: Money::fromMinorUnits( 100, 'USD' ),
						options: new OptionSelection( array() ),
					),
				),
			)
		);

		$this->expectException( ValidationException::class );

		$catalog->saveVariant(
			(int) $product->id,
			new ProductVariant(
				id: null,
				sku: 'case-1',
				price: Money::fromMinorUnits( 200, 'USD' ),
				options: new OptionSelection( array() ),
			)
		);
	}

	public function test_a_sku_already_used_by_another_product_is_a_validation_error(): void {
		$catalog = $this->catalog();

		$catalog->save(
			new Product(
				id: null,
				name: 'Owner',
				variants: array(
					new ProductVariant(
						id: null,
						sku: 'SHARED-1',
						price: Money::fromMinorUnits( 100, 'USD' ),
						options: new OptionSelection( array() ),
					),
				),
			)
		);

		// The column is globally unique, so the guard must produce the contract's
		// validation error rather than letting the driver raise a QueryException.
		try {
			$catalog->save(
				new Product(
					id: null,
					name: 'Thief',
					variants: array(
						new ProductVariant(
							id: null,
							sku: 'SHARED-1',
							price: Money::fromMinorUnits( 300, 'USD' ),
							options: new OptionSelection( array() ),
						),
					),
				)
			);

			$this->fail( 'A cross-product duplicate SKU was accepted.' );
		} catch ( ValidationException $error ) {
			$this->assertArrayHasKey( 'sku', $error->errors );
			$this->assertStringContainsString( 'SHARED-1', $error->getMessage() );
		}

		$this->assertSame( 1, DB::table( 'nvoos_products' )->count() );
	}

	public function test_find_by_sku_is_case_insensitive_and_names_its_product(): void {
		$catalog = $this->catalog();

		$product = $catalog->save(
			new Product(
				id: null,
				name: 'Lookup',
				variants: array(
					new ProductVariant(
						id: null,
						sku: 'TEE-RED-S',
						price: Money::fromMinorUnits( 1999, 'USD' ),
						options: new OptionSelection( array() ),
					),
				),
			)
		);

		$exact = $catalog->findBySku( 'TEE-RED-S' );
		$this->assertNotNull( $exact );
		$this->assertSame( $product->id, $exact->productId );

		$lower = $catalog->findBySku( 'tee-red-s' );
		$this->assertNotNull( $lower );
		$this->assertSame( $exact->id, $lower->id );

		$this->assertNull( $catalog->findBySku( 'TEE-RED-XL' ) );
		$this->assertNull( $catalog->findBySku( '   ' ) );
	}

	public function test_query_filters_and_paginates(): void {
		$catalog = $this->catalog();

		$jacket = $catalog->save(
			new Product(
				id: null,
				name: 'Alpine Jacket',
				variants: array(
					new ProductVariant(
						id: null,
						sku: 'AJ-1',
						price: Money::fromMinorUnits( 12000, 'USD' ),
						options: new OptionSelection( array() ),
						stock: 3,
						trackInventory: true,
					),
				),
				status: ProductStatus::Published,
				vendor: 'Acme',
				tags: array( 'winter', 'outdoor' ),
			)
		);

		$beanie = $catalog->save(
			new Product(
				id: null,
				name: 'Alpine Beanie',
				variants: array(
					new ProductVariant(
						id: null,
						sku: 'AB-1',
						price: Money::fromMinorUnits( 2000, 'USD' ),
						options: new OptionSelection( array() ),
						stock: 0,
						trackInventory: true,
					),
				),
				status: ProductStatus::Published,
				vendor: 'Acme',
				tags: array( 'winter' ),
			)
		);

		$cap = $catalog->save(
			new Product(
				id: null,
				name: 'Desert Cap',
				variants: array(
					new ProductVariant(
						id: null,
						sku: 'DC-1',
						price: Money::fromMinorUnits( 1500, 'USD' ),
						options: new OptionSelection( array() ),
						stock: null,
						trackInventory: false,
					),
				),
				status: ProductStatus::Draft,
				vendor: 'Other',
				tags: array( 'summer' ),
			)
		);

		$hat = $catalog->save(
			new Product(
				id: null,
				name: 'Sun Hat',
				variants: array(
					new ProductVariant(
						id: null,
						sku: 'SH-1',
						price: Money::fromMinorUnits( 5000, 'USD' ),
						options: new OptionSelection( array() ),
						stock: 10,
						trackInventory: true,
					),
				),
				status: ProductStatus::Archived,
				vendor: 'Other',
			)
		);

		$published = $catalog->query( new ProductQuery( status: ProductStatus::Published ) );
		$this->assertSame( 2, $published->total );
		$this->assertEqualsCanonicalizing( array( 'Alpine Jacket', 'Alpine Beanie' ), $this->names( $published ) );

		$this->assertSame( 2, $catalog->query( new ProductQuery( vendor: 'Acme' ) )->total );
		$this->assertSame( 2, $catalog->query( new ProductQuery( vendor: 'Other' ) )->total );
		$this->assertSame( 0, $catalog->query( new ProductQuery( vendor: 'Nobody' ) )->total );

		$this->assertSame( 2, $catalog->query( new ProductQuery( search: 'Alpine' ) )->total );

		// The search also reaches variant SKUs, which live on another table.
		$bySku = $catalog->query( new ProductQuery( search: 'DC-1' ) );
		$this->assertSame( 1, $bySku->total );
		$this->assertSame( array( 'Desert Cap' ), $this->names( $bySku ) );

		$this->assertSame( 2, $catalog->query( new ProductQuery( tags: array( 'winter' ) ) )->total );

		// Tags are ANDed, not ORed.
		$narrow = $catalog->query( new ProductQuery( tags: array( 'winter', 'outdoor' ) ) );
		$this->assertSame( 1, $narrow->total );
		$this->assertSame( array( 'Alpine Jacket' ), $this->names( $narrow ) );

		// The exact-SKU filter is case-insensitive, like findBySku().
		$skuQuery = $catalog->query( new ProductQuery( sku: 'dc-1' ) );
		$this->assertSame( 1, $skuQuery->total );
		$this->assertSame( array( 'Desert Cap' ), $this->names( $skuQuery ) );

		// Tracked stock must be positive; an untracked variant is sellable.
		$inStock = $catalog->query( new ProductQuery( inStockOnly: true ) );
		$this->assertSame( 3, $inStock->total );
		$this->assertEqualsCanonicalizing(
			array( 'Alpine Jacket', 'Desert Cap', 'Sun Hat' ),
			$this->names( $inStock )
		);

		$firstPage  = $catalog->query( new ProductQuery( perPage: 2, page: 1 ) );
		$secondPage = $catalog->query( new ProductQuery( perPage: 2, page: 2 ) );

		$this->assertSame( 4, $firstPage->total );
		$this->assertSame( 2, $firstPage->totalPages );
		$this->assertTrue( $firstPage->hasMorePages() );
		$this->assertFalse( $secondPage->hasMorePages() );
		$this->assertCount( 2, $firstPage->items );
		$this->assertCount( 2, $secondPage->items );

		$pageOneIds = array_map( static fn ( Product $p ): int => (int) $p->id, $firstPage->items );
		$pageTwoIds = array_map( static fn ( Product $p ): int => (int) $p->id, $secondPage->items );

		// A stable tie-break means a row cannot appear on both pages.
		$this->assertSame( array(), array_intersect( $pageOneIds, $pageTwoIds ) );

		$all = array_merge( $pageOneIds, $pageTwoIds );
		sort( $all );

		$this->assertSame(
			array( $jacket->id, $beanie->id, $cap->id, $hat->id ),
			$all
		);
	}

	public function test_query_orders_by_price_and_by_name(): void {
		$catalog = $this->catalog();

		foreach ( array(
			array( 'Alpine Jacket', 'AJ-1', 12000 ),
			array( 'Alpine Beanie', 'AB-1', 2000 ),
			array( 'Desert Cap', 'DC-1', 1500 ),
			array( 'Sun Hat', 'SH-1', 5000 ),
		) as $row ) {
			$catalog->save(
				new Product(
					id: null,
					name: $row[0],
					variants: array(
						new ProductVariant(
							id: null,
							sku: $row[1],
							price: Money::fromMinorUnits( $row[2], 'USD' ),
							options: new OptionSelection( array() ),
						),
					),
				)
			);
		}

		$this->assertSame(
			array( 'Desert Cap', 'Alpine Beanie', 'Sun Hat', 'Alpine Jacket' ),
			$this->names( $catalog->query( new ProductQuery( orderBy: 'price', order: 'ASC' ) ) )
		);

		$this->assertSame(
			array( 'Alpine Jacket', 'Sun Hat', 'Alpine Beanie', 'Desert Cap' ),
			$this->names( $catalog->query( new ProductQuery( orderBy: 'price', order: 'DESC' ) ) )
		);

		$this->assertSame(
			array( 'Alpine Beanie', 'Alpine Jacket', 'Desert Cap', 'Sun Hat' ),
			$this->names( $catalog->query( new ProductQuery( orderBy: 'name', order: 'ASC' ) ) )
		);
	}

	public function test_a_variant_with_no_options_round_trips(): void {
		$catalog = $this->catalog();

		$created = $catalog->save(
			new Product(
				id: null,
				name: 'Simple Product',
				variants: array(
					new ProductVariant(
						id: null,
						sku: 'SIMPLE-1',
						price: Money::fromMinorUnits( 999, 'USD' ),
						options: new OptionSelection( array() ),
					),
				),
			)
		);

		$found = $catalog->find( (int) $created->id );

		$this->assertNotNull( $found );
		$this->assertCount( 1, $found->variants );
		$this->assertSame( array(), $found->variants[0]->options->values );
		$this->assertSame( '', $found->variants[0]->options->key() );

		// One variant is not a "has variants" product, and nothing is incoherent.
		$this->assertFalse( $found->hasVariants() );
		$this->assertSame( array(), $found->optionErrors() );
		$this->assertSame( 'SIMPLE-1', $found->primaryVariant()?->sku );
	}

	public function test_delete_removes_the_product_and_its_variants(): void {
		$catalog = $this->catalog();

		$created = $catalog->save(
			new Product(
				id: null,
				name: 'Doomed',
				variants: array(
					new ProductVariant(
						id: null,
						sku: 'DOOM-1',
						price: Money::fromMinorUnits( 100, 'USD' ),
						options: new OptionSelection( array() ),
					),
					new ProductVariant(
						id: null,
						sku: 'DOOM-2',
						price: Money::fromMinorUnits( 200, 'USD' ),
						options: new OptionSelection( array() ),
					),
				),
			)
		);

		$this->assertSame( 2, DB::table( 'nvoos_product_variants' )->count() );

		$catalog->delete( (int) $created->id );

		$this->assertNull( $catalog->find( (int) $created->id ) );
		$this->assertSame( 0, DB::table( 'nvoos_products' )->count() );
		$this->assertSame( 0, DB::table( 'nvoos_product_variants' )->count() );
	}

	public function test_deleting_a_missing_product_is_refused(): void {
		$this->expectException( NotFoundException::class );

		$this->catalog()->delete( 999999 );
	}

	public function test_save_variant_inserts_updates_and_deletes(): void {
		$catalog = $this->catalog();

		$product = $catalog->save(
			new Product(
				id: null,
				name: 'Extensible',
				variants: array(
					new ProductVariant(
						id: null,
						sku: 'BASE-1',
						price: Money::fromMinorUnits( 100, 'USD' ),
						options: new OptionSelection( array() ),
					),
				),
			)
		);

		$inserted = $catalog->saveVariant(
			(int) $product->id,
			new ProductVariant(
				id: null,
				sku: 'EXTRA-1',
				price: Money::fromMinorUnits( 500, 'USD' ),
				options: new OptionSelection( array( 'Size' => 'M' ) ),
				position: 1,
			)
		);

		$this->assertGreaterThan( 0, $inserted->id );
		$this->assertSame( $product->id, $inserted->productId );

		$renamed = $catalog->saveVariant(
			(int) $product->id,
			new ProductVariant(
				id: $inserted->id,
				sku: 'EXTRA-1',
				price: Money::fromMinorUnits( 700, 'USD' ),
				options: new OptionSelection( array( 'Size' => 'L' ) ),
				position: 1,
			)
		);

		// The same row, updated in place — not a second row.
		$this->assertSame( $inserted->id, $renamed->id );
		$this->assertSame( 700, $renamed->price->minorUnits );
		$this->assertSame( 'L', $renamed->options->value( 'Size' ) );
		$this->assertSame( 2, DB::table( 'nvoos_product_variants' )->count() );

		$catalog->deleteVariant( (int) $inserted->id );

		$this->assertSame( 1, DB::table( 'nvoos_product_variants' )->count() );
		$this->assertNull( $catalog->findBySku( 'EXTRA-1' ) );
	}

	public function test_save_variant_and_delete_variant_refuse_missing_ids(): void {
		$catalog = $this->catalog();

		$product = $catalog->save(
			new Product( id: null, name: 'Sparse' )
		);

		try {
			$catalog->saveVariant(
				(int) $product->id,
				new ProductVariant(
					id: 999999,
					sku: 'MISSING-1',
					price: Money::fromMinorUnits( 100, 'USD' ),
					options: new OptionSelection( array() ),
				)
			);

			$this->fail( 'A missing variant id was accepted.' );
		} catch ( NotFoundException $error ) {
			$this->assertSame( 'variant', $error->resourceType );
		}

		try {
			$catalog->saveVariant(
				999999,
				new ProductVariant(
					id: null,
					sku: 'ORPHAN-1',
					price: Money::fromMinorUnits( 100, 'USD' ),
					options: new OptionSelection( array() ),
				)
			);

			$this->fail( 'A missing product id was accepted.' );
		} catch ( NotFoundException $error ) {
			$this->assertSame( 'product', $error->resourceType );
		}

		$this->expectException( NotFoundException::class );

		$catalog->deleteVariant( 999999 );
	}

	public function test_find_by_external_ref_is_exact_and_ignores_blank(): void {
		$catalog = $this->catalog();

		$created = $catalog->save(
			new Product(
				id: null,
				name: 'Referenced',
				externalRef: 'woocommerce:product:9001',
			)
		);

		$found = $catalog->findByExternalRef( 'woocommerce:product:9001' );
		$this->assertNotNull( $found );
		$this->assertSame( $created->id, $found->id );

		// Padded input is trimmed; a prefix is not a match.
		$this->assertSame( $created->id, $catalog->findByExternalRef( '  woocommerce:product:9001  ' )?->id );
		$this->assertNull( $catalog->findByExternalRef( 'woocommerce:product:900' ) );
		$this->assertNull( $catalog->findByExternalRef( '' ) );
	}
}
