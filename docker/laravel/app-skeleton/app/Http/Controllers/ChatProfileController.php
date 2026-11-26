<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\CapabilityMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nvoos\Core\Domain\Contract\ChatProfileResolverInterface;
use Nvoos\Core\Domain\Security\ChatProfile;

/**
 * Chat profiles — the per-user permission boundary over tool execution.
 *
 * Proposal 149 — the port of Proposal 015's user-facing half. The enforcement
 * is {@see \Nvoos\Core\Application\Tool\ChatProfileGuard}, registered as a
 * monotonic guard in the tool registry; this controller is the *preference*
 * writer and the catalogue reader, and it can widen nothing on its own.
 *
 * The selection rule is asymmetric on purpose, mirroring WordPress's
 * `can_user_select()`:
 *
 *  - entering a restrictive profile (a downgrade) is available to any signed-in
 *    user, because restricting yourself needs no authority;
 *  - leaving one (an upgrade) requires `manage_options`, so a downgraded user
 *    cannot call this same endpoint to elevate themselves back.
 *
 * Nothing here accepts a profile *for an execution*. The guard resolves from
 * the server-built context, so a client cannot pick the profile a tool call
 * runs under — Claude Code's "deny is never overridable" and OWASP LLM06's
 * "execute in the user's security context" both reduce to that.
 */
final class ChatProfileController
{
    public function __construct(private readonly ChatProfileResolverInterface $profiles)
    {
    }

    /**
     * The catalogue plus the profile currently governing this user.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $catalogue = array();

        foreach (ChatProfile::catalogue() as $profile) {
            $catalogue[] = $profile->toArray($this->canSelect($user, $profile));
        }

        return response()->json(array(
            'success'  => true,
            'profiles' => $catalogue,
            'current'  => $this->profiles->resolve(array('user_id' => $user?->getKey()))->slug(),
            'enabled'  => $this->profiles->isEnabled(),
        ));
    }

    /**
     * Store the caller's selection.
     *
     * Selecting the site default clears the stored value instead of pinning
     * it: a user who chose "what the site chooses" should follow the site when
     * the site changes its mind.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->reject('unauthenticated', 'You must be signed in to select a chat profile.', 401);
        }

        if (! $this->profiles->isEnabled()) {
            return $this->reject('chat_profile_disabled', 'Chat profiles are disabled for this deployment.', 403);
        }

        $data = $request->validate(array(
            'profile' => array('required', 'string', 'max:32'),
        ));

        $profile = ChatProfile::tryFromSlug($data['profile']);

        // `tryFromSlug()`, not `fromSlug()`: coercing a typo to `write` here
        // would turn an unknown profile name into the widest grant.
        if (null === $profile) {
            return $this->reject('chat_profile_invalid', 'Unknown chat profile.', 400);
        }

        if (! $this->canSelect($user, $profile)) {
            return $this->reject('chat_profile_forbidden', 'You are not allowed to select this chat profile.', 403);
        }

        $value = $profile->slug() === $this->profiles->defaultProfile()->slug()
            ? null
            : $profile->slug();

        // `query()->update()` rather than `fill()/save()`: the model keeps
        // `chat_profile` out of `$fillable` on purpose, the same reason
        // `auth0_subject` is absent — a mass-assignable permission boundary is
        // a self-elevation path.
        User::query()->whereKey($user->getKey())->update(array('chat_profile' => $value));

        return response()->json(array(
            'success' => true,
            'current' => $profile->slug(),
        ));
    }

    /**
     * Whether this user may select this profile.
     *
     * Anonymous callers select nothing. A restrictive profile is always
     * selectable by a signed-in user; the non-restrictive one is the privileged
     * direction and needs the site capability WordPress maps
     * `wp_mcp_ai_change_chat_profile` onto.
     */
    private function canSelect(?User $user, ChatProfile $profile): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        if (! $profile->requiresPrivilegeToSelect()) {
            return true;
        }

        return CapabilityMap::has($user, 'manage_options');
    }

    private function reject(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(array(
            'success' => false,
            'error'   => array(
                'code'    => $code,
                'message' => $message,
            ),
        ), $status);
    }
}
