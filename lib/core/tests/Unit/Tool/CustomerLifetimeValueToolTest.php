<?php

/**
 * Lifetime value, and the projection defect it corrects.
 *
 * Proposal 164 section 164.183. Pinned here:
 *
 * - the projection divides by the window that was observed, not by the
 *   customer's own span floored at a month, so a first order three days old is
 *   projected at its own value rather than twelve times it;
 * - `total_clv` sums only the components the caller asked for, while historical
 *   spend is always reported as the basis a projection is derived from;
 * - the selector is the account id, and `email` is refused by name;
 * - one of the two selectors is required, and both together is a field error;
 * - a specific account with no paid orders in the window is a not-found rather
 *   than an empty success;
 * - churn bands read from the days since the last paid order.
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
use Nvoos\Core\Tool\Ecommerce\CustomerLifetimeValueTool;
use PHPUnit\Framework\TestCase;

final class CustomerLifetimeValueToolTest extends TestCase
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

        $this->errors->method('notFound')->willReturnCallback(
            static fn (string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'not_found', 'message' => $message, 'data' => $data),
            )
        );
    }

    // ── metadata and guards ──────────────────────────────────

    public function test_the_tool_metadata(): void
    {
        $tool = $this->tool($this->store());

        $this->assertSame('customer_lifetime_value', $tool->getSlug());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());

        $schema = $tool->getParametersSchema();

        $this->assertSame(array('currency'), $schema['required']);
        $this->assertSame(array('historical', 'predictive', 'both'), $schema['properties']['calculation_type']['enum']);
    }

    public function test_it_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->tool($this->store())->execute(array('currency' => 'USD', 'customer_id' => 1), array());
    }

    public function test_the_currency_is_required(): void
    {
        $result = $this->execute(array('customer_id' => 1), $this->store());

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('currency', $result['error']['data']);
    }

    // ── selectors ────────────────────────────────────────────

    public function test_one_selector_is_required(): void
    {
        $result = $this->execute(array('currency' => 'USD'), $this->store());

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('customer_id', $result['error']['data']);
    }

    public function test_both_selectors_together_are_refused(): void
    {
        $result = $this->execute(
            array('currency' => 'USD', 'customer_id' => 1, 'include_all' => true),
            $this->store()
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('include_all', $result['error']['data']);
    }

    public function test_an_email_selector_is_refused_by_name(): void
    {
        $result = $this->execute(
            array('currency' => 'USD', 'email' => 'ada@example.test'),
            $this->store()
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('email', $result['error']['data']);
    }

    public function test_an_unknown_argument_is_refused(): void
    {
        $result = $this->execute(
            array('currency' => 'USD', 'customer_id' => 1, 'order_by' => 'clv'),
            $this->store()
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('order_by', $result['error']['data']);
    }

    // ── bounds ───────────────────────────────────────────────

    public function test_an_unknown_calculation_type_is_refused(): void
    {
        $result = $this->execute(
            array('currency' => 'USD', 'customer_id' => 1, 'calculation_type' => 'psychic'),
            $this->store()
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('calculation_type', $result['error']['data']);
    }

    public function test_the_windows_are_bounded(): void
    {
        $cases = array(
            array('timeframe_months' => 0, 'key' => 'timeframe_months'),
            array('timeframe_months' => 61, 'key' => 'timeframe_months'),
            array('prediction_months' => 2, 'key' => 'prediction_months'),
            array('prediction_months' => 37, 'key' => 'prediction_months'),
            array('top_count' => 0, 'key' => 'top_count'),
            array('top_count' => 1001, 'key' => 'top_count'),
        );

        foreach ($cases as $case) {
            $key = $case['key'];
            unset($case['key']);

            $result = $this->execute(
                array('currency' => 'USD', 'customer_id' => 1) + $case,
                $this->store()
            );

            $this->assertFalse($result['success'], $key . ' should be refused');
            $this->assertArrayHasKey($key, $result['error']['data']);
        }
    }

    // ── the calculation ──────────────────────────────────────

    public function test_historical_reports_spend_and_no_projection(): void
    {
        $store = $this->store(
            $this->order(1, 1, '100.00', $this->daysAgo(10)),
            $this->order(2, 1, '200.00', $this->daysAgo(20))
        );

        $row = $this->execute(
            array('currency' => 'USD', 'customer_id' => 1, 'calculation_type' => 'historical'),
            $store
        )['data']['customers'][0];

        $this->assertSame(300.0, $row['historical_clv']);
        $this->assertSame(0.0, $row['predictive_clv']);
        $this->assertSame(300.0, $row['total_clv']);
        $this->assertSame(150.0, $row['avg_order_value']);
        $this->assertSame(2, $row['order_count']);
    }

    public function test_a_single_order_is_not_multiplied_by_the_prediction_window(): void
    {
        $store = $this->store($this->order(1, 1, '100.00', $this->daysAgo(3)));

        $row = $this->execute(
            array('currency' => 'USD', 'customer_id' => 1),
            $store
        )['data']['customers'][0];

        // Twelve months observed, twelve months projected, one order of 100:
        // the reference divided by a one-month floor and answered 1,300 total.
        $this->assertSame(100.0, $row['historical_clv']);
        $this->assertSame(100.0, $row['predictive_clv']);
        $this->assertSame(200.0, $row['total_clv']);
        $this->assertSame(8.33, $row['monthly_clv_rate']);
    }

    public function test_predictive_totals_only_the_projection_but_still_reports_the_basis(): void
    {
        $store = $this->store($this->order(1, 1, '120.00', $this->daysAgo(10)));

        $row = $this->execute(
            array('currency' => 'USD', 'customer_id' => 1, 'calculation_type' => 'predictive'),
            $store
        )['data']['customers'][0];

        $this->assertSame(120.0, $row['historical_clv']);
        $this->assertSame(120.0, $row['predictive_clv']);
        $this->assertSame(120.0, $row['total_clv']);
    }

    public function test_the_projection_scales_with_the_prediction_window(): void
    {
        $store = $this->store($this->order(1, 1, '120.00', $this->daysAgo(10)));

        $row = $this->execute(
            array('currency' => 'USD', 'customer_id' => 1, 'prediction_months' => 6),
            $store
        )['data']['customers'][0];

        $this->assertSame(60.0, $row['predictive_clv']);
        $this->assertSame(180.0, $row['total_clv']);
    }

    public function test_churn_bands_follow_the_days_since_the_last_order(): void
    {
        $store = $this->store(
            $this->order(1, 1, '100.00', $this->daysAgo(5)),
            $this->order(2, 2, '100.00', $this->daysAgo(45)),
            $this->order(3, 3, '100.00', $this->daysAgo(120))
        );

        $result = $this->execute(array('currency' => 'USD', 'include_all' => true), $store);

        $bands = array();

        foreach ($result['data']['customers'] as $row) {
            $bands[ $row['customer_id'] ] = $row['churn_risk'];
        }

        $this->assertSame('low', $bands[1]);
        $this->assertSame('medium', $bands[2]);
        $this->assertSame('high', $bands[3]);
    }

    // ── selection, window, absence ───────────────────────────

    public function test_include_all_reports_the_biggest_spenders_up_to_the_limit(): void
    {
        $store = $this->store(
            $this->order(1, 1, '100.00', $this->daysAgo(5)),
            $this->order(2, 2, '300.00', $this->daysAgo(5)),
            $this->order(3, 3, '200.00', $this->daysAgo(5))
        );

        $result = $this->execute(
            array('currency' => 'USD', 'include_all' => true, 'top_count' => 2),
            $store
        );

        $this->assertSame(2, $result['data']['total_customers']);
        $this->assertSame(array(2, 3), array_column($result['data']['customers'], 'customer_id'));
    }

    public function test_orders_outside_the_timeframe_are_excluded(): void
    {
        $store = $this->store(
            $this->order(1, 1, '50.00', $this->daysAgo(10)),
            $this->order(2, 1, '999.00', $this->daysAgo(400))
        );

        $row = $this->execute(
            array('currency' => 'USD', 'customer_id' => 1),
            $store
        )['data']['customers'][0];

        $this->assertSame(50.0, $row['historical_clv']);
        $this->assertSame(1, $row['order_count']);
    }

    public function test_an_account_with_no_paid_orders_is_not_found(): void
    {
        $result = $this->execute(
            array('currency' => 'USD', 'customer_id' => 9),
            $this->store()
        );

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['error']['code']);
    }

    public function test_an_empty_include_all_is_a_success_with_nobody_in_it(): void
    {
        $result = $this->execute(array('currency' => 'USD', 'include_all' => true), $this->store());

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['total_customers']);
        $this->assertSame(array(), $result['data']['customers']);
    }

    public function test_another_currency_is_not_aggregated(): void
    {
        $store = $this->store(
            $this->order(1, 1, '100.00', $this->daysAgo(5)),
            $this->order(2, 1, '500.00', $this->daysAgo(5), 'EUR')
        );

        $row = $this->execute(
            array('currency' => 'USD', 'customer_id' => 1),
            $store
        )['data']['customers'][0];

        $this->assertSame(100.0, $row['historical_clv']);
    }

    // ── fixtures ─────────────────────────────────────────────

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

    private function tool(OrderStoreFake $store): CustomerLifetimeValueTool
    {
        return new CustomerLifetimeValueTool($this->errors, $store);
    }

    private function order(int $id, int $customerId, string $total, string $created, string $currency = 'USD'): Order
    {
        return new Order(
            id: $id,
            number: '#' . $id,
            status: OrderStatus::Completed,
            createdAt: new \DateTimeImmutable($created),
            total: Money::fromDecimal($total, $currency),
            lines: array(),
            customerId: $customerId,
            billingName: 'Customer ' . $customerId,
            billingEmail: 'c' . $customerId . '@example.test',
        );
    }

    /**
     * Midnight, that many days ago, so `days_since_last` is exact.
     */
    private function daysAgo(int $days): string
    {
        return (new \DateTimeImmutable('today'))->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
    }
}
