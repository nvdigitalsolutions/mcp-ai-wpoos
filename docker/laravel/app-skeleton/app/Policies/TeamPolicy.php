<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;
use App\Support\CapabilityMap;
use App\Support\EstateScope;
use App\Support\TeamAccess;

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
 *
 * Rule 2 lives in `EstateScope::owns()` so the second and third tenant-scoped
 * resource inherit it. It grew here first (Wave 2, slice 2).
 */
final class TeamPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasNonViewerMembership();
    }

    public function view(User $user, Team $team): bool
    {
        return EstateScope::owns($team->tenant_id)
            && TeamAccess::isMember($user, (int) $team->getKey());
    }

    public function create(User $user): bool
    {
        return $user->hasNonViewerMembership();
    }

    public function update(User $user, Team $team): bool
    {
        return EstateScope::owns($team->tenant_id)
            && TeamAccess::allows($user, (int) $team->getKey(), CapabilityMap::ABILITY_MANAGE_TEAM);
    }

    public function delete(User $user, Team $team): bool
    {
        return EstateScope::owns($team->tenant_id)
            && TeamAccess::allows($user, (int) $team->getKey(), CapabilityMap::ABILITY_DELETE_TEAM);
    }
}
