<?php

namespace App\Support;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * The estate a panel action belongs to (Proposal 149, Wave 2).
 *
 * Every tenant-scoped policy needs the same two questions — "which estate is
 * this request in?" and "does this record belong to it?" — and one wrong answer
 * is a cross-tenant write. `TeamPolicy` grew the logic first; it lives here so
 * the second and third resource inherit the rule instead of copying it.
 *
 * Two fail-closed rules, both deliberate:
 *
 *  1. **No resolved estate means no match.** The `TenantContext` fallback is
 *     only consulted when it was *explicitly pinned* — never its `'default'`
 *     value. A policy that grants access because the caller forgot to pin a
 *     tenant is a cross-tenant read waiting to happen, and `current()` cannot
 *     tell "pinned to default" from "never pinned"; `isPinned()` can.
 *  2. **A null record tenant never matches.** Comparing `(string) null` to an
 *     estate id would otherwise make a tenant-less row universally reachable.
 *
 * `Filament::getTenant()` is the facade accessor. `Panel::getTenant()` is NOT a
 * substitute: in Filament 5 that method resolves a tenant *by key*
 * (`getTenant(string $key): Model`), so calling it with no argument is a fatal.
 * That defect shipped in Wave 0 and only surfaced in Wave 2 (see §9.4 of the
 * implementation plan).
 */
final class EstateScope
{
    /**
     * The estate id this request belongs to, or null when none is resolved.
     */
    public static function currentId(): ?string
    {
        $panel = Filament::getCurrentPanel();

        if (null !== $panel && $panel->hasTenancy()) {
            $tenant = Filament::getTenant();
            $tenantId = $tenant instanceof Model ? $tenant->getKey() : $tenant;

            if (null !== $tenantId && '' !== (string) $tenantId) {
                return (string) $tenantId;
            }
        }

        return TenantContext::isPinned() ? TenantContext::current() : null;
    }

    /**
     * Does a record's tenant column match the current estate?
     */
    public static function owns(mixed $recordTenantId): bool
    {
        $current = self::currentId();

        if (null === $current || null === $recordTenantId || '' === (string) $recordTenantId) {
            return false;
        }

        return (string) $recordTenantId === $current;
    }
}
