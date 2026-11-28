<?php

/**
 * Order analytics for a date window, aggregated by the store.
 *
 * Port of `WP_MCP_AI_Tool_Get_Order_Analytics` (Proposal 164 section 164.178).
 *
 * ## The reference hydrated the window four times
 *
 * `execute()` fetched every order in the range with `'limit' => -1`, and then each
 * of four helpers called `wc_get_order()` again for every id. A month with ten
 * thousand orders meant forty thousand hydrations inside one tool call, and the
 * number grew with the store rather than with the window. The port asks the store
 * for a `GROUP BY` aggregate: the cost is bounded by days and statuses, not orders.
 *
 * ## "Total revenue" was not revenue
 *
 * The summary added `$order->get_total()` for every order regardless of status,
 * so cancelled, failed and refunded orders counted as takings. `OrderAnalytics`
 * reports `gross_total` and `revenue` separately, and only the second is called
 * revenue. `refunded` is excluded rather than subtracted, because the model keeps
 * the order total and not how much of it came back.
 *
 * ## A mixed-currency sum was labelled with the store's currency
 *
 * The reference summed raw floats and reported `get_woocommerce_currency()`. The
 * port aggregates one currency at a time, names it, and counts orders in another
 * currency as `excluded_orders` — a number the caller can see rather than an
 * error they cannot.
 *
 * ## One section is refused, one is not offered
 *
 * `include_customers` aggregates *customers*, which is a different aggregate and
 * belongs with the customer store that `woo_customers` needs, so it is refused by
 * name. `payment_methods` is not reported because the order model has no
 * payment-method field.
 *
 * `include_products` was refused when this tool was first ported, because the
 * store had no line aggregate. Section 164.180 added one
 * (`OrderStoreInterface::topProducts()`), so the section is computed now:
 * best-selling products by revenue over the same window and currency, with
 * `top_count` as its size. The products are rolled up by product rather than by
 * variant, count only paid orders, and keep a product whose catalogue entry has
 * since been deleted, which the reference dropped by joining the products table.
 *
 * ## Corrected inputs
 *
 * - Dates were `sanitize_text_field()`'d and concatenated into an `a...b` range
 *   with no validation; both bounds are parsed strictly, `date_to` includes the
 *   day it names, and the defaults are evaluated once rather than per call site.
 * - `group_by` accepted any string and fell through to `day` for an unknown one.
 *   It is an enum here, so an unknown value is a field error.
 * - `top_count` was `absint()` with no bound; it is bounded to 1..100 here.
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
use Nvoos\Core\Domain\Entity\OrderQuery;
use Nvoos\Core\Domain\Entity\ProductSales;
use Nvoos\Core\Domain\ValueObject\OrderGrouping;
use Nvoos\Core\Support\DateBound;
use Nvoos\Core\Tool\AbstractTool;

final class GetOrderAnalyticsTool extends AbstractTool
{
    /** How far back the window reaches when the caller does not say. */
    public const DEFAULT_DAYS = 30;

    /**
     * Sections the reference offers that this tool does not compute.
     *
     * `include_products` and `top_count` left this list in section 164.180,
     * which added the line aggregate they need.
     *
     * @var array<string, string>
     */
    public const REFUSED_ARGUMENTS = array(
        'include_customers' => 'Customer analytics aggregate customers, which is a different aggregate from this window; it arrives with the customer store.',
    );

    public function __construct(
        ErrorFactoryInterface $errors,
        protected readonly OrderStoreInterface $orders,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string
    {
        return 'get_order_analytics';
    }

    public function getName(): string
    {
        return 'Get Order Analytics';
    }

    public function getDescription(): string
    {
        return 'Summarises orders in a date window for one currency: order counts, gross total, revenue excluding cancelled, failed and refunded orders, average order value, status distribution, optional totals bucketed by day, week or month, and optionally the best-selling products by revenue. Customer analytics are not computed by this tool.';
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
                'date_from'       => array(
                    'type'        => 'string',
                    'description' => 'Start of the window (YYYY-MM-DD). Default: 30 days ago.',
                ),
                'date_to'         => array(
                    'type'        => 'string',
                    'description' => 'End of the window (YYYY-MM-DD), included. Default: today.',
                ),
                'group_by'        => array(
                    'type'        => 'string',
                    'description' => 'How the trend buckets are sized. Default day.',
                    'enum'        => OrderGrouping::values(),
                    'default'     => 'day',
                ),
                'currency'        => array(
                    'type'        => 'string',
                    'description' => 'The ISO 4217 currency to aggregate. Required, because sums in different currencies cannot be added.',
                ),
                'include_trends'  => array(
                    'type'        => 'boolean',
                    'description' => 'Include the per-period buckets.',
                ),
                'include_products' => array(
                    'type'        => 'boolean',
                    'description' => 'Include the best-selling products, by revenue, for the window.',
                ),
                'top_count'       => array(
                    'type'        => 'integer',
                    'description' => 'How many products to report when include_products is true. Default 10.',
                    'minimum'     => 1,
                    'maximum'     => 100,
                    'default'     => 10,
                ),
            ),
            'required'   => array('currency'),
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $fields = array();

        foreach (array_keys($arguments) as $key) {
            $known = array('date_from', 'date_to', 'group_by', 'currency', 'include_trends', 'include_products', 'top_count');

            if (! in_array($key, $known, true)) {
                $fields[$key] = array(self::REFUSED_ARGUMENTS[$key] ?? 'Unknown field.');
            }
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

        $grouping = OrderGrouping::tryFrom(strtolower($this->stringParam($arguments, 'group_by', 'day')));

        if (null === $grouping) {
            $fields['group_by'] = array('One of: ' . implode(', ', OrderGrouping::values()) . '.');
        }

        $topCount = $this->intParam($arguments, 'top_count', 10);

        if ($topCount < 1 || $topCount > 100) {
            $fields['top_count'] = array('Between 1 and 100.');
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The analytics window cannot be read.', $fields);
        }

        if (null === $from || null === $to || null === $grouping) {
            // Unreachable: each null records a field error above.
            return $this->errors->validationFailed('The analytics window cannot be read.', array());
        }

        $analytics = $this->orders->analytics(
            new OrderQuery(
                createdAfter: $from,
                createdBefore: $to,
            ),
            $currency,
            $grouping
        );

        $data = $analytics->jsonSerialize();
        $data['period'] = array(
            'from'     => $from->format('Y-m-d'),
            'to'       => $to->format('Y-m-d'),
            'group_by' => $grouping->value,
        );

        if (! $this->boolParam($arguments, 'include_trends', false)) {
            unset($data['periods']);
        }

        if ($this->boolParam($arguments, 'include_products', false)) {
            $data['products'] = array_map(
                static fn (ProductSales $product): array => $product->jsonSerialize(),
                $this->orders->topProducts(
                    new OrderQuery(createdAfter: $from, createdBefore: $to),
                    $currency,
                    $topCount
                )
            );
        }

        return $this->success(
            sprintf(
                '%d order(s) in %s: revenue %s, gross %s.',
                $analytics->orderCount,
                $currency,
                $analytics->revenue->toDecimal(),
                $analytics->grossTotal->toDecimal()
            ),
            $data
        );
    }
}
