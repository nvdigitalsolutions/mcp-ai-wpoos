<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Map a user onto the WordPress capability names the console's nav and route
 * gates understand (Proposal 090-B §10.1, 090-g Phase 5).
 *
 * Two scopes, two vocabularies:
 *
 *  - **Site role** (`users.role`) — subscriber / contributor / author / editor
 *    / administrator, exactly WordPress's set, expanded to capabilities.
 *  - **Team role** (`team_members.role`) — membership-scoped, expanded to
 *    team abilities. A site role is global; a team role is a property of the
 *    membership, so an editor site-wide can still be a viewer on one team.
 *
 * Host applications can replace the site-role source (`hasRole()` / `is_admin`)
 * without touching the controllers — the capability array is the only contract
 * the console consumes.
 */
final class CapabilityMap
{
    /** Site roles, in WordPress's vocabulary (ascending privilege). */
    public const ROLES = ['subscriber', 'contributor', 'author', 'editor', 'administrator'];

    /** Team roles, in the order the UI lists them. */
    public const TEAM_ROLES = ['owner', 'admin', 'manager', 'member', 'viewer'];

    /** Team abilities a membership role can hold. */
    public const ABILITY_MANAGE_TEAM = 'manage_team';
    public const ABILITY_MANAGE_TEAM_MEMBERS = 'manage_team_members';
    public const ABILITY_DELETE_TEAM = 'delete_team';

    /**
     * Site role → capabilities. Mirrors WP core's role definitions
     * (`delete_others_posts` is what separates editor from author).
     *
     * @var array<string, list<string>>
     */
    private const ROLE_CAPABILITIES = [
        'administrator' => [
            'manage_options',
            'edit_others_posts',
            'delete_others_posts',
            'publish_posts',
            'edit_posts',
            'delete_posts',
            'upload_files',
            'read',
        ],
        'editor' => [
            'edit_others_posts',
            'delete_others_posts',
            'publish_posts',
            'edit_posts',
            'delete_posts',
            'upload_files',
            'read',
        ],
        'author' => ['publish_posts', 'edit_posts', 'delete_posts', 'upload_files', 'read'],
        'contributor' => ['edit_posts', 'delete_posts', 'read'],
        'subscriber' => ['read'],
    ];

    /**
     * Team role → team abilities. `owner` is deliberately team-specific: no
     * site role expresses "may delete *this* team".
     *
     * @var array<string, list<string>>
     */
    private const TEAM_ROLE_CAPABILITIES = [
        'owner' => [self::ABILITY_MANAGE_TEAM, self::ABILITY_MANAGE_TEAM_MEMBERS, self::ABILITY_DELETE_TEAM],
        'admin' => [self::ABILITY_MANAGE_TEAM, self::ABILITY_MANAGE_TEAM_MEMBERS],
        'manager' => [self::ABILITY_MANAGE_TEAM_MEMBERS],
        'member' => [],
        'viewer' => [],
    ];

    /**
     * @return list<string>
     */
    public static function forUser(?Authenticatable $user): array
    {
        if ($user === null) {
            return [];
        }

        return self::capabilitiesForRole(self::roleOf($user));
    }

    /**
     * The user's site role, normalised to the WordPress vocabulary.
     */
    public static function roleOf(?Authenticatable $user): string
    {
        if ($user === null) {
            return 'subscriber';
        }

        $role = isset($user->role) ? (string) $user->role : '';

        // `admin` is the legacy spelling used by earlier console fixtures.
        if ('admin' === $role) {
            return 'administrator';
        }

        if (in_array($role, self::ROLES, true)) {
            return $role;
        }

        // Host applications may supply a role package instead of the column.
        if (method_exists($user, 'hasRole')) {
            try {
                if ($user->hasRole('administrator') || $user->hasRole('admin')) {
                    return 'administrator';
                }
                foreach (array('editor', 'author', 'contributor', 'subscriber') as $candidate) {
                    if ($user->hasRole($candidate)) {
                        return $candidate;
                    }
                }
            } catch (\Throwable) {
                // Fall through to the attribute check.
            }
        }

        if (isset($user->is_admin) && (bool) $user->is_admin) {
            return 'administrator';
        }

        return 'subscriber';
    }

    /**
     * @return list<string>
     */
    public static function capabilitiesForRole(string $role): array
    {
        return self::ROLE_CAPABILITIES[$role] ?? self::ROLE_CAPABILITIES['subscriber'];
    }

    /**
     * @return list<string>
     */
    public static function teamCapabilities(string $teamRole): array
    {
        return self::TEAM_ROLE_CAPABILITIES[$teamRole] ?? [];
    }

    public static function has(?Authenticatable $user, string $capability): bool
    {
        return in_array($capability, self::forUser($user), true);
    }

    public static function teamRoleAllows(string $teamRole, string $ability): bool
    {
        return in_array($ability, self::teamCapabilities($teamRole), true);
    }
}
