<?php

/**
 * Shared ground for the Google Chat API tools.
 *
 * Proposal 164 section 164.105. Google Chat differs from the graph providers in
 * three ways:
 *
 * - **The credential is an OAuth access token.** WordPress also accepted a
 *   service-account JSON key and exchanged it for a token with an RS256 JWT;
 *   the port takes the token, and the exchange is the host's job (see the
 *   section note). A token is one bearer family across the seven tools.
 * - **Resource names are paths.** `spaces/AAAA` and
 *   `spaces/AAAA/members/BBBB` are validated to the characters Google uses and
 *   interpolated unchanged; a value outside that set never becomes a route.
 * - **Pagination is a token.** `pageToken` in, `nextPageToken` out. There is no
 *   absolute link to guard, unlike Graph.
 *
 * The error object carries a numeric `code` and a word `status`
 * (`PERMISSION_DENIED`); the word is the provider code.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

use Nvoos\Core\Support\Json;

abstract class GoogleChatTool extends ChatChannelTool
{
    /**
     * The Chat API root. The version is part of the path.
     */
    private const API_BASE = 'https://chat.googleapis.com/v1';

    /**
     * The page sizes the tools use.
     */
    protected const DEFAULT_PAGE_SIZE = 100;
    protected const MAX_PAGE_SIZE = 1000;

    /**
     * The access token, or the canonical refusal when it is absent.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    protected function accessToken(array $arguments): mixed
    {
        $token = $this->credential($arguments, 'access_token');

        if ('' === $token) {
            return $this->errors->validationFailed(
                'A Google Chat OAuth access token is required.',
                array('access_token' => array('A valid access token is required.'))
            );
        }

        return $token;
    }

    /**
     * A `spaces/{id}` resource name.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    protected function space(array $arguments): mixed
    {
        $value = $this->textArg($arguments, 'space');

        if (1 === preg_match('#^spaces/[A-Za-z0-9_-]{1,200}$#', $value)) {
            return $value;
        }

        return $this->errors->validationFailed(
            'A space resource name is required, in the form spaces/{id}.',
            array('space' => array('Invalid space name.'))
        );
    }

    /**
     * A `spaces/{id}/members/{id}` resource name.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    protected function membership(array $arguments): mixed
    {
        $value = $this->textArg($arguments, 'membership');

        if (1 === preg_match('#^spaces/[A-Za-z0-9_-]{1,200}/members/[A-Za-z0-9_-]{1,200}$#', $value)) {
            return $value;
        }

        return $this->errors->validationFailed(
            'A membership resource name is required, in the form spaces/{id}/members/{id}.',
            array('membership' => array('Invalid membership name.'))
        );
    }

    /**
     * Whether a member name is `users/{id}` or `groups/{id}`.
     */
    protected function isMemberName(string $value): bool
    {
        return 1 === preg_match('#^(users|groups)/[A-Za-z0-9@._-]{1,200}$#', $value);
    }

    /**
     * GET one endpoint.
     *
     * @param array<string, scalar> $query Query parameters.
     *
     * @return array<array-key, mixed>
     *
     * @throws ChatChannelApiException
     */
    protected function apiGet(string $token, string $path, array $query = array()): array
    {
        $url = self::API_BASE . $path;

        if (array() !== $query) {
            $url .= '?' . \http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $this->call('GET', $url, $token, null);
    }

    /**
     * POST one endpoint as JSON.
     *
     * @param array<string, mixed> $payload JSON body.
     *
     * @return array<array-key, mixed>
     *
     * @throws ChatChannelApiException
     */
    protected function apiPost(string $token, string $path, array $payload): array
    {
        return $this->call('POST', self::API_BASE . $path, $token, Json::encode($payload));
    }

    /**
     * DELETE one endpoint.
     *
     * @return array<array-key, mixed>
     *
     * @throws ChatChannelApiException
     */
    protected function apiDelete(string $token, string $path): array
    {
        return $this->call('DELETE', self::API_BASE . $path, $token, null);
    }

    /**
     * One request and its classification.
     *
     * @return array<array-key, mixed>
     *
     * @throws ChatChannelApiException
     */
    private function call(string $method, string $url, string $token, ?string $body): array
    {
        $headers = array('Authorization' => 'Bearer ' . $token);

        if (null !== $body) {
            $headers['Content-Type'] = 'application/json; charset=UTF-8';
        }

        $response = $this->request($method, $url, $headers, $body);
        $decoded  = $this->decode($response);

        if (200 > $response->statusCode || 300 <= $response->statusCode) {
            $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : array();

            $status  = isset($error['status']) && is_scalar($error['status']) ? trim((string) $error['status']) : '';
            $numeric = isset($error['code']) && is_numeric($error['code']) ? (string) (int) $error['code'] : '';
            $message = $this->text($error['message'] ?? '');

            throw new ChatChannelApiException(
                '' !== $message ? 'Google Chat API error: ' . $message : 'Google Chat API returned an error.',
                'google_chat_api_error',
                $response->statusCode,
                '' !== $status ? $status : $numeric,
                $decoded
            );
        }

        return $decoded;
    }

    /**
     * The next page token, or null on the last page.
     *
     * @param array<array-key, mixed> $payload A decoded list payload.
     */
    protected function nextPageToken(array $payload): ?string
    {
        $token = $this->text($payload['nextPageToken'] ?? '');

        return '' !== $token ? $token : null;
    }

    /**
     * A space, projected.
     *
     * @param mixed $space One space object.
     *
     * @return array<string, mixed>
     */
    protected function spaceRow(mixed $space): array
    {
        if (! is_array($space)) {
            return array();
        }

        $type = $this->text($space['spaceType'] ?? '');

        if ('' === $type) {
            $type = $this->text($space['type'] ?? '');
        }

        return array(
            'name'         => $this->text($space['name'] ?? ''),
            'display_name' => $this->text($space['displayName'] ?? ''),
            'type'         => $type,
        );
    }

    /**
     * A string field from a Chat row, or '' when absent.
     */
    protected function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
