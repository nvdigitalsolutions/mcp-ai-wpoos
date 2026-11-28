<?php

/**
 * An order window, aggregated.
 *
 * Proposal 164 section 164.178. This is what the analytics tool returns, and it
 * is deliberately a *summary*: the reference loaded every order in the range
 * (`'limit' => -1`) and hydrated each one, then did it again for trends, again
 * for top products and again for customers — four full passes over the window
 * inside a single tool call. The aggregate here is computed by the store with
 * `GROUP BY`, so the cost is bounded by the number of days and statuses in the
 * window rather than by the number of orders.
 *
 * ## Two totals, because one number cannot answer both questions
 *
 * `grossTotal` sums every order in the window. `revenue` sums only the statuses
 * that represent money actually taken — `processing` and `completed` — and
 * excludes `pending`, `on-hold`, `cancelled`, `failed` and `refunded`. The
 * reference reported the gross figure under the name "total_revenue", so a
 * store with a refund or a cancelled order overstated its takings by exactly
 * those amounts and had no way to see that it had. Both numbers are reported
 * here and neither is called revenue by accident.
 *
 * `refunded` is excluded rather than subtracted because the model stores the
 * order's total and not how much of it came back; subtracting the whole total
 * would be as wrong in the other direction.
 *
 * ## The currency is a filter, not a label
 *
 * Every amount is in one currency, named here. The store aggregates only orders
 * in that currency and counts the rest as `excludedOrders`, because summing euros
 * and dollars and labelling the result with the store's currency is arithmetically
 * meaningless — the reference did exactly that.
 *
 * ## Periods are bucketed from days
 *
 * The store groups by calendar day, which is expressible identically on MariaDB,
 * PostgreSQL and SQLite, and the buckets are folded into weeks or months here.
 * Weeks use the ISO week-year (`o-\\WW`) rather than the reference's `Y-W`, which
 * mislabels the week spanning New Year.
 *
 * A period counts only revenue-bearing orders, so that the figure under
 * `revenue` is revenue and `sum(periods[].orders)` equals
 * `revenueOrderCount`. Gross per period would be a second series; the window
 * totals carry it.
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
use Nvoos\Core\Domain\ValueObject\OrderGrouping;
use Nvoos\Core\Domain\ValueObject\OrderStatus;

final readonly class OrderAnalytics implements \JsonSerializable
{
    /**
     * The statuses whose totals count as revenue.
     *
     * `processing` and `completed` are the two statuses WooCommerce's Revenue
     * report counts: payment has been received. `pending` is an order whose
     * payment has not completed and `on-hold` one awaiting confirmation, so
     * neither is money taken, and section 164.180 corrects the wider set this
     * constant carried when section 164.178 introduced it. `cancelled`,
     * `failed` and `refunded` are excluded for the same reason.
     *
     * @var list<string>
     */
    public const REVENUE_STATUSES = array('processing', 'completed');

    /**
     * @param int                            $orderCount        Orders in the window and currency.
     * @param int                            $revenueOrderCount Orders in a revenue-bearing status.
     * @param int                            $excludedOrders    Orders in the window but another currency.
     * @param int                            $customerCount     Distinct customers with revenue-bearing orders.
     * @param Money                          $grossTotal        Every order's total.
     * @param Money                          $revenue           The revenue-bearing statuses' totals.
     * @param array<string, int>             $statusCounts      Orders per status.
     * @param list<array{period: string, orders: int, revenue_minor: int}> $periods Buckets, oldest first.
     */
    public function __construct(
        public string $currency,
        public int $orderCount,
        public int $revenueOrderCount,
        public int $excludedOrders,
        public int $customerCount,
        public Money $grossTotal,
        public Money $revenue,
        public array $statusCounts,
        public array $periods,
    ) {
    }

    /**
     * Whether a status's totals count as revenue.
     */
    public static function isRevenueBearing(OrderStatus $status): bool
    {
        return in_array($status->value, self::REVENUE_STATUSES, true);
    }

    /**
     * Fold daily totals into the requested buckets.
     *
     * @param array<string, array{orders: int, revenue_minor: int}> $daily Keyed by `Y-m-d`.
     *
     * @return list<array{period: string, orders: int, revenue_minor: int}>
     */
    public static function buckets(array $daily, OrderGrouping $grouping): array
    {
        $buckets = array();

        foreach ($daily as $day => $totals) {
            $key = self::bucketKey($day, $grouping);

            if (! isset($buckets[$key])) {
                $buckets[$key] = array('period' => $key, 'orders' => 0, 'revenue_minor' => 0);
            }

            $buckets[$key]['orders'] += $totals['orders'];
            $buckets[$key]['revenue_minor'] += $totals['revenue_minor'];
        }

        ksort($buckets);

        return array_values($buckets);
    }

    /**
     * The revenue divided by the orders that carried it, in minor units.
     */
    public function averageOrderValueMinor(): int
    {
        if (0 === $this->revenueOrderCount) {
            return 0;
        }

        return (int) round($this->revenue->minorUnits / $this->revenueOrderCount);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array(
            'currency'            => $this->currency,
            'order_count'         => $this->orderCount,
            'revenue_order_count' => $this->revenueOrderCount,
            'excluded_orders'     => $this->excludedOrders,
            'customer_count'      => $this->customerCount,
            'gross_total'         => (float) $this->grossTotal->toDecimal(),
            'revenue'             => (float) $this->revenue->toDecimal(),
            'average_order_value' => (float) Money::fromMinorUnits(
                $this->averageOrderValueMinor(),
                $this->currency
            )->toDecimal(),
            'status_counts'       => $this->statusCounts,
            'periods'             => array_map(
                fn (array $bucket): array => array(
                    'period'  => $bucket['period'],
                    'orders'  => $bucket['orders'],
                    'revenue' => (float) Money::fromMinorUnits(
                        $bucket['revenue_minor'],
                        $this->currency
                    )->toDecimal(),
                ),
                $this->periods
            ),
        );
    }

    /**
     * The bucket key for one day.
     */
    private static function bucketKey(string $day, OrderGrouping $grouping): string
    {
        if (OrderGrouping::Day === $grouping) {
            return $day;
        }

        $date = new \DateTimeImmutable($day);

        return OrderGrouping::Week === $grouping
            ? $date->format('o-\WW')
            : $date->format('Y-m');
    }
}
