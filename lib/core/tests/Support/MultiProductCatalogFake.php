<?php

/**
 * A product catalog holding several products that records every write.
 *
 * `ProductCatalogFake` holds exactly one product, which is enough for a
 * single-product tool and not enough for a bulk one: the bulk tool has to be
 * shown finding several ids, skipping ids the catalog does not hold, and
 * selecting through a query. This double therefore keys products by id and
 * keeps each `ProductQuery` it was handed.
 *
 * Variant writes are recorded rather than applied, because `ProductVariant` is a
 * readonly entity: a tool that mutated the original would otherwise pass an
 * assertion written against the original.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Support;

use Nvoos\Core\Domain\Contract\ProductCatalogInterface;
use Nvoos\Core\Domain\Entity\Product;
use Nvoos\Core\Domain\Entity\ProductCollection;
use Nvoos\Core\Domain\Entity\ProductQuery;
use Nvoos\Core\Domain\Entity\ProductVariant;

final class MultiProductCatalogFake implements ProductCatalogInterface
{
    /** @var array<int, Product> Products by id. */
    public array $products = array();

    /** How many writes reached the catalog. */
    public int $saves = 0;

    /** @var array<int, ProductVariant> Every variant passed to saveVariant(). */
    public array $saved = array();

    /** @var array<int, ProductQuery> Every query the tool issued. */
    public array $queries = array();

    public function __construct(Product ...$products)
    {
        foreach ($products as $product) {
            if (null !== $product->id) {
                $this->products[$product->id] = $product;
            }
        }
    }

    public function find(int $id): ?Product
    {
        return $this->products[$id] ?? null;
    }

    public function findByExternalRef(string $externalRef): ?Product
    {
        return null;
    }

    public function findBySku(string $sku): ?ProductVariant
    {
        return null;
    }

    public function query(ProductQuery $query): ProductCollection
    {
        $this->queries[] = $query;

        $items = array_values($this->products);

        return new ProductCollection($items, count($items), 1, $query->perPage, 1);
    }

    public function save(Product $product): Product
    {
        if (null !== $product->id) {
            $this->products[$product->id] = $product;
        }

        $this->saves++;

        return $product;
    }

    public function delete(int $id): void
    {
    }

    public function saveVariant(int $productId, ProductVariant $variant): ProductVariant
    {
        $this->saves++;
        $this->saved[] = $variant;

        return $variant;
    }

    public function deleteVariant(int $variantId): void
    {
    }
}
