<?php

/**
 * Time arithmetic for the ECA family — one source for "do these overlap?".
 *
 * Two tools need it: the timetable (does this week's schedule collide with
 * itself) and `check_eca_conflicts` (would this new slot collide with what is
 * already on). The arithmetic is three lines and the reasons it is not inlined
 * are not:
 *
 * - **Minutes, not `strtotime()`.** `strtotime( '15:30' )` resolves the time
 *   against *today*, so a comparison run tomorrow could give a different answer
 *   near midnight. Minutes since midnight have no date to depend on.
 * - **Touching is not overlapping.** A session ending at 10:00 and one starting
 *   at 10:00 are back-to-back, which is how a school day is built. The strict
 *   inequalities are the whole contract and a `<=` would fill a timetable with
 *   false conflicts.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Classroom;

final class Schedule
{
    /** The seven days an activity can be scheduled on, in display order. */
    public const DAYS = array('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday');

    /**
     * Minutes since midnight, or null when the value is not a time.
     */
    public static function minutes(string $time): ?int
    {
        if (!\preg_match('/^(\d{1,2}):(\d{2})/', \trim($time), $parts)) {
            return null;
        }

        $hours   = (int) $parts[1];
        $minutes = (int) $parts[2];

        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        return $hours * 60 + $minutes;
    }

    /**
     * Whether two ranges share any minute.
     *
     * @param int $aStart First range start, in minutes.
     * @param int $aEnd   First range end, in minutes.
     */
    public static function overlaps(int $aStart, int $aEnd, int $bStart, int $bEnd): bool
    {
        return $aStart < $bEnd && $aEnd > $bStart;
    }

    /**
     * Whether an activity's day and times collide with a proposed window.
     *
     * @param array<string, mixed> $meta Activity meta (`_eca_day`, times).
     * @param string               $day  Day to compare.
     * @param int                  $start Proposed start, in minutes.
     * @param int                  $end   Proposed end, in minutes.
     */
    public static function collides(array $meta, string $day, int $start, int $end): bool
    {
        if (\trim((string) ($meta['_eca_day'] ?? '')) !== $day) {
            return false;
        }

        $startAt = self::minutes((string) ($meta['_eca_start_time'] ?? ''));
        $endAt   = self::minutes((string) ($meta['_eca_end_time'] ?? ''));

        if (null === $startAt || null === $endAt) {
            return false;
        }

        return self::overlaps($start, $end, $startAt, $endAt);
    }
}
