<?php

/**
 * An order store held in memory, implementing the filter contract.
 *
 * The contract's filter semantics are the interesting part — a filter that runs
 * after the page is a page-size change, and a minimum total in another currency
 * is not comparable — so this double implements them rather than only recording
 * the query. A double that returned everything would let a tool pass while
 * getting the semantics wrong.
 *
 * Every query is kept in `$queries` so a test can assert what was asked for, and
 * every save in `$saved` so a test can assert what was written.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Support;

use Nvoos\Core\Domain\Contract\OrderStoreInterface;
use Nvoos\Core\Domain\Entity\Order;
use Nvoos\Core\Domain\Entity\OrderAnalytics;
use Nvoos\Core\Domain\Entity\OrderCollection;
use Nvoos\Core\Domain\Entity\OrderQuery;
use Nvoos\Core\Domain\Entity\ProductSales;
use Nvoos\Core\Domain\Error\ValidationException;
use Nvoos\Core\Domain\Service\Order\OrderStatusTransition;
use Nvoos\Core\Domain\ValueObject\Money;
use Nvoos\Core\Domain\ValueObject\OrderGrouping;

final class OrderStoreFake implements OrderStoreInterface
{
    /** @var array<int, Order> Orders by id. */
    public array $orders = array();

    /** @var array<int, OrderQuery> Every query the tool issued. */
    public array $queries = array();

    /** @var array<int, Order> Every order passed to save(). */
    public array $saved = array();

    public function __construct(Order ...$orders)
    {
        foreach ($orders as $order) {
            if (null !== $order->id) {
                $this->orders[$order->id] = $order;
            }
        }
    }

    public function find(int $id): ?Order
    {
        return $this->orders[$id] ?? null;
    }

    public function query(OrderQuery $query): OrderCollection
    {
        $this->queries[] = $query;

        $matching = array_values(array_filter(
            $this->orders,
            fn (Order $order): bool => $this->matches($order, $query)
        ));

        usort(
            $matching,
            static fn (Order $a, Order $b): int => $b->createdAt <=> $a->createdAt
        );

        $total = count($matching);
        $page = array_slice($matching, $query->getOffset(), $query->perPage);
        $totalPages = (int) ceil($total / $query->perPage);

        return new OrderCollection($page, $total, $query->page, $query->perPage, $totalPages);
    }

    /**
     * Whether an order satisfies every filter in the query.
     *
     * `query()` and `analytics()` share this so the two paths cannot disagree
     * about what the selection is; in the adapter the same job is done by
     * `filtered()`.
     */
    private function matches(Order $order, OrderQuery $query): bool
    {
        if ($query->uninvoicedOnly && $order->isInvoiced()) {
            return false;
        }

        if ('' !== $query->search) {
            $needle = strtolower($query->search);
            $haystack = strtolower($order->number . ' ' . (string) $order->billingEmail);

            if (false === strpos($haystack, $needle)) {
                return false;
            }
        }

        if (null !== $query->status && $order->status !== $query->status) {
            return false;
        }

        if (null !== $query->customerId && $order->customerId !== $query->customerId) {
            return false;
        }

        if (null !== $query->createdAfter && $order->createdAt < $query->createdAfter) {
            return false;
        }

        if (null !== $query->createdBefore && $order->createdAt > $query->createdBefore) {
            return false;
        }

        if (null !== $query->minTotal) {
            // An order in another currency cannot be compared to the bound, so
            // it cannot be said to satisfy it.
            if ($order->currency() !== $query->minTotal->currency) {
                return false;
            }

            if ($order->total->minorUnits < $query->minTotal->minorUnits) {
                return false;
            }
        }

        return true;
    }

    /**
     * Aggregate the selection in one currency, matching the adapter.
     *
     * The periods are built from revenue-bearing orders only, because the bucket
     * field is called `revenue_minor` and a bucket that summed cancellations
     * would be gross wearing revenue's name — the defect this slice corrects.
     */
    public function analytics(OrderQuery $query, string $currency, OrderGrouping $grouping): OrderAnalytics
    {
        $this->queries[] = $query;

        $filtered = array_values(array_filter(
            $this->orders,
            fn (Order $order): bool => $this->matches($order, $query)
        ));

        $scoped = array_values(array_filter(
            $filtered,
            static fn (Order $order): bool => $order->currency() === $currency
        ));

        $excluded = count($filtered) - count($scoped);

        $statusCounts      = array();
        $revenueOrderCount = 0;
        $grossMinor        = 0;
        $revenueMinor      = 0;
        $daily             = array();
        $customers         = array();

        foreach ($scoped as $order) {
            $status = $order->status->value;

            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            $grossMinor           += $order->total->minorUnits;

            if (! OrderAnalytics::isRevenueBearing($order->status)) {
                continue;
            }

            ++$revenueOrderCount;
            $revenueMinor += $order->total->minorUnits;

            // A guest order carries no customer id and is not a customer.
            if (null !== $order->customerId && $order->customerId > 0) {
                $customers[$order->customerId] = true;
            }

            $day = $order->createdAt->format('Y-m-d');

            if (! isset($daily[$day])) {
                $daily[$day] = array('orders' => 0, 'revenue_minor' => 0);
            }

            ++$daily[$day]['orders'];
            $daily[$day]['revenue_minor'] += $order->total->minorUnits;
        }

        ksort($daily);

        return new OrderAnalytics(
            $currency,
            count($scoped),
            $revenueOrderCount,
            $excluded,
            count($customers),
            Money::fromMinorUnits($grossMinor, $currency),
            Money::fromMinorUnits($revenueMinor, $currency),
            $statusCounts,
            OrderAnalytics::buckets($daily, $grouping),
        );
    }
    /**
     * The products a selection sold most of, in one currency.
     *
     * Mirrors the adapter: revenue-bearing orders only, the order's currency and
     * the line's currency both checked, lines without a product id skipped, and
     * the same ordering. The name reported is the lexicographic minimum, which is
     * what `MIN(name)` gives the adapter; `strcmp` is used rather than `<`
     * because PHP compares two numeric-looking strings as numbers, and products
     * named "10" and "9" would then disagree with the database.
     *
     * @return list<ProductSales>
     */
    public function topProducts(OrderQuery $query, string $currency, int $limit): array
    {
        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new ValidationException('A product aggregate needs an ISO 4217 currency.');
        }

        if ($limit < 1 || $limit > 100) {
            throw new ValidationException('A product aggregate returns between 1 and 100 products.');
        }

        $this->queries[] = $query;

        $groups = array();

        foreach ($this->orders as $order) {
            if (! $this->matches($order, $query)) {
                continue;
            }

            if ($order->currency() !== $currency) {
                continue;
            }

            if (! OrderAnalytics::isRevenueBearing($order->status)) {
                continue;
            }

            foreach ($order->lines as $line) {
                if (null === $line->productId || $line->lineTotal->currency !== $currency) {
                    continue;
                }

                $key = $line->productId;

                if (! isset($groups[ $key ])) {
                    $groups[ $key ] = array(
                        'product_id' => $key,
                        'name'       => $line->name,
                        'sku'        => $line->sku,
                        'quantity'   => 0,
                        'minor'      => 0,
                    );
                }

                if (strcmp($line->name, $groups[ $key ]['name']) < 0) {
                    $groups[ $key ]['name'] = $line->name;
                }

                if (null !== $line->sku) {
                    if (null === $groups[ $key ]['sku'] || strcmp($line->sku, $groups[ $key ]['sku']) < 0) {
                        $groups[ $key ]['sku'] = $line->sku;
                    }
                }

                $groups[ $key ]['quantity'] += $line->quantity;
                $groups[ $key ]['minor']    += $line->lineTotal->minorUnits;
            }
        }

        $products = array();

        foreach ($groups as $group) {
            $products[] = new ProductSales(
                $group['product_id'],
                $group['name'],
                $group['sku'],
                $group['quantity'],
                Money::fromMinorUnits($group['minor'], $currency),
            );
        }

        usort(
            $products,
            static function (ProductSales $a, ProductSales $b): int {
                return ($b->revenue->minorUnits <=> $a->revenue->minorUnits)
                    ?: ($b->quantity <=> $a->quantity)
                    ?: ($a->name <=> $b->name);
            }
        );

        return array_slice($products, 0, $limit);
    }
    public function save(Order $order): Order
    {
        // The same boundary guard the Eloquent adapter applies: a caller that
        // builds an order directly cannot skip the transition rule here either.
        if (null !== $order->id && isset($this->orders[$order->id])) {
            OrderStatusTransition::assert($this->orders[$order->id]->status, $order->status);
        }

        $stored = $order;

        if (null === $stored->id) {
            $next = array() === $this->orders ? 1 : max(array_keys($this->orders)) + 1;

            $stored = new Order(
                id: $next,
                number: $stored->number,
                status: $stored->status,
                createdAt: $stored->createdAt,
                total: $stored->total,
                lines: $stored->lines,
                subtotal: $stored->subtotal,
                discountTotal: $stored->discountTotal,
                shippingTotal: $stored->shippingTotal,
                taxTotal: $stored->taxTotal,
                customerId: $stored->customerId,
                billingName: $stored->billingName,
                billingEmail: $stored->billingEmail,
                invoicedAt: $stored->invoicedAt,
                externalRef: $stored->externalRef,
                updatedAt: $stored->updatedAt,
            );
        }

        $this->orders[ (int) $stored->id ] = $stored;
        $this->saved[] = $stored;

        return $stored;
    }
}
