<?php

/**
 * Shared ground for the Messenger Platform tools.
 *
 * Proposal 164 section 164.105. Messenger runs on the same Graph host and the
 * same nested error object as WhatsApp, so the classifier is the same shape;
 * what differs is the route (`/me/…` rather than a phone number id) and the
 * recipient, which is a page-scoped user id rather than a phone number.
 *
 * `paging.next` is deliberately **not** followed. Meta builds that URL with the
 * access token inside it, so returning it to a model would hand the token away;
 * the reply carries the cursor from `paging.cursors.after` instead, and the
 * caller passes it back as `after`.
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

abstract class MessengerTool extends ChatChannelTool
{
    /**
     * The Graph version the WordPress tools used.
     */
    private const API_BASE = 'https://graph.facebook.com/v19.0';

    /**
     * The page access token, or the canonical refusal when it is absent.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    protected function accessToken(array $arguments): mixed
    {
        $token = $this->credential($arguments, 'access_token');

        if ('' === $token) {
            return $this->errors->validationFailed(
                'A Facebook Page access token is required.',
                array('access_token' => array('A valid Page access token is required.'))
            );
        }

        return $token;
    }

    /**
     * GET one Graph endpoint.
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
     * POST one Graph endpoint as JSON.
     *
     * @param array<string, mixed> $payload JSON body.
     *
     * @return array<array-key, mixed>
     *
     * @throws ChatChannelApiException
     */
    protected function apiPost(string $token, string $path, array $payload): array
    {
        return $this->call(
            'POST',
            self::API_BASE . $path,
            $token,
            Json::encode($payload)
        );
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
            $headers['Content-Type'] = 'application/json';
        }

        $response = $this->request($method, $url, $headers, $body);
        $decoded  = $this->decode($response);

        if (200 > $response->statusCode || 300 <= $response->statusCode || ! empty($decoded['error'])) {
            $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : array();

            $code = isset($error['code']) && is_numeric($error['code'])
                ? (string) (int) $error['code']
                : '';

            $message = $this->text($error['message'] ?? '');

            throw new ChatChannelApiException(
                '' !== $message
                    ? 'Messenger API error: ' . $message
                    : 'Messenger API returned an error.',
                'messenger_api_error',
                $response->statusCode,
                $code,
                $decoded
            );
        }

        return $decoded;
    }

    /**
     * The next cursor, from `paging.cursors.after`, when there is a next page.
     *
     * @param array<array-key, mixed> $payload A decoded list payload.
     */
    protected function nextCursor(array $payload): ?string
    {
        $paging = is_array($payload['paging'] ?? null) ? $payload['paging'] : array();

        if (! isset($paging['next'])) {
            return null;
        }

        $cursors = is_array($paging['cursors'] ?? null) ? $paging['cursors'] : array();
        $after   = $this->text($cursors['after'] ?? '');

        return '' !== $after ? $after : null;
    }

    /**
     * A string field from a Graph row, or '' when absent.
     */
    protected function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
