<?php

namespace App\Support\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Verify an Auth0 RS256 access token against one estate's configuration —
 * Proposal 149 §102.
 *
 * WHAT IT REFUSES TO DO, because each refusal is a known attack:
 *
 *  - **Trust the header's algorithm.** `alg` is read only to reject anything
 *    that is not RS256. Accepting the header's choice is the `alg: none` and
 *    HS256-key-confusion class of forgery, where the "signature" is checked with
 *    the public key as an HMAC secret.
 *  - **Trust a key it did not fetch.** Keys come from the configured domain's
 *    JWKS, matched by `kid`. A token naming a `kid` we do not hold is rejected,
 *    not resolved by trying every key.
 *  - **Accept a token for another API.** The configured audience must appear in
 *    `aud`; an estate's audience is what distinguishes its tokens from every
 *    other Auth0-issued token in the world.
 *  - **Fail open.** An unreachable JWKS is a 503, never a pass.
 *
 * WHAT IT DOES NOT DO: no session, no user, no provisioning. It returns claims
 * or throws. `Auth0Guard` decides what a subject means.
 *
 * The token is parsed by splitting on dots, not by a JWT library — there is none
 * in this runtime's dependencies, and the parsing is three `explode` calls. The
 * verification is `openssl_verify`, which is the part that must be right.
 */
final class Auth0TokenVerifier
{
    /** JWKS lifetime. Auth0 rotates keys rarely; an hour is the documented norm. */
    private const CACHE_TTL = 3600;

    /**
     * Clock skew allowed on `nbf`/`iat` only.
     *
     * `exp` is checked strictly: a token that says it expired is expired, and a
     * grace window there extends every stolen token's life.
     */
    private const LEEWAY = 60;

    private const TIMEOUT = 10;

    /**
     * @throws Auth0TokenException
     */
    public function verify(string $token, Auth0Config $config, ?int $now = null): Auth0Claims
    {
        $now ??= time();
        $segments = explode('.', $token);

        if (3 !== count($segments)) {
            throw Auth0TokenException::invalid();
        }

        $header = $this->decodeJson($segments[0]);

        if (null === $header) {
            throw Auth0TokenException::invalid();
        }

        // Reject before doing any work: an unsigned token must never reach the
        // signature step, where a loose check could accept an empty signature.
        if (($header['alg'] ?? '') !== 'RS256') {
            throw Auth0TokenException::invalid('Unsupported token algorithm; RS256 is required.');
        }

        $kid = isset($header['kid']) ? (string) $header['kid'] : '';

        if ('' === $kid) {
            throw Auth0TokenException::invalid('The token header names no key.');
        }

        $payload = $this->decodeJson($segments[1]);

        if (null === $payload) {
            throw Auth0TokenException::invalid();
        }

        $this->assertSignature($segments[0].'.'.$segments[1], $segments[2], $kid, $config);

        $claims = Auth0Claims::fromPayload($payload);

        $this->assertTiming($payload, $now);
        $this->assertIssuer($claims, $config);
        $this->assertAudience($claims, $config);
        $this->assertScopes($claims, $config);

        if ('' === $claims->subject) {
            throw Auth0TokenException::subjectMissing();
        }

        return $claims;
    }

