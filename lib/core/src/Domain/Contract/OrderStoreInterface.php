<?php

/**
 * Read and write access to orders.
 *
 * Proposal 164 section 164.174. The contract is deliberately three methods: a
 * lookup, a filtered page, and a save. Everything the deferred order tools need
 * is a filtered page; everything they write is a saved order. Adding a method
 * per operation would move each tool's policy into this interface, where it
 * would be implemented once per host instead of written once per tool.
 *
 * ## The filter contract
 *
 * - `uninvoicedOnly` selects orders whose invoice marker is null. This is the
 *   `_invoiced NOT EXISTS` query the reference tool performs, expressed as a
 *   typed field instead of a meta key.
 * - `minTotal` compares only orders in **its own** currency and excludes orders
 *   in another one. An order that cannot be compared to the minimum cannot be
 *   said to satisfy it, and silently comparing across currencies would rank
 *   euros against dollars.
 * - `createdAfter` and `createdBefore` are inclusive bounds on `createdAt`.
 * - The page is taken **after** every filter is applied. A filter applied to a
 *   page is not a filter, it is a page-size change: ask for ten matching orders
 *   and ten matching orders must come back if they exist.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

use Nvoos\Core\Domain\Entity\Order;
use Nvoos\Core\Domain\Entity\OrderAnalytics;
use Nvoos\Core\Domain\Entity\OrderCollection;
use Nvoos\Core\Domain\Entity\OrderQuery;
use Nvoos\Core\Domain\ValueObject\OrderGrouping;

interface OrderStoreInterface
{
    /**
     * One order by its storage id, or null when it does not exist.
     */
    public function find(int $id): ?Order;

    /**
     * One page of orders matching the query, newest first unless asked otherwise.
     *
     * The returned collection's `total` is the number of matching orders across
     * every page, not the size of the page.
     */
    public function query(OrderQuery $query): OrderCollection;

    /**
     * Persist an order and return it as stored.
     *
     * A readonly entity means a change arrives here as a new value; the store
     * decides whether that is an insert (null id) or an update.
     */
    public function save(Order $order): Order;

    /**
     * Aggregate the orders a query selects, in one currency.
     *
     * The currency is an argument rather than a field of the query because this
     * is the only operation that cannot span currencies: a listing can return a
     * mixed page, a sum cannot. An adapter must aggregate only orders in that
     * currency and report the rest as `excludedOrders`, never add across them.
     *
     * The aggregate is computed over the whole selection — not over a page — and
     * `$grouping` sizes the trend buckets. Adapters should compute it with
     * `GROUP BY` so the cost is bounded by days and statuses rather than by the
     * number of orders.
     */
    public function analytics(OrderQuery $query, string $currency, OrderGrouping $grouping): OrderAnalytics;
}
