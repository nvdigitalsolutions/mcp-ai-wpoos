<?php

/**
 * The uninvoiced-order listing, and the four reference defects it corrects.
 *
 * Proposal 164 section 164.174. Pinned here:
 *
 * - a minimum amount filters the set **before** the page, so a page of one still
 *   returns the qualifying order rather than the newest cheap one;
 * - a minimum amount needs a currency, and orders in another currency are
 *   excluded rather than compared across it;
 * - a non-numeric amount and an out-of-range date are refused, where WordPress
 *   silently dropped the filter and ignored the date;
 * - `date_to` includes the day it names;
 * - `total_count` is the match count and `returned` is the page size, where
 *   WordPress called the page size the total.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\Order;
use Nvoos\Core\Domain\Entity\OrderLine;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\ValueObject\Money;
use Nvoos\Core\Domain\ValueObject\OrderStatus;
use Nvoos\Core\Tests\Support\OrderStoreFake;
use Nvoos\Core\Tool\Ecommerce\GetUninvoicedOrdersTool;
use PHPUnit\Framework\TestCase;

final class GetUninvoicedOrdersTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    private OrderStoreFake $store;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'validation_failed', 'message' => $message, 'data' => $fields),
            )
        );
    }

    // ── metadata and guards ──────────────────────────────────

    public function test_the_tool_metadata(): void
    {
        $tool = $this->tool($this->store());

        $this->assertSame('get_uninvoiced_orders', $tool->getSlug());
        $this->assertSame('read', $tool->getRequiredCapability());

        $schema = $tool->getParametersSchema();

        $this->assertSame(array(), $schema['required']);
        $this->assertSame(100, $schema['properties']['limit']['maximum']);
        $this->assertCount(7, $schema['properties']['status']['enum']);
        $this->assertContains('on-hold', $schema['properties']['status']['enum']);
    }

    public function test_it_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->tool($this->store())->execute(array(), array());
    }

    // ── the listing ──────────────────────────────────────────

    public function test_it_lists_only_uninvoiced_orders(): void
    {
        $result = $this->execute(array(), $this->store(
            $this->order(1, '2024-01-03 09:00:00'),
            $this->order(2, '2024-01-02 09:00:00', invoicedAt: '2024-01-04 09:00:00')
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['total_count']);
        $this->assertSame(1, $result['data']['orders'][0]['id']);
        $this->assertTrue($this->store->queries[0]->uninvoicedOnly);
    }

    public function test_it_reports_the_match_count_and_the_page_size_separately(): void
    {
        $result = $this->execute(array('limit' => 1), $this->store(
            $this->order(1, '2024-01-03 09:00:00'),
            $this->order(2, '2024-01-02 09:00:00')
        ));

        $this->assertSame(2, $result['data']['total_count']);
        $this->assertSame(1, $result['data']['returned']);
        $this->assertSame(2, $result['data']['total_pages']);
        $this->assertCount(1, $result['data']['orders']);
    }

    public function test_an_order_is_presented_with_its_billing_details(): void
    {
        $result = $this->execute(array(), $this->store(
            $this->order(4, '2024-01-15 10:00:00', '123.45', customerId: 9)
        ));

        $order = $result['data']['orders'][0];

        $this->assertSame(4, $order['id']);
        $this->assertSame('#4', $order['order_number']);
        $this->assertSame('pending', $order['status']);
        $this->assertSame(123.45, $order['total']);
        $this->assertSame('USD', $order['currency']);
        $this->assertSame(9, $order['customer_id']);
        $this->assertSame('Ada Lovelace', $order['billing_name']);
        $this->assertSame('ada@example.test', $order['billing_email']);
        $this->assertSame(1, $order['item_count']);
        $this->assertArrayHasKey('edit_url', $order);
        $this->assertNull($order['edit_url']);
        $this->assertMatchesRegularExpression(
            '/^2024-01-15T10:00:00[+-]\d{2}:\d{2}$/',
            $order['date_created']
        );
    }

    public function test_an_order_with_no_lines_has_no_items(): void
    {
        $result = $this->execute(array(), $this->store(
            $this->order(1, '2024-01-03 09:00:00', lines: 0)
        ));

        $this->assertSame(0, $result['data']['orders'][0]['item_count']);
    }

    public function test_an_empty_store_reports_zero(): void
    {
        $result = $this->execute(array(), $this->store());

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['total_count']);
        $this->assertSame(0, $result['data']['returned']);
        $this->assertSame(array(), $result['data']['orders']);
        $this->assertSame('Found 0 orders without invoices.', $result['message']);
    }

    // ── minimum amount ───────────────────────────────────────

    public function test_a_minimum_amount_filters_the_set_not_the_page(): void
    {
        // Newest is the cheapest. A limit of one with the filter applied after
        // the page would return nothing at all.
        $result = $this->execute(
            array('min_amount' => 100, 'currency' => 'USD', 'limit' => 1),
            $this->store(
                $this->order(1, '2024-01-03 09:00:00', '10.00'),
                $this->order(2, '2024-01-02 09:00:00', '500.00'),
                $this->order(3, '2024-01-01 09:00:00', '20.00')
            )
        );

        $this->assertSame(1, $result['data']['total_count']);
        $this->assertSame(1, $result['data']['returned']);
        $this->assertSame(2, $result['data']['orders'][0]['id']);
    }

    public function test_a_minimum_amount_requires_a_currency(): void
    {
        $result = $this->execute(array('min_amount' => 100), $this->store($this->order(1)));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('currency', $result['error']['data']);
        $this->assertSame(array(), $this->store->queries);
    }

    public function test_a_non_numeric_minimum_amount_is_refused(): void
    {
        $result = $this->execute(
            array('min_amount' => 'abc', 'currency' => 'USD'),
            $this->store($this->order(1))
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('min_amount', $result['error']['data']);
    }

    public function test_a_minimum_amount_with_too_many_decimals_is_refused(): void
    {
        $result = $this->execute(
            array('min_amount' => 1.234, 'currency' => 'USD'),
            $this->store($this->order(1))
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('min_amount', $result['error']['data']);
    }

    public function test_a_currency_without_a_minimum_amount_is_refused(): void
    {
        $result = $this->execute(array('currency' => 'USD'), $this->store($this->order(1)));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('currency', $result['error']['data']);
    }

    public function test_a_minimum_amount_excludes_orders_in_another_currency(): void
    {
        $result = $this->execute(
            array('min_amount' => 100, 'currency' => 'USD'),
            $this->store(
                $this->order(1, '2024-01-03 09:00:00', '200.00', 'USD'),
                $this->order(2, '2024-01-02 09:00:00', '200.00', 'EUR')
            )
        );

        $this->assertSame(1, $result['data']['total_count']);
        $this->assertSame(1, $result['data']['orders'][0]['id']);
    }

    // ── customer ─────────────────────────────────────────────

    public function test_a_customer_filter_is_applied(): void
    {
        $result = $this->execute(
            array('customer_id' => 9),
            $this->store(
                $this->order(1, '2024-01-03 09:00:00', customerId: 7),
                $this->order(2, '2024-01-02 09:00:00', customerId: 9)
            )
        );

        $this->assertSame(1, $result['data']['total_count']);
        $this->assertSame(2, $result['data']['orders'][0]['id']);
    }

    public function test_a_non_positive_customer_id_is_refused(): void
    {
        $result = $this->execute(array('customer_id' => -5), $this->store($this->order(1)));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('customer_id', $result['error']['data']);
    }

    // ── dates ────────────────────────────────────────────────

    public function test_the_date_range_is_inclusive_at_both_ends(): void
    {
        $result = $this->execute(
            array('date_from' => '2024-01-01', 'date_to' => '2024-01-31'),
            $this->store(
                $this->order(1, '2024-01-01 00:00:00'),
                $this->order(2, '2024-01-31 23:59:00'),
                $this->order(3, '2024-02-01 00:00:01')
            )
        );

        $this->assertSame(2, $result['data']['total_count']);
        $this->assertSame('23:59:59', $this->store->queries[0]->createdBefore?->format('H:i:s'));
    }

    public function test_an_invalid_date_is_refused(): void
    {
        $result = $this->execute(array('date_to' => '2024-13-01'), $this->store($this->order(1)));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('date_to', $result['error']['data']);
        $this->assertSame(array(), $this->store->queries);
    }

    public function test_a_bare_date_is_refused(): void
    {
        $result = $this->execute(array('date_from' => 'last tuesday'), $this->store($this->order(1)));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('date_from', $result['error']['data']);
    }

    public function test_a_start_after_the_end_is_refused(): void
    {
        $result = $this->execute(
            array('date_from' => '2024-02-01', 'date_to' => '2024-01-01'),
            $this->store($this->order(1))
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('date_from', $result['error']['data']);
    }

    // ── status, limit, paging ────────────────────────────────

    public function test_a_status_filter_is_applied(): void
    {
        $result = $this->execute(
            array('status' => 'completed'),
            $this->store(
                $this->order(1, '2024-01-03 09:00:00', status: OrderStatus::Pending),
                $this->order(2, '2024-01-02 09:00:00', status: OrderStatus::Completed)
            )
        );

        $this->assertSame(1, $result['data']['total_count']);
        $this->assertSame(2, $result['data']['orders'][0]['id']);
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $result = $this->execute(array('status' => 'shipped'), $this->store($this->order(1)));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('status', $result['error']['data']);
    }

    public function test_a_limit_above_the_maximum_is_refused(): void
    {
        $result = $this->execute(array('limit' => 101), $this->store($this->order(1)));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('limit', $result['error']['data']);
    }

    public function test_the_second_page_starts_where_the_first_ended(): void
    {
        $store = $this->store(
            $this->order(1, '2024-01-03 09:00:00'),
            $this->order(2, '2024-01-02 09:00:00'),
            $this->order(3, '2024-01-01 09:00:00')
        );

        $result = $this->execute(array('limit' => 2, 'page' => 2), $store);

        $this->assertSame(3, $result['data']['total_count']);
        $this->assertSame(1, $result['data']['returned']);
        $this->assertSame(3, $result['data']['orders'][0]['id']);
        $this->assertSame(2, $result['data']['total_pages']);
    }

    // ── fixtures ─────────────────────────────────────────────

    /**
     * @param array<string, mixed> $arguments Raw tool arguments.
     * @param OrderStoreFake       $store     The store to read.
     *
     * @return array<string, mixed>
     */
    private function execute(array $arguments, OrderStoreFake $store): array
    {
        /** @var array<string, mixed> $result */
        $result = $this->tool($store)->execute($arguments, array('user_id' => 7));

        return $result;
    }

    private function store(Order ...$orders): OrderStoreFake
    {
        $this->store = new OrderStoreFake(...$orders);

        return $this->store;
    }

    private function tool(OrderStoreFake $store): GetUninvoicedOrdersTool
    {
        return new GetUninvoicedOrdersTool($this->errors, $store);
    }

    private function order(
        int $id,
        string $created = '2024-01-15 10:00:00',
        string $total = '100.00',
        string $currency = 'USD',
        OrderStatus $status = OrderStatus::Pending,
        ?int $customerId = 7,
        ?string $invoicedAt = null,
        int $lines = 1,
    ): Order {
        return new Order(
            id: $id,
            number: '#' . $id,
            status: $status,
            createdAt: new \DateTimeImmutable($created),
            total: Money::fromDecimal($total, $currency),
            lines: $this->lines($lines, $total, $currency),
            customerId: $customerId,
            billingName: 'Ada Lovelace',
            billingEmail: 'ada@example.test',
            invoicedAt: null === $invoicedAt ? null : new \DateTimeImmutable($invoicedAt),
        );
    }

    /**
     * @return list<OrderLine>
     */
    private function lines(int $count, string $total, string $currency): array
    {
        $lines = array();

        for ($index = 1; $index <= $count; $index++) {
            $lines[] = new OrderLine(
                name: 'Item ' . $index,
                quantity: 1,
                unitPrice: Money::fromDecimal($total, $currency),
                lineTotal: Money::fromDecimal($total, $currency),
            );
        }

        return $lines;
    }
}
