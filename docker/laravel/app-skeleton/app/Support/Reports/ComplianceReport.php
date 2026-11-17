<?php

namespace App\Support\Reports;

use App\Support\Admin\AuditTrail;
use App\Support\Assets\AssetRegister;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

/**
 * The compliance report — Proposal 149 §34.
 *
 * Two sections, because those are the two the runtime can evidence:
 *
 * | Section | Source | WordPress equivalent |
 * |---|---|---|
 * | Assets | `AssetRegister` | `$data['controls']` + the asset inventory |
 * | Audit trail | `AuditTrail` over `nvoos_admin_audit` | `$data['audit_trail']` |
 *
 * WordPress's generator also emits `controls`, `risks`, `evidence` and
 * `frameworks`, from a Pro compliance database this runtime does not have. Those
 * are **omitted rather than emitted empty**: a report with a "Risks" heading and
 * no rows reads as "no risks", which is a claim, not an absence.
 *
 * ## Formats: CSV and JSON, and that is the whole list
 *
 * The WordPress generator offers HTML/CSV/JSON and returns an error for
 * PDF/DOCX/Excel — *"requires additional libraries. HTML/CSV/JSON are available
 * now."* The same decision holds here for a stronger reason: those formats need a
 * dependency this runtime does not ship, and a report generator that pulls in a
 * PDF engine to render one table is the wrong trade.
 *
 * No standalone HTML artifact either: the page on `/ops/reports` **is** the HTML
 * report. A second HTML file would be a second renderer to keep in sync.
 *
 * ## Shape
 *
 * The CSV mirrors the WordPress sectioning — a `Metadata` block, then `Summary`,
 * then one heading + header row per section. Mirroring it means an auditor who has
 * seen the WordPress export recognises this one, and a spreadsheet can still be
 * read by a human without a schema.
 */
final class ComplianceReport
{
    /** Audit rows carried in a report. The page shows none of these directly. */
    public const AUDIT_ROWS = 1000;

    public const SCOPE_FULL = 'full';

    /**
     * Build the report from what the runtime can evidence.
     *
     * Pure apart from the two readers it is handed, so a test can assert the
     * shape without a database.
     *
     * @return array{
     *     metadata: array<string, string>,
     *     summary: array{total_assets: int, highest: string, by_classification: array<string, int>, by_type: array<string, int>},
     *     assets: list<array<string, mixed>>,
     *     audit: list<array<string, mixed>>
     * }
     */
    public static function build(SettingsStoreInterface $settings, string $generatedBy): array
    {
        $assets = AssetRegister::assets($settings);
        $statistics = AssetRegister::statistics($assets);

        return array(
            'metadata' => array(
                'report_date' => gmdate('c'),
                'generated_by' => $generatedBy,
                'scope' => self::SCOPE_FULL,
                'runtime' => 'laravel',
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'environment' => (string) config('app.env'),
                'sections' => 'assets, audit',
            ),
            'summary' => array(
                'total_assets' => $statistics['total'],
                'highest' => $statistics['highest'],
                'by_classification' => $statistics['by_classification'],
                'by_type' => $statistics['by_type'],
            ),
            'assets' => $assets,
            'audit' => AuditTrail::rows(array(), self::AUDIT_ROWS),
        );
    }

    /**
     * The sectioned CSV, matching the WordPress export's structure.
     *
     * @param array<string, mixed> $report Output of self::build().
     */
    public static function toCsv(array $report): string
    {
        $handle = fopen('php://temp', 'r+');

        if (false === $handle) {
            // php://temp cannot fail in practice; if it does, an empty file is
            // still better than a fatal on a compliance export.
            return '';
        }

        fputcsv($handle, array('Metadata'));

        foreach ((array) ($report['metadata'] ?? array()) as $key => $value) {
            fputcsv($handle, array((string) $key, (string) $value));
        }

        fputcsv($handle, array());

        fputcsv($handle, array('Summary'));
        fputcsv($handle, array('total_assets', (string) (int) ($report['summary']['total_assets'] ?? 0)));
        fputcsv($handle, array('highest_classification', (string) ($report['summary']['highest'] ?? '')));

        foreach ((array) ($report['summary']['by_classification'] ?? array()) as $level => $count) {
            fputcsv($handle, array('classification:'.(string) $level, (string) (int) $count));
        }

        fputcsv($handle, array());
        fputcsv($handle, array('Assets'));
        fputcsv($handle, array('ID', 'Name', 'Type', 'Classification', 'Owner', 'Location', 'Detail'));

        foreach ((array) ($report['assets'] ?? array()) as $asset) {
            fputcsv($handle, array(
                (string) $asset['id'],
                (string) $asset['name'],
                (string) $asset['type'],
                (string) $asset['classification'],
                (string) $asset['owner'],
                (string) $asset['location'],
                (string) $asset['description'],
            ));
        }

        fputcsv($handle, array());
        fputcsv($handle, array(sprintf('Audit trail (newest %d)', self::AUDIT_ROWS)));
        fputcsv($handle, array('At', 'Actor', 'Panel', 'Estate', 'Kind', 'What', 'Status'));

        foreach ((array) ($report['audit'] ?? array()) as $row) {
            fputcsv($handle, array(
                (string) $row['at'],
                (string) $row['actor'],
                (string) $row['panel'],
                '' === (string) $row['estate'] ? 'operator' : (string) $row['estate'],
                (string) $row['kind'],
                (string) $row['label'],
                (string) (int) $row['status'],
            ));
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * The same report as JSON, with the full structure rather than the file's
     * flattened columns.
     *
     * @param array<string, mixed> $report Output of self::build().
     */
    public static function toJson(array $report): string
    {
        $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return false === $encoded ? '{}' : $encoded;
    }

    /**
     * A filename an auditor can sort: the timestamp leads, so exports from one
     * day group together in a directory listing.
     *
     * @param string $format `csv` or `json`.
     */
    public static function filename(string $format, int $timestamp): string
    {
        return sprintf('compliance-report-%s.%s', gmdate('Y-m-d-His', $timestamp), $format);
    }

    /**
     * The MIME type for a format. Anything unknown is served as text, which
     * browsers show rather than execute.
     */
    public static function contentType(string $format): string
    {
        return match ($format) {
            'csv' => 'text/csv; charset=UTF-8',
            'json' => 'application/json; charset=UTF-8',
            default => 'text/plain; charset=UTF-8',
        };
    }
}
