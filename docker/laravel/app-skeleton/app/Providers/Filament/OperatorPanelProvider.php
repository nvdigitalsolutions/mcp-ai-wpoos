<?php

namespace App\Providers\Filament;

use App\Http\Middleware\AuditAdminRequests;
use App\Support\AdminTheme;
use App\Support\Brand\BrandMark;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The vendor panel — `/ops` (Proposal 149 T-0.4).
 *
 * Operator-only. Access is `AdminAccessPolicy::accessOperatorPanel()`:
 * membership in the estate whose `tenants.is_operator` is true, plus
 * `manage_options`. Never derived from `users.role`, so a customer estate
 * administrator cannot reach it (Q-1).
 *
 * Deliberately NOT tenant-scoped: an operator inspects every estate, so the
 * panel has no `->tenant()` and no tenant switcher. Cross-estate reads are a
 * separate `cross_tenant` ability (T-0.7), not a side effect of this panel.
 *
 * Deliberately NOT the default panel: the customer panel is the product
 * surface, and making `/ops` default would send every fresh login there.
 *
 * Resources live under `app/Filament/Operator/` so the two panels cannot
 * accidentally share one — an operator surface rendering in the customer panel
 * would be a privilege leak, not a styling bug.
 */
class OperatorPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('operator')
            ->path('ops')
            ->login()
            // Same tab icon as the customer panel, for the same reason the palette
            // is shared: two panels of one product must not look like two products.
            // `BrandMark::FAVICON_PATH` is the lockup mark, so the tab and the
            // sidebar agree; root-relative so a host/port mismatch with APP_URL
            // does not 404 it.
            ->favicon(BrandMark::FAVICON_PATH)
            // The brand mark, shared with the customer panel for the same reason
            // the palette is shared: two panels of one product must not look like
            // two products. Two variants, because the artwork is a black core with
            // a white rim: it reads as a solid wordmark on a light surface and
            // nearly vanishes on a dark one. `BrandMark` has the full reasoning.
            ->brandName(BrandMark::NAME)
            ->brandLogo(BrandMark::logo())
            ->darkModeBrandLogo(BrandMark::darkLogo())
            // Filament fixes the logo wrapper's height; `BrandMark::HEIGHT` is the
            // one place that number lives.
            ->brandLogoHeight(BrandMark::HEIGHT)
            // Generated from the ADS DTCG tokens by `php artisan admin:theme`
            // and drift-gated by AdminThemeDriftTest. Never hard-code a colour
            // here: the gate can only see what the generator writes.
            ->colors(AdminTheme::FILAMENT_COLORS)
            // Collapsible rail, mirroring the customer panel (Proposal 149 §115).
            //
            // This is not cosmetic. `sidebar/group.blade.php` throws
            // "Navigation group […] has an icon but one or more of its items also
            // have icons" unless the group renders as an icon that opens a
            // dropdown — and that only happens when the sidebar is collapsible.
            // `OperatorNavigationGroup` declares an icon on every case, so without
            // this line the whole panel is a 500, not a styling defect.
            //
            // Consequence, same as on `/admin`: with an icon on the group and a
            // collapsible sidebar, Filament drops each item's own icon from the
            // expanded list (`$hasDropdown` ⇒ `$itemIcon = null`) and keeps it in
            // the rail dropdown. The expanded sidebar is group icon + label, then
            // text links.
            ->sidebarCollapsibleOnDesktop()
            // The same keyboard/screen-reader correction the customer panel makes:
            // Filament's group headers are mouse-only `<div>`s. The partial is
            // panel-agnostic — it works from `document.currentScript.closest('nav')`
            // — so one view serves both panels.
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_END,
                static fn () => view('filament.admin.sidebar-disclosure'),
            )
            ->discoverResources(in: app_path('Filament/Operator/Resources'), for: 'App\Filament\Operator\Resources')
            ->discoverPages(in: app_path('Filament/Operator/Pages'), for: 'App\Filament\Operator\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Operator/Widgets'), for: 'App\Filament\Operator\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                // Appended last so route bindings exist and the panel is
                // resolvable when the audit row is written (T-0.9).
                AuditAdminRequests::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
