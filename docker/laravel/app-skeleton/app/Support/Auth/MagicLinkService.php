<?php

namespace App\Support\Auth;

use App\Models\MagicLoginToken;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Settings\SettingsService;
use App\Support\TenantContext;
use Illuminate\Support\Str;

/**
 * Issue and consume magic-link sign-in tokens — Proposal 149 §103.
 *
 * The WordPress controller is the reference (`class-wp-mcp-ai-rest-magic-login-controller.php`)
 * and its hygiene list is the specification:
 *
 *  - a 43-character random token (~256 bits from the CSPRNG), stored hashed;
 *  - a 15-minute expiry, single use, at most one outstanding link per user
 *    (issuing a new one revokes the previous);
 *  - the emailed link always points at the configured app origin, never at
 *    anything the request supplied;
 *  - uniform answers, so the endpoint cannot be used to enumerate accounts.
 *
 * WHAT DIFFERS, AND WHY
 *
 * Two things are deliberately not a copy:
 *
 *  1. **Storage is a table, not a transient.** Single use has to survive a
 *     concurrent confirm, so consumption is a conditional UPDATE with an
 *     affected-row check rather than a read-then-delete race.
 *  2. **Enablement is per estate.** `enable_magic_login` is tenant scope here, so
 *     a link is issued only if one of the caller's estates has it on, and the
 *     mail origin comes from that same estate. A single-site plugin has one
 *     answer to that question; this runtime does not.
 *
 * Deniability is the default everywhere: unknown account, disabled estate,
 * expired token, used token and missing user all produce the same answer at the
 * controller, and this class simply returns null.
 */
final class MagicLinkService
{
    /** Industry-standard window; the WordPress controller's own constant. */
    public const TTL_MINUTES = 15;

    public function __construct(private readonly SettingsService $settings)
    {
    }

    /**
     * The estate whose configuration enables magic-link sign-in for this user.
     *
     * Deterministic (`orderBy('tenants.id')`) so two estates that both enable it
     * produce the same origin on every request rather than whichever the
     * database returned first.
     */
    public function enabledTenantFor(User $user): ?string
    {
        foreach ($this->estateIds($user) as $tenantId) {
            if ($this->enabledForEstate($tenantId)) {
                return $tenantId;
            }
        }

        return null;
    }

    /**
     * Whether any estate has opted in.
     *
     * The routes 404 when this is false, which is how the surface stays invisible
     * rather than merely refusing — the WordPress controller's choice, and the
     * right one for an off-by-default feature.
     */
    public function anyEstateEnabled(): bool
    {
        $ids = Tenant::query()->orderBy('id')->pluck('id');

        foreach ($ids as $tenantId) {
            if ($this->enabledForEstate((string) $tenantId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the estate this request is bound to has opted in.
     *
     * Used by `GET /api/v1/features` so the console knows whether to offer the
     * option. That endpoint is public and carries no estate selector, so this is
     * the current context — `default` on a fresh process. Truthful for the
     * single-estate deployment (§21) and documented as a limitation for the
     * multi-estate case rather than papered over.
     */
    public function enabledHere(): bool
    {
        return $this->enabledForEstate(TenantContext::current());
    }

    /**
     * Issue a link for this user, or null when no estate of theirs has it on.
     */
    public function issue(User $user): ?MagicLinkTicket
    {
        $tenantId = $this->enabledTenantFor($user);

        if (null === $tenantId) {
            return null;
        }

        // At most one outstanding link per user (the plugin's rule). Deleting is
        // the revoke: a token that never authenticated is not worth a row.
        $this->revokeFor($user);

        // Expired rows are dead weight and the table is written once per
        // sign-in; sweeping this user's old ones here keeps it small without a
        // scheduled job.
        MagicLoginToken::query()
            ->where('user_id', $user->getKey())
            ->where('expires_at', '<', now())
            ->delete();

        $token = Str::random(43);

        MagicLoginToken::query()->create(array(
            'user_id' => $user->getKey(),
            'tenant_id' => $tenantId,
            'token_hash' => $this->hash($token),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ));

        return new MagicLinkTicket(
            $token,
            $this->buildLink($this->originForEstate($tenantId), $token),
            $tenantId,
            $user,
        );
    }

    /**
     * Consume a token, returning the user it signs in — or null.
     *
     * Null covers unknown, expired, already used and deleted-user alike: the
     * caller must not be able to tell them apart, and neither must an attacker.
     */
    public function consume(string $token): ?User
    {
        $hash = $this->hash($token);

        $row = MagicLoginToken::query()->where('token_hash', $hash)->first();

        if (! $row instanceof MagicLoginToken || $row->isConsumed() || $row->isExpired()) {
            return null;
        }

        // The claim is the gate. A concurrent confirm loses here because only
        // one UPDATE can match `consumed_at IS NULL`.
        $claimed = MagicLoginToken::query()
            ->whereKey($row->getKey())
            ->whereNull('consumed_at')
            ->update(array('consumed_at' => now()));

        if (1 !== $claimed) {
            return null;
        }

        $user = User::query()->find($row->user_id);

        return $user instanceof User ? $user : null;
    }

    /**
     * Drop any outstanding link for this user.
     */
    public function revokeFor(User $user): void
    {
        MagicLoginToken::query()
            ->where('user_id', $user->getKey())
            ->whereNull('consumed_at')
            ->delete();
    }

    /**
     * The URL the email points at.
     *
     * The console's own route — the same hash route the WordPress plugin mails,
     * because the console is one SPA and a link that lands anywhere else would
     * be a second entry point to keep in step.
     */
    public function buildLink(string $origin, string $token): string
    {
        return rtrim($origin, '/').'/#/magic-login?token='.rawurlencode($token);
    }

    private function enabledForEstate(string $tenantId): bool
    {
        return (bool) TenantContext::runAs(
            $tenantId,
            fn (): mixed => $this->settings->value('enable_magic_login')
        );
    }

    /**
     * The configured origin for an estate, or the app URL.
     *
     * Never the request's own origin: a link pointed at an attacker-chosen host
     * is a phishing mail sent from your domain.
     */
    public function originForEstate(string $tenantId): string
    {
        $origin = (string) TenantContext::runAs(
            $tenantId,
            fn (): mixed => $this->settings->value('magic_login_app_origin')
        );

        $origin = trim($origin);

        if ('' === $origin) {
            return (string) config('app.url');
        }

        return $origin;
    }

    /**
     * @return list<string>
     */
    private function estateIds(User $user): array
    {
        return $user->tenants()
            ->orderBy('tenants.id')
            ->pluck('tenants.id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
