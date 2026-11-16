<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;
use App\Support\CapabilityMap;
use App\Support\TeamAccess;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * Team policy (Proposal 149, Wave 2's first tenant-scoped `/admin` surface).
 *
 * Three rules compose here, in this order:
 *
 *  1. **Estate membership** — the panel admits a non-viewer member; a viewer is
 *     refused outright (T-0.5).
 *  2. **Estate ownership of the record** — the team must belong to the estate
 *     the panel resolved. This is the cross-tenant rule: without it, a site
 *     administrator in estate A could act on estate B's team by id, because
 *     `manage_options` is a site-wide capability.
 *  3. **Team role** — `manage_team` to rename, `delete_team` to remove. Matches
 *     `TeamAccess` / `TeamController`, so the panel and the REST surface cannot
 *     disagree about who may do what.
 *
 * Outside a resolved estate the policy fails closed rather than falling back to
 * the `TenantContext` default: a policy that grants access because the caller
 * forgot to pin a tenant is a cross-tenant read waiting to happen.
 */
final class TeamPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasNonViewerMembership();
    }

    public function view(User $user, Team $team): bool
    {
        return $this->inCurrentEstate($team)
            && TeamAccess::isMember($user, (int) $team->getKey());
    }

    public function create(User $user): bool
    {
        return $user->hasNonViewerMembership();
    }

    public function update(User $user, Team $team): bool
    {
        return $this->inCurrentEstate($team)
            && TeamAccess::allows($user, (int) $team->getKey(), CapabilityMap::ABILITY_MANAGE_TEAM);
    }

    public function delete(User $user, Team $team): bool
    {
        return $this->inCurrentEstate($team)
            && TeamAccess::allows($user, (int) $team->getKey(), CapabilityMap::ABILITY_DELETE_TEAM);
    }

    /**
     * Does the record belong to the estate the panel resolved?
     *
     * `Filament::getTenant()` returns a model on this runtime (the panel is
     * configured with `Tenant::class`), but Filament also supports a bare key,
     * so both shapes are accepted. The pinned context is a fallback for a call
     * made outside the panel (a console tool reusing the policy), never a
     * substitute for a resolved tenant.
     */
    private function inCurrentEstate(Team $team): bool
    {
        $tenant = Filament::getTenant();

        $tenantId = $tenant instanceof Model ? $tenant->getKey() : $tenant;

        if (null === $tenantId && TenantContext::isPinned()) {
            $tenantId = TenantContext::current();
        }

        return null !== $tenantId && (string) $team->tenant_id === (string) $tenantId;
    }
}
