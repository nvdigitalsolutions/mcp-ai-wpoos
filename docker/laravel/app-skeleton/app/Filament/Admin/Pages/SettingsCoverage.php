<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Navigation\AdminNavigationGroup;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Settings\SettingsCoverage as Coverage;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Settings coverage — Proposal 149 §183, `/admin/settings-coverage`.
 *
 * Read-only on purpose. `/admin/settings` is where values are written; this page
 * answers the question the form cannot: *which of these controls does anything?*
 *
 * WordPress's `orchestration` → `settings` view is thirty-five toggles over
 * software that partly exists here and partly cannot. Porting the toggles as a
 * page would have produced a second settings editor with the same fiction the
 * main form already carries. What an operator needs is the audit, so that is
 * what this page is.
 *
 * The report is generated, never authored (§183): every bucket is a claim about
 * source code, and a hand-written claim about code is wrong the first time the
 * code moves. `SettingsCoverage` reads the artefact; nothing here derives a
 * verdict from a database row, so the page renders identically on every estate
 * and does not fail when the database is the problem.
 *
 * **The unenforced bucket leads.** A writable security control that no
 * enforcement point reads is the most dangerous row on the page: it records an
 * intention and changes nothing, so an operator who sets it believes they have
 * hardened a runtime they have not touched. Presenting it below 469 neutral
 * rows would be the same silent false assurance, one level up.
 *
 * @see \App\Support\Settings\SettingsCoverage
 */
class SettingsCoverage extends Page
{
    protected static ?string $slug = 'settings-coverage';

    protected static ?string $navigationLabel = 'Coverage';

    protected static ?string $title = 'Settings coverage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    // After Presets: both explain the Settings page, and neither writes.
    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Settings;

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.admin.settings-coverage';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessTenantPanel($user);
    }

    /**
     * Counts per bucket, ordered for display.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return Coverage::counts();
    }

    public function total(): int
    {
        return Coverage::total();
    }

    /**
     * The buckets present in the artefact, in the order they should be read.
     *
     * @return list<string>
     */
    public function buckets(): array
    {
        return Coverage::presentBuckets();
    }

    public function label(string $bucket): string
    {
        return Coverage::label($bucket);
    }

    public function reason(string $bucket): string
    {
        return Coverage::reason($bucket);
    }

    /**
     * The keys whose relationship to behaviour is unclear, per bucket.
     *
     * @return array<string, list<array{key: string, bucket: string, reason: string}>>
     */
    public function problemRows(): array
    {
        $rows = array();

        foreach (Coverage::problemBuckets() as $bucket) {
            $rows[ $bucket ] = Coverage::byBucket($bucket);
        }

        return $rows;
    }

    /**
     * The share of the catalogue that changes behaviour, for the headline.
     *
     * Rounded to a whole percent: two decimals would suggest a precision the
     * classification does not have.
     */
    public function enforcedPercent(): string
    {
        $total = $this->total();

        if (0 === $total) {
            return '—';
        }

        $enforced = Coverage::counts()['enforced'] ?? 0;

        return (string) (int) round(100 * $enforced / $total).'%';
    }

    /**
     * Does the artefact exist and parse?
     *
     * The page states its own failure rather than rendering an empty report that
     * reads as "nothing to see".
     */
    public function hasData(): bool
    {
        return $this->total() > 0;
    }

    /**
     * Per-bucket tone: the accent colour and wash used by the summary cards.
     *
     * `unenforced` is the only red. `reported` and `unclassified` are amber —
     * possibly fine, worth a look. Declared buckets are neutral because they are
     * the system working as designed, and `enforced` is green because it is the
     * answer.
     *
     * @return array{0: string, 1: string}
     */
    public function tone(string $bucket): array
    {
        return match ($bucket) {
            'unenforced' => array('#b91c1c', 'rgba(220, 38, 38, 0.10)'),
            'unenforced-operator' => array('#9a3412', 'rgba(234, 88, 12, 0.10)'),
            'unclassified', 'reported', 'stale' => array('#92400e', 'rgba(245, 158, 11, 0.14)'),
            'enforced' => array('#166534', 'rgba(34, 197, 94, 0.12)'),
            default => array('#1e40af', 'rgba(59, 130, 246, 0.10)'),
        };
    }
}
