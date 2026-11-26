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
     * One student's attendance across a set of sessions.
     *
     * `records` is how many sessions carried a row for this student, which is
     * the denominator the rate uses: a session the register never recorded is
     * not an absence, and counting it as one would punish the student for the
     * register keeper's omission.
     *
     * @param array<string, array<string, mixed>> $sessions Date-keyed sessions.
     * @return array{records: int, present: int, absent: int, late: int, excused: int, attendance_rate: float}
     */
    public static function forStudent(array $sessions, int $student_id): array
    {
        $stats = array(
            'records'         => 0,
            'present'         => 0,
            'absent'          => 0,
            'late'            => 0,
            'excused'         => 0,
            'attendance_rate' => 0.0,
        );

        if ($student_id <= 0) {
            return $stats;
        }

        foreach ($sessions as $session) {
            foreach (self::attendees($session) as $attendee) {
                if ((int) ($attendee['student_id'] ?? 0) !== $student_id) {
                    continue;
                }

                ++$stats['records'];

                $status = (string) ($attendee['status'] ?? '');

                if (isset($stats[ $status ]) && 'records' !== $status) {
                    ++$stats[ $status ];
                }
            }
        }

        $stats['attendance_rate'] = self::rate($stats['present'], $stats['late'], $stats['records']);

        return $stats;
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
