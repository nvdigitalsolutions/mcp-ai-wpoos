<?php

/**
 * A participation report at one of four scopes — Proposal 164 §164.58.
 *
 * Ported from `WP_MCP_AI_Tool_Generate_ECA_Participation_Report`: same four
 * scopes, same `term` filter and the same `json`/`markdown` formats, because the
 * WordPress report is pasted into staff briefings and the markdown is the point.
 *
 * ### Four scopes, one shape
 *
 * | scope | rows are |
 * |---|---|
 * | `eca` | the students on one activity, with their own attendance |
 * | `student` | the activities one student is in |
 * | `year_group` | the activities that list a year group |
 * | `school` | every activity |
 *
 * The columns differ because the question does; `columns` travels with `rows` so
 * a renderer does not have to know which scope produced them.
 *
 * ### `term` is free text here
 *
 * WordPress restricts it to `Term 1`, `Term 2`, `Term 3`, `Yearly`. Terms in this
 * runtime are whatever `manage_eca_term` stores, so an enum would refuse a real
 * term the data contains. The filter compares the stored `_eca_term` exactly and
 * accepts any value; the description says so rather than advertising four values
 * the data may not use.
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
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Tool\AbstractTool;

class GenerateEcaParticipationReportTool extends AbstractTool
{
    private const SCOPES = array('eca', 'student', 'year_group', 'school');

    private const FORMATS = array('json', 'markdown');

    private const SCAN_LIMIT = 200;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'generate_eca_participation_report';
    }

    public function getName(): string
    {
        return 'Generate ECA Participation Report';
    }

    public function getDescription(): string
    {
        return 'Builds a participation report for one activity, one student, a year group or the whole school, optionally filtered by term, as JSON or a markdown table with per-student or per-activity attendance.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'scope' => array(
                    'type' => 'string',
                    'enum' => self::SCOPES,
                    'description' => 'What the report is about.',
                ),
                'scope_id' => array(
                    'type' => 'string',
                    'description' => 'Activity id, student id or year group name. Not used for the school scope.',
                ),
                'term' => array(
                    'type' => 'string',
                    'description' => 'Only activities whose stored `_eca_term` matches exactly. Free text, because that is what this runtime stores.',
                    'maxLength' => 100,
                ),
                'format' => array(
                    'type' => 'string',
                    'enum' => self::FORMATS,
                    'default' => 'json',
                    'description' => 'json returns rows and columns; markdown adds a rendered table.',
                ),
            ),
            'required' => array('scope'),
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

        $scope  = $this->stringParam($arguments, 'scope');
        $format = $this->stringParam($arguments, 'format', 'json');
        $term   = \trim($this->stringParam($arguments, 'term'));
        $id     = \trim($this->stringParam($arguments, 'scope_id'));

        if (!\in_array($scope, self::SCOPES, true)) {
            return $this->errors->validationFailed(
                'Unknown scope "' . $scope . '".',
                array('scope' => array('Use one of ' . \implode(', ', self::SCOPES) . '.')),
            );
        }

        if (!\in_array($format, self::FORMATS, true)) {
            return $this->errors->validationFailed(
                'Unknown format "' . $format . '".',
                array('format' => array('Use json or markdown.')),
            );
        }

        if ('school' !== $scope && '' === $id) {
            return $this->errors->validationFailed(
                'scope_id is required for the ' . $scope . ' scope.',
                array('scope_id' => array('Give the activity id, student id or year group.')),
            );
        }

        try {
            $built = $this->build($scope, $id, $term);
        } catch (\Throwable $e) {
            return $this->errors->create('report_failed', $e->getMessage());
        }

        if (\is_array($built) && isset($built['error'])) {
            return $built['error'];
        }

        $payload = array(
            'scope'    => $scope,
            'scope_id' => $id,
            'term'     => $term,
            'format'   => $format,
            'title'    => $built['title'],
            'columns'  => $built['columns'],
            'rows'     => $built['rows'],
            'summary'  => array(
                'rows'          => \count($built['rows']),
                'average_rate'  => $built['average_rate'],
            ),
        );

        if ('markdown' === $format) {
            $payload['markdown'] = $this->markdown($built['title'], $built['columns'], $built['rows']);
        }

        return $this->success($built['title'], $payload);
    }

    /**
     * @return array{title: string, columns: list<array{key: string, label: string}>, rows: list<array<string, mixed>>, average_rate: float}|array{error: mixed}
     */
    private function build(string $scope, string $id, string $term): array
    {
        if ('eca' === $scope) {
            return $this->forEca((int) $id, $term);
        }

        if ('student' === $scope) {
            return $this->forStudent((int) $id, $term);
        }

        return $this->forActivities('school' === $scope ? '' : $id, $term, $scope);
    }

    /**
     * @return array<string, mixed>
     */
    private function forEca(int $eca_id, string $term): array
    {
        $eca = $this->content->find($eca_id);

        if (!$eca instanceof ContentItem || 'mcp_ai_eca' !== $eca->type) {
            return array('error' => $this->errors->notFound('There is no ECA with id ' . $eca_id . '.'));
        }

        $eca_term = \trim((string) ($eca->meta['_eca_term'] ?? ''));

        if ('' !== $term && $eca_term !== $term) {
            return array(
                'title'        => $eca->title . ' (no sessions in term ' . $term . ')',
                'columns'      => $this->studentColumns(),
                'rows'         => array(),
                'average_rate' => 0.0,
            );
        }

        $sessions = AttendanceLog::sessions($eca->meta['_eca_attendance_log'] ?? array());
        $rows     = array();
        $rate_sum = 0.0;

        foreach (EcaRow::enrollments($eca) as $student_id => $record) {
            $student = $this->content->find((int) $student_id);
            $name    = $student instanceof ContentItem ? $student->title : 'Student ' . $student_id;
            $stats   = AttendanceLog::forStudent($sessions, (int) $student_id);

            $rate_sum += $stats['attendance_rate'];

            $rows[] = array(
                'student_id'       => (int) $student_id,
                'student_name'     => $name,
                'enrollment_type'  => (string) ($record['enrollment_type'] ?? 'confirmed'),
                'records'          => $stats['records'],
                'attended'         => $stats['present'] + $stats['late'],
                'absent'           => $stats['absent'],
                'attendance_rate'  => $stats['attendance_rate'],
            );
        }

        return array(
            'title'        => $eca->title . ('' !== $eca_term ? ' — ' . $eca_term : ''),
            'columns'      => $this->studentColumns(),
            'rows'         => $rows,
            'average_rate' => array() === $rows ? 0.0 : \round($rate_sum / \count($rows), 1),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function forStudent(int $student_id, string $term): array
    {
        $student = $this->content->find($student_id);

        if (!$student instanceof ContentItem || 'mcp_ai_student' !== $student->type) {
            return array('error' => $this->errors->notFound('There is no student with id ' . $student_id . '.'));
        }

        $rows     = array();
        $rate_sum = 0.0;

        foreach (Enrollments::map($student->meta['_student_eca_enrollments'] ?? array()) as $eca_id => $record) {
            $eca = $this->content->find((int) $eca_id);

            if (!$eca instanceof ContentItem || 'mcp_ai_eca' !== $eca->type) {
                continue;
            }

            $eca_term = \trim((string) ($eca->meta['_eca_term'] ?? ''));

            if ('' !== $term && $eca_term !== $term) {
                continue;
            }

            $stats     = AttendanceLog::forStudent(AttendanceLog::sessions($eca->meta['_eca_attendance_log'] ?? array()), $student_id);
            $rate_sum += $stats['attendance_rate'];

            $rows[] = array(
                'eca_id'          => $eca->id,
                'eca_name'        => $eca->title,
                'eca_term'        => $eca_term,
                'eca_status'      => (string) ($eca->meta['_eca_status'] ?? ''),
                'records'         => $stats['records'],
                'attended'        => $stats['present'] + $stats['late'],
                'absent'          => $stats['absent'],
                'attendance_rate' => $stats['attendance_rate'],
            );
        }

        return array(
            'title'        => 'Participation: ' . $student->title,
            'columns'      => $this->activityColumns(),
            'rows'         => $rows,
            'average_rate' => array() === $rows ? 0.0 : \round($rate_sum / \count($rows), 1),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function forActivities(string $year_group, string $term, string $scope): array
    {
        $page    = $this->content->query(new ContentQuery(types: array('mcp_ai_eca'), perPage: self::SCAN_LIMIT));
        $rows    = array();
        $rate_sum = 0.0;

        foreach ($page->items as $eca) {
            if (!$eca instanceof ContentItem) {
                continue;
            }

            if ('' !== $year_group && !EcaFilter::matchesList($eca->meta['_eca_year_groups'] ?? array(), $year_group)) {
                continue;
            }

            $eca_term = \trim((string) ($eca->meta['_eca_term'] ?? ''));

            if ('' !== $term && $eca_term !== $term) {
                continue;
            }

            $stats     = $this->activityRate($eca);
            $rate_sum += $stats['attendance_rate'];

            $rows[] = array(
                'eca_id'          => $eca->id,
                'eca_name'        => $eca->title,
                'eca_type'        => (string) ($eca->meta['_eca_type'] ?? ''),
                'enrolled'        => \count(EcaRow::enrollments($eca)),
                'capacity'        => (int) ($eca->meta['_eca_max_students'] ?? 0),
                'records'         => $stats['records'],
       
                'attendance_rate' => $stats['attendance_rate'],
            );
        }

        return array(
            'title'        => 'Participation: ' . ('school' === $scope ? 'whole school' : 'year group ' . $year_group),
            'columns'      => $this->ecaColumns(),
            'rows'         => $rows,
            'average_rate' => array() === $rows ? 0.0 : \round($rate_sum / \count($rows), 1),
        );
    }

    /**
     * The activity's own attendance, across every attendee.
     *
     * @return array{records: int, attendance_rate: float}
     */
    private function activityRate(ContentItem $eca): array
    {
        $records = 0;
        $present = 0;
        $late    = 0;

        foreach (AttendanceLog::sessions($eca->meta['_eca_attendance_log'] ?? array()) as $session) {
            foreach (AttendanceLog::attendees($session) as $attendee) {
                ++$records;

                $status = (string) ($attendee['status'] ?? '');

                if ('present' === $status) {
                    ++$present;
                } elseif ('late' === $status) {
                    ++$late;
                }
            }
        }

        return array(
            'records'         => $records,
            'attendance_rate' => AttendanceLog::rate($present, $late, $records),
        );
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function studentColumns(): array
    {
        return array(
            array('key' => 'student_name', 'label' => 'Student'),
            array('key' => 'enrollment_type', 'label' => 'Enrolled as'),
            array('key' => 'records', 'label' => 'Records'),
            array('key' => 'attended', 'label' => 'Attended'),
            array('key' => 'absent', 'label' => 'Absent'),
            array('key' => 'attendance_rate', 'label' => 'Rate %'),
        );
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function activityColumns(): array
    {
        return array(
            array('key' => 'eca_name', 'label' => 'Activity'),
            array('key' => 'eca_term', 'label' => 'Term'),
            array('key' => 'eca_status', 'label' => 'Status'),
            array('key' => 'records', 'label' => 'Records'),
            array('key' => 'attended', 'label' => 'Attended'),
            array('key' => 'absent', 'label' => 'Absent'),
            array('key' => 'attendance_rate', 'label' => 'Rate %'),
        );
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function ecaColumns(): array
    {
        return array(
            array('key' => 'eca_name', 'label' => 'Activity'),
            array('key' => 'eca_type', 'label' => 'Type'),
            array('key' => 'enrolled', 'label' => 'Enrolled'),
            array('key' => 'capacity', 'label' => 'Capacity'),
            array('key' => 'attendance_rate', 'label' => 'Rate %'),
        );
    }

    /**
     * A markdown table, because that is what the WordPress report was for.
     *
     * @param list<array{key: string, label: string}> $columns
     * @param list<array<string, mixed>>              $rows
     */
    private function markdown(string $title, array $columns, array $rows): string
    {
        $lines = array('## ' . $title, '');

        if (array() === $rows) {
            $lines[] = '_No rows._';

            return \implode(chr(10), $lines);
        }

        $header = array();
        $rule   = array();

        foreach ($columns as $column) {
            $header[] = (string) $column['label'];
            $rule[]   = '---';
        }

        $lines[] = '| ' . \implode(' | ', $header) . ' |';
        $lines[] = '| ' . \implode(' | ', $rule) . ' |';

        foreach ($rows as $row) {
            $cells = array();

            foreach ($columns as $column) {
                $cells[] = (string) ($row[ $column['key'] ] ?? '');
            }

            $lines[] = '| ' . \implode(' | ', $cells) . ' |';
        }

        return \implode(chr(10), $lines);
    }
}
