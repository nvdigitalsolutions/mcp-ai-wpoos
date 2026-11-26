<?php

namespace App\Filament\Operator\Pages;

use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Health\SiteHealth;
use App\Filament\Operator\Navigation\OperatorNavigationGroup;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Runtime health — Proposal 149 §31, `/ops/site-health`.
 *
 * READ-ONLY and stateless: every page load re-runs the probes, so there is
 * nothing to save, retry or acknowledge, and the contract row declares
 * `writer: none`. A "last checked" timestamp would be a lie the moment the
 * page is closed.
 *
 * Operator-only and cross-estate, because the probes describe the runtime
 * rather than an estate. Estates are `/ops/estates`; this is the machine those
 * estates run on.
 *
 * The probe set is a deliberate port of the eleven `site_status_tests` the
 * WordPress plugin registers with core Site Health, minus the ones that need
 * WordPress itself (file integrity, MCP schema auditor, per-model availability).
 * `SiteHealth` documents the mapping table.
 */
class SiteHealthPage extends Page
{
    protected static ?string $slug = 'site-health';

    protected static ?string $navigationLabel = 'Site health';

    protected static ?string $title = 'Runtime health';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    // After the audit log: diagnostics last, once the operational surfaces are
    // known.
    protected static string|UnitEnum|null $navigationGroup = OperatorNavigationGroup::Health;
    protected static ?int $navigationSort = 12;

    protected string $view = 'filament.operator.site-health';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessOperatorPanel($user);
    }

    /**
     * @return list<array{id: string, label: string, status: string, summary: string, detail: string}>
     */
    public function probes(): array
    {
        return SiteHealth::probes();
    }

    /**
     * @param list<array<string, mixed>> $probes
     * @return array{total: int, good: int, recommended: int, critical: int}
     */
    public function totals(array $probes): array
    {
        return SiteHealth::totals($probes);
    }

    /**
     * @param list<array<string, mixed>> $probes
     */
    public function overall(array $probes): string
    {
        return SiteHealth::overall($probes);
    }
}
