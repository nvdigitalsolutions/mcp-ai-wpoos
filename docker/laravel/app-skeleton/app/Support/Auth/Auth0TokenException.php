<?php

namespace App\Support\Auth;

use RuntimeException;

/**
 * A rejected Auth0 bearer token, with the reason and the status — Proposal 149 §102.
 *
 * WordPress answers typed errors here (`invalid_bearer_audience`,
 * `insufficient_bearer_scope`, `expired_bearer_token`), and the reason matters
 * to whoever is integrating: "expired" means fetch a new token, "audience" means
 * the client asked Auth0 for the wrong API, "unmapped" means the estate
 * administrator has not linked the subject. A bare 401 collapses all four into a
 * support ticket.
 *
 * The status is carried rather than derived because the reasons are genuinely
 * different: a bad signature is an authentication failure (401), a valid token
 * for the wrong audience is a refusal (403), and an unreachable JWKS endpoint is
 * our problem, not the caller's (503).
 *
 * Rendered to `{"error": ..., "message": ...}` by the handler in
 * `bootstrap/app.php`.
 */
final class Auth0TokenException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    /**
     * The estate has no Auth0 domain, so no token can be verified against it.
     */
    public static function notConfigured(): self
    {
        return new self(
            'auth0_not_configured',
            'Auth0 is not configured for this estate. Set the Auth0 domain in the estate settings.',
            401,
        );
    }

    /**
     * The request must name the estate whose configuration verifies the token.
     *
     * There is no principal yet, so nothing can infer the estate: the token has
     * to be checked against *some* estate's audience before a user exists.
     */
    public static function tenantRequired(): self
    {
        return new self(
            'auth0_tenant_required',
            'An Auth0 bearer token must select an estate with X-Tenant-Id.',
            401,
        );
    }

    /**
     * Malformed, unsigned, wrongly signed, or signed by a key we do not hold.
     *
     * Deliberately one code for four cases: telling a caller *which* of them
     * failed hands an attacker a signature oracle, and the caller's next step is
     * the same for all of them.
     */
    public static function invalid(string $reason = 'The bearer token is invalid.'): self
    {
        return new self('auth0_token_invalid', $reason, 401);
    }

    public static function expired(): self
    {
        return new self(
            'auth0_token_expired',
            'The bearer token has expired. Request a fresh Auth0 access token and retry.',
            401,
        );
    }

    /**
     * The token is genuine but was not minted for this estate's API.
     */
    public static function audienceMismatch(): self
    {
        return new self(
            'auth0_audience_mismatch',
            'The bearer token was not issued for this estate\'s API. '
                .'Request a token that includes the configured audience.',
            403,
        );
    }

    /**
     * The token lacks a scope the estate requires.
     *
     * @param list<string> $missing
     */
    public static function scopeMissing(array $missing): self
    {
        return new self(
            'auth0_scope_missing',
            sprintf(
                'The bearer token is missing a required scope (%s).',
                implode(', ', $missing)
            ),
            403,
        );
    }

    /**
     * A valid token with no `sub` claim cannot be mapped to anyone.
     */
    public static function subjectMissing(): self
    {
        return new self(
            'auth0_subject_missing',
            'The bearer token has no subject claim, so it cannot be mapped to a user.',
            401,
        );
    }

    /**
     * The subject is authentic but no user is linked to it.
     *
     * Deny by default (Proposal 156 D4): an unlinked subject is not provisioned,
     * and it is never matched by email — an email address is mutable and
     * matching on it is an account takeover.
     */
    public static function subjectUnmapped(string $subject): self
    {
        return new self(
            'auth0_subject_unmapped',
            sprintf(
                'No user is linked to Auth0 subject "%s". Link one with `php artisan nvoos:auth0-link`.',
                $subject
            ),
            401,
        );
    }

    /**
     * The keys could not be fetched, so the token could not be checked.
     *
     * Ours, not the caller's: fail closed with a 503 rather than accepting an
     * unverified token.
     */
    public static function jwksUnavailable(): self
    {
        return new self(
            'auth0_jwks_unavailable',
            'Auth0 could not be reached to validate the bearer token. Retry once the identity provider is reachable.',
            503,
        );
    }

    /**
     * The runtime cannot verify an RS256 signature at all.
     */
    public static function opensslUnavailable(): self
    {
        return new self(
            'auth0_openssl_unavailable',
            'PHP OpenSSL support is required to validate Auth0 bearer tokens.',
            500,
        );
    }
}
