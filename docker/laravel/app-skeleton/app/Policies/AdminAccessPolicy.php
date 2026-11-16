<?php

namespace App\Policies;

use App\Models\User;
use App\Support\CapabilityMap;

/**
 * Panel access — Proposal 149 T-0.5, resolving Q-1.
 *
 * Two audiences, two predicates, and they must never be interchangeable:
 *
 *   operator : belongs to an estate whose `tenants.is_operator` is true, AND
 *              holds the site capability `manage_options`.
 *   customer : has at least one estate membership that is not `viewer`.
 *
 * THE TRAP THIS CLASS EXISTS TO PREVENT: deriving `/ops` from
 * `users.role === 'administrator'`. A customer's estate administrator is an
 * administrator, so that predicate would put a customer inside the vendor's
 * licence, DLQ, key-rotation and diagnostics panel. Operator status is an
 * estate property, not a person property — `AdminPanelAccessTest` pins it with
 * a customer administrator who is also the owner of their own estate.
 *
 * Filament binding: `canAccessPanel(Panel $panel)` lives on the model and is
 * wired in T-0.4, when the Panel class exists. These methods are the single
 * source of truth; the model method must delegate here rather than
 * re-implement, or the two will drift.
 */
final class AdminAccessPolicy
{
    public const PANEL_OPERATOR = 'operator';

    public const PANEL_TENANT = 'admin';

    /**
     * Dispatch by panel id. An unknown panel fails closed.
     */
    public function accessPanel(User $user, string $panel): bool
    {
        return match ($panel) {
            self::PANEL_OPERATOR => $this->accessOperatorPanel($user),
            self::PANEL_TENANT => $this->accessTenantPanel($user),
            default => false,
        };
    }

    /**
     * The vendor panel (`/ops`). Deliberately NOT tenant-scoped: an operator
     * must be able to pick any estate, which is a separate `cross_tenant`
     * ability introduced with the panel in T-0.7.
     *
     * `manage_options` is required in addition to membership as defence in
     * depth: the operator panel performs platform-wide writes (licences, key
     * rotation), and a membership role alone should not be enough to reach it.
     */
    public function accessOperatorPanel(User $user): bool
    {
        return $user->isOperatorMember()
            && CapabilityMap::has($user, 'manage_options');
    }

    /**
     * The customer panel (`/admin`).
     *
     * Membership is the authorization here, not `manage_options` — the same
     * reasoning that keeps the WordPress `/org` routes outside
     * `manage_options` (090-g): the estate boundary is the membership itself.
     *
     * A `viewer` is refused the panel outright rather than being shown a nav
     * with every action disabled. Hiding a button is UX; the policy is the
     * enforcement point.
     */
    public function accessTenantPanel(User $user): bool
    {
        return $user->hasNonViewerMembership();
    }
}
