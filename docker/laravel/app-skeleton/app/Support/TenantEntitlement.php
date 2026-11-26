<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\Schema;
use Nvoos\Core\Domain\Contract\ProEntitlementInterface;
use Nvoos\Laravel\Adapter\ProEntitlementFactory;

/**
 * Per-estate entitlement (Proposal 149 T-0.8).
 *
 * Resolution, in order:
 *
 *   1. The estate has its own `tenants.licence_key` → resolve against the
 *      licence server, regardless of the install-level driver.
 *   2. The estate has no key → use the install-level driver from
 *      `oos.pro_entitlement` (`allow` / `deny` / `config` / `license`).
 *
 * Why the estate wins over the install: the install driver describes the HOST
 * (a dev box is `allow`, a hardened deployment is `deny`); the key describes the
 * CUSTOMER. A customer estate with a licence must never inherit a permissive
 * host default, and a host must not be able to grant one.
 *
 * Memoisation is per tenant id, in an instance that is bound `scoped()`. Octane
 * flushes scoped bindings between operations
 * (`FlushTemporaryContainerInstances` → `forgetScopedInstances()`), so the memo
 * cannot outlive the request. Binding this `singleton()` would pin the first
 * tenant's entitlement for the worker's life — the same defect class as the
 * static TenantContext fixed in T-0.2.
 *
 * Construction is delegated to `ProEntitlementFactory` so this class does not
 * own a second copy of the driver mapping.
 */
final class TenantEntitlement implements ProEntitlementInterface
{
    /**
     * Resolved entitlements, keyed by tenant id.
     *
     * @var array<string, ProEntitlementInterface>
     */
    private array $resolved = array();

    /**
     * Whether the estate table exists, resolved once per request.
     *
     * Entitlement must be answerable BEFORE the schema is in place —
     * `/api/v1/features` reports the plan on any request, and the capability
     * suites deliberately run without migrations. A hard `tenants` lookup made
     * that endpoint 500 with "no such table", so the lookup is guarded and a
     * missing table degrades to the install-level driver (which still honours
     * `deny`).
     */
    private ?bool $estatesAvailable = null;

    /**
     * @param array<string, mixed> $config            `oos.pro_entitlement`.
     * @param string               $defaultInstanceId Fallback for `instance_id` (usually `app.url`).
     */
    public function __construct(
        private readonly array $config,
        private readonly string $defaultInstanceId = ''
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function allows(string $feature): bool
    {
        return $this->forCurrentTenant()->allows($feature);
    }

    /**
     * {@inheritDoc}
     */
    public function plan(): string
    {
        return $this->forCurrentTenant()->plan();
    }

    /**
     * {@inheritDoc}
     */
    public function source(): string
    {
        return $this->forCurrentTenant()->source();
    }

    /**
     * {@inheritDoc}
     */
    public function features(): array
    {
        return $this->forCurrentTenant()->features();
    }

    /**
     * The entitlement that governs the estate pinned for this request.
     */
    private function forCurrentTenant(): ProEntitlementInterface
    {
        $tenantId = TenantContext::current();

        if (! isset($this->resolved[$tenantId])) {
            $this->resolved[$tenantId] = $this->build($tenantId);
        }

        return $this->resolved[$tenantId];
    }

    private function build(string $tenantId): ProEntitlementInterface
    {
        $licenceKey = $this->licenceKeyFor($tenantId);

        if ('' === $licenceKey) {
            return ProEntitlementFactory::make($this->config, $this->defaultInstanceId);
        }

        return ProEntitlementFactory::make(
            array_merge($this->config, array('driver' => 'license')),
            $this->defaultInstanceId,
            $licenceKey
        );
    }

    /**
     * The estate's licence key, or '' when it has none.
     *
     * An unknown tenant id (a seeded record carrying a tenant that was never
     * created) falls back to the install driver rather than throwing.
     */
    private function licenceKeyFor(string $tenantId): string
    {
        if (! $this->estatesAvailable()) {
            return '';
        }

        $tenant = Tenant::query()->find($tenantId);

        $key = $tenant?->licence_key;

        return is_string($key) ? trim($key) : '';
    }

    /**
     * Whether per-estate licensing is available at all.
     *
     * Memoised per instance; the instance is scoped, so this is one metadata
     * query per request at most.
     */
    private function estatesAvailable(): bool
    {
        if (null !== $this->estatesAvailable) {
            return $this->estatesAvailable;
        }

        try {
            $this->estatesAvailable = Schema::hasTable('tenants');
        } catch (\Throwable $exception) {
            // A broken connection must not be swallowed into "unlicensed":
            // report it and fall back for this request.
            report($exception);

            $this->estatesAvailable = false;
        }

        return $this->estatesAvailable;
    }
}
