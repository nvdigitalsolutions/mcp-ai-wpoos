<?php

namespace App\Auth;

use App\Http\Middleware\ResolveApiTenant;
use App\Models\User;
use App\Support\Auth\Auth0Config;
use App\Support\Auth\Auth0TokenException;
use App\Support\Auth\Auth0TokenVerifier;
use App\Support\Settings\SettingsService;
use App\Support\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;

/**
 * The `auth0` guard — Proposal 149 §102.
 *
 * Mounted as a second guard (`auth:sanctum,auth0`), so `Authenticate` tries
 * Sanctum first and falls through to this one only when Sanctum cannot resolve a
 * token. That ordering is what keeps the existing three authentication paths
 * untouched: a Sanctum bearer, an assistant credential, or an SPA session all
 * win before an Auth0 token is ever parsed.
 *
 * WHICH ESTATE'S CONFIGURATION VERIFIES THE TOKEN
 *
 * There is no principal yet, so the estate cannot be inferred — the token has to
 * be checked against *some* estate's audience before a user exists. `X-Tenant-Id`
 * supplies it, and that is safe for a reason worth stating: the header here is a
 * **selector for which configuration to verify against**, not an authorisation.
 * A wrong selection fails verification (a different estate's domain or audience
 * does not match the token), and the real authority check still happens in
 * `ResolveApiTenant`, which runs afterwards and enforces membership. This is the
 * same rule the OWASP note in that middleware states, applied one step earlier.
 *
 * WHY A LINKED SUBJECT AND NOT AN EMAIL
 *
 * Proposal 156 D4: a subject with no link is denied, never provisioned, and
 * never matched by email — an address is mutable and matching on it is an
 * account takeover. `nvoos:auth0-link` is how an operator creates the link.
 */
final class Auth0Guard implements Guard
{
    private ?Authenticatable $user = null;

    private bool $resolved = false;

    public function __construct(
        private readonly Request $request,
        private readonly Auth0TokenVerifier $verifier,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * @throws Auth0TokenException when the request carried an Auth0 token and it
     *                             was rejected — the reason and status travel
     *                             with the exception so the API can say which.
     */
    public function user(): ?Authenticatable
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;

        $token = $this->request->bearerToken();

        // Not-shaped-like-a-JWT is not this guard's business: `1|abc…` is a
        // Sanctum token and has no dots. Returning null lets the other guards
        // answer instead of turning a Sanctum request into an Auth0 401.
        if (! is_string($token) || 2 !== substr_count($token, '.')) {
            return null;
        }

        $tenantId = trim((string) $this->request->header(ResolveApiTenant::HEADER, ''));

        if ('' === $tenantId) {
            throw Auth0TokenException::tenantRequired();
        }

        // `runAs` because the settings store resolves the estate per read, and
        // no tenant middleware has run yet. It restores the previous value.
        $config = TenantContext::runAs(
            $tenantId,
            fn (): ?Auth0Config => Auth0Config::fromValues(
                $this->settings->value('auth0_domain'),
                $this->settings->value('auth0_audience'),
                $this->settings->value('auth0_required_scope'),
            )
        );

        if (null === $config) {
            throw Auth0TokenException::notConfigured();
        }

        $claims = $this->verifier->verify($token, $config);

        $user = User::query()->where('auth0_subject', $claims->subject)->first();

        if (! $user instanceof User) {
            throw Auth0TokenException::subjectUnmapped($claims->subject);
        }

        return $this->user = $user;
    }

    public function check(): bool
    {
        return null !== $this->user();
    }

    /**
     * Whether a user is already resolved on this guard.
     *
     * Deliberately does NOT resolve: the contract asks whether one is set, and
     * resolving here would verify (and possibly reject) a token on a call that
     * only wanted to know if there was anything to check.
     */
    public function hasUser(): bool
    {
        return null !== $this->user;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function id(): mixed
    {
        return $this->user()?->getAuthIdentifier();
    }

    /**
     * This guard has no credential form. A token is presented, not validated.
     *
     * @param array<string, mixed> $credentials
     */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;
        $this->resolved = true;

        return $this;
    }
}
