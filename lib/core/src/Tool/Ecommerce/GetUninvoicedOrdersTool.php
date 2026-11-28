<?php

/**
 * List the orders that carry no invoice marker.
 *
 * Port of `WP_MCP_AI_Tool_Get_Uninvoiced_Orders` (Proposal 164 section 164.174).
 * This is the tool the order model was built for: the register deferred it with
 * "this runtime has no order or invoice model at all", and it now reads one.
 *
 * ## Four defects in the reference are corrected rather than reproduced
 *
 * 1. **The minimum amount was applied after the limit.** WordPress fetched the
 *    newest N uninvoiced orders and then dropped the cheap ones, so asking for
 *    ten orders of at least 100 could return none while a hundred qualifying
 *    orders sat behind the page. A filter applied to a page is a page-size
 *    change. The port filters in the query, so the page is taken after the
 *    filter.
 * 2. **`floatval()` made a bad amount disappear.** `"abc"` became `0.0`, which
 *    silently switched the filter off. A non-numeric or negative amount is a
 *    field error.
 * 3. **Dates were `sanitize_text_field()`'d and concatenated** into
 *    `after...before`, and an unparseable date was ignored rather than refused.
 *    Both bounds are parsed strictly here and must round-trip as `YYYY-MM-DD`.
 * 4. **`absint()` on the customer id** turned `-5` into `5`, so a caller asking
 *    about one customer was answered about another. A non-positive id is a field
 *    error.
 *
 * ## `date_to` includes the day it names
 *
 * The bound is the end of that day, not its midnight. "Up to 2024-01-31" that
 * excluded everything placed on the 31st would be a boundary the caller did not
 * ask for.
 *
 * ## The minimum amount needs a currency
 *
 * A bare number is not a comparable amount. WordPress compared an untyped float
 * against every order's total, which is only meaningful in a single-currency
 * store. The port takes `currency` alongside `min_amount` and compares only
 * orders in that currency; orders in another one are excluded by the store,
 * because an order that cannot be compared cannot be said to satisfy the bound.
 *
 * ## The page holds at most 100 orders
 *
 * WordPress returned up to 1,000 in one response. A thousand-row response is not
 * a page, and the query contract caps one at 100, so the port keeps that cap
 * rather than silently truncating what the caller asked for. The response
 * reports `total_count` and `total_pages`, so a billing pass iterates pages
 * instead of receiving a blob it cannot resume.
 *
 * ## Reported counts
 *
 * `total_count` is the number of matching orders across every page, and
 * `returned` is the size of this page. WordPress reported the page size as the
 * total, which is how a bounded billing pass misses orders.
 *
 * `edit_url` is present and always null: this runtime has no order admin screen,
 * and the key is kept so the shape matches the reference response.
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
use Nvoos\Core\Domain\Entity\Order;
use Nvoos\Core\Domain\Entity\OrderQuery;
use Nvoos\Core\Domain\ValueObject\Money;
use Nvoos\Core\Domain\ValueObject\OrderStatus;
use Nvoos\Core\Tool\AbstractTool;

final class GetUninvoicedOrdersTool extends AbstractTool
{
    /** The most orders one page will hold, matching the query contract. */
    public const MAX_LIMIT = 100;

    /** How many orders a page holds when the caller does not say. */
    public const DEFAULT_LIMIT = 100;

    public function __construct(
        ErrorFactoryInterface $errors,
        protected readonly OrderStoreInterface $orders,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string
    {
        return 'get_uninvoiced_orders';
    }

    public function getName(): string
    {
        return 'Get Uninvoiced Orders';
    }

    public function getDescription(): string
    {
        return 'Lists orders that carry no invoice marker, newest first, with optional date, customer, status and minimum-total filters. A minimum total requires the currency it is expressed in.';
    }

    public function getRequiredCapability(): string
    {
        return 'read';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'date_from'   => array(
                    'type'        => 'string',
                    'description' => 'Include orders created on or after this day (YYYY-MM-DD).',
                ),
                'date_to'     => array(
                    'type'        => 'string',
                    'description' => 'Include orders created on or before this day (YYYY-MM-DD).',
                ),
                'customer_id' => array(
                    'type'        => 'integer',
                    'description' => 'Only orders belonging to this customer.',
                    'minimum'     => 1,
                ),
                'status'      => array(
                    'type'        => 'string',
                    'description' => 'Restrict to one order status. WordPress queried every status; that remains the default.',
                    'enum'        => OrderStatus::values(),
                ),
                'min_amount'  => array(
                    'type'        => 'number',
                    'description' => 'Only orders whose total is at least this amount. Requires currency.',
                    'minimum'     => 0,
                ),
                'currency'    => array(
                    'type'        => 'string',
                    'description' => 'The ISO 4217 currency min_amount is expressed in.',
                ),
                'limit'       => array(
                    'type'        => 'integer',
                    'description' => 'How many orders this page holds. Default 100, at most 100.',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_LIMIT,
                    'default'     => self::DEFAULT_LIMIT,
                ),
                'page'        => array(
                    'type'        => 'integer',
                    'description' => 'Which page to read. Default 1.',
                    'minimum'     => 1,
                    'default'     => 1,
                ),
            ),
            'required'             => array(),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $fields = array();

        $createdAfter = isset($arguments['date_from'])
            ? $this->bound($arguments['date_from'], 'date_from', false, $fields)
            : null;

        $createdBefore = isset($arguments['date_to'])
            ? $this->bound($arguments['date_to'], 'date_to', true, $fields)
            : null;

        if (null !== $createdAfter && null !== $createdBefore && $createdAfter > $createdBefore) {
            $fields['date_from'] = array('Must not be after date_to.');
        }

        $customerId = null;

        if (isset($arguments['customer_id'])) {
            $candidate = $this->intParam($arguments, 'customer_id', 0);

            if ($candidate < 1) {
                $fields['customer_id'] = array('A customer id starts at 1.');
            } else {
                $customerId = $candidate;
            }
        }

        $status = null;

        if (isset($arguments['status'])) {
            $status = OrderStatus::tryFrom($this->stringParam($arguments, 'status'));

            if (null === $status) {
                $fields['status'] = array('One of: ' . implode(', ', OrderStatus::values()) . '.');
            }
        }

        $minTotal = $this->minimum($arguments, $fields);

        $limit = $this->intParam($arguments, 'limit', self::DEFAULT_LIMIT);

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            $fields['limit'] = array(sprintf('1 to %d.', self::MAX_LIMIT));
        }

        $page = $this->intParam($arguments, 'page', 1);

        if ($page < 1) {
            $fields['page'] = array('A page number starts at 1.');
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The order list cannot be read.', $fields);
        }

        $collection = $this->orders->query(
            new OrderQuery(
                uninvoicedOnly: true,
                minTotal: $minTotal,
                status: $status,
                customerId: $customerId,
                createdAfter: $createdAfter,
                createdBefore: $createdBefore,
                page: $page,
                perPage: $limit,
            )
        );

        $orders = array_map(
            fn (Order $order): array => $this->present($order),
            $collection->items
        );

        return $this->success(
            sprintf('Found %d orders without invoices.', $collection->total),
            array(
                'total_count' => $collection->total,
                'returned'    => count($orders),
                'page'        => $collection->page,
                'per_page'    => $collection->perPage,
                'total_pages' => $collection->totalPages,
                'orders'      => $orders,
            )
        );
    }

    /**
     * The response shape for one order.
     *
     * @return array<string, mixed>
     */
    private function present(Order $order): array
    {
        return array(
            'id'            => (int) $order->id,
            'order_number'  => $order->number,
            'status'        => $order->status->value,
            'total'         => (float) $order->total->toDecimal(),
            'currency'      => $order->currency(),
            'date_created'  => $order->createdAt->format(\DATE_ATOM),
            'customer_id'   => $order->customerId,
            'billing_name'  => $order->billingName,
            'billing_email' => $order->billingEmail,
            'item_count'    => $order->itemCount(),
            'edit_url'      => null,
        );
    }

    /**
     * A validated minimum total, or null after recording a field error.
     *
     * @param array<string, mixed> $arguments Raw tool arguments.
     * @param array<string, mixed> $fields    Field errors, by reference.
     */
    private function minimum(array $arguments, array &$fields): ?Money
    {
        $hasAmount = array_key_exists('min_amount', $arguments) && null !== $arguments['min_amount'];
        $hasCurrency = isset($arguments['currency']);

        if (! $hasAmount) {
            if ($hasCurrency) {
                $fields['currency'] = array('Only meaningful with min_amount.');
            }

            return null;
        }

        $amount = $arguments['min_amount'];

        if (! is_numeric($amount) || ! is_finite((float) $amount) || (float) $amount < 0) {
            $fields['min_amount'] = array('A non-negative number.');
            return null;
        }

        $currency = strtoupper($this->stringParam($arguments, 'currency'));

        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            $fields['currency'] = array(
                'min_amount needs a currency, because an amount without one cannot be compared. Pass an ISO 4217 code.',
            );

            return null;
        }

        try {
            return Money::fromDecimal((float) $amount, $currency);
        } catch (\InvalidArgumentException $e) {
            $fields['min_amount'] = array(
                sprintf('At most %d decimal places in %s.', Money::zero($currency)->exponent(), $currency),
            );

            return null;
        }
    }

    /**
     * A strict `YYYY-MM-DD` bound, optionally extended to the end of that day.
     *
     * @param mixed                $raw       Raw argument.
     * @param array<string, mixed> $fields    Field errors, by reference.
     */
    private function bound(mixed $raw, string $field, bool $endOfDay, array &$fields): ?\DateTimeImmutable
    {
        $text = is_string($raw) ? trim($raw) : '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        // A round-trip check, because createFromFormat rolls 2024-13-01 over to
        // 2025-01-01 instead of failing.
        if (false === $date || $date->format('Y-m-d') !== $text) {
            $fields[$field] = array('A date in YYYY-MM-DD form.');

            return null;
        }

        return $endOfDay ? $date->setTime(23, 59, 59) : $date;
    }
}
