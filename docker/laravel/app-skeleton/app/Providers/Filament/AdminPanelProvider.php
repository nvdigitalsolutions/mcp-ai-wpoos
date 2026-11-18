<?php

namespace App\Providers\Filament;

use App\Http\Middleware\AuditAdminRequests;
use App\Http\Middleware\SetTenantContext;
use App\Models\Tenant;
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
            // The tab icon is the lockup mark, not the frontend glyph: the backend
            // and the console are different products, and this repo had already
            // shipped them with mismatched artwork once. Root-relative, not
            // `asset()`, so a panel reached on a different host or port than
            // APP_URL does not 404 the icon.
            ->favicon(BrandMark::FAVICON_PATH)
            // The brand mark, shared with the operator panel. Two variants, because
            // the artwork is a black core with a white rim: it reads as a solid
            // wordmark on a light surface and nearly vanishes on a dark one.
            // Filament renders whichever matches the panel's mode, so no wrapper
            // colour is needed. `BrandMark` has the full reasoning.
            ->brandName(BrandMark::NAME)
            ->brandLogo(BrandMark::logo())
            ->darkModeBrandLogo(BrandMark::darkLogo())
            // Filament fixes the logo wrapper's height; `BrandMark::HEIGHT` is the
            // one place that number lives.
            ->brandLogoHeight(BrandMark::HEIGHT)
            // Generated from the ADS DTCG tokens by `php artisan admin:theme`.
            // Both panels share one palette: a customer estate and the vendor
            // panel must not disagree on what "danger" looks like.
            ->colors(AdminTheme::FILAMENT_COLORS)
            // Collapsible rail (Proposal 149 §55 step 2). The sidebar is the
            // primary navigation and it grows with every slice, so it must be
            // reclaimable without leaving the page. With this on, a group that
            // declares an icon renders as that icon opening a dropdown instead
            // of listing its items — which is why every `AdminNavigationGroup`
            // case carries one (a group without an icon falls back to
            // twenty-one individual rail icons).
            //
            // Consequence worth knowing: when a group has an icon and the
            // sidebar is collapsible, Filament drops the per-item icon from the
            // expanded list (`sidebar/group.blade.php`, `$hasDropdown` ⇒
            // `$itemIcon = null`) and keeps it in the rail dropdown. The
            // expanded sidebar is therefore group-icon + label, then text links.
            ->sidebarCollapsibleOnDesktop()
            // Group headers are `<div>`s with an Alpine click handler in Filament,
            // so folding a group is mouse-only. This restores the disclosure
            // semantics the W3C pattern requires (role, tabindex, Enter/Space,
            // aria-expanded/-controls) without replacing vendor markup — see the
            // partial's docblock for the exact contract and the failure mode.
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_END,
                static fn () => view('filament.admin.sidebar-disclosure'),
            )
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
