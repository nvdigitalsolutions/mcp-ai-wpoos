<?php

/**
 * One sellable variant of a product: the thing with a SKU, a price and a stock level.
 *
 * Proposal 164 section 164.111. In WooCommerce this is a `product_variation`
 * post under its parent product; in Shopify it is a `ProductVariant`; in the
 * Laravel model this contract follows it is a `skus` row. All three are the same
 * object, and this is the one shape they all map onto.
 *
 * Decimal money is a {@see Money}, never a float. `stock` is nullable because
 * "untracked" and "zero on hand" are different answers and every source
 * distinguishes them: WooCommerce has `_manage_stock` plus `_stock`, Shopify has
 * `inventoryItem.tracked` plus the quantity. A host that cannot tell them apart
 * must return null rather than inventing zero.
 *
 * `externalRef` is the opaque identity the row had in the system it came from —
 * `woocommerce:variation:4821`, `shopify:gid://shopify/ProductVariant/123`.
 * Nothing in the core interprets it; it is what makes a migration idempotent
 * (find by this, update; do not insert twice) and it is deliberately a string so
 * no provider's identifier type leaks into the model.
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
use Nvoos\Core\Domain\ValueObject\OptionSelection;

final readonly class ProductVariant implements \JsonSerializable
{
    /**
     * @param int|null         $id             Storage identifier, null before insert.
     * @param string|null      $sku            Stock keeping unit. Null when the source has none.
     * @param Money            $price          Current selling price.
     * @param OptionSelection  $options        The option values this variant chose.
     * @param Money|null       $compareAtPrice Strike-through price, when the source has one.
     * @param Money|null       $cost           Unit cost, when the source has one.
     * @param string|null      $barcode        GTIN/UPC/EAN, when the source has one.
     * @param int|null         $stock          Quantity on hand, or null when not tracked.
     * @param bool             $trackInventory Whether stock is managed for this variant.
     * @param int              $position       Display order within the product, 0-based.
     * @param string|null      $imageUrl       Variant-specific image, when it differs.
     * @param string|null      $externalRef    Opaque identity in the source system.
     * @param int|null         $productId      Owning product, so a SKU lookup is addressable.
     */
    public function __construct(
        public ?int $id,
        public ?string $sku,
        public Money $price,
        public OptionSelection $options,
        public ?Money $compareAtPrice = null,
        public ?Money $cost = null,
        public ?string $barcode = null,
        public ?int $stock = null,
        public bool $trackInventory = true,
        public int $position = 0,
        public ?string $imageUrl = null,
        public ?string $externalRef = null,
        public ?int $productId = null,
    ) {
    }

    /**
     * The identity to match on during a migration: SKU when there is one, else
     * the option signature. Two variants of a product that return the same value
     * are the same variant.
     */
    public function identity(): string
    {
        return null !== $this->sku && '' !== trim($this->sku)
            ? 'sku:' . mb_strtolower(trim($this->sku))
            : 'options:' . $this->options->key();
    }

    /**
     * Whether this variant can be sold: tracked stock must be positive.
     *
     * An untracked variant is available — the store does not count it.
     */
    public function isInStock(): bool
    {
        return ! $this->trackInventory || ( null !== $this->stock && $this->stock > 0 );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array(
            'id'                => $this->id,
            'sku'               => $this->sku,
            'price'             => $this->price,
            'compare_at_price'  => $this->compareAtPrice,
            'cost'              => $this->cost,
            'barcode'           => $this->barcode,
            'stock'             => $this->stock,
            'track_inventory'   => $this->trackInventory,
            'in_stock'          => $this->isInStock(),
            'position'          => $this->position,
            'image_url'         => $this->imageUrl,
            'options'           => $this->options,
            'product_id'        => $this->productId,
            'external_ref'      => $this->externalRef,
        );
    }
}
