<?php

/**
 * Cloudways API v2 over the core HTTP contract.
 *
 * Three behaviours are copied from `WP_MCP_AI_Cloudways_Client` on purpose,
 * because they are what the existing settings screen and the legacy tools
 * already assume:
 *
 * - **The credentials are the same option keys**: `cloudways_email` and
 *   `cloudways_api_key`. On WordPress both runtimes write the same
 *   `wp_mcp_ai_settings` array, so a site that connects through either one is
 *   connected for both, and a migrated install needs no credential work.
 * - **The token is cached in settings**, under `cloudways_access_token` and
 *   `cloudways_token_expires_at`, with a sixty-second safety margin. Cloudways
 *   tokens are hourly; a token that expires mid-request is a 401 that costs a
 *   second round trip to diagnose.
 * - **Errors are classified**, not flattened: 401 is `cloudways_unauthorized`,
 *   429 is `cloudways_rate_limited`, 404 is `cloudways_not_found`, everything
 *   else is `cloudways_api_error` carrying the API's own message.
 *
 * One thing is different. The WordPress client is a singleton that reads
 * settings on every call; this one is constructed with its collaborators, so a
 * test can hand it a fixed clock-free settings double and a fake transport and
 * assert the exact request that leaves the building.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Infrastructure\Cloudways;

use Nvoos\Core\Domain\Contract\CloudwaysClientInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Domain\Error\CloudwaysApiException;

final class CloudwaysClient implements CloudwaysClientInterface
{
    public const API_BASE = 'https://api.cloudways.com/api/v2';

    public const OAUTH_ENDPOINT = 'https://api.cloudways.com/api/v2/oauth/access_token';

    /** Refresh this many seconds before the token actually expires. */
    public const TOKEN_BUFFER_SECONDS = 60;

    public const EMAIL_KEY = 'cloudways_email';

    public const API_KEY_KEY = 'cloudways_api_key';

    public const TOKEN_KEY = 'cloudways_access_token';

    public const EXPIRES_KEY = 'cloudways_token_expires_at';

    public const CONNECTED_KEY = 'cloudways_connected';

    public const CONNECTED_AT_KEY = 'cloudways_connection_time';

    public function __construct(
        private readonly SettingsStoreInterface $settings,
        private readonly HttpClientInterface $http,
        private readonly string $baseUrl = self::API_BASE,
        private readonly string $oauthEndpoint = self::OAUTH_ENDPOINT,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim((string) $this->settings->get(self::EMAIL_KEY, ''))
            && '' !== trim((string) $this->settings->get(self::API_KEY_KEY, ''));
    }

    public function get(string $path, array $query = array()): array
    {
        return $this->request('GET', $path, $query);
    }

    public function post(string $path, array $body = array()): array
    {
        return $this->request('POST', $path, array(), $body);
    }

    public function put(string $path, array $body = array()): array
    {
        return $this->request('PUT', $path, array(), $body);
    }

    public function delete(string $path, array $body = array()): array
    {
        return $this->request('DELETE', $path, array(), $body);
    }

    /**
     * A token that is valid for at least the buffer window.
     *
     * The exchange is the only place credentials leave the host, and it is a
     * form-encoded POST because that is what the v2 endpoint accepts.
     */
    private function accessToken(): string
    {
        $cached  = trim((string) $this->settings->get(self::TOKEN_KEY, ''));
        $expires = (int) $this->settings->get(self::EXPIRES_KEY, 0);

        if ('' !== $cached && $expires > 0 && time() < ( $expires - self::TOKEN_BUFFER_SECONDS )) {
            return $cached;
        }

        $email = trim((string) $this->settings->get(self::EMAIL_KEY, ''));
        $key   = trim((string) $this->settings->get(self::API_KEY_KEY, ''));

        if ('' === $email || '' === $key) {
            throw new CloudwaysApiException(
                'Cloudways credentials are not configured on this host.',
                'cloudways_not_configured'
            );
        }

        $response = $this->send(
            'POST',
            $this->oauthEndpoint,
            array('Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'),
            http_build_query(array('email' => $email, 'api_key' => $key))
        );

        $payload = $this->decode($response->body);
        $status  = $response->statusCode;

        if ($status < 200 || $status >= 300) {
            $message = (string) ( $payload['message'] ?? $payload['error_description'] ?? '' );

            throw new CloudwaysApiException(
                '' !== $message ? $message : 'Cloudways rejected the credentials with HTTP ' . $status . '.',
                'cloudways_auth_failed',
                $status,
                $payload
            );
        }

        $token = (string) ( $payload['access_token'] ?? '' );

        if ('' === $token) {
            throw new CloudwaysApiException(
                'Cloudways did not return an access token.',
                'cloudways_invalid_response',
                $status,
                $payload
            );
        }

        $lifetime = (int) ( $payload['expires_in'] ?? 3600 );

        $this->settings->set(self::TOKEN_KEY, $token);
        $this->settings->set(self::EXPIRES_KEY, time() + ( $lifetime > 0 ? $lifetime : 3600 ));
        $this->settings->set(self::CONNECTED_KEY, true);
        $this->settings->set(self::CONNECTED_AT_KEY, time());

        return $token;
    }

    /**
     * @param array<string, scalar> $query
     * @param array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $query = array(), array $body = array()): array
    {
        $token = $this->accessToken();

        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');

        if (array() !== $query) {
            $url .= '?' . http_build_query($query);
        }

        $headers = array(
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        );

        $bodyString = null;

        if (array() !== $body) {
            $bodyString              = (string) json_encode($body);
            $headers['Content-Type'] = 'application/json';
        }

        $response = $this->send($method, $url, $headers, $bodyString);

        $payload = $this->decode($response->body);
        $status  = $response->statusCode;

        if ($status >= 200 && $status < 300) {
            return $payload;
        }

        $code = 'cloudways_api_error';

        if (401 === $status) {
            $code = 'cloudways_unauthorized';
        } elseif (429 === $status) {
            $code = 'cloudways_rate_limited';
        } elseif (404 === $status) {
            $code = 'cloudways_not_found';
        }

        $message = (string) ( $payload['message'] ?? $payload['error_description'] ?? '' );

        throw new CloudwaysApiException(
            '' !== $message ? $message : 'Cloudways API returned HTTP ' . $status . '.',
            $code,
            $status,
            $payload
        );
    }

    /**
     * Send through the injected transport, which may throw its own exception.
     */
    private function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        try {
            return $this->http->send($method, $url, $headers, $body);
        } catch (\Throwable $e) {
            throw new CloudwaysApiException(
                'Cloudways request failed: ' . $e->getMessage(),
                'cloudways_request_failed',
                0,
                array(),
                $e
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : array();
    }
}
