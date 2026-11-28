<?php

/**
 * The order aggregate's invariants and its query's guards.
 *
 * Proposal 164 section 164.174. These are the rules a port could otherwise
 * discover in production: an order cannot hold a total in one currency and a
 * line in another, an order line has at least one unit, and a query cannot page
 * backwards or ask for a range that ends before it starts.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Entity;

use Nvoos\Core\Domain\Entity\Order;
use Nvoos\Core\Domain\Entity\OrderCollection;
use Nvoos\Core\Domain\Entity\OrderLine;
use Nvoos\Core\Domain\Entity\OrderQuery;
use Nvoos\Core\Domain\ValueObject\Money;
use Nvoos\Core\Domain\ValueObject\OrderStatus;
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
{
    public function test_the_item_count_is_the_number_of_lines(): void
    {
        $order = $this->order(array($this->line('9.99'), $this->line('4.50')));

        $this->assertSame(2, $order->itemCount());
    }

    public function test_an_order_with_no_lines_has_no_items(): void
    {
        $this->assertSame(0, $this->order(array())->itemCount());
    }

    public function test_it_is_invoiced_when_the_marker_is_set(): void
    {
        $this->assertFalse($this->order(array())->isInvoiced());

        $invoiced = new Order(
            id: 1,
            number: '#1',
            status: OrderStatus::Completed,
            createdAt: new \DateTimeImmutable('2024-01-01 00:00:00'),
            total: Money::fromDecimal('10.00', 'USD'),
            invoicedAt: new \DateTimeImmutable('2024-01-02 00:00:00'),
        );

        $this->assertTrue($invoiced->isInvoiced());
    }

    public function test_a_component_total_in_another_currency_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Order(
            id: 1,
            number: '#1',
            status: OrderStatus::Pending,
            createdAt: new \DateTimeImmutable('2024-01-01 00:00:00'),
            total: Money::fromDecimal('10.00', 'USD'),
            taxTotal: Money::fromDecimal('1.00', 'EUR'),
        );
    }

    public function test_a_line_in_another_currency_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->order(array($this->line('9.99', 'EUR')));
    }

    public function test_a_line_quantity_below_one_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OrderLine(
            name: 'Thing',
            quantity: 0,
            unitPrice: Money::fromDecimal('9.99', 'USD'),
            lineTotal: Money::fromDecimal('0.00', 'USD'),
        );
    }

    public function test_it_serialises_the_marker_and_the_totals(): void
    {
        $order = new Order(
            id: 3,
            number: '#3',
            status: OrderStatus::Processing,
            createdAt: new \DateTimeImmutable('2024-01-01 12:00:00'),
            total: Money::fromDecimal('24.99', 'USD'),
            lines: array($this->line('24.99')),
            subtotal: Money::fromDecimal('24.99', 'USD'),
            customerId: 5,
            invoicedAt: new \DateTimeImmutable('2024-01-02 12:00:00'),
        );

        $json = $order->jsonSerialize();

        $this->assertSame(3, $json['id']);
        $this->assertSame('processing', $json['status']);
        $this->assertSame('USD', $json['currency']);
        $this->assertSame(24.99, $json['total']);
        $this->assertSame(
            ( new \DateTimeImmutable('2024-01-02 12:00:00') )->format(\DATE_ATOM),
            $json['invoiced_at']
        );
        $this->assertNull($json['tax_total']);
        $this->assertSame(1, $json['item_count']);
    }

    // ── query guards ─────────────────────────────────────────

    public function test_a_page_below_one_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OrderQuery(page: 0);
    }

    public function test_a_page_size_above_the_cap_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OrderQuery(perPage: 101);
    }

    public function test_a_reversed_date_range_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OrderQuery(
            createdAfter: new \DateTimeImmutable('2024-02-01 00:00:00'),
            createdBefore: new \DateTimeImmutable('2024-01-01 00:00:00'),
        );
    }

    public function test_an_unknown_sort_field_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OrderQuery(orderBy: 'customer');
    }

    public function test_the_next_page_keeps_every_filter(): void
    {
        $query = new OrderQuery(
            uninvoicedOnly: true,
            minTotal: Money::fromDecimal('10.00', 'USD'),
            status: OrderStatus::Processing,
            customerId: 4,
            page: 2,
            perPage: 50,
        );

        $next = $query->nextPage();

        $this->assertSame(3, $next->page);
        $this->assertSame(100, $next->getOffset());
        $this->assertTrue($next->uninvoicedOnly);
        $this->assertSame(OrderStatus::Processing, $next->status);
        $this->assertSame(4, $next->customerId);
        $this->assertSame('USD', $next->minTotal?->currency);
    }

    public function test_a_collection_knows_whether_it_has_more(): void
    {
        $collection = new OrderCollection(array(), 3, 2, 2, 2);

        $this->assertFalse($collection->hasItems());
        $this->assertFalse($collection->hasMorePages());

        $firstPage = new OrderCollection(array($this->order(array())), 3, 1, 2, 2);

        $this->assertTrue($firstPage->hasItems());
        $this->assertTrue($firstPage->hasMorePages());
    }

    // ── fixtures ─────────────────────────────────────────────

    /**
     * @param list<OrderLine> $lines
     */
    private function order(array $lines): Order
    {
        return new Order(
            id: 1,
            number: '#1',
            status: OrderStatus::Pending,
            createdAt: new \DateTimeImmutable('2024-01-01 00:00:00'),
            total: Money::fromDecimal('10.00', 'USD'),
            lines: $lines,
        );
    }

    private function line(string $amount, string $currency = 'USD'): OrderLine
    {
        return new OrderLine(
            name: 'Thing',
            quantity: 1,
            unitPrice: Money::fromDecimal($amount, $currency),
            lineTotal: Money::fromDecimal($amount, $currency),
        );
    }
}
