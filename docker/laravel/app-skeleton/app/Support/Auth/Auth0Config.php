<?php

namespace App\Support\Auth;

/**
 * One estate's Auth0 trust configuration — Proposal 149 §102.
 *
 * The three settings that were inert until this slice: `auth0_domain`,
 * `auth0_audience`, `auth0_required_scope`. They are tenant scope, because an
 * Auth0 tenant configures *one estate's* API audience; a platform-wide Auth0
 * domain would mint tokens for an audience another estate also trusts.
 *
 * Normalising lives here rather than at each call site. `auth0_domain` is
 * documented as `your-domain.auth0.com` but administrators paste
 * `https://your-domain.auth0.com/` from the Auth0 dashboard, and an issuer check
 * against a domain with a scheme prepended would fail on a correct token — a
 * confident-looking 401 with no visible cause. So the scheme is stripped, the
 * trailing slash is stripped, and the issuer is rebuilt from the result.
 */
final class Auth0Config
{
    public function __construct(
        public readonly string $domain,
        public readonly string $audience,
        public readonly string $requiredScope,
    ) {
    }

    /**
     * Build from raw setting values, or null when the estate has no domain.
     *
     * An estate with no domain has not opted into Auth0 at all; that is a
     * configuration state, not a rejection, and the caller decides what to say.
     */
    public static function fromValues(mixed $domain, mixed $audience, mixed $requiredScope): ?self
    {
        $host = self::normaliseDomain($domain);

        if ('' === $host) {
            return null;
        }

        return new self(
            $host,
            trim((string) $audience),
            trim((string) $requiredScope),
        );
    }

    /**
     * Strip a scheme, a path and a trailing slash from the configured domain.
     */
    public static function normaliseDomain(mixed $domain): string
    {
        $host = trim((string) $domain);
        $host = (string) preg_replace('#^https?://#i', '', $host);
        $host = rtrim($host, '/');

        return strtolower($host);
    }

    /**
     * The issuer Auth0 puts in its tokens, with the trailing slash Auth0 uses.
     */
    public function issuer(): string
    {
        return 'https://'.$this->domain.'/';
    }

    /**
     * Where the public keys for this domain come from.
     *
     * Plain HTTPS on the configured host: the domain is estate configuration
     * written by an administrator, not a value from the request, so it is not
     * an SSRF vector the way a caller-supplied URL would be.
     */
    public function jwksUrl(): string
    {
        return 'https://'.$this->domain.'/.well-known/jwks.json';
    }

    /**
     * The required scope list, split into individual scope names.
     *
     * @return list<string>
     */
    public function requiredScopes(): array
    {
        if ('' === $this->requiredScope) {
            return array();
        }

        return array_values(array_filter(
            preg_split('/[\s,]+/', $this->requiredScope) ?: array(),
            static fn (string $scope): bool => '' !== $scope
        ));
    }

    public function hasAudienceRequirement(): bool
    {
        return '' !== $this->audience;
    }
}
