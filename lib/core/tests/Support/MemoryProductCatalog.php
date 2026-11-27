<?php

/**
 * An in-memory {@see ProductCatalogInterface}, for tests and as a worked example.
 *
 * Proposal 164 section 164.111. It is deliberately a *conforming* implementation
 * rather than a stub: the migration tests need a target that enforces the
 * contract's rules (a duplicate `externalRef` is refused, an update leaves the
 * variant list alone, a variant insert reuses nothing), because a permissive
 * fake would let a broken migrator pass.
 *
 * It also records every write, which is how a dry-run test proves that nothing
 * was written rather than merely that the report said so.
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
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Domain\Error\ValidationException;

final class MemoryProductCatalog implements ProductCatalogInterface
{
    /**
     * @var array<int, Product>
     */
    private array $products = array();

    private int $nextProductId = 1;

    private int $nextVariantId = 1;

    /**
     * @var list<string>
     */
    private array $writes = array();

    public function find(int $id): ?Product
    {
        return $this->products[$id] ?? null;
    }

    public function findByExternalRef(string $externalRef): ?Product
    {
        foreach ($this->products as $product) {
            if ($product->externalRef === $externalRef) {
                return $product;
            }
        }

        return null;
    }

    public function findBySku(string $sku): ?ProductVariant
    {
        $needle = mb_strtolower(trim($sku));

        foreach ($this->products as $product) {
            foreach ($product->variants as $variant) {
                if (null !== $variant->sku && mb_strtolower(trim($variant->sku)) === $needle) {
                    return $variant;
                }
            }
        }

        return null;
    }

    public function query(ProductQuery $query): ProductCollection
    {
        $items = array();

        foreach ($this->products as $product) {
            if ($this->matches($product, $query)) {
                $items[] = $product;
            }
        }

        usort(
            $items,
            function (Product $left, Product $right) use ($query): int {
                $comparison = $this->sortKey($left, $query->orderBy) <=> $this->sortKey($right, $query->orderBy);

                return 'DESC' === strtoupper($query->order) ? -$comparison : $comparison;
            }
        );

        $total      = count($items);
        $totalPages = max(1, (int) ceil($total / $query->perPage));

        return new ProductCollection(
            array_slice($items, $query->getOffset(), $query->perPage),
            $total,
            $query->page,
            $query->perPage,
            $totalPages,
        );
    }

    public function save(Product $product): Product
    {
        $this->writes[] = 'save:' . ( $product->id ?? 'new' ) . ':' . $product->name;

        if (null !== $product->externalRef) {
            $clash = $this->findByExternalRef($product->externalRef);

            if (null !== $clash && $clash->id !== $product->id) {
                throw new ValidationException(
                    sprintf('The external reference "%s" is already taken.', $product->externalRef),
                    array('external_ref' => array('Already taken.'))
                );
            }
        }

        if (null === $product->id) {
            $id       = $this->nextProductId++;
            $variants = array();

            foreach ($product->variants as $variant) {
                $variants[] = $this->storeVariant($id, $variant, null);
            }

            $stored                = $this->rebuild($product, $id, $variants);
            $this->products[$id] = $stored;

            return $stored;
        }

        $current = $this->products[$product->id] ?? null;

        if (null === $current) {
            throw new NotFoundException(
                sprintf('Product %d does not exist.', $product->id),
                'product',
                $product->id
            );
        }

        // The contract: an update replaces product fields and options, and does
        // not touch the stored variant list.
        $stored                              = $this->rebuild($product, $product->id, $current->variants);
        $this->products[$product->id] = $stored;

        return $stored;
    }

    public function delete(int $id): void
    {
        if (! isset($this->products[$id])) {
            throw new NotFoundException(sprintf('Product %d does not exist.', $id), 'product', $id);
        }

        $this->writes[] = 'delete:' . $id;

        unset($this->products[$id]);
    }

    public function saveVariant(int $productId, ProductVariant $variant): ProductVariant
    {
        $product = $this->products[$productId] ?? null;

        if (null === $product) {
            throw new NotFoundException(sprintf('Product %d does not exist.', $productId), 'product', $productId);
        }

        $variants = $product->variants;

        if (null === $variant->id) {
            $stored = $this->storeVariant($productId, $variant, null);

            foreach ($variants as $existing) {
                if ($existing->identity() === $stored->identity()) {
                    throw new ValidationException(
                        sprintf('Variant "%s" already exists on this product.', $stored->identity())
                    );
                }
            }

            $variants[] = $stored;
        } else {
            $stored = $this->storeVariant($productId, $variant, $variant->id);
            $found  = false;

            foreach ($variants as $index => $existing) {
                if ($existing->id === $variant->id) {
                    $variants[$index] = $stored;
                    $found            = true;
                    break;
                }
            }

            if (! $found) {
                throw new NotFoundException(
                    sprintf('Variant %d does not exist.', $variant->id),
                    'variant',
                    $variant->id
                );
            }
        }

        $this->writes[] = 'variant:' . $productId . ':' . $stored->identity();

        $this->products[$productId] = $this->rebuild($product, $productId, $variants);

        return $stored;
    }

    public function deleteVariant(int $variantId): void
    {
        foreach ($this->products as $productId => $product) {
            $variants = array();
            $found    = false;

            foreach ($product->variants as $variant) {
                if ($variant->id === $variantId) {
                    $found = true;
                    continue;
                }

                $variants[] = $variant;
            }

            if ($found) {
                $this->writes[]            = 'delete_variant:' . $variantId;
                $this->products[$productId] = $this->rebuild($product, $productId, $variants);

                return;
            }
        }

        throw new NotFoundException(sprintf('Variant %d does not exist.', $variantId), 'variant', $variantId);
    }

    /**
     * Every write this catalog performed, in order.
     *
     * @return list<string>
     */
    public function writes(): array
    {
        return $this->writes;
    }

    /**
     * @return array<int, Product>
     */
    public function all(): array
    {
        return $this->products;
    }

    /**
     * @param list<ProductVariant> $variants
     */
    private function rebuild(Product $product, int $id, array $variants): Product
    {
        return new Product(
            id: $id,
            name: $product->name,
            variants: $variants,
            options: $product->options,
            description: $product->description,
            slug: $product->slug,
            status: $product->status,
            vendor: $product->vendor,
            productType: $product->productType,
            tags: $product->tags,
            externalRef: $product->externalRef,
            createdAt: $product->createdAt,
            updatedAt: $product->updatedAt,
        );
    }

    private function storeVariant(int $productId, ProductVariant $variant, ?int $id): ProductVariant
    {
        return new ProductVariant(
            id: $id ?? $this->nextVariantId++,
            sku: $variant->sku,
            price: $variant->price,
            options: $variant->options,
            compareAtPrice: $variant->compareAtPrice,
            cost: $variant->cost,
            barcode: $variant->barcode,
            stock: $variant->stock,
            trackInventory: $variant->trackInventory,
            position: $variant->position,
            imageUrl: $variant->imageUrl,
            externalRef: $variant->externalRef,
            productId: $productId,
        );
    }

    private function matches(Product $product, ProductQuery $query): bool
    {
        if (null !== $query->status && $product->status !== $query->status) {
            return false;
        }

        if (null !== $query->externalRef && $product->externalRef !== $query->externalRef) {
            return false;
        }

        if (null !== $query->vendor && $product->vendor !== $query->vendor) {
            return false;
        }

        if (null !== $query->sku && null === $product->variantBySku($query->sku)) {
            return false;
        }

        foreach ($query->tags as $tag) {
            if (! in_array($tag, $product->tags, true)) {
                return false;
            }
        }

        if ($query->inStockOnly) {
            $sellable = false;

            foreach ($product->variants as $variant) {
                if ($variant->isInStock()) {
                    $sellable = true;
                    break;
                }
            }

            if (! $sellable) {
                return false;
            }
        }

        if ('' === $query->search) {
            return true;
        }

        $needle = mb_strtolower($query->search);

        if (str_contains(mb_strtolower($product->name), $needle)) {
            return true;
        }

        if (str_contains(mb_strtolower($product->description), $needle)) {
            return true;
        }

        foreach ($product->variants as $variant) {
            if (null !== $variant->sku && str_contains(mb_strtolower($variant->sku), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function sortKey(Product $product, string $orderBy): int|string
    {
        if ('name' === $orderBy) {
            return mb_strtolower($product->name);
        }

        if ('price' === $orderBy) {
            $range = $product->priceRange();

            return null === $range ? 0 : $range['min']->minorUnits;
        }

        if ('id' === $orderBy) {
            return $product->id ?? 0;
        }

        if ('updated_at' === $orderBy) {
            return $product->updatedAt?->getTimestamp() ?? 0;
        }

        return $product->createdAt?->getTimestamp() ?? 0;
    }
}
