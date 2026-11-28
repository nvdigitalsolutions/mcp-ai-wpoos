<?php

/**
 * Order analytics, and the reference defects it corrects.
 *
 * Proposal 164 section 164.178. Pinned here:
 *
 * - revenue counts only the statuses that represent money taken, while gross
 *   counts every order — the reference reported gross under the name
 *   "total_revenue";
 * - the currency is required and is a filter, so a EUR order cannot be added to
 *   a USD sum and is reported as excluded instead;
 * - `date_to` includes the day it names;
 * - `group_by` is a closed enum, so an unknown value is refused rather than
 *   silently answered as `day`;
 * - weeks are keyed by the ISO week-year, so the week spanning New Year is one
 *   bucket rather than two;
 * - the sections this slice does not compute are refused by name rather than
 *   quietly ignored.
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
use Nvoos\Core\Tool\Ecommerce\GetOrderAnalyticsTool;
use PHPUnit\Framework\TestCase;

final class GetOrderAnalyticsToolTest extends TestCase
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

        $this->assertSame('get_order_analytics', $tool->getSlug());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());

        $schema = $tool->getParametersSchema();

        $this->assertSame(array('currency'), $schema['required']);
        $this->assertSame(array('day', 'week', 'month'), $schema['properties']['group_by']['enum']);
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

    public function test_a_non_iso_currency_is_refused(): void
    {
        $result = $this->execute(array('currency' => 'dollars'), $this->store());

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('currency', $result['error']['data']);
    }

    public function test_a_lower_case_currency_is_accepted_and_normalised(): void
    {
        $result = $this->execute(array('currency' => 'usd'), $this->store());

        $this->assertTrue($result['success']);
        $this->assertSame('USD', $result['data']['currency']);
    }

    public function test_an_unknown_argument_is_refused(): void
    {
        $result = $this->execute(
            array('currency' => 'USD', 'limit' => 5),
            $this->store()
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('limit', $result['error']['data']);
    }

    public function test_the_sections_this_slice_does_not_compute_are_refused_by_name(): void
    {
        foreach (array('include_products', 'include_customers', 'top_count') as $argument) {
            $result = $this->execute(
                array('currency' => 'USD', $argument => true),
                $this->store()
            );

            $this->assertFalse($result['success'], $argument . ' should be refused');
            $this->assertArrayHasKey($argument, $result['error']['data'], $argument . ' should be named');
        }
    }

    public function test_an_unknown_group_by_is_refused(): void
    {
        $result = $this->execute(
            array('currency' => 'USD', 'group_by' => 'fortnight'),
            $this->store()
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('group_by', $result['error']['data']);
    }

    public function test_a_malformed_date_is_refused(): void
    {
        foreach (array('2024-13-01', '31/01/2024', 'tomorrow') as $value) {
            $result = $this->execute(
                array('currency' => 'USD', 'date_from' => $value),
                $this->store()
            );

            $this->assertFalse($result['success'], $value . ' should be refused');
            $this->assertArrayHasKey('date_from', $result['error']['data']);
        }
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

    // ── the aggregate ────────────────────────────────────────

    public function test_revenue_excludes_cancelled_failed_and_refunded_while_gross_includes_them(): void
    {
        $store = $this->store(
            $this->order(1, 'completed', '100.00'),
            $this->order(2, 'cancelled', '50.00'),
            $this->order(3, 'failed', '25.00'),
            $this->order(4, 'refunded', '40.00'),
            $this->order(5, 'pending', '10.00'),
            $this->order(6, 'processing', '20.00'),
            $this->order(7, 'on-hold', '30.00')
        );

        $result = $this->execute($this->window(), $store);

        $this->assertTrue($result['success']);
        $this->assertSame(7, $result['data']['order_count']);
        $this->assertSame(4, $result['data']['revenue_order_count']);
        $this->assertSame(275.0, $result['data']['gross_total']);
        $this->assertSame(160.0, $result['data']['revenue']);
        $this->assertSame(40.0, $result['data']['average_order_value']);
        $this->assertSame(1, $result['data']['status_counts']['cancelled']);
        $this->assertSame(1, $result['data']['status_counts']['on-hold']);
    }

    public function test_orders_in_another_currency_are_excluded_and_counted(): void
    {
        $store = $this->store(
            $this->order(1, 'completed', '100.00', 'USD'),
            $this->order(2, 'completed', '200.00', 'EUR'),
            $this->order(3, 'completed', '300.00', 'EUR')
        );

        $result = $this->execute($this->window(), $store);

        $this->assertSame(1, $result['data']['order_count']);
        $this->assertSame(2, $result['data']['excluded_orders']);
        $this->assertSame(100.0, $result['data']['gross_total']);
        $this->assertSame(100.0, $result['data']['revenue']);
    }

    public function test_date_to_includes_the_day_it_names(): void
    {
        $store = $this->store(
            $this->order(1, 'completed', '10.00', 'USD', '2024-01-31 23:59:59'),
            $this->order(2, 'completed', '20.00', 'USD', '2024-02-01 00:00:00')
        );

        $result = $this->execute(
            array('currency' => 'USD', 'date_from' => '2024-01-01', 'date_to' => '2024-01-31'),
            $store
        );

        $this->assertSame(1, $result['data']['order_count']);
        $this->assertSame(10.0, $result['data']['revenue']);
        $this->assertSame('2024-01-31', $result['data']['period']['to']);
    }

    public function test_the_default_window_is_the_last_thirty_days(): void
    {
        $result = $this->execute(array('currency' => 'USD'), $this->store());

        $today = new \DateTimeImmutable('today');

        $this->assertSame($today->modify('-30 days')->format('Y-m-d'), $result['data']['period']['from']);
        $this->assertSame($today->format('Y-m-d'), $result['data']['period']['to']);
        $this->assertSame('day', $result['data']['period']['group_by']);
    }

    public function test_trends_are_omitted_unless_asked_for(): void
    {
        $store = $this->store($this->order(1, 'completed', '10.00'));

        $without = $this->execute($this->window(), $store);

        $this->assertArrayNotHasKey('periods', $without['data']);

        $with = $this->execute($this->window() + array('include_trends' => true), $store);

        $this->assertArrayHasKey('periods', $with['data']);
    }

    public function test_trends_bucket_by_day_and_carry_revenue_only(): void
    {
        $store = $this->store(
            $this->order(1, 'completed', '10.00', 'USD', '2024-01-01 09:00:00'),
            $this->order(2, 'cancelled', '5.00', 'USD', '2024-01-01 10:00:00'),
            $this->order(3, 'completed', '20.00', 'USD', '2024-01-02 09:00:00')
        );

        $result = $this->execute($this->window() + array('include_trends' => true), $store);

        $this->assertSame(
            array(
                array('period' => '2024-01-01', 'orders' => 1, 'revenue' => 10.0),
                array('period' => '2024-01-02', 'orders' => 1, 'revenue' => 20.0),
            ),
            $result['data']['periods']
        );
    }

    public function test_trends_bucket_the_new_year_week_as_one_iso_week(): void
    {
        $store = $this->store(
            $this->order(1, 'completed', '10.00', 'USD', '2024-12-30 09:00:00'),
            $this->order(2, 'completed', '20.00', 'USD', '2025-01-02 09:00:00')
        );

        $result = $this->execute(
            array(
                'currency'       => 'USD',
                'date_from'      => '2024-12-01',
                'date_to'        => '2025-01-31',
                'group_by'       => 'week',
                'include_trends' => true,
            ),
            $store
        );

        $this->assertSame(
            array(array('period' => '2025-W01', 'orders' => 2, 'revenue' => 30.0)),
            $result['data']['periods']
        );
    }

    public function test_trends_bucket_by_month(): void
    {
        $store = $this->store(
            $this->order(1, 'completed', '10.00', 'USD', '2024-01-15 09:00:00'),
            $this->order(2, 'completed', '20.00', 'USD', '2024-01-31 09:00:00'),
            $this->order(3, 'completed', '30.00', 'USD', '2024-02-01 09:00:00')
        );

        $result = $this->execute(
            array('currency' => 'USD', 'date_from' => '2024-01-01', 'date_to' => '2024-02-29', 'group_by' => 'month', 'include_trends' => true),
            $store
        );

        $this->assertSame(
            array(
                array('period' => '2024-01', 'orders' => 2, 'revenue' => 30.0),
                array('period' => '2024-02', 'orders' => 1, 'revenue' => 30.0),
            ),
            $result['data']['periods']
        );
    }

    public function test_an_empty_window_reports_zeroes(): void
    {
        $result = $this->execute($this->window() + array('include_trends' => true), $this->store());

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['order_count']);
        $this->assertSame(0, $result['data']['revenue_order_count']);
        $this->assertSame(0, $result['data']['excluded_orders']);
        $this->assertSame(0.0, $result['data']['gross_total']);
        $this->assertSame(0.0, $result['data']['revenue']);
        $this->assertSame(0.0, $result['data']['average_order_value']);
        $this->assertSame(array(), $result['data']['status_counts']);
        $this->assertSame(array(), $result['data']['periods']);
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

    private function tool(OrderStoreFake $store): GetOrderAnalyticsTool
    {
        return new GetOrderAnalyticsTool($this->errors, $store);
    }

    private function order(
        int $id,
        string $status,
        string $total,
        string $currency = 'USD',
        string $created = '2024-01-15 10:00:00',
    ): Order {
        return new Order(
            id: $id,
            number: '#' . $id,
            status: OrderStatus::from($status),
            createdAt: new \DateTimeImmutable($created),
            total: Money::fromDecimal($total, $currency),
            lines: array(),
            customerId: 7,
            billingName: 'Ada Lovelace',
            billingEmail: 'ada@example.test',
        );
    }
}
