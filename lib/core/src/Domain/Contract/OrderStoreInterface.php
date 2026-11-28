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

use Nvoos\Core\Domain\Entity\CustomerSpend;
use Nvoos\Core\Domain\Entity\Order;
use Nvoos\Core\Domain\Entity\OrderAnalytics;
use Nvoos\Core\Domain\Entity\OrderCollection;
use Nvoos\Core\Domain\Entity\OrderQuery;
use Nvoos\Core\Domain\Entity\ProductSales;
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

    /**
     * The products a query's orders sold most of, in one currency.
     *
     * Proposal 164 section 164.180. This is the aggregate the products section
     * of `get_order_analytics` and the `products` metric of
     * `sales_performance_dashboard` both read, so that one definition of "top
     * product" serves both tools.
     *
     * - It aggregates **order lines**, not orders: quantity and revenue are
     *   summed from the lines, which is where the product reference lives.
     * - It counts only **revenue-bearing** statuses, the same set `analytics()`
     *   uses for revenue, so a cancelled order cannot contribute a best-seller.
     *   An explicit `status` filter on the query intersects with that set
     *   rather than replacing it, so asking for pending orders answers nothing.
     * - The currency filters the selection. An `Order` refuses a line in a
     *   currency other than its own, so the order bound is the live rule; the
     *   line bound is checked too because the table itself permits a mismatch
     *   and adding those amounts would be the mixed-currency sum this family
     *   exists to remove.
     * - A line with no product id is a custom line and is not a product, so it
     *   is not aggregated.
     *
     * @param OrderQuery $query    The window and filters, as for a listing.
     * @param string     $currency The ISO 4217 currency to aggregate.
     * @param int        $limit    How many products to return, 1 to 100.
     *
     * @return list<ProductSales> By revenue descending, then quantity, then name.
     */
    public function topProducts(OrderQuery $query, string $currency, int $limit): array;

    /**
     * The customers a query's orders belong to, biggest spenders first.
     *
     * Proposal 164 section 164.181. Counts **registered customers only**, keyed by
     * the account id: a guest is a buyer but not a customer, and an email string
     * is not an identity — a guest who uses two addresses is one person and a
     * registered customer who changes address is one account.
     *
     * Like `analytics()`, it aggregates one currency and counts paid statuses
     * only. `CustomerSpend::$firstOrderAt` and `$lastOrderAt` are the earliest and
     * latest paid orders **in the window**, not the customer's history.
     *
     * @param OrderQuery $query    The window and filters, as for a listing.
     * @param string     $currency The ISO 4217 currency to aggregate.
     * @param int        $limit    How many customers to return, 1 to 10000.
     *
     *   The ceiling is higher than `topProducts()`'s because the two are used
     *   at different scales: a product list is for display, while a segmentation
     *   pass reads the customer base. The bound is a guard against an unbounded
     *   query, not a page size, and the calling tool decides what it will spend.
     *
     * @return list<CustomerSpend> By spend descending, then customer id.
     */
    public function topCustomers(OrderQuery $query, string $currency, int $limit): array;

    /**
     * How many registered customers placed their first paid order in the window.
     *
     * The first paid order is the customer's first **ever**, in the reported
     * currency, so it necessarily predates or falls inside the window rather than
     * being bounded by it. Only the window bounds are read from `$query`: a
     * customer's first order is not a property of the filtered selection, and a
     * `customerId` filter would make every answer zero or one.
     *
     * The currency is part of the identity of that first order because "first"
     * across two currencies is not a date but a comparison of amounts. A customer
     * whose first purchase was in EUR is not a new USD customer.
     *
     * @param OrderQuery $query    Carries the window bounds.
     * @param string     $currency The ISO 4217 currency to aggregate.
     */
    public function newCustomerCount(OrderQuery $query, string $currency): int;
}
