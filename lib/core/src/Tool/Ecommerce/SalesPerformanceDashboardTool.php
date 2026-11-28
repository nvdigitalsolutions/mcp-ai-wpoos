<?php

/**
 * A sales dashboard for a date window, aggregated by the store.
 *
 * Port of `WP_MCP_AI_Tool_Sales_Performance_Dashboard` (Proposal 164 section
 * 164.179).
 *
 * ## The reference read WooCommerce's tables directly, and read nothing on HPOS
 *
 * All four sections were raw SQL against `wp_posts` and `wp_postmeta`:
 * `shop_order` posts in `wc-completed` and `wc-processing`, `_order_total` for
 * money and `_customer_user` for the customer. WooCommerce moved orders to its
 * own `wc_orders` tables (High-Performance Order Storage), where those rows do
 * not exist, so on a modern store the reference returned a dashboard of zeroes
 * and reported success. The port reads the order store, which is the same source
 * `get_order_analytics` uses, so the two tools cannot disagree and neither
 * depends on which storage WooCommerce happens to be using.
 *
 * ## `include_refunds` inflated revenue
 *
 * The flag added `wc-refunded` to the status list and revenue was
 * `SUM(_order_total)` over that list. A refunded order carries its original
 * total, so asking to include refunds **increased** revenue by the full value of
 * every refunded order rather than netting anything. The flag is refused when
 * true: the order model stores the total and not how much came back, so neither
 * adding nor subtracting is truthful. Revenue already excludes refunded,
 * cancelled and failed orders.
 *
 * ## There was no currency
 *
 * `SUM()` ran over a text column and the result was labelled with whatever the
 * store's currency happened to be. The port requires a currency and aggregates
 * one at a time, the same rule as section 164.178, because a sum across
 * currencies is not a number.
 *
 * ## `average_order` quietly depended on `orders`
 *
 * The reference computed it only when `orders` had also been requested, since it
 * divided the already-computed revenue by the already-computed order count.
 * Asking for revenue and average order value returned revenue alone. Here every
 * requested metric is computed independently, and the average divides revenue by
 * the orders that carried it, the same definition `get_order_analytics` reports,
 * so `revenue / average_order` lands back on `orders`.
 *
 * ## Products are computed, conversion is refused
 *
 * The `products` metric drives the top-products section, which aggregates order
 * *lines*. Section 164.180 added that aggregate, so the section is computed:
 * products roll up by product rather than by variant, count only paid orders, and
 * keep a product whose catalogue entry has since been deleted, which the
 * reference dropped by joining the products table. `top_products_limit` sizes it.
 *
 * `conversion` needs sessions and traffic, which no store in this runtime
 * records, so there is no honest number to report at all. `category_ids` is still
 * refused: the product model has no category field, and the reference's filter
 * could not have worked anyway.
 *
 * The `new_customers` figure the reference reported is not offered: it counts
 * customers whose first order fell in the window, which needs a
 * first-order-per-customer aggregate this store does not expose. `customers`
 * here is the number of distinct customers who placed a revenue-bearing order in
 * the window, which is a fact about the window rather than about history.
 *
 * ## Dates
 *
 * The reference took `start_date` and `end_date` through `sanitize_text_field()`
 * and concatenated them into a range, so a malformed date widened the window to
 * everything and a reversed range returned zeroes. The arguments are `date_from`
 * and `date_to` here, matching `get_order_analytics` so one toolkit has one date
 * idiom; both bounds parse strictly through `DateBound` (164.176), `date_to`
 * includes the day it names, and a reversed range is a field error.
 *
 * `days` is the inclusive length of the window. The reference rounded the
 * difference between the two timestamps, which under-counts an inclusive window
 * by one at every multiple of 24 hours.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Ecommerce;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\OrderStoreInterface;
use Nvoos\Core\Domain\Entity\OrderAnalytics;
use Nvoos\Core\Domain\Entity\OrderQuery;
use Nvoos\Core\Domain\Entity\ProductSales;
use Nvoos\Core\Domain\ValueObject\Money;
use Nvoos\Core\Domain\ValueObject\OrderGrouping;
use Nvoos\Core\Support\DateBound;
use Nvoos\Core\Tool\AbstractTool;

final class SalesPerformanceDashboardTool extends AbstractTool
{
    /** How far back the window reaches when the caller does not say. */
    public const DEFAULT_DAYS = 30;

    /** The metrics this tool computes. */
    public const METRICS = array('revenue', 'orders', 'customers', 'average_order', 'products');

    /** The metrics the reference offers that this tool does not compute. */
    public const REFUSED_METRICS = array(
        'conversion' => 'Conversion needs sessions and traffic, which no store in this runtime records, so there is no honest number to report.',
    );

    /** Arguments the reference offers that this tool does not use. */
    public const REFUSED_ARGUMENTS = array(
        'category_ids' => 'The product model has no category field, and the reference filter could not have worked anyway: it filtered on the term relationships table without joining it.',
    );

    public function __construct(
        ErrorFactoryInterface $errors,
        protected readonly OrderStoreInterface $orders,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string
    {
        return 'sales_performance_dashboard';
    }

    public function getName(): string
    {
        return 'Sales Performance Dashboard';
    }

    public function getDescription(): string
    {
        return 'Summarises one currency over a date window for a dashboard: revenue excluding cancelled, failed and refunded orders, the count of revenue-bearing orders, average order value, the number of distinct customers who bought, a daily revenue trend, and optionally the best-selling products by revenue. Conversion is not computed by this tool.';
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'currency'        => array(
                    'type'        => 'string',
                    'description' => 'The ISO 4217 currency to aggregate. Required, because sums in different currencies cannot be added.',
                ),
                'date_from'       => array(
                    'type'        => 'string',
                    'description' => 'Start of the window (YYYY-MM-DD). Default: 30 days ago.',
                ),
                'date_to'         => array(
                    'type'        => 'string',
                    'description' => 'End of the window (YYYY-MM-DD), included. Default: today.',
                ),
                'metrics'         => array(
                    'type'        => 'array',
                    'description' => 'The metrics to report. Default: revenue, orders and customers.',
                    'items'       => array(
                        'type' => 'string',
                        'enum' => self::METRICS,
                    ),
                ),
                'top_products_limit' => array(
                    'type'        => 'integer',
                    'description' => 'How many products to report when the products metric is requested. Default 10.',
                    'minimum'     => 1,
                    'maximum'     => 100,
                    'default'     => 10,
                ),
                'include_refunds' => array(
                    'type'        => 'boolean',
                    'description' => 'Accepted only as false. Revenue already excludes refunded orders and the model records no refunded amount, so including them would inflate revenue.',
                ),
            ),
            'required'   => array('currency'),
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $fields = array();
        $known = array('currency', 'date_from', 'date_to', 'metrics', 'include_refunds', 'category_ids', 'top_products_limit');

        foreach (array_keys($arguments) as $key) {
            if (! in_array($key, $known, true)) {
                $fields[ $key ] = array(self::REFUSED_ARGUMENTS[ $key ] ?? 'Unknown field.');
            }
        }

        // An empty category list asks for nothing, so it is accepted; a populated
        // one is the top-products filter, which this tool does not compute.
        if (isset($arguments['category_ids']) && array() !== $arguments['category_ids']) {
            $fields['category_ids'] = array(self::REFUSED_ARGUMENTS['category_ids']);
        }

        if ($this->boolParam($arguments, 'include_refunds', false)) {
            $fields['include_refunds'] = array('Revenue already excludes refunded orders and the model records no refunded amount, so refunds cannot be added or netted.');
        }

        $currency = strtoupper($this->stringParam($arguments, 'currency'));

        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            $fields['currency'] = array('An ISO 4217 code is required, because sums in different currencies cannot be added.');
        }

        $today = new \DateTimeImmutable('today');
        $defaultFrom = $today->modify('-' . self::DEFAULT_DAYS . ' days');

        $from = isset($arguments['date_from']) ? DateBound::parse($arguments['date_from']) : $defaultFrom;
        $to = isset($arguments['date_to']) ? DateBound::parse($arguments['date_to'], true) : $today->modify('+1 day -1 second');

        if (null === $from) {
            $fields['date_from'] = array(DateBound::reason());
        }

        if (null === $to) {
            $fields['date_to'] = array(DateBound::reason());
        }

        if (null !== $from && null !== $to && $from > $to) {
            $fields['date_from'] = array('Must not be after date_to.');
        }

        $requested = $this->requestedMetrics($arguments, $fields);

        $topLimit = $this->intParam($arguments, 'top_products_limit', 10);

        if ($topLimit < 1 || $topLimit > 100) {
            $fields['top_products_limit'] = array('Between 1 and 100.');
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The dashboard window cannot be read.', $fields);
        }

        if (null === $from || null === $to) {
            // Unreachable: each null records a field error above.
            return $this->errors->validationFailed('The dashboard window cannot be read.', array());
        }

        $analytics = $this->orders->analytics(
            new OrderQuery(
                createdAfter: $from,
                createdBefore: $to,
            ),
            $currency,
            OrderGrouping::Day
        );

        $products = null;

        if (in_array('products', $requested, true)) {
            $products = array_map(
                static fn (ProductSales $product): array => $product->jsonSerialize(),
                $this->orders->topProducts(
                    new OrderQuery(createdAfter: $from, createdBefore: $to),
                    $currency,
                    $topLimit
                )
            );
        }

        $metrics = array();

        foreach ($requested as $metric) {
            $metrics[ $metric ] = 'products' === $metric
                ? $products
                : $this->metric($metric, $analytics, $currency);
        }

        return $this->success(
            sprintf(
                'Sales dashboard for %s to %s: revenue %s across %d order(s).',
                $from->format('Y-m-d'),
                $to->format('Y-m-d'),
                $analytics->revenue->toDecimal(),
                $analytics->revenueOrderCount
            ),
            array(
                'period'  => array(
                    'from'     => $from->format('Y-m-d'),
                    'to'       => $to->format('Y-m-d'),
                    'days'     => (int) $from->diff($to)->days + 1,
                    'currency' => $currency,
                ),
                'metrics' => $metrics,
                'trends'  => array_map(
                    static fn (array $bucket): array => array(
                        'date'    => $bucket['period'],
                        'orders'  => $bucket['orders'],
                        'revenue' => (float) Money::fromMinorUnits(
                            $bucket['revenue_minor'],
                            $currency
                        )->toDecimal(),
                    ),
                    $analytics->periods
                ),
            )
        );
    }

    /**
     * Normalise the requested metric list, recording a field error per bad entry.
     *
     * @param array<string, mixed>        $arguments Raw arguments.
     * @param array<string, list<string>> $fields    Field errors, by reference.
     *
     * @return list<string>
     */
    private function requestedMetrics(array $arguments, array &$fields): array
    {
        $raw = $arguments['metrics'] ?? array('revenue', 'orders', 'customers');

        if (! is_array($raw)) {
            $fields['metrics'] = array('A list of: ' . implode(', ', self::METRICS) . '.');

            return array();
        }

        $requested = array();

        foreach ($raw as $entry) {
            $metric = is_string($entry) ? strtolower(trim($entry)) : '';

            if (isset(self::REFUSED_METRICS[ $metric ])) {
                $fields['metrics'] = array(self::REFUSED_METRICS[ $metric ]);
                continue;
            }

            if (! in_array($metric, self::METRICS, true)) {
                $fields['metrics'] = array('One of: ' . implode(', ', self::METRICS) . '.');
                continue;
            }

            $requested[ $metric ] = true;
        }

        return array_keys($requested);
    }

    /**
     * One metric value.
     *
     * @param string         $metric    One of {@see self::METRICS}.
     * @param OrderAnalytics $analytics The aggregate.
     * @param string         $currency  The reporting currency.
     */
    private function metric(string $metric, OrderAnalytics $analytics, string $currency): float|int
    {
        return match ($metric) {
            'revenue'       => (float) $analytics->revenue->toDecimal(),
            'orders'        => $analytics->revenueOrderCount,
            'customers'     => $analytics->customerCount,
            'average_order' => (float) Money::fromMinorUnits(
                $analytics->averageOrderValueMinor(),
                $currency
            )->toDecimal(),
            default         => throw new \InvalidArgumentException('Unknown metric: ' . $metric),
        };
    }
}
