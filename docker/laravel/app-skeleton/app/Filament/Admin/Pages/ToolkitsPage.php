<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Navigation\AdminNavigationGroup;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\CapabilityMap;
use App\Support\Pro\ToolkitEngine;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Nvoos\Core\Domain\Contract\ProEntitlementInterface;
use UnitEnum;

/**
 * Toolkit catalogue — Proposal 149 Wave 3 (149 §46 P4), `/admin/{estate}/toolkits`.
 *
 * The manifest engine already existed on the HTTP side (`/api/v1/pro/toolkits`).
 * This page is the panel's entry point: one card per manifest the estate is
 * **entitled** to, linking into {@see ToolkitPage} for its settings, entity
 * workbench and dashboard. Nothing here is family-specific — adding a toolkit
 * is a manifest commit, which is the whole point of the engine.
 *
 * Why the page exists at all when the console already serves the same engine:
 * the panel is the superset of capability (D-6). The console gates surfaces by
 * plan; the panel is where an administrator inspects what the estate actually
 * has, including the settings the console hides.
 *
 * **An unentitled toolkit is absent, not a rendered 403.** `manifests()` filters
 * before the view, and {@see ToolkitPage} answers 404 to a direct URL — an
 * estate must not be able to tell "exists but not licensed" from "does not
 * exist" any more than it can for another estate's records.
 *
 * Access is `manage_options` plus panel membership, matching the REST group and
 * every other credential-adjacent surface. Panel membership alone is enough to
 * *see* most `/admin` pages; a toolkit settings screen writes estate
 * configuration, so it is not one of them.
 */
class ToolkitsPage extends Page
{
    protected static ?string $slug = 'toolkits';

    protected static ?string $navigationLabel = 'Toolkits';

    protected static ?string $title = 'Toolkits';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Knowledge;

    // Between Tools (10) and Knowledge/OKF (20): same question — "what does this
    // estate have?" — different answer (product data vs. reference data).
    protected static ?int $navigationSort = 15;

    protected string $view = 'filament.admin.toolkits';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! app(AdminAccessPolicy::class)->accessTenantPanel($user)) {
            return false;
        }

        return CapabilityMap::has($user, 'manage_options');
    }

    /**
     * Entitled manifests, alphabetical by slug, projected for the cards.
     *
     * `screens` is narrowed to the screens the panel actually routes. A manifest
     * may declare `research` or `consolidate` (the console serves both), and
     * advertising them here would produce a link to a page this runtime does not
     * have.
     *
     * @return list<array<string, mixed>>
     */
    public function toolkits(): array
    {
        $engine = app(ToolkitEngine::class);
        $entitlement = app(ProEntitlementInterface::class);

        $rows = array();

        foreach ($engine->manifests() as $manifest) {
            if (! $entitlement->allows($manifest->feature())) {
                continue;
            }

            $summary = $engine->summary($manifest);
            $summary['screens'] = array_values(array_intersect(
                (array) $summary['screens'],
                array('settings', 'entities', 'dashboard')
            ));

            $rows[] = $summary;
        }

        return $rows;
    }

    /**
     * The URL of one toolkit screen.
     *
     * Returned as a method rather than built in the view so the route shape
     * lives beside the page class that owns it. A manifest that declares no
     * panel screen (console-only) has no URL — the view renders it as text
     * instead of a dead link.
     */
    public function toolkitUrl(string $slug, string $screen = 'settings'): string
    {
        return ToolkitPage::getUrl(array(
            'toolkit' => $slug,
            'screen' => $screen,
        ));
    }
}
