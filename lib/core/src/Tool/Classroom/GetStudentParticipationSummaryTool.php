<?php

/**
 * One student across every activity they are in — Proposal 164 §164.57.
 *
 * Ported from `WP_MCP_AI_Tool_Get_Student_Participation_Summary`: same slug,
 * same three arguments and the same per-activity entry, read from
 * `_student_eca_enrollments` plus each activity's `_eca_attendance_log`.
 *
 * ### Two different questions, both answered
 *
 * The overall rate and the engagement score are not the same number and the
 * payload keeps them apart, as WordPress does. `overall_attendance_rate` is the
 * student's attended records over their total records — the same formula the
 * attendance report uses, through {@see AttendanceLog::rate()} so the two tools
 * cannot disagree. `engagement_score` is the mean of the **per-activity** rates,
 * which weights a one-session club as heavily as a thirty-session one; that is
 * deliberate, because it answers "is this student engaged across their
 * activities", not "how many sessions did they attend".
 *
 * ### Enrolments that outlive their activity
 *
 * A student's map can name an activity that was deleted, or one whose term does
 * not match the filter. Those are skipped, and `skipped` reports how many, so a
 * total of three against a map of five is explained rather than mysterious.
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

class GetStudentParticipationSummaryTool extends AbstractTool
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'get_student_participation_summary';
    }

    public function getName(): string
    {
        return 'Get Student Participation Summary';
    }

    public function getDescription(): string
    {
        return 'Reports one student across every activity they are enrolled in, with per-activity attendance and an overall rate computed over attendee records. Filters by term.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'student_id' => array(
                    'type' => 'integer',
                    'description' => 'Student record ID (required).',
                    'minimum' => 1,
                ),
                'term' => array(
                    'type' => 'string',
                    'description' => 'Only activities whose `_eca_term` matches this value.',
                    'maxLength' => 100,
                ),
                'include_attendance' => array(
                    'type' => 'boolean',
                    'description' => 'Include per-activity attendance and the overall rate.',
                    'default' => true,
                ),
            ),
            'required' => array('student_id'),
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

        $student_id = $this->intParam($arguments, 'student_id');

        if ($student_id <= 0) {
            return $this->errors->validationFailed(
                'A student id is required.',
                array('student_id' => array('Give the student to report on.')),
            );
        }

        $term               = \trim($this->stringParam($arguments, 'term'));
        $include_attendance = $this->boolParam($arguments, 'include_attendance', true);

        $student = $this->content->find($student_id);

        if (!$student instanceof ContentItem || 'mcp_ai_student' !== $student->type) {
            return $this->errors->notFound('There is no student with id ' . $student_id . '.');
        }

        $enrolments = Enrollments::map($student->meta['_student_eca_enrollments'] ?? array());

        $entries       = array();
        $active        = 0;
        $skipped       = 0;
        $rate_sum      = 0.0;
        $rated         = 0;
        $record_total  = 0;
        $present_total = 0;
        $late_total    = 0;

        foreach ($enrolments as $eca_id => $enrolment) {
            $eca = $this->content->find((int) $eca_id);

            if (!$eca instanceof ContentItem || 'mcp_ai_eca' !== $eca->type) {
                ++$skipped;
                continue;
            }

            $eca_term = \trim((string) ($eca->meta['_eca_term'] ?? ''));

            if ('' !== $term && $eca_term !== $term) {
                ++$skipped;
                continue;
            }

            $status = \trim((string) ($eca->meta['_eca_status'] ?? ''));

            if ('active' === $status || 'full' === $status) {
                ++$active;
            }

            $entry = array(
                'eca_id'          => $eca->id,
                'eca_name'        => $eca->title,
                'eca_type'        => (string) ($eca->meta['_eca_type'] ?? ''),
                'eca_status'      => $status,
                'eca_term'        => $eca_term,
                'enrollment_type' => (string) ($enrolment['enrollment_type'] ?? 'confirmed'),
                'enrollment_date' => (string) ($enrolment['enrollment_date'] ?? ''),
                'day'             => (string) ($eca->meta['_eca_day'] ?? ''),
                'venue'           => (string) ($eca->meta['_eca_venue'] ?? ''),
            );

            if ($include_attendance) {
                $stats               = $this->attendance($eca, $student_id);
                $entry['attendance'] = $stats;

                if ($stats['sessions_recorded'] > 0) {
                    $rate_sum += $stats['attendance_rate'];
                    ++$rated;
                    $record_total  += $stats['sessions_recorded'];
                    $present_total += $stats['present'];
                    $late_total    += $stats['late'];
                }
            }

            $entries[] = $entry;
        }

        $payload = array(
            'student'       => array(
                'student_id'   => $student->id,
                'student_name' => $student->title,
                'year_group'   => (string) ($student->meta['_student_year_group'] ?? ''),
            ),
            'ecas_enrolled' => $entries,
            'overall_stats' => array(
                'total_ecas'              => \count($entries),
                'active_ecas'             => $active,
                'total_sessions_attended' => $record_total,
                'overall_attendance_rate' => AttendanceLog::rate($present_total, $late_total, $record_total),
                'engagement_score'        => $rated > 0 ? \round($rate_sum / $rated, 1) : 0.0,
                'skipped'                 => $skipped,
            ),
            'filters' => array(
                'term'               => $term,
                'include_attendance' => $include_attendance,
            ),
        );

        return $this->success(
            \count($entries) . ' activity(ies) for ' . $student->title . '.',
            $payload
        );
    }

    /**
     * One student's attendance inside one activity.
     *
     * @return array{sessions_recorded: int, present: int, absent: int, late: int, excused: int, attendance_rate: float}
     */
    private function attendance(ContentItem $eca, int $student_id): array
    {
        $stats = array(
            'sessions_recorded' => 0,
            'present'           => 0,
            'absent'            => 0,
            'late'              => 0,
            'excused'           => 0,
            'attendance_rate'   => 0.0,
        );

        foreach (AttendanceLog::sessions($eca->meta['_eca_attendance_log'] ?? array()) as $session) {
            foreach (AttendanceLog::attendees($session) as $attendee) {
                if ((int) ($attendee['student_id'] ?? 0) !== $student_id) {
                    continue;
                }

                ++$stats['sessions_recorded'];

                $status = (string) ($attendee['status'] ?? '');

                if (isset($stats[ $status ])) {
                    ++$stats[ $status ];
                }
            }
        }

        $stats['attendance_rate'] = AttendanceLog::rate(
            $stats['present'],
            $stats['late'],
            $stats['sessions_recorded']
        );

        return $stats;
    }
}
