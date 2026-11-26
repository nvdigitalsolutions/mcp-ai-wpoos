<?php

/**
 * Reads `_eca_attendance_log` — one shape, three tools.
 *
 * `mark_eca_attendance` writes a date-keyed map:
 *
 *     [ '2026-11-14' => [ date, recorded_by, recorded_at, session_notes,
 *                         attendees[], present_count, absent_count,
 *                         late_count, excused_count ] ]
 *
 * `attendees` is a plain list of `{student_id, student_name, status}` records,
 * deliberately not an id-keyed map like the enrolment side, so it is not run
 * through {@see Enrollments}. Three tools read this log (\u00a7164.57) and one rate
 * formula is shared, because the alternative is three copies of the same
 * division that drift apart.
 *
 * ## The rate lives here once
 *
 * `(present + late) / records`, with `late` counted as attended because a late
 * student was there. Both WordPress tools that report a rate compute exactly
 * this, one over the whole log and one per student; keeping it in a single
 * method is what stops the two from drifting the day one of them gains a status.
 *
 * A record whose status is neither present, absent, late nor excused still
 * counts in the denominator: the rate must not improve because a log carries a
 * value the reporter does not recognise.
 *
 * @package Nvoos\Core
 * @since   2.9.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Classroom;

final class AttendanceLog
{
    /**
     * The log as a date-keyed map, oldest first.
     *
     * `ksort` on ISO dates is chronological, which is why the keys are read as
     * strings rather than parsed: a malformed key sorts to one end instead of
     * throwing mid-report.
     *
     * @param mixed $raw Raw `_eca_attendance_log` meta.
     * @return array<string, array<string, mixed>>
     */
    public static function sessions(mixed $raw): array
    {
        if (!is_array($raw) || array() === $raw) {
            return array();
        }

        $sessions = array();

        foreach ($raw as $date => $session) {
            $key = is_string($date) && '' !== \trim($date) ? \trim($date) : '';

            if ('' === $key || !is_array($session)) {
                continue;
            }

            $sessions[ $key ] = $session;
        }

        \ksort($sessions);

        return $sessions;
    }

    /**
     * Attendee records from one session.
     *
     * @param array<string, mixed> $session One session record.
     * @return list<array<string, mixed>>
     */
    public static function attendees(array $session): array
    {
        $attendees = isset($session['attendees']) && \is_array($session['attendees'])
            ? $session['attendees']
            : array();

        return \array_values(\array_filter($attendees, 'is_array'));
    }

    /**
     * A rate as a percentage of records, one decimal place.
     *
     * @param int $present Counted as attended.
     * @param int $late    Counted as attended: a late student was there.
     * @param int $total   Records counted, whatever their status.
     */
    public static function rate(int $present, int $late, int $total): float
    {
        return $total > 0 ? \round(( ( $present + $late ) / $total) * 100, 1) : 0.0;
    }
}
