<?php

/**
 * Shared ground for the Twitter/X tools.
 *
 * Proposal 164 section 164.107. Twitter is the first provider in this family
 * that does not authenticate with a bearer token. The three tools use two
 * different schemes, and neither of them is a header the caller can just paste:
 *
 * - **OAuth 1.0a user context** for sending DMs and managing webhooks. The
 *   caller supplies four values, and the tool derives a per-request signature
 *   from them. `sign()` is RFC 5849 HMAC-SHA1, checked against the worked
 *   example in X's own "Creating a signature" page.
 * - **OAuth 2.0 user token** for reading DMs. That one *is* a bearer header,
 *   but it is a user token with `dm.read`, not the app-only bearer token used
 *   for public reads; an app-only token answers 403 here.
 *
 * ## Correction: the signature covers the URL it is sent to
 *
 * The WordPress implementation signed `rawurlencode($fullUrl)` and ignored any
 * query string. That is correct only while the URL has neither a query nor a
 * default port nor an upper-case host, which happened to hold for all four of
 * its signed endpoints. `signatureBaseString()` derives the base URI per
 * RFC 5849 section 3.4.1.2 instead — lower-cased scheme and host, the port only
 * when it is not the scheme default, no query, no fragment — so a future signed
 * endpoint with a query string cannot silently produce an invalid signature.
 *
 * ## Correction: the two id shapes are checked before they reach a path
 *
 * WordPress ran `sanitize_text_field()` and then interpolated the result into a
 * path segment. A user id or conversation id that contains `/` or `..` then
 * addresses a different route. Both shapes are validated here and the value is
 * encoded once on the way out.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

use Nvoos\Core\Domain\Entity\HttpResponse;

abstract class TwitterTool extends ChatChannelTool
{
    /**
     * The API host. X documents `api.x.com` and keeps `api.twitter.com` as an
     * alias; the alias is used so a stored WordPress credential set keeps
     * addressing the host it was issued against.
     */
    protected const API = 'https://api.twitter.com';

    /**
     * `POST /2/dm_conversations/.../messages` rejects text longer than this.
     */
    protected const MAX_DM_LENGTH = 10000;

    /**
     * `GET /2/dm_events` accepts 1-100 per page.
     */
    protected const MAX_DM_EVENTS = 100;

    /**
     * The page size the read tool uses when none is given.
     */
    protected const DEFAULT_DM_EVENTS = 25;

    /**
     * X documents `pagination_token` as a base32hex string of at least this
     * length. The floor catches an event id passed where a token belongs.
     */
    protected const MIN_PAGINATION_TOKEN = 16;

    /**
     * The four OAuth 1.0a values, or the canonical refusal naming the missing
     * ones.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array{api_key: string, api_secret: string, access_token: string, access_token_secret: string}|mixed
     */
    protected function oauth1(array $arguments): mixed
    {
        $credentials = array(
            'api_key'             => $this->credential($arguments, 'api_key'),
            'api_secret'          => $this->credential($arguments, 'api_secret'),
            'access_token'        => $this->credential($arguments, 'access_token'),
            'access_token_secret' => $this->credential($arguments, 'access_token_secret'),
        );

        $missing = array();

        foreach ($credentials as $key => $value) {
            if ('' === $value) {
                $missing[] = $key;
            }
        }

        if (array() !== $missing) {
            return $this->errors->validationFailed(
                'All four OAuth 1.0a credentials are required; missing: ' . implode(', ', $missing) . '.',
                array_fill_keys($missing, array('Required.'))
            );
        }

        return $credentials;
    }

    /**
     * The OAuth 2.0 user token, or the canonical refusal.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    protected function bearer(array $arguments): mixed
    {
        $token = $this->credential($arguments, 'bearer_token');

        if ('' === $token) {
            return $this->errors->validationFailed(
                'An OAuth 2.0 user token with the dm.read scope is required.',
                array('bearer_token' => array('Required.'))
            );
        }

        return $token;
    }

    /**
     * An `Authorization: OAuth ...` header for one request.
     *
     * @param array{api_key: string, api_secret: string, access_token: string, access_token_secret: string} $credentials Credentials.
     * @param string|null                                                                                  $nonce       Fixed nonce, for tests.
     * @param int|null                                                                                     $timestamp   Fixed timestamp, for tests.
     */
    protected function oauth1Header(
        string $method,
        string $url,
        array $credentials,
        ?string $nonce = null,
        ?int $timestamp = null
    ): string {
        $parameters = array(
            'oauth_consumer_key'     => $credentials['api_key'],
            'oauth_nonce'            => $nonce ?? bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => (string) ($timestamp ?? time()),
            'oauth_token'            => $credentials['access_token'],
            'oauth_version'          => '1.0',
        );

        $parameters['oauth_signature'] = self::sign(
            $method,
            $url,
            $parameters,
            $credentials['api_secret'],
            $credentials['access_token_secret']
        );

        $parts = array();

        foreach ($parameters as $name => $value) {
            $parts[] = rawurlencode($name) . '="' . rawurlencode($value) . '"';
        }

        return 'OAuth ' . implode(', ', $parts);
    }

    /**
     * The RFC 5849 signature for a parameter set.
     *
     * `$url` supplies only the scheme, host and path; its query string, if any,
     * is deliberately ignored. A caller whose endpoint carries a query must
     * pass those pairs in `$parameters` — the documented contract of
     * `signatureBaseString()` — so that nothing is counted twice.
     *
     * @param array<string, string> $parameters Every parameter to sign: the
     *                                          `oauth_*` values plus any query
     *                                          or form-encoded body pairs.
     */
    public static function sign(
        string $method,
        string $url,
        array $parameters,
        string $consumerSecret,
        string $tokenSecret
    ): string {
        $base   = self::signatureBaseString($method, $url, $parameters);
        $key    = rawurlencode($consumerSecret) . '&' . rawurlencode($tokenSecret);
        $digest = hash_hmac('sha1', $base, $key, true);

        return base64_encode($digest); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- OAuth 1.0a mandates base64.
    }

    /**
     * The signature base string, RFC 5849 section 3.4.1.
     *
     * @param array<string, string> $parameters Every parameter to sign.
     */
    public static function signatureBaseString(string $method, string $url, array $parameters): string
    {
        $pairs = array();

        foreach ($parameters as $name => $value) {
            $pairs[] = array(rawurlencode((string) $name), rawurlencode((string) $value));
        }

        usort(
            $pairs,
            static function (array $left, array $right): int {
                return $left[0] === $right[0]
                    ? strcmp($left[1], $right[1])
                    : strcmp($left[0], $right[0]);
            }
        );

        $normalized = array();

        foreach ($pairs as $pair) {
            $normalized[] = $pair[0] . '=' . $pair[1];
        }

        return strtoupper($method)
            . '&' . rawurlencode(self::baseUri($url))
            . '&' . rawurlencode(implode('&', $normalized));
    }

    /**
     * Scheme, host, optional non-default port and path, lower-cased, with no
     * query and no fragment.
     */
    private static function baseUri(string $url): string
    {
        $parts  = parse_url($url);
        $parts  = is_array($parts) ? $parts : array();
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host   = strtolower((string) ($parts['host'] ?? ''));
        $port   = isset($parts['port']) ? (int) $parts['port'] : 0;
        $path   = (string) ($parts['path'] ?? '');

        $default = array('http' => 80, 'https' => 443);
        $suffix  = 0 !== $port && ($default[$scheme] ?? 0) !== $port ? ':' . $port : '';

        return $scheme . '://' . $host . $suffix . ('' === $path ? '/' : $path);
    }

    /**
     * A user id: the documented `^[0-9]{1,19}$`, or ''.
     */
    protected function userId(mixed $value): string
    {
        $id = is_scalar($value) ? trim((string) $value) : '';

        return 1 === preg_match('/^[0-9]{1,19}$/', $id) ? $id : '';
    }

    /**
     * A DM conversation id, or ''.
     *
     * X does not publish a single pattern: a one-to-one conversation is two
     * user ids joined by a hyphen, a group conversation is a numeric id. The
     * guard is therefore the safe subset that cannot leave a path segment.
     */
    protected function conversationId(mixed $value): string
    {
        $id = is_scalar($value) ? trim((string) $value) : '';

        return 1 === preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) ? $id : '';
    }

    /**
     * A pagination token, or ''.
     *
     * @return string|mixed The token, or the validation error for a value that
     *                      cannot be a token.
     */
    protected function paginationToken(array $arguments): mixed
    {
        $token = $this->textArg($arguments, 'pagination_token');

        if ('' === $token) {
            return '';
        }

        if (1 !== preg_match('/^[A-Za-z0-9_-]{' . self::MIN_PAGINATION_TOKEN . ',}$/', $token)) {
            return $this->errors->validationFailed(
                'A pagination token is at least ' . self::MIN_PAGINATION_TOKEN . ' URL-safe characters.',
                array('pagination_token' => array('Not a page token.'))
            );
        }

        return $token;
    }

    /**
     * DM text, trimmed, or the canonical refusal.
     *
     * @param mixed $value Raw argument.
     */
    protected function dmText(mixed $value): mixed
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        if ('' === $text) {
            return $this->errors->validationFailed(
                'DM text must be provided.',
                array('text' => array('Required.'))
            );
        }

        if (self::MAX_DM_LENGTH < mb_strlen($text)) {
            return $this->errors->validationFailed(
                'DM text is limited to ' . self::MAX_DM_LENGTH . ' characters.',
                array('text' => array('Too long.'))
            );
        }

        return $text;
    }

    /**
     * One signed request, and its classification.
     *
     * @param array{api_key: string, api_secret: string, access_token: string, access_token_secret: string} $credentials Credentials.
     *
     * @return array<array-key, mixed>
     *
     * @throws ChatChannelApiException
     */
    protected function signed(
        string $method,
        string $url,
        array $credentials,
        ?string $body = null
    ): array {
        $headers = array('Authorization' => $this->oauth1Header($method, $url, $credentials));

        if (null !== $body) {
            $headers['Content-Type'] = 'application/json';
        }

        return $this->finish($this->request($method, $url, $headers, $body));
    }

    /**
     * One bearer request, and its classification.
     *
     * @return array<array-key, mixed>
     *
     * @throws ChatChannelApiException
     */
    protected function authorized(string $method, string $url, string $token): array
    {
        return $this->finish(
            $this->request($method, $url, array('Authorization' => 'Bearer ' . $token), null)
        );
    }

    /**
     * Turn a response into a body or a classified exception.
     *
     * X answers a refusal with an RFC 9457 problem document
     * (`type`, `title`, `status`, `detail`) and sometimes with a legacy
     * `errors` list; v1.1 answers with `errors[].code` and `.message`. The
     * detail, then the title, then the legacy message is the message; the
     * numeric `status` or the legacy `code` is the provider code.
     *
     * @return array<array-key, mixed>
     *
     * @throws ChatChannelApiException
     */
    private function finish(HttpResponse $response): array
    {
        $decoded = $this->decode($response);

        if (200 > $response->statusCode || 300 <= $response->statusCode) {
            $legacy = is_array($decoded['errors'][0] ?? null) ? $decoded['errors'][0] : array();

            $detail = $this->scalarText($decoded['detail'] ?? null);
            $title  = $this->scalarText($decoded['title'] ?? null);
            $text   = $this->scalarText($legacy['message'] ?? null);

            $message = $detail;

            if ('' === $message) {
                $message = $title;
            }

            if ('' === $message) {
                $message = $text;
            }

            $code = '';

            if (isset($decoded['status']) && is_numeric($decoded['status'])) {
                $code = (string) (int) $decoded['status'];
            } elseif (isset($legacy['code']) && is_scalar($legacy['code'])) {
                $code = trim((string) $legacy['code']);
            }

            throw new ChatChannelApiException(
                '' !== $message ? 'Twitter API error: ' . $message : 'Twitter API returned an error.',
                'twitter_api_error',
                $response->statusCode,
                $code,
                $decoded,
                $this->retryAfter($response)
            );
        }

        return $decoded;
    }

    /**
     * The wait before a retry, in whole seconds.
     *
     * A 429 from X carries `x-rate-limit-reset` as a Unix timestamp rather than
     * a delay, so the remaining seconds are measured against now. A
     * `Retry-After` header, when a proxy adds one, wins because it is already a
     * delay.
     */
    private function retryAfter(HttpResponse $response): int
    {
        $reset = 0;

        foreach ($response->headers as $name => $value) {
            $name = (string) $name;

            if (0 === strcasecmp($name, 'retry-after')) {
                return is_numeric($value) ? max(0, (int) ceil((float) $value)) : 0;
            }

            if (0 === strcasecmp($name, 'x-rate-limit-reset') && is_numeric($value)) {
                $reset = (int) $value;
            }
        }

        return $reset > 0 ? max(0, $reset - time()) : 0;
    }

    /**
     * A scalar field, trimmed, or ''.
     */
    protected function scalarText(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
