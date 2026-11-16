<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Estate resolution for the console API — Proposal 149 §21.
 *
 * WHY THIS EXISTS. The `/admin` panel pins `TenantContext` through Filament
 * tenancy, but nothing pinned it on `/api/v1`. Every console request therefore
 * read and wrote the `default` estate: a leak for a caller who is not a member of
 * it, and silently-wrong for a caller who belongs to several.
 *
 * THE RULE (OWASP Multi-Tenant Security Cheat Sheet, §1): a client-supplied
 * tenant identifier is a **selector only**; the server must verify the
 * authenticated principal may act in the selected tenant. So:
 *
 *   1. `X-Tenant-Id`, when present, selects an estate — but only one the caller
 *      is a member of. A non-member selection answers 404, not 403, so an estate
 *      the caller cannot enter is indistinguishable from one that does not exist
 *      (R4).
 *   2. With no header, exactly one membership resolves implicitly. That is the
 *      single-estate case, which is every deployment today.
 *   3. No memberships → 403. Never a fallback to `default`: an unprovisioned
 *      account is not a licence to read the default estate's rows.
 *   4. More than one membership and no header → 409. Ambiguity must not silently
 *      pick one, because "it picked the wrong estate" is indistinguishable from
 *      "it leaked another estate" to the person affected.
 *
 * The context is cleared in `finally`, so a long-lived process (Octane, a queue
 * worker running a synchronous job) cannot carry one request's estate into the
 * next. `config/octane.php` and the queue listeners already reset it; this is the
 * belt to that braces, and it is what makes the middleware safe under `dispatchSync`.
 *
 * Mounted per route group rather than globally, per the same cheat sheet (§5):
 * "mount tenant-aware middleware only on routes where tenant context is
 * required". `/session/me` is deliberately outside it — it is how a client
 * discovers which estates it may select.
 */
final class ResolveApiTenant
{
    /**
     * The selector header.
     *
     * A header rather than a path segment: the console's route table is shared
     * with WordPress (`/api/v1/settings`, `/api/v1/assistants`), and adding a
     * tenant segment would fork every path on two runtimes that are meant to
     * serve the same client.
     */
    public const HEADER = 'X-Tenant-Id';

    /** Matches the `tenant_id` column width. */
    private const MAX_LENGTH = 64;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->reject('tenant_unauthenticated', 'Authentication is required.', 401);
        }

        $resolved = $this->resolve($user, $request);

        if ($resolved instanceof Response) {
            return $resolved;
        }

        TenantContext::set($resolved);

        try {
            return $next($request);
        } finally {
            TenantContext::forget();
        }
    }

    /**
     * The estate this request acts in, or the rejection to send instead.
     */
    private function resolve(User $user, Request $request): string|Response
    {
        $requested = $request->header(self::HEADER);
        $requested = is_string($requested) ? trim($requested) : '';

        if ('' !== $requested) {
            return $this->select($user, $requested, $request);
        }

        $memberships = $this->membershipIds($user);
        $count = count($memberships);

        if (1 === $count) {
            return $memberships[0];
        }

        if (0 === $count) {
            return $this->reject(
                'tenant_unprovisioned',
                'This account is not a member of any estate.',
                403
            );
        }

        return $this->reject(
            'tenant_required',
            'This account belongs to more than one estate. Send the '.self::HEADER
                .' header to select one; GET /api/v1/session/me lists them.',
            409
        );
    }

    /**
     * A client-supplied selector is accepted only for a membership.
     */
    private function select(User $user, string $requested, Request $request): string|Response
    {
        if (strlen($requested) > self::MAX_LENGTH) {
            return $this->reject('tenant_not_found', 'No such estate.', 404);
        }

        if (! $user->tenants()->whereKey($requested)->exists()) {
            // Recorded, not surfaced: a probe for another estate is exactly the
            // event the cheat sheet's logging section (§8) asks to monitor.
            Log::warning('api.tenant_selection_refused', [
                'user_id' => (int) $user->getAuthIdentifier(),
                'requested_tenant' => $requested,
                'path' => $request->path(),
            ]);

            return $this->reject('tenant_not_found', 'No such estate.', 404);
        }

        return $requested;
    }

    /**
     * Estate ids the user may act in, deterministic order.
     *
     * @return list<string>
     */
    private function membershipIds(User $user): array
    {
        return $user->tenants()
            ->orderBy('tenants.id')
            ->pluck('tenants.id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }

    private function reject(string $code, string $message, int $status): Response
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
        ], $status);
    }
}
