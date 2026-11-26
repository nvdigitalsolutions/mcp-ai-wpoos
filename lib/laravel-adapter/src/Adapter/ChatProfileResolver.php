<?php
/**
 * Laravel adapter: ChatProfileResolverInterface implementation.
 *
 * Reads the preference the migration added to `users.chat_profile` and falls
 * back to the deployment default. This is the only place that knows where a
 * profile is stored, so the core guard stays framework-agnostic and the API
 * and any future panel ask the same resolver.
 *
 * TWO DELIBERATE CHOICES
 *
 *  1. **No per-user cache.** This class is a singleton, and the runtime serves
 *     requests from long-lived Octane workers. A cached profile would outlive
 *     the request that changed it — a user who downgraded to `read-only` would
 *     keep writing until the worker recycled. One primary-key lookup per tool
 *     call is cheaper than that failure.
 *
 *  2. **No principal means `write`, not the WordPress guest default.** There
 *     is no anonymous chat surface in this runtime (`guest_token_lifetime` is
 *     inert for exactly that reason), so a context without `user_id` is a
 *     machine credential or a queued job. Those are governed by the machine
 *     tool allowlist and the approval gate; applying a chat preference to them
 *     would silently disable every machine tool.
 *
 * @package Nvoos\Laravel
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Illuminate\Support\Facades\DB;
use Nvoos\Core\Domain\Contract\ChatProfileResolverInterface;
use Nvoos\Core\Domain\Security\ChatProfile;

/**
 * Resolves a chat profile for the current execution context.
 *
 * @since 1.4.0
 */
final class ChatProfileResolver implements ChatProfileResolverInterface
{
    /**
     * @param bool   $enabled     Whether the feature is enabled for this deployment.
     * @param string $defaultSlug Site default when the user has no selection.
     */
    public function __construct(
        private readonly bool $enabled = true,
        private readonly string $defaultSlug = ChatProfile::WRITE,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function resolve(array $context): ChatProfile
    {
        if (! $this->enabled) {
            return ChatProfile::write();
        }

        $userId = (int) ( $context['user_id'] ?? 0 );

        if ($userId <= 0) {
            return ChatProfile::write();
        }

        return $this->forUser($userId);
    }

    /**
     * {@inheritDoc}
     */
    public function defaultProfile(): ChatProfile
    {
        return ChatProfile::tryFromSlug($this->defaultSlug) ?? ChatProfile::write();
    }

    /**
     * {@inheritDoc}
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * The stored selection, else the site default.
     *
     * A stored value that is no longer a registered slug (a profile retired by
     * a downgrade of this package) falls through to the default rather than
     * locking the user out of the tool surface entirely.
     */
    private function forUser(int $userId): ChatProfile
    {
        $stored = DB::table('users')->where('id', $userId)->value('chat_profile');

        if (is_string($stored)) {
            $profile = ChatProfile::tryFromSlug($stored);

            if (null !== $profile) {
                return $profile;
            }
        }

        return $this->defaultProfile();
    }
}
