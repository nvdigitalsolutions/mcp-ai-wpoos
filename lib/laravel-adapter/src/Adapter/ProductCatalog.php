<?php
/**
 * Laravel adapter: ProductCatalogInterface over Eloquent.
 *
 * Proposal 164 section 164.114. This is the *target* of a WooCommerce → Laravel
 * catalogue migration, and the store the product tools read once they are ported
 * (section 164.111 defined the contract; this is its first implementation).
 *
 * ## The write rule, implemented
 *
 * `save()` creates a product **and its variants** together, and on update it
 * replaces the product's fields and option definitions while leaving the stored
 * variant list alone. Variant lifecycle after creation is `saveVariant()` and
 * `deleteVariant()`. That is the contract's asymmetry, and it exists so a partial
 * payload cannot delete a variant by omission.
 *
 * `external_ref` is the migration's idempotency key. A duplicate is refused —
 * including a duplicate about to be created by an update that renames its own
 * reference — because two products claiming one source identity make every
 * re-run ambiguous.
 *
 * ## Portability
 *
 * Written for the SQL Laravel actually has on the deployment target (a Cloudways
 * PHP server on MariaDB 10.11, verified 2026-11-27) and for the PostgreSQL that
 * Proposal 148's contract server would use. No PostgreSQL-only type, no MySQL-only
 * function: JSON goes through Eloquent's `json` cast and `whereJsonContains`, and
 * price ordering is a standard scalar subquery.
 *
 * Because `external_ref` and `sku` are unique columns, MySQL's 767-byte index
 * limit is why both are 191 characters at utf8mb4 (see the migrations).
 *
 * @package Nvoos\Laravel
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

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
use Nvoos\Laravel\Models\NvoosProduct;
use Nvoos\Laravel\Models\NvoosProductVariant;

class ProductCatalog implements ProductCatalogInterface {

	/**
	 * Find one product by storage id, with its options and variants.
	 *
	 * @param int $id Product identifier.
	 *
	 * @return Product|null
	 */
	public function find( int $id ): ?Product {
		$model = NvoosProduct::query()->with( 'variants' )->find( $id );

		return null === $model ? null : $this->hydrate( $model );
	}

	/**
	 * Find the product whose source identity is `$externalRef`.
	 *
	 * @param string $externalRef Opaque source identity.
	 *
	 * @return Product|null
	 */
	public function findByExternalRef( string $externalRef ): ?Product {
		$reference = trim( $externalRef );

		if ( '' === $reference ) {
			return null;
		}

		$model = NvoosProduct::query()->with( 'variants' )->where( 'external_ref', $reference )->first();

		return null === $model ? null : $this->hydrate( $model );
	}

	/**
	 * Find the variant carrying a SKU, case-insensitively.
	 *
	 * @param string $sku Stock keeping unit.
	 *
	 * @return ProductVariant|null
	 */
	public function findBySku( string $sku ): ?ProductVariant {
		$needle = mb_strtolower( trim( $sku ) );

		if ( '' === $needle ) {
			return null;
		}

		$model = NvoosProductVariant::query()->whereRaw( 'LOWER(sku) = ?', array( $needle ) )->first();

		return null === $model ? null : $this->hydrateVariant( $model );
	}

	/**
	 * Query products with filtering and pagination.
	 *
	 * @param ProductQuery $query Query specification.
	 *
	 * @return ProductCollection
	 */
	public function query( ProductQuery $query ): ProductCollection {
		$builder = NvoosProduct::query();

		if ( null !== $query->status ) {
			$builder->where( 'status', $query->status->value );
		}

		if ( null !== $query->externalRef ) {
			$builder->where( 'external_ref', $query->externalRef );
		}

		if ( null !== $query->vendor ) {
			$builder->where( 'vendor', $query->vendor );
		}

		if ( '' !== $query->search ) {
			$like    = '%' . $this->escapeLike( $query->search ) . '%';
			$builder->where(
				function ( $nested ) use ( $like ) {
					$nested->where( 'name', 'like', $like )
						->orWhere( 'description', 'like', $like )
						->orWhereHas(
							'variants',
							static function ( $variant ) use ( $like ) {
								$variant->where( 'sku', 'like', $like );
							}
						);
				}
			);
		}

		if ( null !== $query->sku ) {
			$sku = mb_strtolower( trim( $query->sku ) );
			$builder->whereHas(
				'variants',
				static function ( $variant ) use ( $sku ) {
					$variant->whereRaw( 'LOWER(sku) = ?', array( $sku ) );
				}
			);
		}

		foreach ( $query->tags as $tag ) {
			$builder->whereJsonContains( 'tags', $tag );
		}

		if ( $query->inStockOnly ) {
			$builder->whereHas(
				'variants',
				static function ( $variant ) {
					$variant->where(
						static function ( $either ) {
							$either->where( 'track_inventory', false )->orWhere( 'stock', '>', 0 );
						}
					);
				}
			);
		}

		$total = (int) ( clone $builder )->count();

		$direction = 'ASC' === strtoupper( $query->order ) ? 'asc' : 'desc';

		switch ( $query->orderBy ) {
			case 'name':
				$builder->orderBy( 'name', $direction );
				break;

			case 'price':
				// A standard scalar subquery: portable to MariaDB and PostgreSQL,
				// and no join to inflate the row count.
				$builder->orderByRaw(
					'(select min(price_minor) from nvoos_product_variants where nvoos_product_variants.product_id = nvoos_products.id) ' . $direction
				);
				break;

			case 'updated_at':
				$builder->orderBy( 'updated_at', $direction );
				break;

			case 'id':
				$builder->orderBy( 'id', $direction );
				break;

			default:
				$builder->orderBy( 'created_at', $direction );
				break;
		}

		// A stable tie-break, so paging cannot return one row twice.
		$builder->orderBy( 'id' );

		$rows = $builder->with( 'variants' )
			->forPage( $query->page, $query->perPage )
			->get();

		$items = array();

		foreach ( $rows as $row ) {
			$items[] = $this->hydrate( $row );
		}

		return new ProductCollection(
			$items,
			$total,
			$query->page,
			$query->perPage,
			max( 1, (int) ceil( $total / $query->perPage ) )
		);
	}

	/**
	 * Create or update a product.
	 *
	 * @param Product $product The product to store.
	 *
	 * @return Product
	 *
	 * @throws ValidationException When the payload is invalid, including a duplicate external reference.
	 * @throws NotFoundException  When an update names a product that does not exist.
	 */
	public function save( Product $product ): Product {
		return DB::transaction(
			function () use ( $product ) {
				$this->assertReferenceIsFree( $product->externalRef, $product->id );

				$attributes = $this->productAttributes( $product );

				if ( null === $product->id ) {
					$model = new NvoosProduct();
					$model->fill( $attributes );

					// `created_at` is not mass-assignable, so it is assigned as a date
					// attribute. A migration carries the source's creation time; letting
					// Eloquent stamp "now" would lose the only record of it.
					if ( null !== $product->createdAt ) {
						$model->created_at = $product->createdAt;
					}

					if ( null !== $product->updatedAt ) {
						$model->updated_at = $product->updatedAt;
					}

					$model->save();

					foreach ( $product->variants as $variant ) {
						$this->insertVariant( (int) $model->id, $variant );
					}

					return $this->requireProduct( (int) $model->id );
				}

				$model = NvoosProduct::query()->find( $product->id );

				if ( null === $model ) {
					throw new NotFoundException(
						sprintf( 'Product %d does not exist.', $product->id ),
						'product',
						$product->id
					);
				}

				$model->fill( $attributes );

				// An update never rewrites `created_at`: creation time is history, and a
				// caller that sends a different one is not describing the same product.
				if ( null !== $product->updatedAt ) {
					$model->updated_at = $product->updatedAt;
				}

				$model->save();

				// The contract: an update does not touch the stored variant list.
				return $this->requireProduct( (int) $model->id );
			}
		);
	}

	/**
	 * Delete a product and every variant it owns.
	 *
	 * The variants are deleted explicitly rather than left to the foreign key's
	 * `ON DELETE CASCADE`. That cascade is a property of the connection — SQLite
	 * leaves foreign keys off unless the PDO layer turns them on — and the
	 * contract's promise should not depend on how a host configured its database.
	 *
	 * @param int $id Product identifier.
	 *
	 * @throws NotFoundException When the product does not exist.
	 */
	public function delete( int $id ): void {
		DB::transaction(
			function () use ( $id ) {
				if ( ! NvoosProduct::query()->whereKey( $id )->exists() ) {
					throw new NotFoundException( sprintf( 'Product %d does not exist.', $id ), 'product', $id );
				}

				NvoosProductVariant::query()->where( 'product_id', $id )->delete();
				NvoosProduct::query()->whereKey( $id )->delete();
			}
		);
	}

	/**
	 * Insert or update one variant of a product.
	 *
	 * @param int            $productId Owning product.
	 * @param ProductVariant $variant   The variant to store.
	 *
	 * @return ProductVariant
	 *
	 * @throws NotFoundException  When the product or variant does not exist.
	 * @throws ValidationException When the variant duplicates an existing identity.
	 */
	public function saveVariant( int $productId, ProductVariant $variant ): ProductVariant {
		return DB::transaction(
			function () use ( $productId, $variant ) {
				if ( null === NvoosProduct::query()->find( $productId ) ) {
					throw new NotFoundException(
						sprintf( 'Product %d does not exist.', $productId ),
						'product',
						$productId
					);
				}

				$this->assertIdentityIsFree( $productId, $variant, $variant->id );

				if ( null === $variant->id ) {
					return $this->hydrateVariant( $this->insertVariant( $productId, $variant ) );
				}

				$model = NvoosProductVariant::query()
					->where( 'product_id', $productId )
					->find( $variant->id );

				if ( null === $model ) {
					throw new NotFoundException(
						sprintf( 'Variant %d does not exist on product %d.', $variant->id, $productId ),
						'variant',
						$variant->id
					);
				}

				$model->fill( $this->variantAttributes( $productId, $variant ) );
				$model->save();

				return $this->hydrateVariant( $model );
			}
		);
	}

	/**
	 * Delete one variant.
	 *
	 * @param int $variantId Variant identifier.
	 *
	 * @throws NotFoundException When the variant does not exist.
	 */
	public function deleteVariant( int $variantId ): void {
		$deleted = NvoosProductVariant::query()->whereKey( $variantId )->delete();

		if ( 0 === $deleted ) {
			throw new NotFoundException(
				sprintf( 'Variant %d does not exist.', $variantId ),
				'variant',
				$variantId
			);
		}
	}

	/**
	 * A product row, hydrated.
	 *
	 * @param NvoosProduct $model Loaded product with its variants.
	 *
	 * @return Product
	 *
	 * @throws ValidationException When the stored row cannot form a valid product.
	 */
	private function hydrate( NvoosProduct $model ): Product {
		$variants = array();

		foreach ( $model->variants as $variant ) {
			$variants[] = $this->hydrateVariant( $variant );
		}

		$options = array();

		foreach ( is_array( $model->options ) ? $model->options : array() as $option ) {
			if ( ! is_array( $option ) || ! isset( $option['name'] ) ) {
				continue;
			}

			$values = array();

			foreach ( is_array( $option['values'] ?? null ) ? $option['values'] : array() as $value ) {
				if ( is_scalar( $value ) ) {
					$values[] = (string) $value;
				}
			}

			$options[] = new ProductOption( (string) $option['name'], $values );
		}

		$status = ProductStatus::tryFrom( (string) $model->status );

		try {
			return new Product(
				id: (int) $model->id,
				name: (string) $model->name,
				variants: $variants,
				options: $options,
				description: (string) ( $model->description ?? '' ),
				slug: null !== $model->slug ? (string) $model->slug : null,
				status: $status ?? ProductStatus::Draft,
				vendor: null !== $model->vendor ? (string) $model->vendor : null,
				productType: null !== $model->product_type ? (string) $model->product_type : null,
				tags: $this->stringList( $model->tags ),
				externalRef: null !== $model->external_ref ? (string) $model->external_ref : null,
				createdAt: $this->immutableTime( $model->created_at ),
				updatedAt: $this->immutableTime( $model->updated_at ),
			);
		} catch ( \InvalidArgumentException $error ) {
			throw new ValidationException( $error->getMessage() );
		}
	}

	/**
	 * A variant row, hydrated.
	 *
	 * @param NvoosProductVariant $model Loaded variant.
	 *
	 * @return ProductVariant
	 */
	private function hydrateVariant( NvoosProductVariant $model ): ProductVariant {
		return new ProductVariant(
			id: (int) $model->id,
			sku: null !== $model->sku ? (string) $model->sku : null,
			price: new Money( (int) $model->price_minor, (string) $model->price_currency ),
			options: new OptionSelection( $this->optionMap( $model->options ) ),
			compareAtPrice: $this->optionalMoney(
				$model->compare_at_price_minor,
				$model->compare_at_price_currency
			),
			cost: $this->optionalMoney( $model->cost_minor, $model->cost_currency ),
			barcode: null !== $model->barcode ? (string) $model->barcode : null,
			stock: null !== $model->stock ? (int) $model->stock : null,
			trackInventory: (bool) $model->track_inventory,
			position: (int) $model->position,
			imageUrl: null !== $model->image_url ? (string) $model->image_url : null,
			externalRef: null !== $model->external_ref ? (string) $model->external_ref : null,
			productId: (int) $model->product_id,
		);
	}

	/**
	 * The product columns for a save.
	 *
	 * @param Product $product Source product.
	 *
	 * @return array<string, mixed>
	 */
	private function productAttributes( Product $product ): array {
		$options = array();

		foreach ( $product->options as $option ) {
			$options[] = array(
				'name'   => $option->name,
				'values' => $option->values,
			);
		}

		$attributes = array(
			'name'         => $product->name,
			'slug'         => $product->slug,
			'description'  => $product->description,
			'status'       => $product->status->value,
			'vendor'       => $product->vendor,
			'product_type' => $product->productType,
			'tags'         => $product->tags,
			'options'      => $options,
			'external_ref' => $product->externalRef,
		);

		return $attributes;
	}

	/**
	 * The variant columns for a save.
	 *
	 * @param int            $productId Owning product.
	 * @param ProductVariant $variant   Source variant.
	 *
	 * @return array<string, mixed>
	 */
	private function variantAttributes( int $productId, ProductVariant $variant ): array {
		return array(
			'product_id'                  => $productId,
			'sku'                         => $variant->sku,
			'price_minor'                 => $variant->price->minorUnits,
			'price_currency'              => $variant->price->currency,
			'compare_at_price_minor'      => $variant->compareAtPrice?->minorUnits,
			'compare_at_price_currency'   => $variant->compareAtPrice?->currency,
			'cost_minor'                  => $variant->cost?->minorUnits,
			'cost_currency'               => $variant->cost?->currency,
			'barcode'                     => $variant->barcode,
			'stock'                       => $variant->stock,
			'track_inventory'             => $variant->trackInventory,
			'position'                    => $variant->position,
			'image_url'                   => $variant->imageUrl,
			'options'                     => $variant->options->values,
			'external_ref'                => $variant->externalRef,
		);
	}

	/**
	 * Insert one variant, refusing a duplicate identity.
	 *
	 * @param int            $productId Owning product.
	 * @param ProductVariant $variant   Variant to insert.
	 *
	 * @return NvoosProductVariant
	 *
	 * @throws ValidationException When the product already carries this identity.
	 */
	private function insertVariant( int $productId, ProductVariant $variant ): NvoosProductVariant {
		$this->assertIdentityIsFree( $productId, $variant, null );

		return NvoosProductVariant::query()->create( $this->variantAttributes( $productId, $variant ) );
	}

	/**
	 * Refuse a variant identity the catalog already carries.
	 *
	 * Identity is the SKU when there is one, the option signature otherwise — the
	 * contract's rule. Two checks, because they answer different questions:
	 *
	 * - **SKU is unique catalog-wide**, matching the `unique` index on the column.
	 *   Without this guard a duplicate would surface as a `QueryException` from
	 *   the driver rather than the `ValidationException` the contract promises,
	 *   and `findBySku()` — which returns one variant — would pick arbitrarily.
	 * - **Identity is unique within the product**, which is the case the schema
	 *   cannot express: two variants with no SKU and the same option signature
	 *   collide on nothing a database index can see.
	 *
	 * SKUs are compared case-insensitively because `findBySku()` is.
	 *
	 * @param int            $productId Owning product.
	 * @param ProductVariant $variant   Candidate.
	 * @param int|null       $ignoreId  Row to exclude (the one being updated).
	 *
	 * @throws ValidationException When the identity is taken.
	 */
	private function assertIdentityIsFree( int $productId, ProductVariant $variant, ?int $ignoreId ): void {
		$sku = null !== $variant->sku ? trim( $variant->sku ) : '';

		if ( '' !== $sku ) {
			$taken = NvoosProductVariant::query()
				->whereRaw( 'LOWER(sku) = ?', array( mb_strtolower( $sku ) ) )
				->when(
					null !== $ignoreId,
					static function ( $builder ) use ( $ignoreId ) {
						$builder->whereKeyNot( $ignoreId );
					}
				)
				->exists();

			if ( $taken ) {
				throw new ValidationException(
					sprintf( 'The SKU "%s" is already taken.', $sku ),
					array( 'sku' => array( 'Already taken.' ) )
				);
			}
		}

		$identity = $variant->identity();
		$siblings = NvoosProductVariant::query()->where( 'product_id', $productId )->get();

		foreach ( $siblings as $sibling ) {
			if ( null !== $ignoreId && (int) $sibling->id === $ignoreId ) {
				continue;
			}

			if ( $this->hydrateVariant( $sibling )->identity() === $identity ) {
				throw new ValidationException(
					sprintf( 'The product already has a variant with the identity "%s".', $identity )
				);
			}
		}
	}

	/**
	 * A stored date as the immutable type the entity declares.
	 *
	 * Eloquent's `datetime` cast returns `Carbon`, which extends `\DateTime`, so
	 * passing it straight to `Product::createdAt` — typed `\DateTimeImmutable` —
	 * is a `TypeError` on every row. `createFromInterface()` converts either way
	 * without losing the timezone.
	 *
	 * @param mixed $value Stored date, Carbon, or null.
	 */
	private function immutableTime( mixed $value ): ?\DateTimeImmutable {
		return $value instanceof \DateTimeInterface
			? \DateTimeImmutable::createFromInterface( $value )
			: null;
	}

	/**
	 * Refuse an external reference another product already claims.
	 *
	 * @param string|null $reference Candidate reference.
	 * @param int|null    $ignoreId  Row to exclude (the one being updated).
	 *
	 * @throws ValidationException When the reference is taken.
	 */
	private function assertReferenceIsFree( ?string $reference, ?int $ignoreId ): void {
		if ( null === $reference || '' === trim( $reference ) ) {
			return;
		}

		$builder = NvoosProduct::query()->where( 'external_ref', $reference );

		if ( null !== $ignoreId ) {
			$builder->whereKeyNot( $ignoreId );
		}

		if ( $builder->exists() ) {
			throw new ValidationException(
				sprintf( 'The external reference "%s" is already taken.', $reference ),
				array( 'external_ref' => array( 'Already taken.' ) )
			);
		}
	}

	/**
	 * Load a product or fail.
	 *
	 * @param int $id Product identifier.
	 *
	 * @return Product
	 *
	 * @throws NotFoundException When the row vanished inside the transaction.
	 */
	private function requireProduct( int $id ): Product {
		$product = $this->find( $id );

		if ( null === $product ) {
			throw new NotFoundException( sprintf( 'Product %d does not exist.', $id ), 'product', $id );
		}

		return $product;
	}

	/**
	 * Money from two nullable columns.
	 *
	 * @param mixed $minor    Minor units, or null.
	 * @param mixed $currency Currency code, or null.
	 */
	private function optionalMoney( mixed $minor, mixed $currency ): ?Money {
		if ( ! is_numeric( $minor ) || ! is_string( $currency ) || '' === $currency ) {
			return null;
		}

		return new Money( (int) $minor, $currency );
	}

	/**
	 * A string-keyed option map from a decoded JSON column.
	 *
	 * @param mixed $value Decoded column.
	 *
	 * @return array<string, string>
	 */
	private function optionMap( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$map = array();

		foreach ( $value as $name => $selected ) {
			if ( is_string( $name ) && is_scalar( $selected ) ) {
				$map[ $name ] = (string) $selected;
			}
		}

		return $map;
	}

	/**
	 * A string list from a decoded JSON column.
	 *
	 * @param mixed $value Decoded column.
	 *
	 * @return list<string>
	 */
	private function stringList( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$list = array();

		foreach ( $value as $item ) {
			if ( is_scalar( $item ) ) {
				$list[] = (string) $item;
			}
		}

		return $list;
	}

	/**
	 * Escape a LIKE pattern so a search for `100%` does not match everything.
	 *
	 * MariaDB, MySQL and PostgreSQL all default to a backslash escape character,
	 * which is what this produces. SQLite has no default escape character, so on
	 * an SQLite connection (the in-memory database the tests run on) a `%` or `_`
	 * in the term keeps its wildcard meaning. Production targets escape correctly;
	 * the alternative — a raw `LIKE ? ESCAPE '\'` at every call site — costs three
	 * hand-written SQL fragments to change behaviour only a test connection sees.
	 *
	 * @param string $value Raw term.
	 */
	private function escapeLike( string $value ): string {
		return str_replace( array( '\\', '%', '_' ), array( '\\\\', '\\%', '\\_' ), $value );
	}
}
