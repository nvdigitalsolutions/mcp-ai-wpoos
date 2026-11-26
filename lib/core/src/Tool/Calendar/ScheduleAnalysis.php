<?php

/**
 * Schedule analysis: what the appointment book actually looks like.
 *
 * Proposal 164 section 164.87. WordPress's `optimize_schedule` returned
 * `utilization_rate: 0.75` and `average_gap_minutes: 30` as literals, whatever
 * the calendar contained, and then ignored both the analysis and the requested
 * goal when building its one fixed recommendation. A stub is not a spec, so the
 * port implements the measurement instead of reproducing the numbers.
 *
 * ## What is measured
 *
 * - **Counted appointments** are those whose stored `_status` is not
 *   `cancelled` or `no-show`. A missing status reads as `confirmed`, which is
 *   what the appointment writer defaults to.
 * - **`booked_minutes`** is the sum of appointment durations. Overlapping
 *   historical rows therefore count twice: this is booked work, not unique
 *   occupied wall-clock time. Gaps are computed against the furthest end seen
 *   so far, so an overlap cannot manufacture a gap.
 * - **`capacity`** comes from the `business_hours` rules the availability slice
 *   already owns. With no rules configured there is no capacity to divide by,
 *   so `utilization_rate` is `null` and `capacity.basis` says
 *   `unconfigured` — the honest answer, and the one WordPress never gave.
 * - **`outside_hours_booked_minutes`** explains a utilisation above `1.0`:
 *   bookings that fall outside the configured windows still occupy time.
 *
 * ## Exclusions are reported, not silently dropped
 *
 * Cancelled, no-show and end-before-start rows are counted in
 * `excluded_appointments`, so a caller can reconcile the range total with the
 * record count. An incomplete row is not assigned a default duration: inventing
 * an hour would change the very figure the caller is asking about.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Calendar;

use Nvoos\Core\Domain\Entity\ContentItem;

final class ScheduleAnalysis
{
    /**
     * The longest analysable range, in days.
     *
     * A year including a leap day. Longer ranges are refused by the tool rather
     * than silently sampled: the scan ceiling means a busy year is truncated
     * anyway, and a "year" that quietly means "the first thousand rows" would
     * make every rate in the payload wrong without saying so.
     */
    public const MAX_RANGE_DAYS = 366;

    /** Statuses whose time is not booked work. */
    public const EXCLUDED_STATUSES = array('cancelled', 'no-show');

    /**
     * Build the analysis.
     *
     * @param list<ContentItem>                    $items Appointments in range.
     * @param array<string, array<string, mixed>>  $rules Normalised business hours.
     * @param string                               $start Range start, `Y-m-d`.
     * @param string                               $end   Range end, `Y-m-d`.
     *
     * @return array<string, mixed>
     */
    public static function build(array $items, array $rules, string $start, string $end): array
    {
        $dates      = self::dates($start, $end);
        $configured = array() !== $rules;

        $capacityByDate = array();
        $capacity       = 0;
        $workingDays    = 0;

        foreach ($dates as $date) {
            $minutes = self::dayCapacity($rules, $date);

            $capacityByDate[ $date ] = $minutes;

            if (null !== $minutes && $minutes > 0) {
                $capacity += $minutes;
                ++$workingDays;
            }
        }

        $rows     = array();
        $excluded = array('cancelled' => 0, 'no_show' => 0, 'incomplete' => 0);

        foreach ($items as $item) {
            if (! $item instanceof ContentItem) {
                continue;
            }

            $rowStart = self::metaString($item->meta[ '_start_time' ] ?? null);
            $rowEnd   = self::metaString($item->meta[ '_end_time' ] ?? null);

            if ('' === $rowStart) {
                ++$excluded['incomplete'];
                continue;
            }

            $date = substr($rowStart, 0, 10);

            if (! array_key_exists($date, $capacityByDate)) {
                continue;
            }

            $from = AppointmentDefinition::parseTime($rowStart);
            $to   = AppointmentDefinition::parseTime($rowEnd);

            if (null === $from || null === $to || $to <= $from) {
                ++$excluded['incomplete'];
                continue;
            }

            $status = AppointmentDefinition::status($item->meta[ '_status' ] ?? null);

            if (in_array($status, self::EXCLUDED_STATUSES, true)) {
                if ('cancelled' === $status) {
                    ++$excluded['cancelled'];
                } else {
                    ++$excluded['no_show'];
                }

                continue;
            }

            $rows[ $date ][] = array(
                'start'   => $rowStart,
                'end'     => $rowEnd,
                'minutes' => AppointmentDefinition::minutesBetween($rowStart, $rowEnd),
            );
        }

        $days             = array();
        $totalCount       = 0;
        $totalBooked      = 0;
        $outsideHours     = 0;
        $gapCount         = 0;
        $gapMinutes       = 0;
        $largestGap       = null;

        foreach ($dates as $date) {
            if (empty($rows[ $date ])) {
                continue;
            }

            $dayRows = $rows[ $date ];

            usort(
                $dayRows,
                static fn (array $a, array $b): int => $a['start'] <=> $b['start']
            );

            $booked     = 0;
            $gaps       = 0;
            $dayGaps    = 0;
            $furthestEnd = '';

            foreach ($dayRows as $row) {
                $booked += $row['minutes'];

                if (! AvailabilityDefinition::withinHours($rules, $row['start'], $row['end'])) {
                    $outsideHours += $row['minutes'];
                }

                if ('' !== $furthestEnd && $row['start'] > $furthestEnd) {
                    $gap = AppointmentDefinition::minutesBetween($furthestEnd, $row['start']);

                    $candidate = array(
                        'date'    => $date,
                        'from'    => $furthestEnd,
                        'to'      => $row['start'],
                        'minutes' => $gap,
                    );

                    ++$gaps;
                    $dayGaps += $gap;
                    ++$gapCount;
                    $gapMinutes += $gap;

                    if (null === $largestGap || $gap > $largestGap['minutes']) {
                        $largestGap = $candidate;
                    }
                }

                if ('' === $furthestEnd || $row['end'] > $furthestEnd) {
                    $furthestEnd = $row['end'];
                }
            }

            $dayCapacity = $capacityByDate[ $date ];

            $days[] = array(
                'date'             => $date,
                'appointments'     => count($dayRows),
                'booked_minutes'   => $booked,
                'capacity_minutes' => $dayCapacity,
                'utilization_rate' => null !== $dayCapacity && $dayCapacity > 0
                    ? round($booked / $dayCapacity, 4)
                    : null,
                'gaps'             => $gaps,
                'gap_minutes'      => $dayGaps,
            );

            $totalCount += count($dayRows);
            $totalBooked += $booked;
        }

        $idleWorkingDays = 0;

        foreach ($dates as $date) {
            $dayCapacity = $capacityByDate[ $date ];

            if (null !== $dayCapacity && $dayCapacity > 0 && empty($rows[ $date ])) {
                ++$idleWorkingDays;
            }
        }

        return array(
            'range'                         => array(
                'start'        => $start,
                'end'          => $end,
                'days'         => count($dates),
                'working_days' => $workingDays,
            ),
            'total_appointments'            => $totalCount,
            'excluded_appointments'         => $excluded,
            'total_booked_minutes'          => $totalBooked,
            'average_appointment_minutes'   => $totalCount > 0
                ? round($totalBooked / $totalCount, 1)
                : null,
            'appointments_per_day'          => array() !== $dates
                ? round($totalCount / count($dates), 2)
                : null,
            'capacity'                      => array(
                'basis'             => $configured ? 'business_hours' : 'unconfigured',
                'minutes'           => $configured ? $capacity : null,
                'working_days'      => $workingDays,
                'idle_working_days' => $idleWorkingDays,
            ),
            'utilization_rate'              => $configured && $capacity > 0
                ? round($totalBooked / $capacity, 4)
                : null,
            'outside_hours_booked_minutes'  => $outsideHours,
            'average_gap_minutes'           => $gapCount > 0 ? round($gapMinutes / $gapCount, 1) : null,
            'largest_gap'                   => $largestGap,
            'busiest_day'                   => self::busiest($days),
            'quietest_working_day'          => self::quietest($dates, $capacityByDate, $rows),
            'days'                          => $days,
        );
    }

    /**
     * The inclusive day count of a range, uncapped.
     */
    public static function rangeDays(string $start, string $end): int
    {
        $from = AppointmentDefinition::parseTime($start . ' 00:00:00');
        $to   = AppointmentDefinition::parseTime($end . ' 00:00:00');

        if (null === $from || null === $to || $to < $from) {
            return 0;
        }

        return (int) $from->diff($to)->days + 1;
    }

    /**
     * The dates in a range, ascending and capped at `MAX_RANGE_DAYS`.
     *
     * @return list<string>
     */
    private static function dates(string $start, string $end): array
    {
        $from = AppointmentDefinition::parseTime($start . ' 00:00:00');
        $to   = AppointmentDefinition::parseTime($end . ' 00:00:00');

        if (null === $from || null === $to || $to < $from) {
            return array();
        }

        $dates  = array();
        $cursor = $from;

        while ($cursor <= $to && count($dates) < self::MAX_RANGE_DAYS) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor  = $cursor->modify('+1 day');
        }

        return $dates;
    }

    /**
     * A day's configured capacity in minutes.
     *
     * `null` means the rules are unconfigured and the day has no measurable
     * capacity; `0` means the day is configured but closed.
     *
     * @param array<string, array<string, mixed>> $rules Normalised business hours.
     */
    private static function dayCapacity(array $rules, string $date): ?int
    {
        if (array() === $rules) {
            return null;
        }

        $weekday = AvailabilityDefinition::weekday($date);

        if (null === $weekday) {
            return 0;
        }

        $rule = AvailabilityDefinition::slotRule($rules, $weekday);

        if (null === $rule) {
            return 0;
        }

        $open  = self::clockMinutes((string) $rule['start_time']);
        $close = self::clockMinutes((string) $rule['end_time']);

        if (null === $open || null === $close) {
            return 0;
        }

        return max(0, $close - $open);
    }

    /**
     * Minutes since midnight for a normalised `HH:MM` clock value.
     */
    private static function clockMinutes(string $clock): ?int
    {
        $parts = explode(':', $clock);

        if (2 !== count($parts) || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            return null;
        }

        return ( (int) $parts[0] ) * 60 + (int) $parts[1];
    }

    /**
     * The day with the most booked minutes; ties keep the earlier date.
     *
     * @param list<array<string, mixed>> $days Day rows, date-ascending.
     *
     * @return array<string, mixed>|null
     */
    private static function busiest(array $days): ?array
    {
        $busiest = null;

        foreach ($days as $day) {
            if (null === $busiest || $day['booked_minutes'] > $busiest['booked_minutes']) {
                $busiest = $day;
            }
        }

        return $busiest;
    }

    /**
     * The open working day with the fewest booked minutes; ties keep the
     * earlier date. Idle days are candidates, because they are the ones a
     * growth goal can actually fill.
     *
     * @param list<string>                        $dates           Range dates.
     * @param array<string, int|null>             $capacityByDate  Capacity per date.
     * @param array<string, list<array<string, mixed>>> $rows      Booked rows per date.
     *
     * @return array<string, mixed>|null
     */
    private static function quietest(array $dates, array $capacityByDate, array $rows): ?array
    {
        $quietest = null;

        foreach ($dates as $date) {
            $capacity = $capacityByDate[ $date ];

            if (null === $capacity || $capacity <= 0) {
                continue;
            }

            $booked = 0;
            $count  = 0;

            foreach ($rows[ $date ] ?? array() as $row) {
                $booked += $row['minutes'];
                ++$count;
            }

            $entry = array(
                'date'             => $date,
                'appointments'     => $count,
                'booked_minutes'   => $booked,
                'capacity_minutes' => $capacity,
                'free_minutes'     => max(0, $capacity - $booked),
            );

            if (null === $quietest || $booked < $quietest['booked_minutes']) {
                $quietest = $entry;
            }
        }

        return $quietest;
    }

    /**
     * A scalar meta value as a string; arrays become ''.
     */
    private static function metaString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
