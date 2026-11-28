<?php

/**
 * What one product sold, over a window.
 *
 * Proposal 164 section 164.180. This is the aggregate behind the products
 * section of `get_order_analytics` and the `products` metric of
 * `sales_performance_dashboard`.
 *
 * ## Rolled up by product, not by variant
 *
 * The lines carry `variantId` as well as `productId`, and the grouping key is
 * the product: "which product sold most" is a question about a catalogue entry,
 * and answering it per variant would rank the large size of a shirt separately
 * from the small one. A caller that wants the variant split has the line ids on
 * the orders themselves.
 *
 * ## The name is the one the order recorded, not the catalogue name
 *
 * A line snapshots the product's name at checkout (section 164.174), so a
 * product renamed last week still reports the name it sold under. The grouping
 * picks one name from the window deterministically rather than reaching into
 * the product store: resolving the current name would be a second store and a
 * second failure mode for a display string, and would make the same report
 * answer differently depending on when it ran.
 *
 * ## Only products are counted
 *
 * A line with no `productId` is a custom line, not a catalogue entry, and is
 * not aggregated. That is the same rule the reference applied by joining on
 * `_product_id`, with one difference: the reference also dropped lines whose
 * product had since been deleted, because it `INNER JOIN`ed the products table.
 * This aggregate keeps them, because the sale happened.
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

final readonly class ProductSales implements \JsonSerializable
{
    /**
     * @param int         $productId The catalogue id the sold lines carried.
     * @param string      $name      One of the names the product sold under in the window.
     * @param string|null $sku       One of the SKUs the product sold under, when recorded.
     * @param int         $quantity  Units sold, summed across lines.
     * @param Money       $revenue   Line totals summed, in the reported currency.
     */
    public function __construct(
        public int $productId,
        public string $name,
        public ?string $sku,
        public int $quantity,
        public Money $revenue,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array(
            'product_id' => $this->productId,
            'name'       => $this->name,
            'sku'        => $this->sku,
            'quantity'   => $this->quantity,
            'revenue'    => (float) $this->revenue->toDecimal(),
            'currency'   => $this->revenue->currency,
        );
    }
}
