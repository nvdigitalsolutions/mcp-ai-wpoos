<?php

/**
 * The customer lookup, and what it deliberately does not report.
 *
 * Proposal 164 section 164.184. Pinned here:
 *
 * - identity comes from the directory, so an account with no orders is listed;
 * - spend is absent, because a sum needs a currency and this is a lookup;
 * - `total` counts matches across pages while `per_page` is the page size;
 * - the role filter is exact and a role the host does not use matches nobody;
 * - a missing account is a not-found, and `get`/`get_orders` require an id.
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
use Nvoos\Core\Domain\Entity\Customer;
use Nvoos\Core\Domain\Entity\Order;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\ValueObject\Money;
use Nvoos\Core\Domain\ValueObject\OrderStatus;
use Nvoos\Core\Tests\Support\CustomerDirectoryFake;
use Nvoos\Core\Tests\Support\OrderStoreFake;
use Nvoos\Core\Tool\Ecommerce\WoocommerceCustomersTool;
use PHPUnit\Framework\TestCase;

final class WoocommerceCustomersToolTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    private CustomerDirectoryFake $directory;

    private OrderStoreFake $orders;

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

        $this->directory = new CustomerDirectoryFake();
        $this->orders = new OrderStoreFake();
    }

    // ── metadata and guards ──────────────────────────────────

    public function test_the_tool_metadata(): void
    {
        $tool = $this->tool();

        $this->assertSame('woo_customers', $tool->getSlug());
        $this->assertSame('list_users', $tool->getRequiredCapability());

        $schema = $tool->getParametersSchema();

        $this->assertSame(array('get', 'list', 'search', 'get_orders'), $schema['properties']['action']['enum']);
        $this->assertContains('any', $schema['properties']['order_status']['enum']);
    }

    public function test_it_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->tool()->execute(array('action' => 'list'), array());
    }

    public function test_an_unknown_action_is_refused(): void
    {
        $result = $this->execute(array('action' => 'delete'));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('action', $result['error']['data']);
    }

    public function test_get_requires_an_id(): void
    {
        $result = $this->execute(array('action' => 'get'));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('customer_id', $result['error']['data']);
    }

    public function test_get_orders_requires_an_id(): void
    {
        $result = $this->execute(array('action' => 'get_orders'));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('customer_id', $result['error']['data']);
    }

    public function test_search_requires_a_term(): void
    {
        $result = $this->execute(array('action' => 'search'));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('search', $result['error']['data']);
    }

    public function test_an_unknown_order_status_is_refused(): void
    {
        $this->directory($this->customer(1));

        $result = $this->execute(array('action' => 'get_orders', 'customer_id' => 1, 'order_status' => 'lost'));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('order_status', $result['error']['data']);
    }

    public function test_the_page_size_is_bounded(): void
    {
        $result = $this->execute(array('action' => 'list', 'per_page' => 101));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('per_page', $result['error']['data']);
    }

    // ── the listing ──────────────────────────────────────────

    public function test_list_reports_matches_across_pages_and_the_page_size(): void
    {
        $this->directory(
            $this->customer(1, 'Ada Lovelace', 'ada@example.test'),
            $this->customer(2, 'Grace Hopper', 'grace@example.test'),
            $this->customer(3, 'Alan Turing', 'alan@example.test')
        );

        $result = $this->execute(array('action' => 'list', 'per_page' => 2));

        $this->assertSame(3, $result['data']['total']);
        $this->assertSame(2, $result['data']['per_page']);
        $this->assertSame(2, $result['data']['total_pages']);
        $this->assertCount(2, $result['data']['customers']);
    }

    public function test_search_matches_the_name_and_the_email(): void
    {
        $this->directory(
            $this->customer(1, 'Ada Lovelace', 'ada@example.test'),
            $this->customer(2, 'Grace Hopper', 'grace@example.test')
        );

        $by_name = $this->execute(array('action' => 'search', 'search' => 'hopper'));
        $this->assertSame(array(2), array_column($by_name['data']['customers'], 'id'));

        $by_email = $this->execute(array('action' => 'search', 'search' => 'ada@'));
        $this->assertSame(array(1), array_column($by_email['data']['customers'], 'id'));
    }

    public function test_the_role_filter_is_exact(): void
    {
        $this->directory(
            $this->customer(1, 'Ada Lovelace', 'ada@example.test', 'customer'),
            $this->customer(2, 'Grace Hopper', 'grace@example.test', 'administrator')
        );

        $result = $this->execute(array('action' => 'list', 'role' => 'customer'));

        $this->assertSame(array(1), array_column($result['data']['customers'], 'id'));
    }

    public function test_a_role_the_host_does_not_use_matches_nobody(): void
    {
        $this->directory($this->customer(1, 'Ada Lovelace', 'ada@example.test', 'customer'));

        $result = $this->execute(array('action' => 'list', 'role' => 'wholesale'));

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['total']);
        $this->assertSame(array(), $result['data']['customers']);
    }

    // ── one account ──────────────────────────────────────────

    public function test_get_reports_the_profile_and_the_order_facts(): void
    {
        $this->directory($this->customer(1, 'Ada Lovelace', 'ada@example.test', 'customer'));
        $this->withOrders(
            $this->order(10, 1, '2024-01-05 09:00:00'),
            $this->order(11, 1, '2024-02-05 09:00:00')
        );

        $result = $this->execute(array('action' => 'get', 'customer_id' => 1));

        $this->assertSame(1, $result['data']['id']);
        $this->assertSame('Ada Lovelace', $result['data']['name']);
        $this->assertSame(2, $result['data']['orders']['count']);
        $this->assertSame('2024-02-05', $result['data']['orders']['last_order_date']);
    }

    public function test_get_reports_no_spend_because_a_sum_needs_a_currency(): void
    {
        $this->directory($this->customer(1));
        $this->withOrders($this->order(10, 1, '2024-01-05 09:00:00'));

        $result = $this->execute(array('action' => 'get', 'customer_id' => 1));

        $this->assertArrayNotHasKey('total_spent', $result['data']);
        $this->assertArrayNotHasKey('spend', $result['data']['orders']);
    }

    public function test_get_on_an_account_without_orders(): void
    {
        $this->directory($this->customer(1));

        $result = $this->execute(array('action' => 'get', 'customer_id' => 1));

        $this->assertSame(0, $result['data']['orders']['count']);
        $this->assertNull($result['data']['orders']['last_order_date']);
    }

    public function test_get_on_a_missing_account_is_not_found(): void
    {
        $result = $this->execute(array('action' => 'get', 'customer_id' => 9));

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['error']['code']);
    }

    // ── one account's orders ─────────────────────────────────

    public function test_get_orders_returns_newest_first_with_the_currency(): void
    {
        $this->directory($this->customer(1));
        $this->withOrders(
            $this->order(10, 1, '2024-01-05 09:00:00', 'USD'),
            $this->order(11, 1, '2024-02-05 09:00:00', 'USD')
        );

        $result = $this->execute(array('action' => 'get_orders', 'customer_id' => 1));

        $this->assertSame(array(11, 10), array_column($result['data']['orders'], 'id'));
        $this->assertSame('USD', $result['data']['orders'][0]['currency']);
        $this->assertSame(2, $result['data']['total']);
    }

    public function test_get_orders_filters_by_status(): void
    {
        $this->directory($this->customer(1));
        $this->withOrders(
            $this->order(10, 1, '2024-01-05 09:00:00', 'USD', 'completed'),
            $this->order(11, 1, '2024-02-05 09:00:00', 'USD', 'cancelled')
        );

        $result = $this->execute(
            array('action' => 'get_orders', 'customer_id' => 1, 'order_status' => 'completed')
        );

        $this->assertSame(array(10), array_column($result['data']['orders'], 'id'));
    }

    public function test_get_orders_on_a_missing_account_is_not_found(): void
    {
        $result = $this->execute(array('action' => 'get_orders', 'customer_id' => 9));

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['error']['code']);
    }

    // ── fixtures ─────────────────────────────────────────────

    /**
     * @param array<string, mixed> $arguments Raw tool arguments.
     *
     * @return array<string, mixed>
     */
    private function execute(array $arguments): array
    {
        /** @var array<string, mixed> $result */
        $result = $this->tool()->execute($arguments, array('user_id' => 7));

        return $result;
    }

    private function tool(): WoocommerceCustomersTool
    {
        return new WoocommerceCustomersTool($this->errors, $this->directory, $this->orders);
    }

    private function directory(Customer ...$customers): void
    {
        $this->directory = new CustomerDirectoryFake(...$customers);
    }

    private function withOrders(Order ...$orders): void
    {
        $this->orders = new OrderStoreFake(...$orders);
    }

    private function customer(
        int $id,
        string $name = 'Ada Lovelace',
        string $email = 'ada@example.test',
        ?string $role = 'customer',
    ): Customer {
        return new Customer($id, $name, $email, $role, new \DateTimeImmutable('2024-01-01 00:00:00'));
    }

    private function order(
        int $id,
        int $customerId,
        string $created,
        string $currency = 'USD',
        string $status = 'completed',
    ): Order {
        return new Order(
            id: $id,
            number: '#' . $id,
            status: OrderStatus::from($status),
            createdAt: new \DateTimeImmutable($created),
            total: Money::fromDecimal('100.00', $currency),
            lines: array(),
            customerId: $customerId,
        );
    }
}
