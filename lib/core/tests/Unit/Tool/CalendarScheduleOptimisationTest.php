<?php

/**
 * Tests for `optimize_schedule` and the schedule analysis.
 *
 * Proposal 164 section 164.87. WordPress returned literal `utilization_rate`
 * and `average_gap_minutes` values and ignored the requested goal, so these
 * cases exist to pin the measured behaviour: the counts come from the records,
 * capacity comes from the business-hours rules (or is reported as
 * unconfigured), and each goal produces a different recommendation derived
 * from the analysis.
 *
 * Every expected minute count is computed by hand from the fixture, not from
 * the implementation.
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
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Tool\Calendar\AppointmentDefinition;
use Nvoos\Core\Tool\Calendar\AvailabilityDefinition;
use Nvoos\Core\Tool\Calendar\CalendarScan;
use Nvoos\Core\Tool\Calendar\OptimizeScheduleTool;
use Nvoos\Core\Tool\Calendar\ScheduleAnalysis;
use Nvoos\Core\Tests\Support\FakeCalendarStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CalendarScheduleOptimisationTest extends TestCase
{
    private const CONTEXT = array('user_id' => 1);

    /** A Monday. */
    private const MONDAY = '2026-03-02';

    /** The Tuesday after it. */
    private const TUESDAY = '2026-03-03';

    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => $code, 'message' => $message, 'data' => $data),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'validation_failed', 'message' => $message, 'data' => $fields),
            )
        );

        $this->errors->method('forbidden')->willReturnCallback(
            static fn (string $message = 'Access denied.', array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'forbidden', 'message' => $message, 'data' => $data),
            )
        );
    }

    // ─── Validation ──────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidRanges(): array
    {
        return array(
            'no start'        => array(array('date_range_end' => self::TUESDAY), 'date_range_start'),
            'no end'          => array(array('date_range_start' => self::MONDAY), 'date_range_end'),
            'impossible day'  => array(array('date_range_start' => '2026-02-31', 'date_range_end' => self::TUESDAY), 'date_range_start'),
            'reversed'        => array(array('date_range_start' => self::TUESDAY, 'date_range_end' => self::MONDAY), 'date_range_end'),
            'range too long'  => array(array('date_range_start' => '2026-01-01', 'date_range_end' => '2027-01-02'), 'date_range_end'),
        );
    }

    /**
     * @param array<string, mixed> $arguments Tool arguments.
     */
    #[DataProvider('invalidRanges')]
    public function test_an_unusable_range_is_refused(array $arguments, string $field): void
    {
        $result = $this->tool(new FakeCalendarStore())->execute($arguments, self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey($field, $result['error']['data']);
    }

    public function test_the_range_ceiling_is_a_year(): void
    {
        $this->assertSame(366, ScheduleAnalysis::MAX_RANGE_DAYS);
        $this->assertSame(366, ScheduleAnalysis::rangeDays('2026-01-01', '2027-01-01'));
    }

    public function test_an_unknown_goal_is_refused_instead_of_defaulted(): void
    {
        $result = $this->tool(new FakeCalendarStore())->execute(
            array(
                'date_range_start'  => self::MONDAY,
                'date_range_end'    => self::TUESDAY,
                'optimization_goal' => 'grow_revenue',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('optimization_goal', $result['error']['data']);
    }

    public function test_optimize_requires_an_administrator(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->tool(new FakeCalendarStore())->execute(
            array('date_range_start' => self::MONDAY, 'date_range_end' => self::MONDAY),
            array('user_id' => 0)
        );
    }

    // ─── Measurement ─────────────────────────────────────────────────────

    public function test_an_empty_range_reports_zeroes_and_no_capacity(): void
    {
        $result = $this->tool(new FakeCalendarStore())->execute(
            array('date_range_start' => self::MONDAY, 'date_range_end' => self::MONDAY),
            self::CONTEXT
        );

        $analysis = $result['data']['analysis'];

        $this->assertSame(0, $analysis['total_appointments']);
        $this->assertSame(0, $analysis['total_booked_minutes']);
        $this->assertNull($analysis['utilization_rate']);
        $this->assertNull($analysis['average_gap_minutes']);
        $this->assertNull($analysis['largest_gap']);
        $this->assertSame('unconfigured', $analysis['capacity']['basis']);
        $this->assertNull($analysis['capacity']['minutes']);
        $this->assertNull($analysis['quietest_working_day']);
        $this->assertSame(array(), $analysis['days']);
        $this->assertSame('no_action', $result['data']['recommendations'][0]['type']);
    }

    public function test_gaps_are_measured_between_appointments(): void
    {
        $store = new FakeCalendarStore();
        $this->appointment($store, 1, self::MONDAY . ' 09:00:00', self::MONDAY . ' 10:00:00');
        $this->appointment($store, 2, self::MONDAY . ' 11:00:00', self::MONDAY . ' 11:30:00');

        $analysis = $this->analyse($store, self::MONDAY, self::MONDAY);

        $this->assertSame(2, $analysis['total_appointments']);
        $this->assertSame(90, $analysis['total_booked_minutes']);
        $this->assertSame(60.0, $analysis['average_gap_minutes']);
        $this->assertSame(
            array(
                'date'    => self::MONDAY,
                'from'    => self::MONDAY . ' 10:00:00',
                'to'      => self::MONDAY . ' 11:00:00',
                'minutes' => 60,
            ),
            $analysis['largest_gap']
        );
        $this->assertSame(1, $analysis['days'][0]['gaps']);
        $this->assertSame(60, $analysis['days'][0]['gap_minutes']);
    }

    public function test_overlapping_appointments_do_not_create_a_gap(): void
    {
        $store = new FakeCalendarStore();
        $this->appointment($store, 1, self::MONDAY . ' 09:00:00', self::MONDAY . ' 11:00:00');
        $this->appointment($store, 2, self::MONDAY . ' 10:00:00', self::MONDAY . ' 10:30:00');

        $analysis = $this->analyse($store, self::MONDAY, self::MONDAY);

        $this->assertSame(0, $analysis['days'][0]['gaps']);
        $this->assertNull($analysis['largest_gap']);
        $this->assertNull($analysis['average_gap_minutes']);
    }

    public function test_cancelled_and_no_show_appointments_are_excluded_and_reported(): void
    {
        $store = new FakeCalendarStore();
        $this->appointment($store, 1, self::MONDAY . ' 09:00:00', self::MONDAY . ' 10:00:00', 'confirmed');
        $this->appointment($store, 2, self::MONDAY . ' 10:00:00', self::MONDAY . ' 11:00:00', 'cancelled');
        $this->appointment($store, 3, self::MONDAY . ' 11:00:00', self::MONDAY . ' 12:00:00', 'no-show');

        $analysis = $this->analyse($store, self::MONDAY, self::MONDAY);

        $this->assertSame(1, $analysis['total_appointments']);
        $this->assertSame(60, $analysis['total_booked_minutes']);
        $this->assertSame(1, $analysis['excluded_appointments']['cancelled']);
        $this->assertSame(1, $analysis['excluded_appointments']['no_show']);
    }

    public function test_an_appointment_without_an_end_is_excluded_as_incomplete(): void
    {
        $store = new FakeCalendarStore();
        $this->appointment($store, 1, self::MONDAY . ' 09:00:00', '');

        $analysis = $this->analyse($store, self::MONDAY, self::MONDAY);

        $this->assertSame(0, $analysis['total_appointments']);
        $this->assertSame(1, $analysis['excluded_appointments']['incomplete']);
    }

    public function test_capacity_and_utilisation_come_from_business_hours(): void
    {
        $store = new FakeCalendarStore();
        $this->appointment($store, 1, self::MONDAY . ' 09:00:00', self::MONDAY . ' 10:00:00');

        $analysis = $this->analyse($store, self::MONDAY, '2026-03-06', self::weekdayRules());

        $this->assertSame(5, $analysis['range']['working_days']);
        $this->assertSame(2400, $analysis['capacity']['minutes']);
        $this->assertSame('business_hours', $analysis['capacity']['basis']);
        $this->assertSame(4, $analysis['capacity']['idle_working_days']);
        $this->assertSame(0.025, $analysis['utilization_rate']);
        $this->assertSame(self::TUESDAY, $analysis['quietest_working_day']['date']);
        $this->assertSame(480, $analysis['quietest_working_day']['free_minutes']);
    }

    public function test_bookings_outside_business_hours_explain_utilisation_above_one(): void
    {
        $store = new FakeCalendarStore();
        $this->appointment($store, 1, self::MONDAY . ' 08:00:00', self::MONDAY . ' 09:00:00');
        $this->appointment($store, 2, self::MONDAY . ' 09:00:00', self::MONDAY . ' 10:00:00');

        $analysis = $this->analyse(
            $store,
            self::MONDAY,
            self::MONDAY,
            array(
                'monday' => array(
                    'enabled'       => true,
                    'start_time'    => '09:00',
                    'end_time'      => '10:00',
                    'slot_duration' => 60,
                    'buffer_time'   => 0,
                ),
            )
        );

        $this->assertSame(60, $analysis['capacity']['minutes']);
        $this->assertSame(120, $analysis['total_booked_minutes']);
        $this->assertSame(2.0, $analysis['utilization_rate']);
        $this->assertSame(60, $analysis['outside_hours_booked_minutes']);
    }

    // ─── Recommendations ─────────────────────────────────────────────────

    public function test_minimize_gaps_names_the_day_with_the_largest_gap(): void
    {
        $store = new FakeCalendarStore();
        $this->appointment($store, 1, self::MONDAY . ' 09:00:00', self::MONDAY . ' 10:00:00');
        $this->appointment($store, 2, self::MONDAY . ' 10:30:00', self::MONDAY . ' 11:00:00');
        $this->appointment($store, 3, self::TUESDAY . ' 09:00:00', self::TUESDAY . ' 10:00:00');
        $this->appointment($store, 4, self::TUESDAY . ' 11:30:00', self::TUESDAY . ' 12:00:00');

        $result = $this->tool($store)->execute(
            array(
                'date_range_start'  => self::MONDAY,
                'date_range_end'    => self::TUESDAY,
                'optimization_goal' => 'minimize_gaps',
            ),
            self::CONTEXT
        );

        $this->assertSame(60.0, $result['data']['analysis']['average_gap_minutes']);

        $first = $result['data']['recommendations'][0];

        $this->assertSame('consolidate_gaps', $first['type']);
        $this->assertSame(self::TUESDAY, $first['date']);
        $this->assertSame(90, $first['gap_minutes']);
    }

    public function test_maximize_bookings_targets_the_quietest_open_day(): void
    {
        $store = new FakeCalendarStore();
        $this->appointment($store, 1, self::MONDAY . ' 09:00:00', self::MONDAY . ' 10:00:00');

        $result = $this->tool($store, self::weekdayRules())->execute(
            array(
                'date_range_start'  => self::MONDAY,
                'date_range_end'    => self::TUESDAY,
                'optimization_goal' => 'maximize_bookings',
            ),
            self::CONTEXT
        );

        $first = $result['data']['recommendations'][0];

        $this->assertSame('fill_capacity', $first['type']);
        $this->assertSame(self::TUESDAY, $first['date']);
        $this->assertSame(480, $first['free_minutes']);
        $this->assertSame(0, $first['booked_minutes']);
    }

    public function test_maximize_bookings_without_business_hours_is_honest(): void
    {
        $store = new FakeCalendarStore();
        $this->appointment($store, 1, self::MONDAY . ' 09:00:00', self::MONDAY . ' 10:00:00');

        $result = $this->tool($store)->execute(
            array(
                'date_range_start'  => self::MONDAY,
                'date_range_end'    => self::MONDAY,
                'optimization_goal' => 'maximize_bookings',
            ),
            self::CONTEXT
        );

        $this->assertSame('unconfigured', $result['data']['analysis']['capacity']['basis']);

        $first = $result['data']['recommendations'][0];

        $this->assertSame('fill_capacity', $first['type']);
        $this->assertNull($first['capacity_minutes']);
        $this->assertNull($first['free_minutes']);
        $this->assertStringContainsString('Configure business hours', $first['description']);
    }

    public function test_balance_load_reports_the_widest_imbalance(): void
    {
        $store = new FakeCalendarStore();
        $this->appointment($store, 1, self::MONDAY . ' 09:00:00', self::MONDAY . ' 10:00:00');

        for ($hour = 9; $hour < 14; $hour++) {
            $this->appointment(
                $store,
                10 + $hour,
                sprintf('%s %02d:00:00', self::TUESDAY, $hour),
                sprintf('%s %02d:00:00', self::TUESDAY, $hour + 1)
            );
        }

        $result = $this->tool($store)->execute(
            array(
                'date_range_start'  => self::MONDAY,
                'date_range_end'    => self::TUESDAY,
                'optimization_goal' => 'balance_load',
            ),
            self::CONTEXT
        );

        $first = $result['data']['recommendations'][0];

        $this->assertSame('rebalance', $first['type']);
        $this->assertSame(self::TUESDAY, $first['from_date']);
        $this->assertSame(self::MONDAY, $first['to_date']);
        $this->assertSame(120, $first['minutes_to_move']);
        $this->assertSame(2, $first['appointments_to_move']);
    }

    public function test_balance_load_is_quiet_when_the_days_are_close(): void
    {
        $store = new FakeCalendarStore();
        $this->appointment($store, 1, self::MONDAY . ' 09:00:00', self::MONDAY . ' 10:00:00');
        $this->appointment($store, 2, self::TUESDAY . ' 09:00:00', self::TUESDAY . ' 10:00:00');

        $result = $this->tool($store)->execute(
            array(
                'date_range_start'  => self::MONDAY,
                'date_range_end'    => self::TUESDAY,
                'optimization_goal' => 'balance_load',
            ),
            self::CONTEXT
        );

        $this->assertSame('no_action', $result['data']['recommendations'][0]['type']);
    }

    // ─── Scan honesty ────────────────────────────────────────────────────

    public function test_a_truncated_scan_is_reported(): void
    {
        $store = new FakeCalendarStore();

        for ($i = 1; $i <= CalendarScan::MAX_SCAN + 1; $i++) {
            $this->appointment(
                $store,
                $i,
                self::MONDAY . ' 09:00:00',
                self::MONDAY . ' 09:15:00'
            );
        }

        $analysis = $this->analyse($store, self::MONDAY, self::MONDAY);

        $this->assertSame(1001, $analysis['scan']['matched']);
        $this->assertSame(1000, $analysis['scan']['scanned']);
        $this->assertTrue($analysis['scan']['truncated']);
        $this->assertSame(1000, $analysis['total_appointments']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function tool(FakeCalendarStore $store, array $rules = array()): OptimizeScheduleTool
    {
        return new OptimizeScheduleTool($this->errors, $store, $this->settingsStore($rules));
    }

    /**
     * @param array<string, mixed> $rules Stored business hours.
     */
    private function analyse(FakeCalendarStore $store, string $start, string $end, array $rules = array()): array
    {
        $result = $this->tool($store, $rules)->execute(
            array('date_range_start' => $start, 'date_range_end' => $end),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);

        return $result['data']['analysis'];
    }

    private function appointment(
        FakeCalendarStore $store,
        int $id,
        string $start,
        string $end,
        string $status = 'confirmed',
    ): void {
        $store->seed(
            $id,
            AppointmentDefinition::TYPE,
            'Appointment',
            array(
                '_start_time' => $start,
                '_end_time'   => $end,
                '_status'     => $status,
            )
        );
    }

    /**
     * @param array<string, mixed> $rules Stored business hours.
     */
    private function settingsStore(array $rules): SettingsStoreInterface
    {
        $store = $this->createMock(SettingsStoreInterface::class);

        $store->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => AvailabilityDefinition::SETTINGS_KEY === $key
                ? $rules
                : $default
        );

        $store->method('all')->willReturn(array(AvailabilityDefinition::SETTINGS_KEY => $rules));

        return $store;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function weekdayRules(): array
    {
        $rules = array();

        foreach (array('monday', 'tuesday', 'wednesday', 'thursday', 'friday') as $day) {
            $rules[ $day ] = array(
                'enabled'       => true,
                'start_time'    => '09:00',
                'end_time'      => '17:00',
                'slot_duration' => 60,
                'buffer_time'   => 0,
            );
        }

        return $rules;
    }
}
