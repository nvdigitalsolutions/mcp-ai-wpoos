<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

/**
 * Estate policy (Proposal 149, Wave 2's first `/ops` surface).
 *
 * Reading or changing an estate is an operator ability, not a membership one.
 * The panel already refuses non-operators, but a policy is the enforcement
 * point for the *record*: Filament resolves abilities through the Gate, and a
 * missing policy would fall back to "anyone who reached the panel may do
 * anything". Naming the rule here also means a future `/admin` resource on the
 * same model cannot inherit an accidental allow.
 *
 * `create` and `delete` are denied outright. An estate is an authorization
 * boundary, not a record: it is provisioned by the platform (a migration or a
 * service that also seeds the first membership), and deleting one would orphan
 * every assistant and team scoped to its `tenant_id`.
 */
final class TenantPolicy
{
    public function __construct(private readonly AdminAccessPolicy $access)
    {
    }

    public function viewAny(User $user): bool
    {
        return $this->access->accessOperatorPanel($user);
    }

    public function view(User $user, Tenant $tenant): bool
    {
        return $this->access->accessOperatorPanel($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $this->access->accessOperatorPanel($user);
    }

    public function delete(User $user, Tenant $tenant): bool
    {
        return false;
    }
}
