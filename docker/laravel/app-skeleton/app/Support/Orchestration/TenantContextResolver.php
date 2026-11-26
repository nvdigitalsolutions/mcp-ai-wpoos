<?php

namespace App\Support\Orchestration;

use App\Support\TenantContext;
use Nvoos\Core\Domain\Contract\TenantResolverInterface;

/**
 * The host's estate, for package code — Proposal 149 §163.
 *
 * `TenantContext` lives in the app; the package's orchestration adapter must
 * namespace its team cache by estate but cannot reference an app class. This
 * adapter is the one-line seam, matching the way `SettingsStore` already takes
 * the estate as a dependency rather than reaching for it.
 *
 * `TenantContext::current()` already answers `default` when nothing is pinned,
 * so an unauthenticated call gets the same attribution as the rest of the
 * runtime rather than an empty key.
 */
final class TenantContextResolver implements TenantResolverInterface
{
    /**
     * {@inheritDoc}
     */
    public function current(): string
    {
        return TenantContext::current();
    }
}
