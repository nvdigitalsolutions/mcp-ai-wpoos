<?php

namespace App\Support\Admin;

use App\Models\AdminSavedView;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Collection;

/**
 * Saved table views, scoped to the active estate (Proposal 149 T-0.9).
 *
 * Every query filters by `TenantContext::current()`, which the admin panel's
 * middleware pins from the resolved tenant. A saved view is therefore an
 * estate-local artefact: the same user on a different estate starts with an
 * empty view list, which is what "these are the views for THIS customer's
 * table" has to mean.
 *
 * The `(tenant_id, user_id, resource, name)` unique index is the real
 * invariant — `save()` uses `updateOrCreate` so re-saving the same name
 * overwrites rather than throwing, and the index catches a concurrent insert
 * that slips between the check and the write.
 */
final class SavedViewRepository
{
    /**
     * Create or replace a named view for the current estate.
     *
     * @param array<string, mixed> $state
     */
    public function save(User $user, string $resource, string $name, array $state): AdminSavedView
    {
        return AdminSavedView::query()->updateOrCreate(
            array(
                'tenant_id' => $this->tenantId(),
                'user_id' => $user->getKey(),
                'resource' => $resource,
                'name' => $name,
            ),
            array('state' => $state)
        );
    }

    /**
     * The user's views for one resource on the current estate.
     *
     * @return Collection<int, AdminSavedView>
     */
    public function forUser(User $user, string $resource): Collection
    {
        return AdminSavedView::query()
            ->where('tenant_id', $this->tenantId())
            ->where('user_id', $user->getKey())
            ->where('resource', $resource)
            ->orderBy('name')
            ->get();
    }

    /**
     * Delete one named view. Returns false when it did not exist on this estate.
     */
    public function forget(User $user, string $resource, string $name): bool
    {
        return AdminSavedView::query()
            ->where('tenant_id', $this->tenantId())
            ->where('user_id', $user->getKey())
            ->where('resource', $resource)
            ->where('name', $name)
            ->delete() > 0;
    }

    private function tenantId(): string
    {
        return TenantContext::current();
    }
}
