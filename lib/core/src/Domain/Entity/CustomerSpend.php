<?php

/**
 * What one customer spent, over a window.
 *
 * Proposal 164 section 164.181. This is the aggregate behind the customers
 * section of `get_order_analytics` and the `new_customers` metric of
 * `sales_performance_dashboard`, and the base `segment_customers` will read.
 *
 * ## Identity is the account, not the email string
 *
 * The reference keyed customers by `customer_id` when there was one and by the
 * billing email otherwise, so a guest who checked out twice under two addresses
 * counted as two customers, and a registered customer who changed their billing
 * email counted as two as well while their two orders split across both. This
 * aggregate counts **registered customers only**, keyed by the account id.
 * Guests are real buyers and are counted in orders and revenue; they are not
 * customers, which is the same rule `customerCount` follows and the same one
 * WooCommerce's own Customers report applies.
 *
 * ## Window facts, named as window facts
 *
 * `firstOrderAt` and `lastOrderAt` are the earliest and latest **paid orders in
 * the window**, not the customer's first and last order ever. The reference
 * reported `first_order_date` as whichever order the loop happened to encounter
 * first — order ids are not guaranteed to be in date order, so that value was
 * arbitrary. A global first-order date is a different question, and
 * `OrderStoreInterface::newCustomerCount()` answers it.
 *
 * `spend` sums paid orders only, matching the revenue definition of section
 * 164.180. The reference summed every order regardless of status.
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

final readonly class CustomerSpend implements \JsonSerializable
{
    /**
     * @param int               $customerId   The account the paid orders belonged to.
     * @param string|null       $email        One of the billing emails used in the window.
     * @param string|null       $name         One of the billing names used in the window.
     * @param int               $orderCount   Paid orders in the window.
     * @param Money             $spend        Paid totals summed, in the reported currency.
     * @param \DateTimeImmutable|null $firstOrderAt Earliest paid order in the window.
     * @param \DateTimeImmutable|null $lastOrderAt  Latest paid order in the window.
     */
    public function __construct(
        public int $customerId,
        public ?string $email,
        public ?string $name,
        public int $orderCount,
        public Money $spend,
        public ?\DateTimeImmutable $firstOrderAt = null,
        public ?\DateTimeImmutable $lastOrderAt = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array(
            'customer_id'      => $this->customerId,
            'email'            => $this->email,
            'name'             => $this->name,
            'order_count'      => $this->orderCount,
            'spend'            => (float) $this->spend->toDecimal(),
            'currency'         => $this->spend->currency,
            'first_order_date' => $this->firstOrderAt?->format('Y-m-d'),
            'last_order_date'  => $this->lastOrderAt?->format('Y-m-d'),
        );
    }
}
