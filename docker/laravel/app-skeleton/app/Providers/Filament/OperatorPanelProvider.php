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
            // Same brand icon as the customer panel, for the same reason the
            // palette is shared: two panels of one product must not look like
            // two products. Root-relative so it survives a host/port mismatch
            // with APP_URL.
            ->favicon('/nvoos-icon.svg')
            // The brand mark, shared with the customer panel. `BrandMark` explains
            // why the white artwork sits on a chip: the panel follows the OS
            // theme, and the logo is white on transparent, so a bare <img> would
            // vanish on the light sidebar.
            ->brandName(BrandMark::NAME)
            ->brandLogo(BrandMark::logo())
            // Generated from the ADS DTCG tokens by `php artisan admin:theme`
            // and drift-gated by AdminThemeDriftTest. Never hard-code a colour
            // here: the gate can only see what the generator writes.
            ->colors(AdminTheme::FILAMENT_COLORS)
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
