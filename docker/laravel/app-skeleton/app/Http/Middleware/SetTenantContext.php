<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins `TenantContext` to the estate Filament resolved (Proposal 149 T-0.7).
 *
 * Why this exists: every assistant and team query in the runtime is already
 * scoped by `TenantContext::current()` (the 090-g seam), but nothing ever called
 * `set()` — the value sat at the `'default'` fallback. Filament tenancy gives
 * the request a real estate; without this bridge, a customer working inside
 * their own panel would still query the default estate's rows.
 *
 * Registered with `->tenantMiddleware([...], isPersistent: true)` on the
 * customer panel only. The operator panel is deliberately not tenant-scoped
 * (an operator inspects every estate), so it must not pin a tenant.
 *
 * The value does NOT need clearing here: `App\Listeners\ForgetTenantContext`
 * (Octane) and the queue listeners in `AppServiceProvider` own that, and
 * `Tests\TestCase::setUp()` owns it for the suite. Middleware that also cleared
 * would fight those resets.
 */
final class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if ($tenant instanceof Tenant) {
            TenantContext::set((string) $tenant->getKey());
        }

        return $next($request);
    }
}
