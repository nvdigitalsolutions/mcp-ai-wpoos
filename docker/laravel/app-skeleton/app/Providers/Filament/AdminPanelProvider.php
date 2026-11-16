<?php

namespace App\Providers\Filament;

use App\Http\Middleware\AuditAdminRequests;
use App\Http\Middleware\SetTenantContext;
use App\Models\Tenant;
use App\Support\AdminTheme;
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
 * The customer panel — `/admin` (Proposal 149 T-0.4).
 *
 * Access is `AdminAccessPolicy::accessTenantPanel()`: at least one estate
 * membership that is not `viewer`. Membership is the authorization boundary,
 * the same reasoning that keeps WordPress `/org` outside `manage_options`
 * (090-g) — a customer's estate administrator is not a platform operator.
 *
 * Default panel: this is the product surface, so `url()` outside a panel
 * context resolves here rather than to `/ops`.
 *
 * Tenancy (T-0.7): `->tenant(Tenant::class, 'slug', 'tenant')` scopes every
 * resource query by `tenant_id`. The third argument is the OWNERSHIP
 * RELATIONSHIP name — Filament resolves it to `whereBelongsTo($tenant, 'tenant')`
 * on the resource model, which requires each tenant-scoped model to declare a
 * `tenant()` belongsTo. Passing the default (the plural `tenants`) would look
 * for a belongsToMany and silently match nothing.
 *
 * `SetTenantContext` bridges Filament's resolution into `TenantContext`, which
 * is what the runtime's assistant/team queries already read.
 *
 * Resources live under `app/Filament/Admin/` — a separate namespace from
 * `app/Filament/Operator/` so an operator surface can never appear here.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // Generated from the ADS DTCG tokens by `php artisan admin:theme`.
            // Both panels share one palette: a customer estate and the vendor
            // panel must not disagree on what "danger" looks like.
            ->colors(AdminTheme::FILAMENT_COLORS)
            ->tenant(Tenant::class, 'slug', 'tenant')
            ->tenantMiddleware([
                SetTenantContext::class,
            ], isPersistent: true)
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
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
                // Appended last so route bindings and the resolved tenant are
                // both available when the audit row is written (T-0.9).
                AuditAdminRequests::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
