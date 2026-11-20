<?php

namespace App\Support\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Sanctum\TransientToken;

/**
 * What a machine credential is allowed to reach — Proposal 159 slice 1.
 *
 * The decision (159 §5) is that a machine principal is a **personal access token
 * with an explicit scope**, encoded inside Sanctum's existing `abilities` array.
 * This class is the only place that knows how to read that encoding, so a scope
 * cannot be spelled one way in one handler and another way in the next.
 *
 * ## `['*']` means unscoped, and that is not a loophole
 *
 * Every personal token minted before this slice carries `['*']`, and a session or
 * Auth0 request carries **no** Sanctum token at all. Both are *user* credentials:
 * their authority is the owner's role, checked by the route's own gates. So an
 * unscoped principal is allowed through a scoped route, and slice 1 changes no
 * behaviour for a single existing caller. That compatibility rule is pinned by a
 * test rather than left to a comment, because discovering it at rollout is how a
 * scoping change locks everyone out.
 *
 * An **empty** ability list is the opposite: `[]` on a real token means "this
 * credential was deliberately given nothing", and it satisfies no requirement.
 * The distinction between absent and empty is the whole reason
 * {@see self::fromAbilities()} does not use `empty()`.
 *
 * ## Requirements
 *
 * A requirement is what a route asks for:
 *
 *  - `assistant` — any **well-formed** assistant scope satisfies it (the route
 *    acts on *some* assistant and resolves the id itself); `assistant:abc` and
 *    `assistant:0` do not, because a class requirement that admits a scope with
 *    no usable id hands the handler a null it will not expect;
 *  - `assistant:42` — only that assistant;
 *  - `estate` — any non-empty estate scope;
 *  - `any` — any scope at all, for a route that only describes the caller.
 *
 * Exact match, then prefix match; never a substring. `assistant:4` must not be
 * satisfied by `assistant:42`, which is the same shape of mistake the JIT
 * allowlist refuses for domains (149 §116.2).
 */
final class PrincipalScope
{
    /** The ability that means "no narrowing" — the owner's own authority. */
    public const UNRESTRICTED = '*';

    /** Scope prefixes this runtime understands. */
    public const ASSISTANT = 'assistant';

    public const ESTATE = 'estate';

    /**
     * A requirement any scoped credential satisfies — 159 §10.4.
     *
     * It exists for self-describing routes (`/session/me`) where no narrower
     * scope would make sense, and it is a declaration rather than a bypass: a
     * credential given `[]` still fails it, because `[]` means "nothing".
     */
    public const ANY = 'any';

    /**
     * @param list<string> $scopes
     */
    private function __construct(
        private readonly array $scopes,
        private readonly bool $unrestricted,
    ) {
    }

    /**
     * A principal with no narrowing: a session, an Auth0 user, or a `['*']` token.
     */
    public static function unrestricted(): self
    {
        return new self(array(), true);
    }

    /**
     * Read scopes out of a token's stored abilities.
     *
     * `null` and non-arrays are unrestricted: they are the shapes a session or a
     * mocked token produce, and treating them as "restricted to nothing" would
     * lock out every caller that has no ability list to read.
     */
    public static function fromAbilities(mixed $abilities): self
    {
        if (! is_array($abilities)) {
            return self::unrestricted();
        }

        $scopes = array();

        foreach ($abilities as $ability) {
            if (is_string($ability) && '' !== trim($ability)) {
                $scopes[] = trim($ability);
            }
        }

        if (in_array(self::UNRESTRICTED, $scopes, true)) {
            return self::unrestricted();
        }

        return new self(array_values(array_unique($scopes)), false);
    }

    /**
     * The scope of the credential behind this request.
     *
     * A session token is Sanctum's `TransientToken`, which reports every ability as
     * allowed and carries none of its own; it is a user credential, so it is
     * unrestricted. An Auth0 request has no Sanctum token at all and takes the
     * same path.
     */
    public static function forRequest(?Authenticatable $user): self
    {
        if (null === $user || ! method_exists($user, 'currentAccessToken')) {
            return self::unrestricted();
        }

        $token = $user->currentAccessToken();

        if (null === $token || $token instanceof TransientToken) {
            return self::unrestricted();
        }

        return self::fromAbilities($token->abilities ?? null);
    }

    public function isUnrestricted(): bool
    {
        return $this->unrestricted;
    }

    /**
     * @return list<string>
     */
    public function entries(): array
    {
        return $this->scopes;
    }

    /**
     * The assistant this credential is bound to, or null when it is not bound.
     *
     * Only the first well-formed `assistant:{id}` counts: a credential scoped to
     * two assistants would be two principals in one token, which the decision does
     * not allow and this class therefore cannot express.
     */
    public function assistantId(): ?int
    {
        foreach ($this->scopes as $scope) {
            if (1 === preg_match('/^'.self::ASSISTANT.':(\d+)$/', $scope, $matches)) {
                $id = (int) $matches[1];

                if ($id > 0) {
                    return $id;
                }
            }
        }

        return null;
    }

    /**
     * Whether this principal satisfies a route's requirement.
     *
     * Unrestricted principals satisfy everything (see the class docblock). A
     * scoped one matches exactly, or — when the requirement names no id — by
     * prefix, so `assistant` admits `assistant:42` but `assistant:4` never admits
     * `assistant:42`.
     */
    public function allows(string $requirement): bool
    {
        if ($this->unrestricted) {
            return true;
        }

        $requirement = trim($requirement);

        if ('' === $requirement) {
            return false;
        }

        // `any` admits a credential that actually carries a scope, and still
        // refuses one deliberately given nothing.
        if (self::ANY === $requirement) {
            return array() !== $this->scopes;
        }

        if (in_array($requirement, $this->scopes, true)) {
            return true;
        }

        if (str_contains($requirement, ':')) {
            return false;
        }

        foreach ($this->scopes as $scope) {
            if (self::scopeSatisfiesClass($scope, $requirement)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a stored scope is a usable member of a required class.
     *
     * `assistant` is the only class with an id grammar worth enforcing: a scope
     * that says `assistant:` (empty), `assistant:0` or `assistant:abc` is a
     * credential no route can act on, so it must not satisfy the class — otherwise
     * the route passes the check and then reads a null id. Every other class only
     * requires a non-empty value, because `estate:assistants.list` is a name, not a
     * number.
     */
    private static function scopeSatisfiesClass(string $scope, string $requirement): bool
    {
        if (! str_starts_with($scope, $requirement.':')) {
            return false;
        }

        $value = substr($scope, strlen($requirement) + 1);

        if ('' === $value) {
            return false;
        }

        if (self::ASSISTANT === $requirement) {
            return 1 === preg_match('/^\d+$/', $value) && (int) $value > 0;
        }

        return true;
    }
}
