<?php

/**
 * One line of an order, as it was bought.
 *
 * Proposal 164 section 164.174. Every amount here is a **snapshot**: the price
 * the customer was charged, not the product's current price. That distinction is
 * the whole reason an order line exists as its own entity rather than a
 * reference to a variant — the catalogue moves on, and an invoice for last
 * month's order has to keep saying what last month's order cost.
 *
 * `lineTotal` is not derived from `quantity * unitPrice` on purpose. Real orders
 * carry line-level discounts and tax, so the total is a stored fact rather than
 * an arithmetic result; deriving it would quietly discard a discount the store
 * applied.
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

final readonly class OrderLine implements \JsonSerializable
{
    /**
     * @param int         $quantity  How many units were bought; at least one.
     * @param Money       $unitPrice The price charged per unit at checkout.
     * @param Money       $lineTotal The line's total after line-level adjustments.
     * @param string|null $sku       The SKU at checkout, when the source recorded one.
     *
     * @throws \InvalidArgumentException When the quantity is below one or the amounts disagree on currency.
     */
    public function __construct(
        public string $name,
        public int $quantity,
        public Money $unitPrice,
        public Money $lineTotal,
        public ?int $id = null,
        public ?string $sku = null,
        public ?int $productId = null,
        public ?int $variantId = null,
    ) {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('An order line has at least one unit.');
        }

        if ($unitPrice->currency !== $lineTotal->currency) {
            throw new \InvalidArgumentException(
                sprintf('A line cannot mix %s and %s.', $unitPrice->currency, $lineTotal->currency)
            );
        }
    }

    /**
     * The currency the line is denominated in.
     */
    public function currency(): string
    {
        return $this->unitPrice->currency;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array(
            'id'         => $this->id,
            'name'       => $this->name,
            'sku'        => $this->sku,
            'quantity'   => $this->quantity,
            'unit_price' => (float) $this->unitPrice->toDecimal(),
            'line_total' => (float) $this->lineTotal->toDecimal(),
            'currency'   => $this->currency(),
            'product_id' => $this->productId,
            'variant_id' => $this->variantId,
        );
    }
}
