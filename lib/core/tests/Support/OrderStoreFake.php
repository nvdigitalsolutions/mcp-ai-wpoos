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

        foreach ($scoped as $order) {
            $status = $order->status->value;

            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            $grossMinor           += $order->total->minorUnits;

            if (! OrderAnalytics::isRevenueBearing($order->status)) {
                continue;
            }

            ++$revenueOrderCount;
            $revenueMinor += $order->total->minorUnits;

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
            Money::fromMinorUnits($grossMinor, $currency),
            Money::fromMinorUnits($revenueMinor, $currency),
            $statusCounts,
            OrderAnalytics::buckets($daily, $grouping),
        );
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
