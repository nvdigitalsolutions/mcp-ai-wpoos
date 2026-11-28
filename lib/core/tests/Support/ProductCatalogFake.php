<?php

/**
 * A product catalog that holds one product and records every write.
 *
 * The variant tools build a new `ProductVariant` because the entity is
 * readonly, so a double that only mutated the original would let a broken tool
 * pass: the assertions have to read what was handed to `saveVariant`. This
 * therefore keeps every saved variant in `$saved` and counts the calls.
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

final class ProductCatalogFake implements ProductCatalogInterface
{
    /** How many writes reached the catalog. */
    public int $saves = 0;

    /** @var array<int, ProductVariant> Every variant passed to saveVariant(). */
    public array $saved = array();

    /** @var array<int, int> Every id passed to delete(). */
    public array $deleted = array();

    public function __construct(public ?Product $product)
    {
    }

    public function find(int $id): ?Product
    {
        return $this->product;
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
        $items = null === $this->product ? array() : array($this->product);

        return new ProductCollection($items, count($items), 1, 20, 1);
    }

    public function save(Product $product): Product
    {
        $this->product = $product;
        $this->saves++;

        return $product;
    }

    public function delete(int $id): void
    {
        $this->deleted[] = $id;

        if (null !== $this->product && $this->product->id === $id) {
            $this->product = null;
        }
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
