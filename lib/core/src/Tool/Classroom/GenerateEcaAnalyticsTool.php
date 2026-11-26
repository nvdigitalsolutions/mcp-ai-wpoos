<?php

/**
 * Programme analytics in four flavours — Proposal 164 §164.58.
 *
 * Ported from `WP_MCP_AI_Tool_Generate_ECA_Analytics`: same `report_type`
 * values, same `date_range`/`filters` shape and Chart.js-compatible data,
 * because the WordPress dashboard reads those keys directly.
 *
 * | report_type | question |
 * |---|---|
 * | `participation` | how many students are in each activity, and how full is it |
 * | `capacity` | where is there room, and what is over capacity |
 * | `financial` | what is paid, at what price, projected from the confirmed roster |
 * | `engagement` | how often do attendees turn up, per activity |
 *
 * ### Projections are labelled projections
 *
 * `projected_revenue` is `cost x confirmed enrolments` from what the roster
 * says. It is not money received: this runtime has no payments ledger (§164.54
 * says so where the export was designed), and a figure labelled "revenue" that
 * is really "expected from the roster" would be read as the former.
 *
 * ### The range filters sessions, not activities
 *
 * `date_range` narrows the attendance sessions a rate is computed over and the
 * enrolments dated inside it. An activity with no session in range still
 * appears in participation and capacity, with `sessions_logged: 0` rather than
 * being dropped: "no sessions yet" is a finding, not an absence.
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

class GenerateEcaAnalyticsTool extends AbstractTool
{
    private const REPORT_TYPES = array('participation', 'capacity', 'financial', 'engagement');

    private const SCAN_LIMIT = 200;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'generate_eca_analytics';
    }

    public function getName(): string
    {
        return 'Generate ECA Analytics';
    }

    public function getDescription(): string
    {
        return 'Programme analytics over the activities and attendance this runtime stores: participation, capacity, financial (roster-based projections, not receipts) and engagement, with Chart.js-compatible series.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'report_type' => array(
                    'type' => 'string',
                    'enum' => self::REPORT_TYPES,
                    'description' => 'Which analysis to produce.',
                ),
                'date_range' => array(
                    'type' => 'object',
                    'description' => 'Narrows the attendance sessions and enrolment dates counted.',
                    'properties' => array(
                        'start_date' => array('type' => 'string', 'description' => 'YYYY-MM-DD.'),
                        'end_date' => array('type' => 'string', 'description' => 'YYYY-MM-DD.'),
                    ),
                ),
                'filters' => array(
                    'type' => 'object',
                    'description' => 'Narrows which activities are included.',
                    'properties' => array(
                        'eca_type' => array('type' => 'string', 'description' => 'Only this `_eca_type`.'),
                        'year_group' => array('type' => 'string', 'description' => 'Only activities listing this year group.'),
                    ),
                ),
            ),
            'required' => array('report_type'),
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

        $report_type = $this->stringParam($arguments, 'report_type');

        if (!\in_array($report_type, self::REPORT_TYPES, true)) {
            return $this->errors->validationFailed(
                'Unknown report_type "' . $report_type . '".',
                array('report_type' => array('Use one of ' . \implode(', ', self::REPORT_TYPES) . '.')),
            );
        }

        $range = \is_array($arguments['date_range'] ?? null) ? $arguments['date_range'] : array();
        $from  = \trim((string) ($range['start_date'] ?? ''));
        $to    = \trim((string) ($range['end_date'] ?? ''));

        foreach (array('start_date' => $from, 'end_date' => $to) as $key => $value) {
            if ('' !== $value && !\preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return $this->errors->validationFailed(
                    'date_range.' . $key . ' must be YYYY-MM-DD.',
                    array('date_range' => array('Use YYYY-MM-DD for both dates.')),
                );
            }
        }

        $filters = \is_array($arguments['filters'] ?? null) ? $arguments['filters'] : array();
        $type    = \trim((string) ($filters['eca_type'] ?? ''));
        $year    = \trim((string) ($filters['year_group'] ?? ''));

        try {
            $page = $this->content->query(
                new ContentQuery(types: array('mcp_ai_eca'), perPage: self::SCAN_LIMIT)
            );
        } catch (\Throwable $e) {
            return $this->errors->create('analytics_failed', $e->getMessage());
        }

        $rows = array();

        foreach ($page->items as $eca) {
            if (!$eca instanceof ContentItem) {
                continue;
            }

            if ('' !== $type && $type !== \trim((string) ($eca->meta['_eca_type'] ?? ''))) {
                continue;
            }

            if ('' !== $year && !EcaFilter::matchesList($eca->meta['_eca_year_groups'] ?? array(), $year)) {
                continue;
            }

            $rows[] = $this->row($eca, $from, $to);
        }

        return $this->success(
            \count($rows) . ' activity(ies) analysed.',
            array(
                'report_type' => $report_type,
                'date_range'  => array('start_date' => $from, 'end_date' => $to),
                'filters'     => array('eca_type' => $type, 'year_group' => $year),
                'summary'     => $this->summary($report_type, $rows),
                'rows'        => $rows,
                'chart_data'  => $this->chart($report_type, $rows),
                'truncated'   => $page->total > self::SCAN_LIMIT,
            )
        );
    }

    /**
     * One activity, with the fields every report type reads.
     *
     * @return array<string, mixed>
     */
    private function row(ContentItem $eca, string $from, string $to): array
    {
        $enrolled = \count(EcaRow::enrollments($eca));
        $capacity = (int) ($eca->meta['_eca_max_students'] ?? 0);
        $is_paid  = 'yes' === \strtolower(\trim((string) ($eca->meta['_eca_is_paid'] ?? '')));
        $cost     = (float) ($eca->meta['_eca_cost'] ?? 0);

        $sessions = 0;
        $present  = 0;
        $late     = 0;
        $records  = 0;

        foreach (AttendanceLog::sessions($eca->meta['_eca_attendance_log'] ?? array()) as $date => $session) {
            if ('' !== $from && $date < $from) {
                continue;
            }

            if ('' !== $to && $date > $to) {
                continue;
            }

            ++$sessions;

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
            'eca_id'            => $eca->id,
            'eca_name'          => $eca->title,
            'eca_type'          => (string) ($eca->meta['_eca_type'] ?? ''),
            'eca_status'        => (string) ($eca->meta['_eca_status'] ?? ''),
            'eca_term'          => (string) ($eca->meta['_eca_term'] ?? ''),
            'capacity'          => $capacity,
            'enrolled'          => $enrolled,
            'fill_rate'         => $capacity > 0 ? \round(($enrolled / $capacity) * 100, 1) : 0.0,
            'is_paid'           => $is_paid,
            'cost'              => $is_paid ? $cost : 0.0,
            'currency'          => (string) ($eca->meta['_eca_currency'] ?? ''),
            'cost_period'       => (string) ($eca->meta['_eca_cost_period'] ?? ''),
            'projected_revenue' => $is_paid ? \round($cost * $enrolled, 2) : 0.0,
            'sessions_logged'   => $sessions,
            'attendance_records' => $records,
            'present'           => $present,
            'late'              => $late,
            'attendance_rate'   => AttendanceLog::rate($present, $late, $records),
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function summary(string $report_type, array $rows): array
    {
        $enrolments = 0;

        foreach ($rows as $row) {
            $enrolments += (int) $row['enrolled'];
        }

        switch ($report_type) {
            case 'participation':
                $by_type = array();

                foreach ($rows as $row) {
                    $key            = '' !== (string) $row['eca_type'] ? (string) $row['eca_type'] : 'unspecified';
                    $by_type[ $key ] = ($by_type[ $key ] ?? 0) + 1;
                }

                \ksort($by_type);

                return array(
                    'total_ecas'         => \count($rows),
                    'total_enrolments'   => $enrolments,
                    'average_enrolments' => array() === $rows ? 0.0 : \round($enrolments / \count($rows), 1),
                    'by_type'            => $by_type,
                );

            case 'capacity':
                $capacity = 0;
                $full     = 0;
                $over     = 0;
                $no_limit = 0;

                foreach ($rows as $row) {
                    $max = (int) $row['capacity'];
                    $capacity += $max;

                    if ($max <= 0) {
                        ++$no_limit;
                        continue;
                    }

                    if ((int) $row['enrolled'] > $max) {
                        ++$over;
                    } elseif ((int) $row['enrolled'] === $max) {
                        ++$full;
                    }
                }

                return array(
                    'total_capacity'     => $capacity,
                    'total_enrolled'     => $enrolments,
                    'overall_fill_rate'  => $capacity > 0 ? \round(($enrolments / $capacity) * 100, 1) : 0.0,
                    'full_ecas'          => $full,
                    'over_capacity'      => $over,
                    'without_limit'      => $no_limit,
                    'spare_places'       => $capacity > $enrolments ? $capacity - $enrolments : 0,
                );

            case 'financial':
                $paid     = 0;
                $free     = 0;
                $currency = array();

                foreach ($rows as $row) {
                    if (!(bool) $row['is_paid']) {
                        ++$free;
                        continue;
                    }

                    ++$paid;

                    $key              = '' !== (string) $row['currency'] ? (string) $row['currency'] : 'unspecified';
                    $currency[ $key ] = \round(($currency[ $key ] ?? 0) + (float) $row['projected_revenue'], 2);
                }

                \ksort($currency);

                return array(
                    'paid_ecas'               => $paid,
                    'free_ecas'               => $free,
                    'projected_by_currency'   => $currency,
                    'note'                    => 'projected_revenue is cost x enrolments from the roster, not money received; this runtime keeps no payments ledger.',
                );

            default:
                $sessions = 0;
                $records  = 0;
                $attended = 0;
                $silent   = 0;

                foreach ($rows as $row) {
                    $sessions += (int) $row['sessions_logged'];
                    $records  += (int) $row['attendance_records'];

                    if ((int) $row['sessions_logged'] === 0) {
                        ++$silent;
                    }

                    $attended += (int) $row['present'] + (int) $row['late'];
                }

                return array(
                    'sessions_logged'         => $sessions,
                    'attendance_records'      => $records,
                    'average_attendance_rate' => AttendanceLog::rate($attended, 0, $records),
                    'ecas_without_sessions'   => $silent,
                );
        }
    }

    /**
     * Chart.js-shaped data for the metric each report type is about.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function chart(string $report_type, array $rows): array
    {
        $metric = array(
            'participation' => 'enrolled',
            'capacity'      => 'fill_rate',
            'financial'     => 'projected_revenue',
            'engagement'    => 'attendance_rate',
        )[ $report_type ];

        $labels = array();
        $data   = array();

        foreach ($rows as $row) {
            $labels[] = (string) $row['eca_name'];
            $data[]   = $row[ $metric ];
        }

        return array(
            'labels'   => $labels,
            'datasets' => array(
                array('label' => $metric, 'data' => $data),
            ),
        );
    }
}
