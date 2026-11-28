<?php

/**
 * The orders tool: four actions, two refusals, and a guarded status move.
 *
 * Proposal 164 section 164.175. Pinned here:
 *
 * - the schema enum names the four actions the tool performs, and `add_note` and
 *   `refund` are refused with a reason rather than dropped;
 * - `search` requires a term and matches the number or the billing email, where
 *   the reference action was a synonym for `list` that accepted no term;
 * - `update_status` asks the transition rule, so a refunded order cannot reopen,
 *   and setting the status it already has writes nothing;
 * - `per_page` out of range is refused, where the reference clamped it silently.
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
use Nvoos\Core\Tool\Ecommerce\WoocommerceOrdersTool;
use PHPUnit\Framework\TestCase;

final class WoocommerceOrdersTest extends TestCase
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

        $this->errors->method('notFound')->willReturnCallback(
            static fn (string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'not_found', 'message' => $message, 'data' => $data),
            )
        );

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => $code, 'message' => $message, 'data' => $data),
            )
        );
    }

    // ── metadata and refusals ────────────────────────────────

    public function test_the_tool_metadata(): void
    {
        $tool = $this->tool($this->store());
        $schema = $tool->getParametersSchema();

        $this->assertSame('woo_orders', $tool->getSlug());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());
        $this->assertSame(array('get', 'list', 'search', 'update_status'), $schema['properties']['action']['enum']);
        $this->assertNotContains('add_note', $schema['properties']['action']['enum']);
        $this->assertNotContains('refund', $schema['properties']['action']['enum']);
    }

    public function test_it_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->tool($this->store())->execute(array('action' => 'list'), array());
    }

    public function test_add_note_is_refused_by_name(): void
    {
        $result = $this->execute(array('action' => 'add_note', 'order_id' => 1, 'note' => 'hi'));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('notes', $result['error']['data']['action'][0]);
    }

    public function test_refund_is_refused_by_name(): void
    {
        $result = $this->execute(array('action' => 'refund', 'order_id' => 1));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('refund', $result['error']['data']['action'][0]);
    }

    public function test_an_unknown_action_is_refused(): void
    {
        $result = $this->execute(array('action' => 'archive'));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('action', $result['error']['data']);
    }

    public function test_a_refused_action_argument_is_named(): void
    {
        $result = $this->execute(array('action' => 'list', 'refund_amount' => 5));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('refund', $result['error']['data']['refund_amount'][0]);
    }

    public function test_an_unknown_field_is_refused(): void
    {
        $result = $this->execute(array('action' => 'list', 'wibble' => 1));

        $this->assertFalse($result['success']);
        $this->assertSame(array('Unknown field.'), $result['error']['data']['wibble']);
    }

    // ── get ──────────────────────────────────────────────────

    public function test_get_returns_the_order_with_its_lines(): void
    {
        $result = $this->execute(
            array('action' => 'get', 'order_id' => 1),
            $this->store($this->order(1, lines: 2))
        );

        $this->assertTrue($result['success']);
        $this->assertSame('#1', $result['data']['number']);
        $this->assertSame(2, $result['data']['item_count']);
        $this->assertCount(2, $result['data']['lines']);
    }

    public function test_get_requires_an_order_id(): void
    {
        $result = $this->execute(array('action' => 'get'));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('order_id', $result['error']['data']);
    }

    public function test_get_of_a_missing_order_is_not_found(): void
    {
        $result = $this->execute(array('action' => 'get', 'order_id' => 99), $this->store());

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['error']['code']);
    }

    // ── list and search ──────────────────────────────────────

    public function test_list_omits_lines_but_keeps_the_item_count(): void
    {
        $result = $this->execute(
            array('action' => 'list'),
            $this->store($this->order(1, lines: 2))
        );

        $order = $result['data']['orders'][0];

        $this->assertArrayNotHasKey('lines', $order);
        $this->assertSame(2, $order['item_count']);
        $this->assertSame(1, $result['data']['total_count']);
    }

    public function test_list_filters_by_status(): void
    {
        $result = $this->execute(
            array('action' => 'list', 'status' => 'completed'),
            $this->store(
                $this->order(1, status: OrderStatus::Pending),
                $this->order(2, status: OrderStatus::Completed)
            )
        );

        $this->assertSame(1, $result['data']['total_count']);
        $this->assertSame(2, $result['data']['orders'][0]['id']);
    }

    public function test_list_filters_by_customer(): void
    {
        $result = $this->execute(
            array('action' => 'list', 'customer' => 9),
            $this->store(
                $this->order(1, customerId: 7),
                $this->order(2, customerId: 9)
            )
        );

        $this->assertSame(1, $result['data']['total_count']);
        $this->assertSame(2, $result['data']['orders'][0]['id']);
    }

    public function test_a_non_positive_customer_is_refused(): void
    {
        $result = $this->execute(array('action' => 'list', 'customer' => -5));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('customer', $result['error']['data']);
    }

    public function test_a_page_above_the_maximum_is_refused(): void
    {
        $result = $this->execute(array('action' => 'list', 'per_page' => 101));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('per_page', $result['error']['data']);
    }

    public function test_search_needs_a_term(): void
    {
        $result = $this->execute(array('action' => 'search'));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('search', $result['error']['data']);
    }

    public function test_search_matches_the_order_number(): void
    {
        $result = $this->execute(
            array('action' => 'search', 'search' => '#2'),
            $this->store($this->order(1), $this->order(2))
        );

        $this->assertSame(1, $result['data']['total_count']);
        $this->assertSame(2, $result['data']['orders'][0]['id']);
    }

    public function test_search_matches_the_billing_email(): void
    {
        $result = $this->execute(
            array('action' => 'search', 'search' => 'grace@example.test'),
            $this->store(
                $this->order(1, email: 'ada@example.test'),
                $this->order(2, email: 'grace@example.test')
            )
        );

        $this->assertSame(1, $result['data']['total_count']);
        $this->assertSame(2, $result['data']['orders'][0]['id']);
    }

    // ── update_status ────────────────────────────────────────

    public function test_a_legal_move_is_applied(): void
    {
        $store = $this->store($this->order(1, status: OrderStatus::Pending));

        $result = $this->execute(array('action' => 'update_status', 'order_id' => 1, 'new_status' => 'processing'), $store);

        $this->assertTrue($result['success']);
        $this->assertSame('pending', $result['data']['old_status']);
        $this->assertSame('processing', $result['data']['new_status']);
        $this->assertTrue($result['data']['changed']);
        $this->assertContains('completed', $result['data']['allowed_next']);
        $this->assertSame(OrderStatus::Processing, $store->orders[1]->status);
    }

    public function test_a_wc_prefix_is_ignored(): void
    {
        $store = $this->store($this->order(1, status: OrderStatus::Pending));

        $result = $this->execute(
            array('action' => 'update_status', 'order_id' => 1, 'new_status' => 'wc-processing'),
            $store
        );

        $this->assertTrue($result['success']);
        $this->assertSame('processing', $result['data']['new_status']);
    }

    public function test_a_refunded_order_cannot_reopen(): void
    {
        $store = $this->store($this->order(1, status: OrderStatus::Refunded));

        $result = $this->execute(
            array('action' => 'update_status', 'order_id' => 1, 'new_status' => 'processing'),
            $store
        );

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_status_transition', $result['error']['code']);
        $this->assertSame(array(), $result['error']['data']['allowed']);
        $this->assertSame(OrderStatus::Refunded, $store->orders[1]->status);
    }

    public function test_setting_the_current_status_writes_nothing(): void
    {
        $store = $this->store($this->order(1, status: OrderStatus::Processing));

        $result = $this->execute(
            array('action' => 'update_status', 'order_id' => 1, 'new_status' => 'processing'),
            $store
        );

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['changed']);
        $this->assertSame(array(), $store->saved);
    }

    public function test_update_status_requires_a_new_status(): void
    {
        $result = $this->execute(array('action' => 'update_status', 'order_id' => 1));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('new_status', $result['error']['data']);
    }

    public function test_update_status_of_a_missing_order_is_not_found(): void
    {
        $result = $this->execute(
            array('action' => 'update_status', 'order_id' => 99, 'new_status' => 'processing'),
            $this->store()
        );

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['error']['code']);
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

    private function tool(OrderStoreFake $store): WoocommerceOrdersTool
    {
        return new WoocommerceOrdersTool($this->errors, $store);
    }

    private function order(
        int $id,
        OrderStatus $status = OrderStatus::Pending,
        ?int $customerId = 7,
        string $email = 'ada@example.test',
        int $lines = 1,
    ): Order {
        $built = array();

        for ($index = 1; $index <= $lines; $index++) {
            $built[] = new OrderLine(
                name: 'Item ' . $index,
                quantity: 1,
                unitPrice: Money::fromDecimal('10.00', 'USD'),
                lineTotal: Money::fromDecimal('10.00', 'USD'),
            );
        }

        return new Order(
            id: $id,
            number: '#' . $id,
            status: $status,
            createdAt: new \DateTimeImmutable('2024-01-0' . min($id, 9) . ' 00:00:00'),
            total: Money::fromDecimal('10.00', 'USD'),
            lines: $built,
            customerId: $customerId,
            billingName: 'Ada Lovelace',
            billingEmail: $email,
        );
    }
}
