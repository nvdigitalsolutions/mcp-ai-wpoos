<?php

namespace App\Support\Auth;

use App\Models\User;

/**
 * An issued sign-in link — Proposal 149 §103.
 *
 * Carries the raw token because exactly one consumer needs it: the email. The
 * service returns it, the controller puts it in the message, and nothing stores
 * it. `tenantId` is the estate whose configuration issued the link, so the mail
 * origin and the token row agree.
 */
final class MagicLinkTicket
{
    public function __construct(
        public readonly string $token,
        public readonly string $link,
        public readonly string $tenantId,
        public readonly User $user,
    ) {
    }
}
