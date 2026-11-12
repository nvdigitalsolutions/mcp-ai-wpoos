<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Map a Laravel user onto the WordPress capability names the console's nav
 * and route gates understand (Proposal 090-B, §10.1).
 *
 * Host applications can replace this map (or add a role package) without
 * touching the controllers — the capability array is the only contract the
 * console consumes.
 */
final class CapabilityMap
{
    /**
     * @return list<string>
     */
    public static function forUser(?Authenticatable $user): array
    {
        if ($user === null) {
            return [];
        }

        if (self::isAdmin($user)) {
            return ['manage_options', 'edit_others_posts', 'publish_posts', 'edit_posts', 'read'];
        }

        return ['edit_posts', 'read'];
    }

    private static function isAdmin(Authenticatable $user): bool
    {
        if (method_exists($user, 'hasRole')) {
            try {
                if ($user->hasRole('admin') || $user->hasRole('administrator')) {
                    return true;
                }
            } catch (\Throwable) {
                // Fall through to the attribute checks.
            }
        }

        if (isset($user->is_admin) && (bool) $user->is_admin) {
            return true;
        }

        return isset($user->role)
            && in_array($user->role, ['admin', 'administrator'], true);
    }
}
