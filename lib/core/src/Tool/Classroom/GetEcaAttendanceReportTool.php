<?php

/**
 * An activity's attendance, from the log the family already writes — §164.57.
 *
 * Ported from `WP_MCP_AI_Tool_Get_ECA_Attendance_Report`: same slug, same five
 * arguments, same summary/detailed split and the same Chart.js-shaped datasets,
 * because the WordPress widget reads them directly.
 *
 * ### What it reads
 *
 * `_eca_attendance_log`, the date-keyed map `mark_eca_attendance` writes, through
 * {@see AttendanceLog}. `summary` counts attendee records across the selected
 * range; `detailed` adds one entry per session with its notes, recorder and the
 * attendees (filtered to one student when asked).
 *
 * ### The rate
 *
 * `(present + late) / records`, not WordPress's `(present + late) / sessions`:
 * the second divides a per-student count by a per-session count, and on a class
 * of twenty it reports a rate near 2000%. `late` counts as attended because a
 * late student was there; that part is WordPress's and it is right.
 *
 * ### Empty is a success
 *
 * No log, or no sessions in the range, is not an error: it is a true answer, and
 * the payload says which of the two it was so a caller can tell "nothing
 * recorded" from "nothing in these dates".
 *
 * @package Nvoos\Core
 * @since   2.9.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Classroom;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Tool\AbstractTool;

class GetEcaAttendanceReportTool extends AbstractTool
{
    private const FORMATS = array('summary', 'detailed');

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'get_eca_attendance_report';
    }

    public function getName(): string
    {
        return 'Get ECA Attendance Report';
    }

    public function getDescription(): string
    {
        return 'Reports attendance for one activity: totals and a rate in summary form, or a per-session breakdown with detailed. Filters by student and date range. The rate is (present + late) over attendee records.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'eca_id' => array(
                    'type' => 'integer',
                    'description' => 'ECA record ID (required).',
                    'minimum' => 1,
                ),
                'student_id' => array(
                    'type' => 'integer',
                    'description' => 'Count only this student\'s attendance.',
                    'minimum' => 1,
                ),
                'date_from' => array(
                    'type' => 'string',
                    'description' => 'First session date to include (YYYY-MM-DD).',
                    'minLength' => 10,
                    'maxLength' => 10,
                ),
                'date_to' => array(
                    'type' => 'string',
                    'description' => 'Last session date to include (YYYY-MM-DD).',
                    'minLength' => 10,
                    'maxLength' => 10,
                ),
                'format' => array(
                    'type' => 'string',
                    'enum' => self::FORMATS,
                    'default' => 'summary',
                    'description' => 'summary returns totals, a rate and per-student rates; detailed adds the per-session breakdown.',
                ),
            ),
            'required' => array('eca_id'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $eca_id = $this->intParam($arguments, 'eca_id');

        if ($eca_id <= 0) {
            return $this->errors->validationFailed(
                'An ECA id is required.',
                array('eca_id' => array('Give the activity to report on.')),
            );
        }

        $format = $this->stringParam($arguments, 'format', 'summary');

        if (!\in_array($format, self::FORMATS, true)) {
            return $this->errors->validationFailed(
                'Unknown format "' . $format . '".',
                array('format' => array('Use summary or detailed.')),
            );
        }

        $student_id = $this->intParam($arguments, 'student_id');
        $date_from  = \trim($this->stringParam($arguments, 'date_from'));
        $date_to    = \trim($this->stringParam($arguments, 'date_to'));

        foreach (array('date_from' => $date_from, 'date_to' => $date_to) as $key => $value) {
            if ('' !== $value && !\preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return $this->errors->validationFailed(
                    $key . ' must be a date in YYYY-MM-DD format.',
                    array($key => array('Use YYYY-MM-DD.')),
                );
            }
        }

        $eca = $this->content->find($eca_id);

        if (!$eca instanceof ContentItem || 'mcp_ai_eca' !== $eca->type) {
            return $this->errors->notFound('There is no ECA with id ' . $eca_id . '.');
        }

        $student = null;

        if ($student_id > 0) {
            $student = $this->content->find($student_id);

            if (!$student instanceof ContentItem || 'mcp_ai_student' !== $student->type) {
                return $this->errors->notFound('There is no student with id ' . $student_id . '.');
            }
        }

        $log = AttendanceLog::sessions($eca->meta['_eca_attendance_log'] ?? array());

        if (array() === $log) {
            return $this->success(
                'No attendance records found for this ECA.',
                $this->emptyPayload($eca, $student)
            );
        }

        $filtered = array();

        foreach ($log as $date => $session) {
            if ('' !== $date_from && $date < $date_from) {
                continue;
            }

            if ('' !== $date_to && $date > $date_to) {
                continue;
            }

            $filtered[ $date ] = $session;
        }

        if (array() === $filtered) {
            return $this->success(
                'No attendance records found for the specified date range.',
                $this->emptyPayload($eca, $student)
            );
        }

        $counts = array('present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0);
        $records = 0;
        $per_student = array();

        foreach ($filtered as $date => $session) {
            foreach (AttendanceLog::attendees($session) as $attendee) {
                $attendee_id = (int) ($attendee['student_id'] ?? 0);

                if ($student_id > 0 && $attendee_id !== $student_id) {
                    continue;
                }

                $status = (string) ($attendee['status'] ?? '');

                if (!\array_key_exists($status, $counts)) {
                    // An unknown status is counted as a record with no verdict,
                    // so the denominator never silently shrinks.
                    ++$records;
                    continue;
                }

                ++$counts[ $status ];
                ++$records;

                if ($attendee_id <= 0) {
                    continue;
                }

                if (!isset($per_student[ $attendee_id ])) {
                    $per_student[ $attendee_id ] = array(
                        'student_id'   => $attendee_id,
                        'student_name' => (string) ($attendee['student_name'] ?? ''),
                        'total'        => 0,
                        'present'      => 0,
                        'absent'       => 0,
                        'late'         => 0,
                        'excused'      => 0,
                    );
                }

                ++$per_student[ $attendee_id ]['total'];
                ++$per_student[ $attendee_id ][ $status ];
            }
        }

        $rates = array();

        foreach ($per_student as $data) {
            $data['rate'] = AttendanceLog::rate($data['present'], $data['late'], $data['total']);
            $rates[]      = $data;
        }

        $payload = array(
            'eca_id'          => $eca->id,
            'eca_name'        => $eca->title,
            'date_from'       => $date_from,
            'date_to'         => $date_to,
            'total_sessions'  => \count($filtered),
            'attendance_rate' => AttendanceLog::rate($counts['present'], $counts['late'], $records),
            'summary'         => array(
                'total_records' => $records,
                'present'       => $counts['present'],
                'absent'        => $counts['absent'],
                'late'          => $counts['late'],
                'excused'       => $counts['excused'],
            ),
            'student_rates' => $rates,
            'chart_data'    => $this->chartData($filtered),
        );

        if ($student instanceof ContentItem) {
            $payload['student_filter'] = array(
                'student_id'   => $student->id,
                'student_name' => $student->title,
            );
        }

        if ('detailed' === $format) {
            $payload['sessions'] = $this->breakdown($filtered, $student_id);
        }

        return $this->success(
            \count($filtered) . ' session(s) over ' . $records . ' attendance record(s).',
            $payload
        );
    }

    // ─── Shapes ────────────────────────────────────────────────────────────

    /**
     * The answer when there is nothing to report, with the same keys a report
     * has, so a caller reads one shape either way.
     *
     * @return array<string, mixed>
     */
    private function emptyPayload(ContentItem $eca, ?ContentItem $student): array
    {
        $payload = array(
            'eca_id'          => $eca->id,
            'eca_name'        => $eca->title,
            'total_sessions'  => 0,
            'attendance_rate' => 0.0,
            'summary'         => array(
                'total_records' => 0,
                'present'       => 0,
                'absent'        => 0,
                'late'          => 0,
                'excused'       => 0,
            ),
            'student_rates' => array(),
            'chart_data'    => array('labels' => array(), 'datasets' => array()),
        );

        if ($student instanceof ContentItem) {
            $payload['student_filter'] = array(
                'student_id'   => $student->id,
                'student_name' => $student->title,
            );
        }

        return $payload;
    }

    /**
     * Chart.js-shaped totals per session, oldest first.
     *
     * The counters come from the session record rather than being recomputed:
     * `mark_eca_attendance` wrote them for exactly this, and recomputing would
     * let a hand-edited log disagree with itself.
     *
     * @param array<string, array<string, mixed>> $sessions Date-keyed sessions.
     * @return array<string, mixed>
     */
    private function chartData(array $sessions): array
    {
        $labels  = array();
        $present = array();
        $absent  = array();
        $late    = array();
        $excused = array();

        foreach ($sessions as $date => $session) {
            $labels[]  = $date;
            $present[] = (int) ($session['present_count'] ?? 0);
            $absent[]  = (int) ($session['absent_count'] ?? 0);
            $late[]    = (int) ($session['late_count'] ?? 0);
            $excused[] = (int) ($session['excused_count'] ?? 0);
        }

        return array(
            'labels'   => $labels,
            'datasets' => array(
                array('label' => 'Present', 'data' => $present),
                array('label' => 'Absent', 'data' => $absent),
                array('label' => 'Late', 'data' => $late),
                array('label' => 'Excused', 'data' => $excused),
            ),
        );
    }

    /**
     * One entry per session, oldest first.
     *
     * @param array<string, array<string, mixed>> $sessions    Date-keyed sessions.
     * @param int                                 $student_id Filter, or 0.
     * @return list<array<string, mixed>>
     */
    private function breakdown(array $sessions, int $student_id): array
    {
        $rows = array();

        foreach ($sessions as $date => $session) {
            $attendees = AttendanceLog::attendees($session);

            if ($student_id > 0) {
                $attendees = \array_values(\array_filter(
                    $attendees,
                    static fn (array $a): bool => (int) ($a['student_id'] ?? 0) === $student_id
                ));
            }

            $rows[] = array(
                'date'          => $date,
                'session_notes' => (string) ($session['session_notes'] ?? ''),
                'recorded_by'   => (int) ($session['recorded_by'] ?? 0),
                'recorded_at'   => (string) ($session['recorded_at'] ?? ''),
                'attendees'     => $attendees,
            );
        }

        return $rows;
    }
}
