<?php

namespace App\Policies;

use App\Models\Assistant;
use App\Models\User;
use App\Support\EstateScope;

/**
 * Assistant policy (Proposal 149, Wave 2's fifth slice).
 *
 * Two rules compose, in this order:
 *
 *  1. **Estate membership** — the panel admits a non-viewer member; a viewer is
 *     refused outright (T-0.5). This mirrors `AdminAccessPolicy` rather than
 *     inventing a second predicate.
 *  2. **Estate ownership of the record** — the assistant must belong to the
 *     estate the panel resolved. This is the cross-tenant rule: a site
 *     administrator in estate A holds `manage_options`, which is a *site*
 *     capability, so without this check they could reach estate B's assistant
 *     by id.
 *
 * Deliberately absent: a capability gate on top of membership. Assistants have
 * no per-record ACL — unlike teams, there is no `manage_assistant` ability —
 * and WordPress's own edit screen gates on `manage_options` *or* the
 * assistant's owner. Estate membership is the boundary here (the same reasoning
 * that keeps WordPress's `/org` routes outside `manage_options`, 090-g). When
 * estate role granularity (owner/admin/manager/member) arrives with per-action
 * checks, it narrows here — not in the resource, which would be a second place
 * to get it wrong.
 */
final class AssistantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasNonViewerMembership();
    }

    public function view(User $user, Assistant $assistant): bool
    {
        return EstateScope::owns($assistant->tenant_id);
    }

    public function create(User $user): bool
    {
        return $user->hasNonViewerMembership();
    }

    public function update(User $user, Assistant $assistant): bool
    {
        return EstateScope::owns($assistant->tenant_id);
    }

    public function delete(User $user, Assistant $assistant): bool
    {
        return EstateScope::owns($assistant->tenant_id);
    }
}