    /**
     * @throws Auth0TokenException
     */
    private function assertSignature(string $signed, string $signature, string $kid, Auth0Config $config): void
    {
        if (! function_exists('openssl_verify')) {
            throw Auth0TokenException::opensslUnavailable();
        }

        $pem = null;

        foreach ($this->keys($config) as $key) {
            if (isset($key['kid']) && $kid === (string) $key['kid']) {
                $pem = $this->publicKeyPem($key);

                break;
            }
        }

        if (null === $pem) {
            throw Auth0TokenException::invalid();
        }

        $raw = $this->base64UrlDecode($signature);

        if ('' === $raw) {
            throw Auth0TokenException::invalid();
        }

        $verified = openssl_verify($signed, $raw, $pem, OPENSSL_ALGO_SHA256);

        if (1 !== $verified) {
            throw Auth0TokenException::invalid();
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws Auth0TokenException
     */
    private function assertTiming(array $payload, int $now): void
    {
        if (empty($payload['exp'])) {
            throw Auth0TokenException::invalid('The token has no expiry.');
        }

        if ($now >= (int) $payload['exp']) {
            throw Auth0TokenException::expired();
        }

        if (isset($payload['nbf']) && $now + self::LEEWAY < (int) $payload['nbf']) {
            throw Auth0TokenException::invalid('The token is not valid yet.');
        }
    }

    /**
     * @throws Auth0TokenException
     */
    private function assertIssuer(Auth0Claims $claims, Auth0Config $config): void
    {
        // An issuer from a different estate's Auth0 tenant would be signed by a
        // key we happened to hold only if the domains are the same, so this also
        // catches a token minted for another estate that shares an audience.
        if ($claims->issuer !== $config->issuer()) {
            throw Auth0TokenException::invalid('The token was issued by a different identity provider.');
        }
    }

    /**
     * @throws Auth0TokenException
     */
    private function assertAudience(Auth0Claims $claims, Auth0Config $config): void
    {
        if (! $config->hasAudienceRequirement()) {
            return;
        }

        if (! $claims->hasAudience($config->audience)) {
            throw Auth0TokenException::audienceMismatch();
        }
    }

    /**
     * @throws Auth0TokenException
     */
    private function assertScopes(Auth0Claims $claims, Auth0Config $config): void
    {
        $required = $config->requiredScopes();

        if (array() === $required) {
            return;
        }

        $missing = array_values(array_filter(
            $required,
            static fn (string $scope): bool => ! $claims->hasScope($scope)
        ));

        if (array() !== $missing) {
            throw Auth0TokenException::scopeMissing($missing);
        }
    }

    /**
     * The domain's signing keys, cached.
     *
     * Only a successful, well-formed response is cached: a transient Auth0
     * outage must not pin an estate to "no keys" for an hour, and an empty key
     * set would reject every valid token.
     *
     * @return list<array<string, mixed>>
     *
     * @throws Auth0TokenException
     */
    private function keys(Auth0Config $config): array
    {
        $cacheKey = 'nvoos:auth0:jwks:'.sha1($config->domain);

        $cached = Cache::get($cacheKey);

        if (is_array($cached) && array() !== $cached) {
            return $cached;
        }

        try {
            $response = Http::timeout(self::TIMEOUT)
                ->acceptJson()
                ->get($config->jwksUrl());
        } catch (\Throwable $exception) {
            report($exception);

            throw Auth0TokenException::jwksUnavailable();
        }

        if (! $response->successful()) {
            throw Auth0TokenException::jwksUnavailable();
        }

        $body = $response->json();
        $keys = is_array($body) && isset($body['keys']) && is_array($body['keys'])
            ? array_values(array_filter($body['keys'], 'is_array'))
            : array();

        if (array() === $keys) {
            throw Auth0TokenException::jwksUnavailable();
        }

        Cache::put($cacheKey, $keys, self::CACHE_TTL);

        return $keys;
    }

    /**
     * Turn a JWK into a PEM public key.
     *
     * Encodes the SPKI structure by hand — `openssl_pkey_get_public` wants PEM
     * and JWKs carry only the modulus and exponent. The shape is
     * `SEQUENCE { AlgorithmIdentifier, BIT STRING { SEQUENCE { INTEGER n, INTEGER e } } }`.
     *
     * @param array<string, mixed> $jwk
     */
    private function publicKeyPem(array $jwk): ?string
    {
        if (($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
            return null;
        }

        $modulus = $this->base64UrlDecode((string) $jwk['n']);
        $exponent = $this->base64UrlDecode((string) $jwk['e']);

        if ('' === $modulus || '' === $exponent) {
            return null;
        }

        $rsaKey = $this->der(0x30, $this->derInteger($modulus).$this->derInteger($exponent));

        // AlgorithmIdentifier: rsaEncryption (1.2.840.113549.1.1.1) + NULL params.
        $algorithm = $this->der(0x30, "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");

        // BIT STRING: one unused-bits octet, then the key.
        $subjectPublicKeyInfo = $this->der(0x30, $algorithm.$this->der(0x03, "\x00".$rsaKey));

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
            .'-----END PUBLIC KEY-----';

        return false === openssl_pkey_get_public($pem) ? null : $pem;
    }

    /**
     * A DER TLV with a short or long-form length.
     */
    private function der(int $tag, string $content): string
    {
        $length = strlen($content);

        if ($length < 0x80) {
            return chr($tag).chr($length).$content;
        }

        $encoded = ltrim(pack('N', $length), "\x00");

        return chr($tag).chr(0x80 | strlen($encoded)).$encoded.$content;
    }

    /**
     * A DER INTEGER, stripping leading zero bytes and re-adding one when the
     * high bit is set (otherwise the value reads as negative).
     */
    private function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        if ('' === $bytes) {
            $bytes = "\x00";
        }

        if (ord($bytes[0]) > 0x7f) {
            $bytes = "\x00".$bytes;
        }

        return $this->der(0x02, $bytes);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $segment): ?array
    {
        $decoded = $this->base64UrlDecode($segment);

        if ('' === $decoded) {
            return null;
        }

        $json = json_decode($decoded, true);

        return is_array($json) ? $json : null;
    }

    private function base64UrlDecode(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $remainder = strlen($value) % 4;

        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($value, true);

        return false === $decoded ? '' : $decoded;
    }
}
