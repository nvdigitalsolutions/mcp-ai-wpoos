<?php

namespace App\Support;

use App\Models\Assistant;

/**
 * The tenancy seam (090-g).
 *
 * Tenant resolution is a runtime concern: a WordPress site is one tenant, a
 * multi-tenant Laravel deployment resolves the tenant from the request, and
 * the console's single-operator mode falls back to the default tenant. Models
 * and services ask this class instead of hard-coding a tenant, so replacing
 * the fallback with real resolution does not touch call sites.
 *
 * OCTANE / LONG-LIVED-PROCESS CONTRACT (Proposal 149 T-0.2).
 *
 * The pinned value is process-wide static state. A classic PHP-FPM request
 * tears the process down and the static dies with it. An Octane worker or a
 * `queue:work` worker does NOT — so an un-reset value is read by the next
 * request or job on the same process. Combined with the tenant-scoped queries
 * in LaravelAssistantService / LaravelTeamService, that is a cross-tenant data
 * leak, not a stale cache.
 *
 * Three resets, all required because they cover different execution modes:
 *
 *   1. Octane lifecycle — App\Listeners\ForgetTenantContext, registered in
 *      config/octane.php for RequestTerminated, TaskTerminated and
 *      TickTerminated. Task/tick workers never run HTTP terminating callbacks.
 *   2. Queue lifecycle — JobProcessed / JobExceptionOccurred listeners in
 *      AppServiceProvider. The oos-laravel-worker container runs queue:work,
 *      which fires no Octane events at all.
 *   3. Tests\TestCase::setUp() — test-to-test isolation.
 *
 * Do not call set() outside a request or job scope (service provider
 * register(), a static initialiser, module boot): there is no lifecycle to
 * clear it again.
 */
final class TenantContext
{
    private static ?string $tenantId = null;

    public static function current(): string
    {
        return self::$tenantId ?? Assistant::DEFAULT_TENANT;
    }

    /**
     * Pin the tenant for the remainder of the request or job.
     *
     * Do not assume the value survives the operation: the resets listed in the
     * class docblock clear it. Passing null restores the default tenant.
     */
    public static function set(?string $tenantId): void
    {
        self::$tenantId = $tenantId;
    }

    /**
     * Discard the pinned tenant.
     *
     * Called by the lifecycle resets, not by feature code. Feature code that
     * needs to add a reset point must call this from a lifecycle hook, never
     * mid-request.
     */
    public static function forget(): void
    {
        self::$tenantId = null;
    }

    /**
     * Whether a tenant was pinned in this request/job.
     *
     * Prefer this over comparing current() to DEFAULT_TENANT: the comparison
     * cannot distinguish "explicitly pinned to default" from "never pinned",
     * and the resets care about the difference.
     */
    public static function isPinned(): bool
    {
        return null !== self::$tenantId;
    }

    /**
     * Run a callback with the context pinned to one estate, then restore it.
     *
     * For code that must read one estate's values before the request has a
     * principal — bearer authentication (§102) has to read an estate's Auth0
     * configuration before it knows which user is calling. Restoring the
     * previous value (rather than forgetting it) keeps this safe to call inside
     * an already-pinned request.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function runAs(string $tenantId, callable $callback): mixed
    {
        $previous = self::$tenantId;
        self::$tenantId = $tenantId;

        try {
            return $callback();
        } finally {
            self::$tenantId = $previous;
        }
    }
}
