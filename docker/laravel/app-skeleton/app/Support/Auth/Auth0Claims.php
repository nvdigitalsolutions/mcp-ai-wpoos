<?php

namespace App\Support\Auth;

/**
 * The claims of a verified Auth0 access token — Proposal 149 §102.
 *
 * Only the fields authentication needs are promoted; `payload` keeps the rest
 * for auditing and for whatever the token carries beyond the contract.
 *
 * Nothing here has been trusted by construction. The verifier signs off on the
 * signature, issuer, audience, scope and expiry *before* this object exists, so
 * a consumer may treat these values as authenticated-but-unverified-in-meaning:
 * the token proves the claims, not that they mean what a caller hopes.
 */
final class Auth0Claims
{
    /**
     * @param list<string>         $audience
     * @param list<string>         $scopes
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $issuer,
        public readonly array $audience,
        public readonly array $scopes,
        public readonly int $expiresAt,
        public readonly ?string $email,
        public readonly array $payload,
    ) {
    }

    /**
     * Build from a verified payload.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        $audience = $payload['aud'] ?? array();

        if (is_string($audience)) {
            $audience = array($audience);
        }

        // Auth0 puts granted scopes in `scope` (space-separated) for access
        // tokens and in `scp` (an array) for tokens minted through the older
        // rule-based flow. Accept either; a token that carries both is not worth
        // guessing about.
        $scopes = array();

        if (isset($payload['scope']) && is_string($payload['scope'])) {
            $scopes = preg_split('/\s+/', trim($payload['scope'])) ?: array();
        }

        if (isset($payload['scp']) && is_array($payload['scp'])) {
            $scopes = array_merge($scopes, $payload['scp']);
        }

        return new self(
            (string) ($payload['sub'] ?? ''),
            (string) ($payload['iss'] ?? ''),
            array_values(array_filter(
                array_map(static fn ($value): string => (string) $value, (array) $audience),
                static fn (string $value): bool => '' !== $value
            )),
            array_values(array_unique(array_filter(
                array_map(static fn ($value): string => (string) $value, $scopes),
                static fn (string $scope): bool => '' !== $scope
            ))),
            (int) ($payload['exp'] ?? 0),
            isset($payload['email']) ? (string) $payload['email'] : null,
            $payload,
        );
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function hasAudience(string $audience): bool
    {
        return in_array($audience, $this->audience, true);
    }
}
