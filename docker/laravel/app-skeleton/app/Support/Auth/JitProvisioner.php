<?php

namespace App\Support\Auth;

use App\Models\User;
use App\Support\Settings\SettingsService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Just-in-time provisioning — Proposal 149 §110, decisions 158 D2–D4.
 *
 * Called by the `auth0` guard when a verified subject has no `auth0_subject`
 * link. It either creates the account and a `viewer` membership, or records why
 * it did not, and it never does anything else.
 *
 * ## The decision order is the security property
 *
 * Each gate is passed before the next is consulted, and a failure at any gate
 * records the reason and returns null. That ordering matters: an unverified
 * address must not be able to probe whether a domain is allowlisted, and no
 * outcome may be reached by satisfying gates out of order.
 *
 * 1. **JIT is on for this estate** (`enable_jit_provisioning`, default off).
 * 2. **The IdP says the address is verified.** Tolerant of how the claim is
 *    actually spelled — `true`, `"true"`, `1`, `"1"` — because IdPs differ and a
 *    silently-rejected `"true"` looks like a provisioning bug, not a policy. A
 *    negative or absent claim blocks.
 * 3. **The address's domain is on the verified allowlist.** Exact match, no
 *    subdomain inheritance (158 D3, {@see EstateDomains}).
 * 4. **No existing account already holds that address.** `users.email` is
 *    unique, and attaching an IdP subject to an account created by another route
 *    is the account-takeover primitive 156 D4 forbids. So the answer is *no*:
 *    record it and let a person link it through `nvoos:auth0-link`.
 *
 * ## What it deliberately never does
 *
 * - **No role from claims.** A provisioned subject gets `viewer` and nothing
 *   else. Mapping IdP groups to roles hands privilege assignment to whoever
 *   administers the group mappings, which is a different team from the one
 *   accountable for the estate.
 * - **No re-elevation on later logins.** Provisioning runs only when no link
 *   exists. A second login resolves through `auth0_subject` and touches no
 *   membership, so a mutable claim cannot upgrade an existing member.
 * - **No cross-estate membership.** The membership is created in the estate whose
 *   configuration verified the token, and nowhere else.
 */
final class JitProvisioner
{
    /** JIT is disabled for this estate (the default). */
    public const REASON_DISABLED = 'jit_disabled';

    /** The IdP did not assert a verified address. */
    public const REASON_UNVERIFIED = 'email_unverified';

    /** The address is verified but its domain is not on the estate's list. */
    public const REASON_DOMAIN = 'domain_not_allowed';

    /** An account already holds this address and is not linked to this subject. */
    public const REASON_ACCOUNT_EXISTS = 'account_exists_unlinked';

    /** The role a provisioned subject receives, and the only one it can receive. */
    public const ROLE = 'viewer';

