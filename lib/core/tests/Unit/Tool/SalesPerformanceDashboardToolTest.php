<?php

/**
 * The sales dashboard, and the reference defects it corrects.
 *
 * Proposal 164 section 164.179. Pinned here:
 *
 * - revenue excludes cancelled, failed and refunded orders, and the
 *   `include_refunds` flag that used to add refunded totals back is refused
 *   when true;
 * - the currency is required and is a filter;
 * - `average_order` is computed whenever it is asked for, rather than only when
 *   `orders` happened to be requested too;
 * - `customers` counts distinct buyers and ignores guest orders;
 * - the products and conversion metrics are refused by name;
 * - `date_to` includes the day it names and `days` counts it;
 * - an unknown metric or argument is a field error.
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
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\ValueObject\Money;
use Nvoos\Core\Domain\ValueObject\OrderStatus;
use Nvoos\Core\Tests\Support\OrderStoreFake;
use Nvoos\Core\Tool\Ecommerce\SalesPerformanceDashboardTool;
use PHPUnit\Framework\TestCase;

final class SalesPerformanceDashboardToolTest extends TestCase
{
    private ErrorFactoryInterface $errors;

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

        $this->assertSame('sales_performance_dashboard', $tool->getSlug());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());

        $schema = $tool->getParametersSchema();

        $this->assertSame(array('currency'), $schema['required']);
        $this->assertSame(
            array('revenue', 'orders', 'customers', 'average_order'),
            $schema['properties']['metrics']['items']['enum']
        );
    }

    public function test_it_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->tool($this->store())->execute(array('currency' => 'USD'), array());
    }

    // ── argument validation ──────────────────────────────────

    public function test_the_currency_is_required(): void
    {
        $result = $this->execute(array(), $this->store());

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('currency', $result['error']['data']);
    }

    public function test_an_unknown_argument_is_refused(): void
    {
        $result = $this->execute(
            array('currency' => 'USD', 'group_by' => 'week'),
            $this->store()
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('group_by', $result['error']['data']);
    }

    public function test_the_products_and_conversion_metrics_are_refused_by_name(): void
    {
        foreach (array('products', 'conversion') as $metric) {
            $result = $this->execute(
                array('currency' => 'USD', 'metrics' => array($metric)),
                $this->store()
            );

            $this->assertFalse($result['success'], $metric . ' should be refused');
            $this->assertArrayHasKey('metrics', $result['error']['data']);
        }
    }

    public function test_an_unknown_metric_is_refused(): void
    {
        $result = $this->execute(
            array('currency' => 'USD', 'metrics' => array('profit')),
            $this->store()
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('metrics', $result['error']['data']);
    }

    public function test_include_refunds_is_refused_when_true_and_accepted_when_false(): void
    {
        $refused = $this->execute(
            array('currency' => 'USD', 'include_refunds' => true),
            $this->store()
        );

        $this->assertFalse($refused['success']);
        $this->assertArrayHasKey('include_refunds', $refused['error']['data']);

        $accepted = $this->execute(
            array('currency' => 'USD', 'include_refunds' => false),
            $this->store()
        );

        $this->assertTrue($accepted['success']);
    }

    public function test_a_populated_category_filter_is_refused_and_an_empty_one_accepted(): void
    {
        $refused = $this->execute(
            array('currency' => 'USD', 'category_ids' => array(12)),
            $this->store()
        );

        $this->assertFalse($refused['success']);
        $this->assertArrayHasKey('category_ids', $refused['error']['data']);

        $accepted = $this->execute(
            array('currency' => 'USD', 'category_ids' => array()),
            $this->store()
        );

        $this->assertTrue($accepted['success']);
    }

    public function test_top_products_limit_is_refused(): void
    {
        $result = $this->execute(
            array('currency' => 'USD', 'top_products_limit' => 5),
            $this->store()
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('top_products_limit', $result['error']['data']);
    }

    public function test_a_malformed_date_is_refused(): void
    {
        $result = $this->execute(
            array('currency' => 'USD', 'date_from' => '2024-02-30'),
            $this->store()
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('date_from', $result['error']['data']);
    }

    public function test_a_reversed_range_is_refused(): void
    {
        $result = $this->execute(
            array('currency' => 'USD', 'date_from' => '2024-02-01', 'date_to' => '2024-01-01'),
            $this->store()
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('date_from', $result['error']['data']);
    }

    // ── the metrics ──────────────────────────────────────────

    public function test_revenue_orders_and_average_order(): void
    {
        $store = $this->store(
            $this->order(1, 'completed', '100.00'),
            $this->order(2, 'processing', '50.00'),
            $this->order(3, 'cancelled', '25.00')
        );

        $result = $this->execute(
            $this->window() + array('metrics' => array('revenue', 'orders', 'average_order')),
            $store
        );

        $this->assertTrue($result['success']);
        $this->assertSame(150.0, $result['data']['metrics']['revenue']);
        $this->assertSame(2, $result['data']['metrics']['orders']);
        $this->assertSame(75.0, $result['data']['metrics']['average_order']);
    }

    public function test_average_order_does_not_depend_on_orders_being_requested(): void
    {
        $store = $this->store(
            $this->order(1, 'completed', '100.00'),
            $this->order(2, 'completed', '50.00')
        );

        $result = $this->execute(
            $this->window() + array('metrics' => array('revenue', 'average_order')),
            $store
        );

        $this->assertArrayHasKey('average_order', $result['data']['metrics']);
        $this->assertSame(75.0, $result['data']['metrics']['average_order']);
        $this->assertArrayNotHasKey('orders', $result['data']['metrics']);
    }

    public function test_customers_counts_distinct_buyers_and_ignores_guests(): void
    {
        $store = $this->store(
            $this->order(1, 'completed', '10.00', 'USD', '2024-01-05 09:00:00', 7),
            $this->order(2, 'processing', '20.00', 'USD', '2024-01-06 09:00:00', 8),
            $this->order(3, 'pending', '5.00', 'USD', '2024-01-07 09:00:00', 7),
            $this->order(4, 'completed', '30.00', 'USD', '2024-01-08 09:00:00', null),
            $this->order(5, 'cancelled', '40.00', 'USD', '2024-01-09 09:00:00', 9)
        );

        $result = $this->execute(
            $this->window() + array('metrics' => array('customers')),
            $store
        );

        $this->assertSame(2, $result['data']['metrics']['customers']);
    }

    public function test_orders_in_another_currency_are_not_aggregated(): void
    {
        $store = $this->store(
            $this->order(1, 'completed', '100.00', 'USD'),
            $this->order(2, 'completed', '200.00', 'EUR')
        );

        $result = $this->execute($this->window(), $store);

        $this->assertSame(100.0, $result['data']['metrics']['revenue']);
        $this->assertSame(1, $result['data']['metrics']['orders']);
    }

    public function test_metrics_default_to_revenue_orders_and_customers(): void
    {
        $result = $this->execute($this->window(), $this->store());

        $this->assertSame(array('revenue', 'orders', 'customers'), array_keys($result['data']['metrics']));
    }

    // ── the trend ────────────────────────────────────────────

    public function test_trends_carry_revenue_only_per_day(): void
    {
        $store = $this->store(
            $this->order(1, 'completed', '10.00', 'USD', '2024-01-01 09:00:00'),
            $this->order(2, 'cancelled', '5.00', 'USD', '2024-01-01 10:00:00'),
            $this->order(3, 'completed', '20.00', 'USD', '2024-01-02 09:00:00')
        );

        $result = $this->execute($this->window(), $store);

        $this->assertSame(
            array(
                array('date' => '2024-01-01', 'orders' => 1, 'revenue' => 10.0),
                array('date' => '2024-01-02', 'orders' => 1, 'revenue' => 20.0),
            ),
            $result['data']['trends']
        );
    }

    public function test_an_empty_window_reports_zeroes_and_no_trend(): void
    {
        $result = $this->execute($this->window(), $this->store());

        $this->assertTrue($result['success']);
        $this->assertSame(0.0, $result['data']['metrics']['revenue']);
        $this->assertSame(0, $result['data']['metrics']['orders']);
        $this->assertSame(0, $result['data']['metrics']['customers']);
        $this->assertSame(array(), $result['data']['trends']);
    }

    // ── the period ───────────────────────────────────────────

    public function test_days_counts_both_ends_of_the_window(): void
    {
        $result = $this->execute($this->window(), $this->store());

        $this->assertSame('2024-01-01', $result['data']['period']['from']);
        $this->assertSame('2024-01-31', $result['data']['period']['to']);
        $this->assertSame(31, $result['data']['period']['days']);
        $this->assertSame('USD', $result['data']['period']['currency']);
    }

    public function test_the_default_window_is_the_last_thirty_days(): void
    {
        $result = $this->execute(array('currency' => 'USD'), $this->store());

        $today = new \DateTimeImmutable('today');

        $this->assertSame($today->modify('-30 days')->format('Y-m-d'), $result['data']['period']['from']);
        $this->assertSame($today->format('Y-m-d'), $result['data']['period']['to']);
        $this->assertSame(31, $result['data']['period']['days']);
    }

    // ── fixtures ─────────────────────────────────────────────

    /**
     * A window that contains the default fixture date, in USD.
     *
     * @return array<string, string>
     */
    private function window(): array
    {
        return array(
            'currency'  => 'USD',
            'date_from' => '2024-01-01',
            'date_to'   => '2024-01-31',
        );
    }

    /**
     * @param array<string, mixed> $arguments Raw tool arguments.
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
        return new OrderStoreFake(...$orders);
    }

    private function tool(OrderStoreFake $store): SalesPerformanceDashboardTool
    {
        return new SalesPerformanceDashboardTool($this->errors, $store);
    }

    private function order(
        int $id,
        string $status,
        string $total,
        string $currency = 'USD',
        string $created = '2024-01-15 10:00:00',
        ?int $customerId = 7,
    ): Order {
        return new Order(
            id: $id,
            number: '#' . $id,
            status: OrderStatus::from($status),
            createdAt: new \DateTimeImmutable($created),
            total: Money::fromDecimal($total, $currency),
            lines: array(),
            customerId: $customerId,
            billingName: 'Ada Lovelace',
            billingEmail: 'ada@example.test',
        );
    }
}
