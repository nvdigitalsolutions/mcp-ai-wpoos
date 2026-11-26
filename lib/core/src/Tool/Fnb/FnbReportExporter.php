<?php

/**
 * Export an F&B report as CSV (data) or PDF (presentation).
 *
 * Port of `WP_MCP_AI_Fnb_Report_Exporter` (Proposal 164 section 164.75). The
 * report itself comes from {@see FnbReportBuilder}; this class only renders and
 * stores it.
 *
 * ## Three adaptations, each named
 *
 * - **CSV is stored, not written to a directory.** WordPress writes under the
 *   toolkit's `csv_data_dir` and returns an absolute path plus a content URL.
 *   This runtime has a file registry, so the bytes are written to a temporary
 *   file and handed to {@see FileVisibilityStoreInterface}; the payload carries
 *   the stored file's id and URL. `file` is added only when the store can also
 *   answer `getPath()`, because a path that does not exist would be worse than
 *   an absent key.
 * - **PDF uses the bound renderer, not DomPDF-or-a-gated-tool.** The WordPress
 *   ladder (DomPDF, then the document-generation HTML→PDF tool, then a typed
 *   error) collapses to {@see HtmlToPdfInterface}, which the host binds. An
 *   unbound or unavailable renderer is the same named refusal, with the same
 *   advice: export CSV instead.
 * - **No file registry is a named refusal.** WordPress always has a filesystem;
 *   a bare core host may have no store, and silently returning a temp path would
 *   hand back a file that disappears. So the export refuses by name.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Fnb;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\HtmlToPdfInterface;
use Nvoos\Core\Support\Text;

final class FnbReportExporter
{
    /**
     * Client report number → canonical report ID.
     *
     * The operator's three templates use their own 1-2-3 numbering; this maps
     * them onto the canonical registry without changing the report engine.
     */
    public const CLIENT_ALIASES = array(
        'client-r01' => 'R-14',
        'client-r02' => 'R-05',
        'client-r03' => 'R-08',
    );

    public function __construct(
        private readonly ErrorFactoryInterface $errors,
        private readonly FnbReportBuilder $builder,
        private readonly ?HtmlToPdfInterface $pdf = null,
        private readonly ?FileVisibilityStoreInterface $files = null,
    ) {
    }

    /**
     * Every report id the exporter accepts, canonical and aliased.
     *
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_merge(FnbReportBuilder::ids(), array_keys(self::CLIENT_ALIASES));
    }

    /**
     * Resolve a report ID (canonical R-* or client alias) to a canonical ID.
     */
    public static function resolveReportId(string $reportId): string
    {
        return self::CLIENT_ALIASES[ $reportId ] ?? $reportId;
    }

    /**
     * Export a report to CSV.
     *
     * @param array<string, mixed> $args    Report arguments (month, date, …).
     * @param array<string, mixed> $context Execution context (`user_id`, `tenant_id`).
     *
     * @return array<string, mixed>|mixed The export payload, or the platform's error object.
     */
    public function toCsv(string $reportId, array $args, array $context): mixed
    {
        $reportId = self::resolveReportId($reportId);
        $report   = $this->builder->build($reportId, $args);

        if ($this->errors->isError($report)) {
            return $report;
        }

        if (null === $this->files) {
            return $this->errors->create(
                'file_store_unavailable',
                'CSV export needs a file registry on this host. No FileVisibilityStoreInterface is bound.'
            );
        }

        $path = $this->writeCsv($report);

        if (null === $path) {
            return $this->errors->create(
                'csv_write_failed',
                'Could not write the CSV output to a temporary file.'
            );
        }

        $period = isset($args['month']) && '' !== $args['month']
            ? (string) $args['month']
            : (string) ($args['date'] ?? '');
        $stem = Text::slug((string) $report['title'] . '-' . $period);

        if ('' === $stem) {
            $stem = Text::slug((string) $report['title']);
        }

        if ('' === $stem) {
            $stem = 'fnb-report';
        }

        try {
            $file = $this->files->storeWithVisibility(
                $path,
                $stem . '.csv',
                'text/csv',
                (int) ($context['user_id'] ?? 0),
                FileVisibilityStoreInterface::VISIBILITY_PUBLIC,
                $this->tenant($context)
            );
        } catch (\Throwable $error) {
            return $this->errors->create('csv_store_failed', $error->getMessage());
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $payload = array(
            'report_id'     => $reportId,
            'mode'          => 'csv',
            'attachment_id' => $file->id,
            'filename'      => $file->filename,
            'url'           => (string) $file->publicUrl,
            'mime_type'     => $file->mimeType,
            'size'          => $file->sizeBytes,
            'size_bytes'    => $file->sizeBytes,
        );

        if ($this->files instanceof FileStoreInterface) {
            $stored = $this->files->getPath($file->id);

            if (null !== $stored) {
                $payload['file'] = $stored;
            }
        }

        return $payload;
    }

    /**
     * Export a report to PDF.
     *
     * @param array<string, mixed> $args    Report arguments.
     * @param array<string, mixed> $context Execution context.
     *
     * @return array<string, mixed>|mixed The export payload, or the platform's error object.
     */
    public function toPdf(string $reportId, array $args, array $context): mixed
    {
        $reportId = self::resolveReportId($reportId);
        $report   = $this->builder->build($reportId, $args);

        if ($this->errors->isError($report)) {
            return $report;
        }

        if (null === $this->pdf || ! $this->pdf->isAvailable()) {
            return $this->errors->create(
                'no_pdf_engine',
                'No PDF renderer is bound on this host. Configure the media worker (MEDIA_WORKER_URL) or install a PHP renderer; export the report as CSV instead.'
            );
        }

        if (null === $this->files) {
            return $this->errors->create(
                'file_store_unavailable',
                'PDF export needs a file registry on this host. No FileVisibilityStoreInterface is bound.'
            );
        }

        try {
            $rendered = $this->pdf->render(
                $this->toHtml($report),
                array(
                    'format'           => 'A4',
                    'landscape'        => false,
                    'print_background' => true,
                )
            );
        } catch (\Throwable $error) {
            return $this->errors->create('pdf_failed', $error->getMessage());
        }

        $stem = Text::slug((string) $report['title']);

        try {
            $file = $this->files->storeWithVisibility(
                $rendered['path'],
                ('' !== $stem ? $stem : 'fnb-report') . '.pdf',
                'application/pdf',
                (int) ($context['user_id'] ?? 0),
                FileVisibilityStoreInterface::VISIBILITY_PUBLIC,
                $this->tenant($context)
            );
        } catch (\Throwable $error) {
            return $this->errors->create('pdf_store_failed', $error->getMessage());
        }

        return array(
            'report_id'     => $reportId,
            'mode'          => 'pdf',
            'attachment_id' => $file->id,
            'filename'      => $file->filename,
            'url'           => (string) $file->publicUrl,
            'mime_type'     => $file->mimeType,
            'size'          => $file->sizeBytes,
            'size_bytes'    => $file->sizeBytes,
        );
    }

    /**
     * Flatten a built report's sections into CSV rows.
     *
     * @param array<string, mixed> $report Report from {@see FnbReportBuilder::build()}.
     *
     * @return list<list<string>>
     */
    public function rows(array $report): array
    {
        $rows   = array();
        $rows[] = array('section', 'key', 'value');

        foreach ((array) $report['sections'] as $section) {
            $heading = isset($section['heading']) ? (string) $section['heading'] : '';
            $lines   = isset($section['lines']) && is_array($section['lines']) ? $section['lines'] : array();

            foreach ($lines as $line) {
                if (is_string($line) && false !== strpos($line, ':')) {
                    list($key, $value) = explode(':', $line, 2);
                    $rows[] = array($heading, trim($key), trim($value));
                    continue;
                }

                $rows[] = array($heading, '', trim((string) $line));
            }
        }

        return $rows;
    }

    /**
     * Render a report's sections to a simple HTML document (for PDF).
     *
     * @param array<string, mixed> $report Report from {@see FnbReportBuilder::build()}.
     */
    public function toHtml(array $report): string
    {
        $title = isset($report['title']) ? (string) $report['title'] : 'F&B Report';
        $html  = '<h1>' . htmlspecialchars($title, ENT_QUOTES) . '</h1>';

        foreach ((array) $report['sections'] as $section) {
            $heading = isset($section['heading']) ? (string) $section['heading'] : '';
            $lines   = isset($section['lines']) && is_array($section['lines']) ? $section['lines'] : array();

            $html .= '<h2>' . htmlspecialchars($heading, ENT_QUOTES) . '</h2>';
            $html .= '<table border="1" cellspacing="0" cellpadding="6" width="100%">';

            foreach ($lines as $line) {
                $html .= '<tr><td>' . htmlspecialchars((string) $line, ENT_QUOTES) . '</td></tr>';
            }

            $html .= '</table>';
        }

        return $html;
    }

    /**
     * Write the flattened report to a temporary CSV file.
     *
     * @param array<string, mixed> $report Report.
     */
    private function writeCsv(array $report): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'nvoos-fnb-csv-');

        if (false === $path) {
            return null;
        }

        $handle = fopen($path, 'w');

        if (false === $handle) {
            unlink($path);

            return null;
        }

        foreach ($this->rows($report) as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return $path;
    }

    /**
     * @param array<string, mixed> $context Execution context.
     */
    private function tenant(array $context): ?string
    {
        $tenant = trim((string) ($context['tenant_id'] ?? ''));

        return '' !== $tenant ? $tenant : null;
    }
}
