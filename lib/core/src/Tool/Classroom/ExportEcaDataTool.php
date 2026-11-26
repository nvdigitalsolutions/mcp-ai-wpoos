<?php

/**
 * ECA data as a CSV in the file registry — Proposal 164 §164.58.
 *
 * Ported from `WP_MCP_AI_Tool_Export_ECA_Data`: same five export types, same
 * filters and date range. The difference is where the file goes. WordPress
 * writes to `wp-content/uploads` and returns a URL; here the CSV is stored
 * through the file registry, so it gets an id, a slug and a visibility — and
 * **private by default**, because an attendance or financial export names
 * children. A public default would put those names on a web-served path, which
 * is the one mistake an export tool must not make quietly.
 *
 * ### Money is still a derivation
 *
 * `financial` exports cost, currency, paid flag and a projected figure from the
 * roster. This runtime has no payments ledger (§164.54), so the column is named
 * `projected_revenue` and the payload repeats the caveat: a spreadsheet column
 * called "revenue" that is really "expected from the roster" would be read as
 * receipts.
 *
 * ### A row cap, reported
 *
 * An export is capped at 20,000 rows and `truncated` says when the cap was hit.
 * Silently exporting half a term's attendance as if it were all of it is the
 * failure mode a truncated flag exists to prevent.
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
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Tool\AbstractTool;

class ExportEcaDataTool extends AbstractTool
{
    private const EXPORT_TYPES = array('ecas', 'students', 'enrollments', 'attendance', 'financial');

    private const SCAN_LIMIT = 200;

    private const ROW_CAP = 20000;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
        private readonly ?FileVisibilityStoreInterface $files = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'export_eca_data';
    }

    public function getName(): string
    {
        return 'Export ECA Data';
    }

    public function getDescription(): string
    {
        return 'Exports activities, students, enrolments, attendance or financial rows as a CSV stored in the file registry (private by default, because these rows name children). Filters by type, year group, status, activity and date range.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'export_type' => array(
                    'type' => 'string',
                    'enum' => self::EXPORT_TYPES,
                    'description' => 'Which rows to export.',
                ),
                'filters' => array(
                    'type' => 'object',
                    'description' => 'Narrows which rows are exported.',
                    'properties' => array(
                        'eca_type' => array('type' => 'string', 'description' => 'Only this `_eca_type`.'),
                        'year_group' => array('type' => 'string', 'description' => 'Only activities listing this year group.'),
                        'status' => array('type' => 'string', 'description' => 'Only this `_eca_status`.'),
                        'eca_id' => array('type' => 'integer', 'description' => 'Only this activity.', 'minimum' => 1),
                    ),
                ),
                'date_range' => array(
                    'type' => 'object',
                    'description' => 'Narrows attendance dates and enrolment dates.',
                    'properties' => array(
                        'start_date' => array('type' => 'string', 'description' => 'YYYY-MM-DD.'),
                        'end_date' => array('type' => 'string', 'description' => 'YYYY-MM-DD.'),
                    ),
                ),
                'visibility' => array(
                    'type' => 'string',
                    'enum' => array(FileVisibilityStoreInterface::VISIBILITY_PRIVATE, FileVisibilityStoreInterface::VISIBILITY_PUBLIC),
                    'default' => FileVisibilityStoreInterface::VISIBILITY_PRIVATE,
                    'description' => 'Stored file visibility. Private by default: these rows name students.',
                ),
                'file_name' => array(
                    'type' => 'string',
                    'description' => 'Optional base file name for the export.',
                ),
            ),
            'required' => array('export_type'),
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

        $type = $this->stringParam($arguments, 'export_type');

        if (!\in_array($type, self::EXPORT_TYPES, true)) {
            return $this->errors->validationFailed(
                'Unknown export_type "' . $type . '".',
                array('export_type' => array('Use one of ' . \implode(', ', self::EXPORT_TYPES) . '.')),
            );
        }

        if (null === $this->files) {
            return $this->errors->create(
                'no_file_store',
                'No file registry is bound on this host, so the export has nowhere to live.',
            );
        }

        $filters = \is_array($arguments['filters'] ?? null) ? $arguments['filters'] : array();
        $range   = \is_array($arguments['date_range'] ?? null) ? $arguments['date_range'] : array();

        $from = \trim((string) ($range['start_date'] ?? ''));
        $to   = \trim((string) ($range['end_date'] ?? ''));

        try {
            $built = $this->build($type, $filters, $from, $to);
        } catch (\Throwable $e) {
            return $this->errors->create('export_failed', $e->getMessage());
        }

        if (\is_array($built) && isset($built['error'])) {
            return $built['error'];
        }

        $path = $this->writeCsv($built['header'], $built['rows']);

        if (null === $path) {
            return $this->errors->create('export_failed', 'Could not write the export to a temporary file.');
        }

        $name = $this->stringParam($arguments, 'file_name');
        $name = '' !== \trim($name) ? \trim($name) : 'eca-' . $type . '-' . \gmdate('Ymd-His');

        try {
            $file = $this->files->storeWithVisibility(
                $path,
                $name . '.csv',
                'text/csv',
                (int) ($context['user_id'] ?? 0),
                $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PRIVATE),
                isset($context['tenant_id']) ? (string) $context['tenant_id'] : null,
            );
        } catch (\Throwable $e) {
            return $this->errors->create('export_not_stored', $e->getMessage());
        } finally {
            if (\is_file($path)) {
                \unlink($path);
            }
        }

        $payload = array(
            'export_type' => $type,
            'rows'        => \count($built['rows']),
            'columns'     => $built['header'],
            'truncated'   => $built['truncated'],
            'file'        => array(
                'attachment_id' => $file->id,
                'slug'          => $file->slug,
                'filename'      => $file->filename,
                'mime_type'     => $file->mimeType,
                'size_bytes'    => $file->sizeBytes,
                'visibility'    => $file->visibility,
                'source_url'    => $file->publicUrl,
            ),
        );

        if ('financial' === $type) {
            $payload['note'] = 'projected_revenue is cost x enrolments from the roster, not money received; this runtime keeps no payments ledger.';
        }

        return $this->success(
            \count($built['rows']) . ' row(s) exported to ' . $file->filename . '.',
            $payload
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{header: list<string>, rows: list<list<mixed>>, truncated: bool}|array{error: mixed}
     */
    private function build(string $type, array $filters, string $from, string $to): array
    {
        $ecas = $this->ecas($filters);
        $cap  = null;
        $kind = $type;

        switch ($kind) {
            case 'ecas':
                $header = array('eca_id', 'eca_name', 'eca_type', 'eca_status', 'eca_term', 'day', 'start_time', 'end_time', 'venue', 'teachers', 'capacity', 'enrolled', 'is_paid', 'cost', 'currency', 'cost_period');
                $rows   = array();

                foreach ($ecas as $eca) {
                    $rows[] = array(
                        $eca->id,
                        $eca->title,
                        (string) ($eca->meta['_eca_type'] ?? ''),
                        (string) ($eca->meta['_eca_status'] ?? ''),
                        (string) ($eca->meta['_eca_term'] ?? ''),
                        (string) ($eca->meta['_eca_day'] ?? ''),
                        (string) ($eca->meta['_eca_start_time'] ?? ''),
                        (string) ($eca->meta['_eca_end_time'] ?? ''),
                        (string) ($eca->meta['_eca_venue'] ?? ''),
                        \implode('; ', $this->names($eca->meta['_eca_teachers'] ?? array())),
                        (int) ($eca->meta['_eca_max_students'] ?? 0),
                        \count(EcaRow::enrollments($eca)),
                        (string) ($eca->meta['_eca_is_paid'] ?? ''),
                        (string) ($eca->meta['_eca_cost'] ?? ''),
                        (string) ($eca->meta['_eca_currency'] ?? ''),
                        (string) ($eca->meta['_eca_cost_period'] ?? ''),
                    );
                }
                break;

            case 'students':
                $header = array('student_id', 'student_name', 'year_group', 'email', 'enrolments');
                $rows   = array();

                foreach ($this->students() as $student) {
                    $year = \trim((string) ($filters['year_group'] ?? ''));

                    if ('' !== $year && 0 !== \strcasecmp((string) ($student->meta['_student_year_group'] ?? ''), $year)) {
                        continue;
                    }

                    $rows[] = array(
                        $student->id,
                        $student->title,
                        (string) ($student->meta['_student_year_group'] ?? ''),
                        (string) ($student->meta['_student_email'] ?? ''),
                        \count(Enrollments::map($student->meta['_student_eca_enrollments'] ?? array())),
                    );
                }
                break;

            case 'enrollments':
                $header = array('eca_id', 'eca_name', 'student_id', 'student_name', 'enrollment_type', 'enrollment_date');
                $rows   = array();

                foreach ($ecas as $eca) {
                    foreach (EcaRow::enrollments($eca) as $student_id => $record) {
                        $date = (string) ($record['enrollment_date'] ?? '');

                        if (('' !== $from && $date < $from) || ('' !== $to && $date > $to)) {
                            continue;
                        }

                        $student = $this->content->find((int) $student_id);

                        $rows[] = array(
                            $eca->id,
                            $eca->title,
                            (int) $student_id,
                            $student instanceof ContentItem ? $student->title : '',
                            (string) ($record['enrollment_type'] ?? 'confirmed'),
                            $date,
                        );
                    }
                }
                break;

            case 'attendance':
                $header = array('date', 'eca_id', 'eca_name', 'student_id', 'student_name', 'status', 'session_notes');
                $rows   = array();

                foreach ($ecas as $eca) {
                    foreach (AttendanceLog::sessions($eca->meta['_eca_attendance_log'] ?? array()) as $date => $session) {
                        if (('' !== $from && $date < $from) || ('' !== $to && $date > $to)) {
                            continue;
                        }

                        foreach (AttendanceLog::attendees($session) as $attendee) {
                            $rows[] = array(
                                $date,
                                $eca->id,
                                $eca->title,
                                (int) ($attendee['student_id'] ?? 0),
                                (string) ($attendee['student_name'] ?? ''),
                                (string) ($attendee['status'] ?? ''),
                                (string) ($session['session_notes'] ?? ''),
                            );
                        }
                    }
                }
                break;

            default:
                $header = array('eca_id', 'eca_name', 'is_paid', 'cost', 'currency', 'cost_period', 'enrolled', 'projected_revenue');
                $rows   = array();

                foreach ($ecas as $eca) {
                    $enrolled = \count(EcaRow::enrollments($eca));
                    $paid     = 'yes' === \strtolower(\trim((string) ($eca->meta['_eca_is_paid'] ?? '')));
                    $cost     = (float) ($eca->meta['_eca_cost'] ?? 0);

                    $rows[] = array(
                        $eca->id,
                        $eca->title,
                        $paid ? 'yes' : 'no',
                        $paid ? $cost : 0.0,
                        (string) ($eca->meta['_eca_currency'] ?? ''),
                        (string) ($eca->meta['_eca_cost_period'] ?? ''),
                        $enrolled,
                        $paid ? \round($cost * $enrolled, 2) : 0.0,
                    );
                }
        }

        $truncated = \count($rows) > self::ROW_CAP;

        if ($truncated) {
            $rows = \array_slice($rows, 0, self::ROW_CAP);
        }

        return array('header' => $header, 'rows' => $rows, 'truncated' => $truncated);
    }

    /**
     * The activities the export covers, after the activity-level filters.
     *
     * @param array<string, mixed> $filters
     * @return list<ContentItem>
     */
    private function ecas(array $filters): array
    {
        $type   = \trim((string) ($filters['eca_type'] ?? ''));
        $year   = \trim((string) ($filters['year_group'] ?? ''));
        $status = \trim((string) ($filters['status'] ?? ''));
        $only   = (int) ($filters['eca_id'] ?? 0);

        $page = $this->content->query(new ContentQuery(types: array('mcp_ai_eca'), perPage: self::SCAN_LIMIT));
        $rows = array();

        foreach ($page->items as $eca) {
            if (!$eca instanceof ContentItem) {
                continue;
            }

            if ($only > 0 && $eca->id !== $only) {
                continue;
            }

            if ('' !== $type && $type !== \trim((string) ($eca->meta['_eca_type'] ?? ''))) {
                continue;
            }

            if ('' !== $status && $status !== \trim((string) ($eca->meta['_eca_status'] ?? ''))) {
                continue;
            }

            if ('' !== $year && !EcaFilter::matchesList($eca->meta['_eca_year_groups'] ?? array(), $year)) {
                continue;
            }

            $rows[] = $eca;
        }

        return $rows;
    }

    /**
     * @return list<ContentItem>
     */
    private function students(): array
    {
        $page = $this->content->query(new ContentQuery(types: array('mcp_ai_student'), perPage: self::SCAN_LIMIT));
        $rows = array();

        foreach ($page->items as $student) {
            if ($student instanceof ContentItem) {
                $rows[] = $student;
            }
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function names(mixed $value): array
    {
        if (\is_string($value)) {
            return array('' === \trim($value) ? '' : \trim($value));
        }

        if (!\is_array($value)) {
            return array();
        }

        $names = array();

        foreach ($value as $item) {
            if (\is_scalar($item)) {
                $names[] = \trim((string) $item);
            }
        }

        return $names;
    }

    /**
     * @param list<string>  $header
     * @param list<list<mixed>> $rows
     */
    private function writeCsv(array $header, array $rows): ?string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'eca-export-');

        if (false === $path) {
            return null;
        }

        $handle = \fopen($path, 'w');

        if (false === $handle) {
            return null;
        }

        \fputcsv($handle, $header);

        foreach ($rows as $row) {
            \fputcsv($handle, $row);
        }

        \fclose($handle);

        return $path;
    }
}
