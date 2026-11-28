<?php

/**
 * The bulk status move: one guard per order, and a report that says which.
 *
 * Proposal 164 section 164.176. Pinned here:
 *
 * - every selected order is checked against the transition graph, and a refused
 *   order does not stop the ones after it;
 * - an order already at the target is unchanged and is not written;
 * - a dry run reports would-be moves and writes nothing;
 * - selection by id is capped and a missing id is reported rather than dropped;
 * - selection by filter is capped, and `truncated` says when more matched than
 *   were selected — the reference applied the limit silently;
 * - `send_notification`, `note` and `note_type` are refused by name.
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
use Nvoos\Core\Tool\Ecommerce\BulkOrderStatusUpdateTool;
use PHPUnit\Framework\TestCase;

final class BulkOrderStatusUpdateTest extends TestCase
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

    // ── metadata and arguments ───────────────────────────────

    public function test_the_tool_metadata(): void
    {
        $tool = $this->tool($this->store());

        $this->assertSame('bulk_order_status_update', $tool->getSlug());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());
        $this->assertSame(array('new_status'), $tool->getParametersSchema()['required']);
    }

    public function test_it_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->tool($this->store())->execute(array('new_status' => 'processing'), array());
    }

    public function test_a_new_status_is_required(): void
    {
        $result = $this->execute(array('order_ids' => array(1)));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('new_status', $result['error']['data']);
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $result = $this->execute(array('new_status' => 'shipped', 'order_ids' => array(1)));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('new_status', $result['error']['data']);
    }

    public function test_a_wc_prefix_is_ignored(): void
    {
        $store = $this->store($this->order(1));

        $result = $this->execute(array('new_status' => 'wc-processing', 'order_ids' => array(1)), $store);

        $this->assertTrue($result['success']);
        $this->assertSame('processing', $result['data']['new_status']);
    }

    // ── selection by id ──────────────────────────────────────

    public function test_only_the_named_orders_move(): void
    {
        $store = $this->store($this->order(1), $this->order(2));

        $result = $this->execute(array('new_status' => 'processing', 'order_ids' => array(2)), $store);

        $this->assertSame(1, $result['data']['updated']);
        $this->assertSame(OrderStatus::Pending, $store->orders[1]->status);
        $this->assertSame(OrderStatus::Processing, $store->orders[2]->status);
    }

    public function test_order_ids_win_over_a_filter(): void
    {
        $store = $this->store($this->order(1));

        $result = $this->execute(
            array(
                'new_status' => 'processing',
                'order_ids'  => array(1),
                'filter'     => array('nonsense' => true),
            ),
            $store
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['selected']);
    }

    public function test_a_missing_id_is_reported_and_the_others_move(): void
    {
        $store = $this->store($this->order(1));

        $result = $this->execute(array('new_status' => 'processing', 'order_ids' => array(1, 99)), $store);

        $this->assertSame(1, $result['data']['updated']);
        $this->assertSame(1, $result['data']['not_found']);
        $this->assertSame(2, $result['data']['requested']);
    }

    public function test_ids_that_are_not_positive_are_dropped(): void
    {
        $result = $this->execute(
            array('new_status' => 'processing', 'order_ids' => array(0, -5)),
            $this->store($this->order(1))
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('order_ids', $result['error']['data']);
    }

    public function test_more_than_the_cap_is_refused(): void
    {
        $result = $this->execute(
            array('new_status' => 'processing', 'order_ids' => range(1, 101)),
            $this->store()
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('order_ids', $result['error']['data']);
    }

    // ── selection by filter ──────────────────────────────────

    public function test_the_filter_selects_the_current_status(): void
    {
        $store = $this->store(
            $this->order(1, status: OrderStatus::Pending),
            $this->order(2, status: OrderStatus::Completed)
        );

        $result = $this->execute(
            array('new_status' => 'processing', 'filter' => array('status' => 'pending')),
            $store
        );

        $this->assertSame(1, $result['data']['selected']);
        $this->assertSame(OrderStatus::Processing, $store->orders[1]->status);
        $this->assertSame(OrderStatus::Completed, $store->orders[2]->status);
    }

    public function test_the_filter_selects_one_customer(): void
    {
        $store = $this->store(
            $this->order(1, customerId: 7),
            $this->order(2, customerId: 9)
        );

        $result = $this->execute(
            array('new_status' => 'processing', 'filter' => array('customer_id' => 9)),
            $store
        );

        $this->assertSame(1, $result['data']['selected']);
        $this->assertSame(OrderStatus::Processing, $store->orders[2]->status);
    }

    public function test_the_filter_limit_above_the_cap_is_refused(): void
    {
        $result = $this->execute(
            array('new_status' => 'processing', 'filter' => array('limit' => 101)),
            $this->store()
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('filter.limit', $result['error']['data']);
    }

    public function test_the_filter_date_range_is_inclusive(): void
    {
        $store = $this->store(
            $this->order(1, created: '2024-01-01 00:00:00'),
            $this->order(2, created: '2024-01-31 23:59:00'),
            $this->order(3, created: '2024-02-01 00:00:01')
        );

        $result = $this->execute(
            array(
                'new_status' => 'processing',
                'filter'     => array('date_from' => '2024-01-01', 'date_to' => '2024-01-31'),
            ),
            $store
        );

        $this->assertSame(2, $result['data']['selected']);
    }

    public function test_an_invalid_date_is_refused(): void
    {
        $result = $this->execute(
            array('new_status' => 'processing', 'filter' => array('date_to' => '2024-13-01')),
            $this->store()
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('filter.date_to', $result['error']['data']);
    }

    public function test_a_reversed_range_is_refused(): void
    {
        $result = $this->execute(
            array(
                'new_status' => 'processing',
                'filter'     => array('date_from' => '2024-02-01', 'date_to' => '2024-01-01'),
            ),
            $this->store()
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('filter.date_from', $result['error']['data']);
    }

    public function test_an_unknown_filter_key_is_refused(): void
    {
        $result = $this->execute(
            array('new_status' => 'processing', 'filter' => array('search' => 'x')),
            $this->store()
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('filter.search', $result['error']['data']);
    }

    public function test_a_cut_selection_is_reported_as_truncated(): void
    {
        $store = $this->store($this->order(1), $this->order(2), $this->order(3));

        $result = $this->execute(
            array('new_status' => 'processing', 'filter' => array('limit' => 2)),
            $store
        );

        $this->assertSame(3, $result['data']['requested']);
        $this->assertSame(2, $result['data']['selected']);
        $this->assertTrue($result['data']['truncated']);
    }

    // ── the guard ────────────────────────────────────────────

    public function test_a_refused_order_does_not_stop_the_rest(): void
    {
        $store = $this->store(
            $this->order(1, status: OrderStatus::Refunded),
            $this->order(2, status: OrderStatus::Pending)
        );

        $result = $this->execute(
            array('new_status' => 'processing', 'order_ids' => array(1, 2)),
            $store
        );

        $this->assertSame(1, $result['data']['updated']);
        $this->assertSame(1, $result['data']['refused']);
        $this->assertSame(OrderStatus::Refunded, $store->orders[1]->status);
        $this->assertSame(OrderStatus::Processing, $store->orders[2]->status);

        $refusal = $result['data']['results'][0];

        $this->assertSame('refused', $refusal['outcome']);
        $this->assertSame(array(), $refusal['allowed']);
    }

    public function test_an_order_already_at_the_target_is_not_written(): void
    {
        $store = $this->store($this->order(1, status: OrderStatus::Processing));

        $result = $this->execute(
            array('new_status' => 'processing', 'order_ids' => array(1)),
            $store
        );

        $this->assertSame(1, $result['data']['unchanged']);
        $this->assertSame(0, $result['data']['updated']);
        $this->assertSame(array(), $store->saved);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $store = $this->store($this->order(1), $this->order(2));

        $result = $this->execute(
            array('new_status' => 'processing', 'order_ids' => array(1, 2), 'dry_run' => true),
            $store
        );

        $this->assertTrue($result['data']['dry_run']);
        $this->assertSame(2, $result['data']['updated']);
        $this->assertSame(array(), $store->saved);
        $this->assertSame(OrderStatus::Pending, $store->orders[1]->status);
    }

    // ── refusals ─────────────────────────────────────────────

    public function test_a_customer_notification_is_refused(): void
    {
        $result = $this->execute(
            array('new_status' => 'processing', 'order_ids' => array(1), 'send_notification' => true),
            $this->store($this->order(1))
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('mail channel', $result['error']['data']['send_notification'][0]);
    }

    public function test_a_false_notification_flag_is_accepted(): void
    {
        $result = $this->execute(
            array('new_status' => 'processing', 'order_ids' => array(1), 'send_notification' => false),
            $this->store($this->order(1))
        );

        $this->assertTrue($result['success']);
    }

    public function test_a_note_is_refused(): void
    {
        $result = $this->execute(
            array('new_status' => 'processing', 'order_ids' => array(1), 'note' => 'hello'),
            $this->store($this->order(1))
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('note', $result['error']['data']);
    }

    // ── fixtures ─────────────────────────────────────────────

    /**
     * @param array<string, mixed> $arguments Raw tool arguments.
     * @param OrderStoreFake|null  $store     The store to use.
     *
     * @return array<string, mixed>
     */
    private function execute(array $arguments, ?OrderStoreFake $store = null): array
    {
        /** @var array<string, mixed> $result */
        $result = $this->tool($store ?? $this->store())->execute($arguments, array('user_id' => 7));

        return $result;
    }

    private function store(Order ...$orders): OrderStoreFake
    {
        $this->store = new OrderStoreFake(...$orders);

        return $this->store;
    }

    private function tool(OrderStoreFake $store): BulkOrderStatusUpdateTool
    {
        return new BulkOrderStatusUpdateTool($this->errors, $store);
    }

    private function order(
        int $id,
        OrderStatus $status = OrderStatus::Pending,
        ?int $customerId = 7,
        string $created = '2024-01-15 10:00:00',
    ): Order {
        return new Order(
            id: $id,
            number: '#' . $id,
            status: $status,
            createdAt: new \DateTimeImmutable($created),
            total: Money::fromDecimal('10.00', 'USD'),
            lines: array(
                new OrderLine(
                    name: 'Item',
                    quantity: 1,
                    unitPrice: Money::fromDecimal('10.00', 'USD'),
                    lineTotal: Money::fromDecimal('10.00', 'USD'),
                ),
            ),
            customerId: $customerId,
            billingName: 'Ada Lovelace',
            billingEmail: 'ada@example.test',
        );
    }
}
