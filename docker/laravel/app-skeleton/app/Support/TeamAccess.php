<?php

namespace App\Support;

use App\Models\TeamMember;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Team-scoped authorization (090-g Phase 5).
 *
 * The site role answers "may this user use the console at all"; the membership
 * role answers "what may this user do to *this* team". A site administrator is
 * allowed everything; otherwise the membership row decides.
 *
 * Kept out of LaravelTeamService on purpose: the service is the storage
 * contract shared with WordPress and stays authorization-free, so a tool can
 * reuse it without inheriting a console policy.
 */
final class TeamAccess
{
    /**
     * May the user see the team at all? Non-members get a 404 rather than a
     * 403 so the API does not disclose that a team id exists.
     */
    public static function isMember(?Authenticatable $user, int $teamId): bool
    {
        if ($user === null) {
            return false;
        }

        if (CapabilityMap::has($user, 'manage_options')) {
            return true;
        }

        return self::roleOf($user, $teamId) !== null;
    }

    /**
     * May the user perform a team ability?
     */
    public static function allows(?Authenticatable $user, int $teamId, string $ability): bool
    {
        if ($user === null) {
            return false;
        }

        if (CapabilityMap::has($user, 'manage_options')) {
            return true;
        }

        $role = self::roleOf($user, $teamId);

        return $role !== null && CapabilityMap::teamRoleAllows($role, $ability);
    }

    /**
     * The user's role on the team, or null when they are not a member.
     */
    public static function roleOf(?Authenticatable $user, int $teamId): ?string
    {
        if ($user === null) {
            return null;
        }

        $role = TeamMember::query()
            ->where('team_id', $teamId)
            ->where('user_id', $user->getAuthIdentifier())
            ->value('role');

        return is_string($role) ? $role : null;
    }
}
