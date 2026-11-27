<?php

/**
 * A product and its variants.
 *
 * Proposal 164 section 164.111. The aggregate the whole commerce toolkit was
 * blocked on. A product owns its option definitions and its variant list; a
 * variant is never addressed without its product, which is why the contract's
 * write methods take a product id alongside a variant.
 *
 * The shape is the intersection of the three models this has to absorb, and the
 * intersection is not empty:
 *
 * | core | WooCommerce | Shopify | Thornburgh (Laravel) |
 * |---|---|---|---|
 * | `Product` | a `product` post | `Product` | `products` |
 * | `ProductOption` | `_product_attributes` / `pa_*` | `options[]` | `attributes` |
 * | `ProductVariant` | `product_variation` post | `ProductVariant` | `skus` |
 * | per-variant value | `attribute_pa_*` meta | `selectedOptions[]` | `attribute_sku.value` |
 *
 * `priceRange()` reads the variants; it is derived, never stored. That is
 * deliberate, because the storefront contract (Proposal 148 `product.price` /
 * `old_price`) wants one flat price per product for a listing page, and a stored
 * copy of a derived value is the classic source of drift.
 *
 * Duplicate variant identities are refused in the constructor: two variants of
 * one product that resolve to the same SKU (or, with no SKU, the same option
 * signature) are a data error, and storing both makes every later lookup
 * arbitrary. Option coherence — a variant naming an option the product does not
 * define — is reported by `optionErrors()` rather than thrown, because an
 * adapter reading a partial source should be able to say so instead of failing
 * the whole import.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Entity;

use Nvoos\Core\Domain\ValueObject\Money;
use Nvoos\Core\Domain\ValueObject\ProductOption;
use Nvoos\Core\Domain\ValueObject\ProductStatus;

final readonly class Product implements \JsonSerializable
{
    /**
     * @param int|null                $id          Storage identifier, null before insert.
     * @param string                  $name        Display name.
     * @param list<ProductVariant>    $variants    Every sellable variant, in display order.
     * @param list<ProductOption>     $options     The option definitions the variants choose from.
     * @param string                  $description Description, HTML allowed.
     * @param string|null             $slug        URL slug (`handle` in Shopify).
     * @param ProductStatus           $status      Visibility state.
     * @param string|null             $vendor      Vendor or brand name.
     * @param string|null             $productType Merchant-defined type.
     * @param list<string>            $tags        Searchable keywords.
     * @param string|null             $externalRef Opaque identity in the source system.
     * @param \DateTimeImmutable|null $createdAt   Creation time, when the source records it.
     * @param \DateTimeImmutable|null $updatedAt   Last update, when the source records it.
     *
     * @throws \InvalidArgumentException When the name is blank or two variants
     *                                   share an identity.
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public array $variants = array(),
        public array $options = array(),
        public string $description = '',
        public ?string $slug = null,
        public ProductStatus $status = ProductStatus::Draft,
        public ?string $vendor = null,
        public ?string $productType = null,
        public array $tags = array(),
        public ?string $externalRef = null,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('A product must have a name.');
        }

        $seen = array();

        foreach ($variants as $variant) {
            if (! $variant instanceof ProductVariant) {
                throw new \InvalidArgumentException('A product carries only ProductVariant instances.');
            }

            $identity = $variant->identity();

            if (isset($seen[$identity])) {
                throw new \InvalidArgumentException(
                    sprintf('Product "%s" has two variants with the identity "%s".', $name, $identity)
                );
            }

            $seen[$identity] = true;
        }
    }

    /**
     * Whether the product is sold in more than one variant.
     */
    public function hasVariants(): bool
    {
        return count($this->variants) > 1;
    }

    /**
     * The variant a listing page shows: the first in display order.
     */
    public function primaryVariant(): ?ProductVariant
    {
        foreach ($this->variants as $variant) {
            if (0 === $variant->position) {
                return $variant;
            }
        }

        return $this->variants[0] ?? null;
    }

    /**
     * Find a variant by SKU, case-insensitively.
     */
    public function variantBySku(string $sku): ?ProductVariant
    {
        $needle = mb_strtolower(trim($sku));

        foreach ($this->variants as $variant) {
            if (null !== $variant->sku && mb_strtolower(trim($variant->sku)) === $needle) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * The cheapest and dearest variant price, or null when the product has no variants.
     *
     * @return array{min: Money, max: Money}|null
     */
    public function priceRange(): ?array
    {
        $min = null;
        $max = null;

        foreach ($this->variants as $variant) {
            if (null === $min || $variant->price->lessThan($min)) {
                $min = $variant->price;
            }

            if (null === $max || $max->lessThan($variant->price)) {
                $max = $variant->price;
            }
        }

        return null === $min || null === $max
            ? null
            : array('min' => $min, 'max' => $max);
    }

    /**
     * Total tracked stock across variants; untracked variants contribute nothing.
     */
    public function totalStock(): int
    {
        $total = 0;

        foreach ($this->variants as $variant) {
            if ($variant->trackInventory && null !== $variant->stock) {
                $total += $variant->stock;
            }
        }

        return $total;
    }

    /**
     * Variant selections that do not fit this product's option definitions.
     *
     * Empty when the product is coherent. Non-empty messages name the variant and
     * the offending option, so an import report can say precisely what is wrong.
     *
     * @return list<string>
     */
    public function optionErrors(): array
    {
        $definitions = array();
        $errors      = array();

        foreach ($this->options as $option) {
            if ($option instanceof ProductOption) {
                $definitions[mb_strtolower($option->name)] = $option;
            }
        }

        foreach ($this->variants as $variant) {
            foreach ($variant->options->values as $name => $value) {
                $key = mb_strtolower($name);

                if (! isset($definitions[$key])) {
                    $errors[] = sprintf(
                        'Variant "%s" sets the option "%s", which the product does not define.',
                        $variant->identity(),
                        $name
                    );
                    continue;
                }

                if (! $definitions[$key]->offers($value)) {
                    $errors[] = sprintf(
                        'Variant "%s" sets "%s" to "%s", which the option does not offer.',
                        $variant->identity(),
                        $name,
                        $value
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array(
            'id'           => $this->id,
            'name'         => $this->name,
            'slug'         => $this->slug,
            'description'  => $this->description,
            'status'       => $this->status->value,
            'vendor'       => $this->vendor,
            'product_type' => $this->productType,
            'tags'         => $this->tags,
            'options'      => $this->options,
            'variants'     => $this->variants,
            'price_range'  => $this->priceRange(),
            'total_stock'  => $this->totalStock(),
            'external_ref' => $this->externalRef,
            'created_at'   => $this->createdAt?->format(\DATE_ATOM),
            'updated_at'   => $this->updatedAt?->format(\DATE_ATOM),
        );
    }
}
