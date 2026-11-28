<?php

/**
 * Customer segmentation, and the reference defects it corrects.
 *
 * Proposal 164 section 164.182. Pinned here:
 *
 * - geographic and product-preference segments are refused by name, with their
 *   prerequisites, rather than answered with empty buckets;
 * - the window is the top-level date pair, and `custom_criteria`' own dates are
 *   refused because the reference accepted them and never read them;
 * - overlapping or duplicate value tiers are a field error rather than resolved
 *   by array order;
 * - a segment reports its true count and lists at most 25 members;
 * - empty segments are still reported, so "nobody is at risk" is an answer.
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
use Nvoos\Core\Tool\Ecommerce\SegmentCustomersTool;
use PHPUnit\Framework\TestCase;

final class SegmentCustomersToolTest extends TestCase
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

        $this->assertSame('segment_customers', $tool->getSlug());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());

        $schema = $tool->getParametersSchema();

        $this->assertSame(array('currency'), $schema['required']);
        $this->assertSame(array('rfm', 'value_based', 'custom'), $schema['properties']['segmentation_type']['enum']);
    }

    public function test_it_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->tool($this->store())->execute(array('currency' => 'USD'), array());
    }

    public function test_the_currency_is_required(): void
    {
        $result = $this->execute(array(), $this->store());

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('currency', $result['error']['data']);
    }

    // ── refused types ────────────────────────────────────────

    public function test_the_geographic_type_is_refused_by_name(): void
    {
        $result = $this->execute(
            array('currency' => 'USD', 'segmentation_type' => 'geographic'),
            $this->store()
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('segmentation_type', $result['error']['data']);
    }

    public function test_the_product_preference_type_is_refused_by_name(): void
    {
        $result = $this->execute(
            array('currency' => 'USD', 'segmentation_type' => 'product_preference'),
            $this->store()
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('segmentation_type', $result['error']['data']);
    }

    public function test_an_unknown_type_is_refused(): void
    {
        $result = $this->execute(
            array('currency' => 'USD', 'segmentation_type' => 'astrology'),
            $this->store()
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('segmentation_type', $result['error']['data']);
    }

    public function test_arguments_only_the_refused_types_use_are_refused(): void
    {
        foreach (array('geographic_field', 'product_category') as $argument) {
            $result = $this->execute(
                array('currency' => 'USD', $argument => 'GB'),
                $this->store()
            );

            $this->assertFalse($result['success'], $argument . ' should be refused');
            $this->assertArrayHasKey($argument, $result['error']['data']);
        }
    }

    // ── rfm ──────────────────────────────────────────────────

    public function test_rfm_places_each_customer_in_one_segment(): void
    {
        $store = $this->store(
            $this->order(1, 1, '100.00', $this->daysAgo(5)),
            $this->order(2, 1, '200.00', $this->daysAgo(6)),
            $this->order(3, 2, '150.00', $this->daysAgo(100)),
            $this->order(4, 2, '150.00', $this->daysAgo(101)),
            $this->order(5, 3, '50.00', $this->daysAgo(5)),
            $this->order(6, 4, '25.00', $this->daysAgo(100)),
            $this->order(7, 4, '25.00', $this->daysAgo(101)),
            $this->order(8, 5, '50.00', $this->daysAgo(100))
        );

        $segments = $this->execute(array('currency' => 'USD'), $store)['data']['segments'];

        $this->assertSame(array(1), $this->ids($segments['champions']));
        $this->assertSame(array(2), $this->ids($segments['loyal']));
        $this->assertSame(array(3), $this->ids($segments['potential']));
        $this->assertSame(array(4), $this->ids($segments['at_risk']));
        $this->assertSame(array(5), $this->ids($segments['hibernating']));
    }

    public function test_each_listed_customer_reports_days_since_last(): void
    {
        $store = $this->store($this->order(1, 1, '500.00', $this->daysAgo(5)));

        $segments = $this->execute(array('currency' => 'USD'), $store)['data']['segments'];

        $this->assertSame(5, $segments['potential']['customers'][0]['days_since_last']);
    }

    public function test_the_recency_threshold_is_honoured(): void
    {
        $store = $this->store(
            $this->order(1, 1, '500.00', $this->daysAgo(20)),
            $this->order(2, 1, '500.00', $this->daysAgo(21))
        );

        $wide = $this->execute(array('currency' => 'USD', 'rfm_segments' => array('recency_days' => 30)), $store);
        $this->assertSame(1, $wide['data']['segments']['champions']['count']);

        $narrow = $this->execute(array('currency' => 'USD', 'rfm_segments' => array('recency_days' => 10)), $store);
        $this->assertSame(0, $narrow['data']['segments']['champions']['count']);
        $this->assertSame(1, $narrow['data']['segments']['loyal']['count']);
    }

    public function test_empty_segments_are_still_reported(): void
    {
        $store = $this->store($this->order(1, 1, '500.00', $this->daysAgo(1)));

        $segments = $this->execute(array('currency' => 'USD'), $store)['data']['segments'];

        $this->assertSame(0, $segments['at_risk']['count']);
        $this->assertSame(array(), $segments['at_risk']['customers']);
        $this->assertFalse($segments['at_risk']['truncated']);
    }

    public function test_an_invalid_rfm_threshold_is_refused(): void
    {
        $cases = array(
            array('recency_days' => -1),
            array('frequency_min' => 0),
            array('monetary_min' => -5),
            array('colour' => 'blue'),
        );

        foreach ($cases as $rfm) {
            $result = $this->execute(
                array('currency' => 'USD', 'rfm_segments' => $rfm),
                $this->store()
            );

            $this->assertFalse($result['success']);
            $this->assertArrayHasKey('rfm_segments', $result['error']['data']);
        }
    }

    // ── value tiers ──────────────────────────────────────────

    public function test_value_tiers_split_by_spend(): void
    {
        $store = $this->store(
            $this->order(1, 1, '600.00', $this->daysAgo(10)),
            $this->order(2, 2, '200.00', $this->daysAgo(10)),
            $this->order(3, 3, '50.00', $this->daysAgo(10))
        );

        $segments = $this->execute(
            array('currency' => 'USD', 'segmentation_type' => 'value_based'),
            $store
        )['data']['segments'];

        $this->assertSame(array(1), $this->ids($segments['High Value']));
        $this->assertSame(array(2), $this->ids($segments['Medium Value']));
        $this->assertSame(array(3), $this->ids($segments['Low Value']));
    }

    public function test_custom_tiers_are_used(): void
    {
        $store = $this->store(
            $this->order(1, 1, '600.00', $this->daysAgo(10)),
            $this->order(2, 2, '200.00', $this->daysAgo(10))
        );

        $segments = $this->execute(
            array(
                'currency'          => 'USD',
                'segmentation_type' => 'value_based',
                'value_tiers'       => array(array('name' => 'Whale', 'min' => 500)),
            ),
            $store
        )['data']['segments'];

        $this->assertSame(array('Whale'), array_keys($segments));
        $this->assertSame(array(1), $this->ids($segments['Whale']));
        $this->assertNull($segments['Whale']['max']);
    }

    public function test_overlapping_tiers_are_refused(): void
    {
        $result = $this->execute(
            array(
                'currency'          => 'USD',
                'segmentation_type' => 'value_based',
                'value_tiers'       => array(
                    array('name' => 'A', 'min' => 0, 'max' => 500),
                    array('name' => 'B', 'min' => 100, 'max' => 900),
                ),
            ),
            $this->store()
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('value_tiers', $result['error']['data']);
    }

    public function test_a_bad_tier_is_refused(): void
    {
        $cases = array(
            array(array('name' => 'A'), array('name' => 'A', 'min' => 0)),
            array(array('name' => 'A', 'min' => 0), array('name' => 'A', 'min' => 100)),
            array(array('name' => 'A', 'min' => 'lots')),
            array(array('name' => 'A', 'min' => 100, 'max' => 50)),
        );

        foreach ($cases as $tiers) {
            $result = $this->execute(
                array('currency' => 'USD', 'segmentation_type' => 'value_based', 'value_tiers' => $tiers),
                $this->store()
            );

            $this->assertFalse($result['success']);
            $this->assertArrayHasKey('value_tiers', $result['error']['data']);
        }
    }

    // ── custom criteria ──────────────────────────────────────

    public function test_custom_bounds_are_anded(): void
    {
        $store = $this->store(
            $this->order(1, 1, '100.00', $this->daysAgo(10)),
            $this->order(2, 1, '100.00', $this->daysAgo(20)),
            $this->order(3, 2, '500.00', $this->daysAgo(10))
        );

        $result = $this->execute(
            array(
                'currency'        => 'USD',
                'segmentation_type' => 'custom',
                'custom_criteria' => array('min_orders' => 2, 'max_spent' => 300),
            ),
            $store
        );

        $this->assertSame(array(1), $this->ids($result['data']['segments']['matching']));
    }

    public function test_custom_criteria_dates_are_refused(): void
    {
        $result = $this->execute(
            array(
                'currency'          => 'USD',
                'segmentation_type' => 'custom',
                'custom_criteria'   => array('date_from' => '2024-01-01'),
            ),
            $this->store()
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('custom_criteria', $result['error']['data']);
    }

    public function test_a_reversed_custom_bound_is_refused(): void
    {
        $result = $this->execute(
            array(
                'currency'          => 'USD',
                'segmentation_type' => 'custom',
                'custom_criteria'   => array('min_orders' => 5, 'max_orders' => 2),
            ),
            $this->store()
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('custom_criteria', $result['error']['data']);
    }

    // ── window, limit, truncation ────────────────────────────

    public function test_the_window_bounds_the_orders_considered(): void
    {
        $store = $this->store(
            $this->order(1, 1, '100.00', '2024-01-10 10:00:00'),
            $this->order(2, 2, '100.00', '2024-03-10 10:00:00')
        );

        $result = $this->execute(
            array(
                'currency'          => 'USD',
                'segmentation_type' => 'custom',
                'date_from'         => '2024-01-01',
                'date_to'           => '2024-01-31',
            ),
            $store
        );

        $this->assertSame(1, $result['data']['analysed_customers']);
        $this->assertSame(array(1), $this->ids($result['data']['segments']['matching']));
        $this->assertSame('2024-01-31', $result['data']['window']['to']);
    }

    public function test_the_limit_bounds_how_many_are_analysed(): void
    {
        $store = $this->store(
            $this->order(1, 1, '300.00', $this->daysAgo(5)),
            $this->order(2, 2, '200.00', $this->daysAgo(5)),
            $this->order(3, 3, '100.00', $this->daysAgo(5))
        );

        $result = $this->execute(array('currency' => 'USD', 'limit' => 2), $store);

        $this->assertSame(2, $result['data']['analysed_customers']);
    }

    public function test_a_limit_outside_the_bounds_is_refused(): void
    {
        foreach (array(0, 10001) as $limit) {
            $result = $this->execute(array('currency' => 'USD', 'limit' => $limit), $this->store());

            $this->assertFalse($result['success'], (string) $limit . ' should be refused');
            $this->assertArrayHasKey('limit', $result['error']['data']);
        }
    }

    public function test_a_segment_lists_at_most_twenty_five_members(): void
    {
        $orders = array();

        for ($customer = 1; $customer <= 30; $customer++) {
            $orders[] = $this->order($customer, $customer, '10.00', $this->daysAgo(5));
        }

        $segments = $this->execute(array('currency' => 'USD'), $this->store(...$orders))['data']['segments'];

        $this->assertSame(30, $segments['potential']['count']);
        $this->assertCount(25, $segments['potential']['customers']);
        $this->assertTrue($segments['potential']['truncated']);
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

    private function tool(OrderStoreFake $store): SegmentCustomersTool
    {
        return new SegmentCustomersTool($this->errors, $store);
    }

    private function order(int $id, int $customerId, string $total, string $created): Order
    {
        return new Order(
            id: $id,
            number: '#' . $id,
            status: OrderStatus::Completed,
            createdAt: new \DateTimeImmutable($created),
            total: Money::fromDecimal($total, 'USD'),
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

    /**
     * @param array<string, mixed> $segment A built segment.
     *
     * @return list<int>
     */
    private function ids(array $segment): array
    {
        return array_map(
            static fn (array $customer): int => (int) $customer['customer_id'],
            (array) ($segment['customers'] ?? array())
        );
    }
}
