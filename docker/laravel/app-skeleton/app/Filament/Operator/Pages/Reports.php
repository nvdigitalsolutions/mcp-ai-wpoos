<?php

namespace App\Filament\Operator\Pages;

use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Assets\AssetRegister;
use App\Support\Reports\ComplianceReport;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Compliance reports — Proposal 149 §34, `/ops/reports`.
 *
 * The page **is** the HTML report: metadata, the classification summary and the
 * highest-classified assets, rendered from the same `ComplianceReport` the
 * downloads serialise. There is no third renderer to keep in sync, and nothing
 * is persisted — the file is streamed from memory on demand.
 *
 * ## Formats
 *
 * CSV and JSON only. The WordPress generator offers the same two plus HTML and
 * returns an error for PDF/DOCX/Excel — *"requires additional libraries. HTML/
 * CSV/JSON are available now."* Adding a PDF engine to render one table is the
 * wrong trade, so the omission is deliberate and stated on the page rather than
 * hidden behind a disabled button.
 *
 * ## The one write
 *
 * A download records an audit row (`resource: reports`). That is the only write
 * this surface performs, and it is why the contract row keeps `writer: ops`
 * while its state is `read-only`: the surface writes no report data, but an
 * export of a security register must leave a trace of who exported it.
 */
class Reports extends Page
{
    protected static ?string $slug = 'reports';

    protected static ?string $navigationLabel = 'Reports';

    protected static ?string $title = 'Compliance reports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    // With the compliance surfaces: assets, then reports.
    protected static ?int $navigationSort = 14;

    protected string $view = 'filament.operator.reports';

    /** Rows the page renders before deferring to the download. */
    public const PREVIEW_ROWS = 25;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessOperatorPanel($user);
    }

    /**
     * The built report.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        return ComplianceReport::build(app(SettingsStoreInterface::class), $this->generatedBy());
    }

    /**
     * The assets shown on the page. The download carries all of them; this
     * window is what a page can render without becoming a wall of rows.
     *
     * @param array<string, mixed> $report
     * @return list<array<string, mixed>>
     */
    public function preview(array $report): array
    {
        return array_slice((array) ($report['assets'] ?? array()), 0, self::PREVIEW_ROWS);
    }

    /**
     * @return array<string, string>
     */
    public function classifications(): array
    {
        return AssetRegister::CLASSIFICATIONS;
    }

    public function classificationLabel(string $classification): string
    {
        return (string) (AssetRegister::CLASSIFICATIONS[$classification] ?? $classification);
    }

    /**
     * The preview ceiling, so the view does not reach for the class constant.
     */
    public function previewLimit(): int
    {
        return self::PREVIEW_ROWS;
    }

    /**
     * Stream the CSV.
     *
     * The content is built before the response so a serialisation failure is a
     * page error rather than a truncated file that looks like a short report.
     */
    public function downloadCsv(): StreamedResponse
    {
        return $this->download('csv');
    }

    /**
     * Stream the JSON.
     */
    public function downloadJson(): StreamedResponse
    {
        return $this->download('json');
    }

    private function download(string $format): StreamedResponse
    {
        $report = $this->report();

        // `now()` rather than `time()` so a test can freeze the clock and
        // assert the filename, and so the stamp matches every other timestamp
        // this runtime writes.
        $stamp = now()->getTimestamp();

        $content = 'csv' === $format
            ? ComplianceReport::toCsv($report)
            : ComplianceReport::toJson($report);

        $filename = ComplianceReport::filename($format, $stamp);

        // The export of a security register must leave a trace. The row records
        // the format and the asset count, never the content.
        app(AdminAuditLogger::class)->recordResource(
            'reports',
            $filename,
            'generate',
            array(),
            array(
                'format' => $format,
                'assets' => (int) ($report['summary']['total_assets'] ?? 0),
                'audit_rows' => count((array) ($report['audit'] ?? array())),
            )
        );

        return response()->streamDownload(
            static function () use ($content): void {
                echo $content;
            },
            $filename,
            array('Content-Type' => ComplianceReport::contentType($format))
        );
    }

    /**
     * Who the report says generated it.
     */
    private function generatedBy(): string
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return 'unknown';
        }

        $name = trim((string) $user->name);

        return '' === $name ? 'user #'.$user->getKey() : $name;
    }
}