    /** Longest stored email, matching `users.email`. */
    private const MAX_EMAIL = 191;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly EstateDomains $domains,
    ) {
    }

    /**
     * Try to provision, or explain why not.
     *
     * @param string $tenantId      The estate whose configuration verified the token.
     * @param string $subject       The IdP's immutable subject identifier.
     * @param string $email         The asserted address; may be empty.
     * @param mixed  $emailVerified The raw claim, in whatever spelling the IdP uses.
     *
     * @return array{user: User|null, reason: string|null}
     */
    public function provision(string $tenantId, string $subject, string $email, mixed $emailVerified): array
    {
        $email = mb_substr(trim($email), 0, self::MAX_EMAIL);

        if (! $this->enabled($tenantId)) {
            return $this->pending($tenantId, $subject, $email, self::REASON_DISABLED);
        }

        if (! self::assertsVerified($emailVerified)) {
            return $this->pending($tenantId, $subject, $email, self::REASON_UNVERIFIED);
        }

        if ('' === $email || ! $this->domains->allows($tenantId, $email, $this->subdomainsEnabled($tenantId))) {
            return $this->pending($tenantId, $subject, $email, self::REASON_DOMAIN);
        }

        // The address is allowlisted, but an account already owns it and carries
        // no link to this subject. Creating a second account is impossible
        // (`users.email` is unique) and attaching to the existing one is an
        // account takeover. So this is a person's decision, not a rule's.
        $existing = User::query()->where('email', $email)->first();

        if ($existing instanceof User) {
            return $this->pending($tenantId, $subject, $email, self::REASON_ACCOUNT_EXISTS);
        }

        $user = $this->create($tenantId, $subject, $email);

        if (! $user instanceof User) {
            return $this->pending($tenantId, $subject, $email, self::REASON_ACCOUNT_EXISTS);
        }

        return array('user' => $user, 'reason' => null);
    }

    /**
     * Whether JIT is enabled for an estate.
     *
     * Read through `runAs` because this runs on the auth path, before any
     * middleware has pinned an estate — the same reason `Auth0Guard` wraps its
     * configuration read.
     */
    public function enabled(string $tenantId): bool
    {
        return TenantContext::runAs(
            $tenantId,
            fn (): bool => (bool) $this->settings->value('enable_jit_provisioning'),
        );
    }

    /**
     * Whether a verified domain also admits its subdomains for this estate.
     *
     * Read the same way {@see self::enabled()} is, and for the same reason: this
     * runs before any middleware has pinned an estate. Off unless an estate has
     * deliberately turned it on — 158 D3 rule 3.
     */
    private function subdomainsEnabled(string $tenantId): bool
    {
        return TenantContext::runAs(
            $tenantId,
            fn (): bool => (bool) $this->settings->value('enable_jit_subdomain_inheritance'),
        );
    }

    /**
     * Whether a claim asserts a verified address, in any spelling an IdP uses.
     *
     * Deliberately tolerant of the positive spellings and strict about the rest:
     * a missing claim, `false`, `"false"`, `0` and `"0"` all block. Reading only
     * strict `true` would reject a correct IdP; reading truthiness would accept
     * the string `"false"`, which PHP considers truthy — the exact hazard the
     * JIT-audit literature names.
     */
    public static function assertsVerified(mixed $claim): bool
    {
        if (true === $claim) {
            return true;
        }

        if (is_int($claim) || is_float($claim)) {
            return 1 === (int) $claim;
        }

        if (is_string($claim)) {
            return in_array(strtolower(trim($claim)), array('true', '1'), true);
        }

        return false;
    }

    /**
     * The reason in words.
     *
     * Static and here rather than on the panel page because the page is no
     * longer the only reader: the access digest (§111) names the same gate in
     * an email, and a second copy of these strings would drift from the first
     * the moment a gate is added.
     */
    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            self::REASON_DISABLED => 'Provisioning is off for this estate',
            self::REASON_UNVERIFIED => 'The provider did not verify the address',
            self::REASON_DOMAIN => 'The address domain is not on the verified list',
            self::REASON_ACCOUNT_EXISTS => 'An account already holds that address',
            default => $reason,
        };
    }

    /**
     * The pending rows for an estate, newest first.
     *
     * @return list<array{id: int, subject: string, email: string, reason: string, attempted_at: string}>
     */
    public function pendingRows(string $tenantId, int $limit = 50): array
    {
        if (! Schema::hasTable('nvoos_jit_provisioning_requests')) {
            return array();
        }

        $rows = DB::table('nvoos_jit_provisioning_requests')
            ->where('tenant_id', $tenantId)
            ->where('status', 'pending')
            ->orderByDesc('attempted_at')
            ->limit(max(1, $limit))
            ->get(array('id', 'subject', 'email', 'reason', 'attempted_at'));

        return array_map(
            static fn (object $row): array => array(
                'id' => (int) $row->id,
                'subject' => (string) $row->subject,
                'email' => (string) $row->email,
                'reason' => (string) $row->reason,
                'attempted_at' => (string) ($row->attempted_at ?? ''),
            ),
            $rows->all(),
        );
    }

    /**
     * How many requests are waiting on a person, independent of the list limit.
     *
     * Separate from `count(pendingRows())` on purpose: the digest names ten and
     * counts all of them, and a page that paginates would otherwise report the
     * page size as the total.
     */
    public function pendingCount(string $tenantId): int
    {
        if (! Schema::hasTable('nvoos_jit_provisioning_requests')) {
            return 0;
        }

        return (int) DB::table('nvoos_jit_provisioning_requests')
            ->where('tenant_id', $tenantId)
            ->where('status', 'pending')
            ->count();
    }

    /**
     * Promote a pending request: create the account, grant `viewer`, mark it done.
     *
     * This is the operator's override of D2's dead end, and it re-checks nothing
     * about the domain — a person decided, which is the point of having a person
     * in the loop. It does refuse to attach to an existing account, for the same
     * takeover reason as {@see self::provision()}.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function approve(string $tenantId, int $requestId, ?int $actorId = null): array
    {
        if (! Schema::hasTable('nvoos_jit_provisioning_requests')) {
            return array('ok' => false, 'error' => 'The request table is not installed.');
        }

        $request = DB::table('nvoos_jit_provisioning_requests')
            ->where('tenant_id', $tenantId)
            ->where('id', $requestId)
            ->where('status', 'pending')
            ->first();

        if (null === $request) {
            return array('ok' => false, 'error' => 'That request is no longer pending.');
        }

        $email = (string) $request->email;

        if ('' === $email) {
            return array('ok' => false, 'error' => 'That request carries no address to provision.');
        }

        if (User::query()->where('email', $email)->exists()) {
            return array('ok' => false, 'error' => 'An account already holds that address; link it with nvoos:auth0-link instead.');
        }

        $user = $this->create($tenantId, (string) $request->subject, $email);

        if (! $user instanceof User) {
            return array('ok' => false, 'error' => 'The account could not be created.');
        }

        DB::table('nvoos_jit_provisioning_requests')
            ->where('tenant_id', $tenantId)
            ->where('id', $requestId)
            ->update(array(
                'status' => 'approved',
                'resolved_by' => $actorId,
                'resolved_at' => now(),
                'updated_at' => now(),
            ));

        return array('ok' => true, 'error' => null);
    }

    /**
     * Dismiss a pending request without provisioning.
     */
    public function reject(string $tenantId, int $requestId, ?int $actorId = null): bool
    {
        if (! Schema::hasTable('nvoos_jit_provisioning_requests')) {
            return false;
        }

        return 1 === DB::table('nvoos_jit_provisioning_requests')
            ->where('tenant_id', $tenantId)
            ->where('id', $requestId)
            ->where('status', 'pending')
            ->update(array(
                'status' => 'rejected',
                'resolved_by' => $actorId,
                'resolved_at' => now(),
                'updated_at' => now(),
            ));
    }

    /**
     * Record a denial, refreshing an existing pending row rather than duplicating.
     *
     * @return array{user: User|null, reason: string}
     */
    private function pending(string $tenantId, string $subject, string $email, string $reason): array
    {
        if (Schema::hasTable('nvoos_jit_provisioning_requests')) {
            // A person who tries five times is one request. `updateOrInsert`
            // against the unique (tenant, subject) is what keeps the operator's
            // list readable, and it keeps `attempted_at` honest as "last try".
            DB::table('nvoos_jit_provisioning_requests')->updateOrInsert(
                array('tenant_id' => $tenantId, 'subject' => $subject),
                array(
                    'email' => $email,
                    'reason' => $reason,
                    'status' => 'pending',
                    'attempted_at' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ),
            );
        }

        return array('user' => null, 'reason' => $reason);
    }

    /**
     * Create the account and its single `viewer` membership.
     *
     * `role` and `auth0_subject` are set on the instance because `User::$fillable`
     * is `name, email, password` — the same trap `Assistant::$tenant_id` sets. A
     * random password is stored so no password login exists for an SSO account;
     * the hash is never returned or shown.
     */
    private function create(string $tenantId, string $subject, string $email): ?User
    {
        $at = strrpos($email, '@');
        $local = false === $at ? $email : substr($email, 0, $at);

        try {
            return DB::transaction(function () use ($tenantId, $subject, $email, $local): ?User {
                $user = new User(array(
                    'name' => '' !== $local ? $local : 'SSO user',
                    'email' => $email,
                    'password' => Hash::make(Str::random(48)),
                ));

                // Not fillable, deliberately: the tenant is never supplied by a
                // request body, and the subject is an identity, not an input.
                $user->role = 'subscriber';
                $user->auth0_subject = $subject;

                if (Schema::hasColumn('users', 'email_verified_at')) {
                    // The IdP verified it — that is the whole reason we are here.
                    $user->email_verified_at = now();
                }

                $user->save();

                $user->tenants()->syncWithoutDetaching(array($tenantId => array('role' => self::ROLE)));

                return $user;
            });
        } catch (\Throwable) {
            // A unique-constraint race with another request for the same subject
            // or address: the caller records it as pending rather than failing
            // the login with a 500.
            return null;
        }
    }
}
