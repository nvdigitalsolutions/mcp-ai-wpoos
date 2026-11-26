<?php

/**
 * A participation report, emailed to a guardian — Proposal 164 §164.59.
 *
 * Ported from `WP_MCP_AI_Tool_Send_ECA_Parent_Report`: same `student_id`,
 * `term`, `include_attendance`, `include_achievements` and `custom_notes`, and
 * the same refusal when the student has no enrolments. Four decisions:
 *
 * ### The address is chosen once and named in the result
 *
 * WordPress reads `_student_parent_email` and falls back to `_student_email`.
 * That precedence is kept, and the result says which one was used in
 * `recipient_role`, because a report that reached the student instead of the
 * guardian is a fact the operator needs, not a detail to infer from the log.
 *
 * ### `term` is free text
 *
 * WordPress restricts it to `Term 1`, `Term 2`, `Term 3`, `Yearly`. Terms in
 * this runtime are whatever `manage_eca_term` stores, so an enum would refuse a
 * real term the data contains. The filter compares the activity's stored term
 * exactly, like `generate_eca_participation_report`.
 *
 * ### Achievements are declared absent, not invented
 *
 * There is no achievements store in this runtime. With `include_achievements`
 * the body carries the same line WordPress prints when it finds nothing — the
 * flag is honoured, and the result states `achievements_included: false` so a
 * caller can tell "none recorded" from "not asked for".
 *
 * ### The send is logged on the student
 *
 * WordPress appends `term`, `sent_at`, `sent_by` and `sent_to` to
 * `_student_reports_sent`. This port does the same, and adds `recipient_role`,
 * because the log is the only place a later reader can see whether the parent
 * or the student received it.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Classroom;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\EmailServiceInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Tool\AbstractTool;

class SendEcaParentReportTool extends AbstractTool
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
        private readonly EmailServiceInterface $email,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'send_eca_parent_report';
    }

    public function getName(): string
    {
        return 'Send ECA Parent Report';
    }

    public function getDescription(): string
    {
        return 'Emails a participation report for one student: each enrolled activity with its term, day, time, venue and optionally the attendance record, plus optional teacher notes. Goes to the student parent address when one is on file and to the student otherwise, and the result names which. The send is appended to the student report log.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'student_id'           => array(
                    'type'        => 'integer',
                    'description' => 'Student record ID whose report is being sent (required).',
                    'minimum'     => 1,
                ),
                'term'                 => array(
                    'type'        => 'string',
                    'description' => 'Only activities whose stored term matches exactly. Free text, because that is what this runtime stores.',
                    'maxLength'   => 100,
                ),
                'include_attendance'   => array(
                    'type'        => 'boolean',
                    'description' => 'Include each activity attendance record and rate.',
                    'default'     => true,
                ),
                'include_achievements' => array(
                    'type'        => 'boolean',
                    'description' => 'Include an achievements section. This runtime has no achievements store, so the section states that none are recorded.',
                    'default'     => false,
                ),
                'custom_notes'         => array(
                    'type'        => 'string',
                    'description' => 'Teacher notes appended to the report.',
                    'maxLength'   => 2000,
                ),
            ),
            'required'   => array( 'student_id' ),
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

        $user_id    = (int) ( $context['user_id'] ?? 0 );
        $student_id = $this->intParam($arguments, 'student_id');
        $term       = trim($this->stringParam($arguments, 'term'));
        $attendance = $this->boolParam($arguments, 'include_attendance', true);
        $achievements = $this->boolParam($arguments, 'include_achievements', false);
        $notes      = trim($this->stringParam($arguments, 'custom_notes'));

        if ($student_id <= 0) {
            return $this->errors->validationFailed(
                'A student id is required.',
                array( 'student_id' => array( 'The student whose report is being sent.' ) ),
            );
        }

        $student = $this->content->find($student_id);

        if (! $student instanceof ContentItem || 'mcp_ai_student' !== $student->type) {
            return $this->errors->notFound('There is no student with id ' . $student_id . '.');
        }

        $rows = $this->rows($student, $term);

        if (array() === $rows) {
            return $this->errors->create(
                'no_enrollments',
                '' !== $term
                    ? 'This student has no activities in term "' . $term . '".'
                    : 'This student has no ECA enrollments.',
                array( 'student_id' => $student_id, 'term' => $term ),
            );
        }

        $address = Contacts::forReport($student);

        if ('' === $address['email']) {
            return $this->errors->create(
                'no_recipient_email',
                'Neither a parent nor a student email address is on file for ' . $student->title . '.',
                array( 'student_id' => $student_id ),
            );
        }

        if (! $this->email->validateRecipient($address['email'])) {
            return $this->errors->validationFailed(
                'The stored ' . $address['role'] . ' address is not a valid email.',
                array( 'student_id' => array( 'Correct the address on the student record first.' ) ),
            );
        }

        if (! $this->email->isAvailable()) {
            return $this->errors->create('email_unavailable', 'No email transport is configured on this host.');
        }

        $subject = 'ECA Participation Report: ' . $student->title . ( '' !== $term ? ' - ' . $term : '' );
        $body    = $this->body($student, $term, $rows, $attendance, $achievements, $notes);

        $result = $this->email->send(
            array(
                'to'      => $address['email'],
                'subject' => $subject,
                'body'    => $body,
                'is_html' => true,
            )
        );

        if (empty($result['success'])) {
            return $this->errors->create(
                'email_failed',
                'The report could not be sent to ' . $address['email'] . ': ' . (string) ( $result['error'] ?? 'send failed' ),
                array( 'student_id' => $student_id ),
            );
        }

        $this->log($student, $user_id, $term, $address);

        return $this->success(
            'Report for ' . $student->title . ' sent to the ' . $address['role'] . '.',
            array(
                'student_id'           => $student_id,
                'student_name'         => $student->title,
                'term'                 => '' !== $term ? $term : 'all terms',
                'recipient_email'      => $address['email'],
                'recipient_role'       => $address['role'],
                'activities'           => count($rows),
                'attendance_included'  => $attendance,
                'achievements_included' => false,
            )
        );
    }

    /**
     * One row per enrolled activity, optionally narrowed to a term.
     *
     * A roster entry whose activity has been deleted is skipped rather than
     * rendered as a nameless line: the report is read by a parent, and an id
     * with no title reads as a mistake.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(ContentItem $student, string $term): array
    {
        $rows = array();

        foreach (Enrollments::map($student->meta['_student_eca_enrollments'] ?? array()) as $eca_id => $record) {
            $eca = $this->content->find((int) $eca_id);

            if (! $eca instanceof ContentItem || 'mcp_ai_eca' !== $eca->type) {
                continue;
            }

            $eca_term = trim((string) ( $eca->meta['_eca_term'] ?? '' ));

            if ('' !== $term && $eca_term !== $term) {
                continue;
            }

            $stats = AttendanceLog::forStudent(
                AttendanceLog::sessions($eca->meta['_eca_attendance_log'] ?? array()),
                $student->id
            );

            $rows[] = array(
                'eca_id'          => $eca->id,
                'eca_name'        => $eca->title,
                'eca_term'        => $eca_term,
                'eca_status'      => trim((string) ( $eca->meta['_eca_status'] ?? '' )),
                'day'             => trim((string) ( $eca->meta['_eca_day'] ?? '' )),
                'time'            => $this->window($eca),
                'venue'           => trim((string) ( $eca->meta['_eca_venue'] ?? '' )),
                'enrollment_type' => (string) ( $record['enrollment_type'] ?? 'confirmed' ),
                'records'         => $stats['records'],
                'attended'        => $stats['present'] + $stats['late'],
                'absent'          => $stats['absent'],
                'attendance_rate' => $stats['attendance_rate'],
            );
        }

        return $rows;
    }

    private function window(ContentItem $eca): string
    {
        $start = trim((string) ( $eca->meta['_eca_start_time'] ?? '' ));
        $end   = trim((string) ( $eca->meta['_eca_end_time'] ?? '' ));

        if ('' === $start || '' === $end) {
            return '';
        }

        return $start . '-' . $end;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function body(
        ContentItem $student,
        string $term,
        array $rows,
        bool $attendance,
        bool $achievements,
        string $notes
    ): string {
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        $html  = '<html><body>';
        $html .= '<h2>ECA Participation Report: ' . $escape($student->title) . '</h2>';

        if ('' !== $term) {
            $html .= '<p>Term: ' . $escape($term) . '</p>';
        }

        $html .= '<table style="border-collapse:collapse;width:100%;">';
        $html .= '<tr>';
        $html .= '<th style="text-align:left;padding:4px 8px;">Activity</th>';
        $html .= '<th style="text-align:left;padding:4px 8px;">Term</th>';
        $html .= '<th style="text-align:left;padding:4px 8px;">When</th>';

        if ($attendance) {
            $html .= '<th style="text-align:left;padding:4px 8px;">Attended</th>';
            $html .= '<th style="text-align:left;padding:4px 8px;">Absent</th>';
            $html .= '<th style="text-align:left;padding:4px 8px;">Rate %</th>';
        }

        $html .= '</tr>';

        foreach ($rows as $row) {
            $when = trim($row['day'] . ' ' . $row['time']);
            $html .= '<tr>';
            $html .= '<td style="padding:4px 8px;">' . $escape($row['eca_name']) . '</td>';
            $html .= '<td style="padding:4px 8px;">' . $escape($row['eca_term']) . '</td>';
            $html .= '<td style="padding:4px 8px;">' . $escape($when) . '</td>';

            if ($attendance) {
                $html .= '<td style="padding:4px 8px;">' . $escape($row['attended']) . '</td>';
                $html .= '<td style="padding:4px 8px;">' . $escape($row['absent']) . '</td>';
                $html .= '<td style="padding:4px 8px;">' . $escape($row['attendance_rate']) . '</td>';
            }

            $html .= '</tr>';
        }

        $html .= '</table>';

        if ($achievements) {
            $html .= '<h3>Achievements</h3><p>No achievements data available at this time.</p>';
        }

        if ('' !== $notes) {
            $html .= '<hr /><p>' . nl2br($escape($notes)) . '</p>';
        }

        return $html . '</body></html>';
    }

    /**
     * @param array{email: string, role: string} $address
     */
    private function log(ContentItem $student, int $user_id, string $term, array $address): void
    {
        $log = $student->meta['_student_reports_sent'] ?? array();

        if (! is_array($log)) {
            $log = array();
        }

        $log[] = array(
            'term'          => '' !== $term ? $term : 'N/A',
            'sent_at'       => gmdate('Y-m-d H:i:s'),
            'sent_by'       => $user_id,
            'sent_to'       => $address['email'],
            'recipient_role' => $address['role'],
        );

        try {
            $this->content->update(
                $student->id,
                new UpdateContentCommand(userId: $user_id, meta: array( '_student_reports_sent' => $log ))
            );
        } catch (\Throwable $e) {
            return;
        }
    }
}
