<?php

/**
 * Shared ground for the Telegram Bot API tools.
 *
 * Proposal 164 section 164.102. Telegram differs from Slack and Discord in
 * three ways the five tools share:
 *
 * - **The token is a path segment.** The route is
 *   `/bot{token}/{method}`, so the token is encoded once and never logged. A
 *   malformed value cannot escape the segment because `/` is percent-encoded.
 * - **`ok` is the authority, with a real status behind it.** A refusal is
 *   `{"ok": false, "error_code": 400, "description": "..."}`, usually with a
 *   4xx status that carries the same number. The provider code is kept as
 *   `error_code`.
 * - **Rate limits are structured.** A 429 carries
 *   `parameters.retry_after` in seconds, which the family envelope surfaces as
 *   `retry_after`.
 *
 * The `Content-Type` header is sent on writes only: Telegram rejects a POST
 * body with no content type (`missing_post_type`), and a GET needs none.
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

abstract class TelegramTool extends ChatChannelTool
{
    /**
     * `getUpdates` accepts 1-100.
     */
    protected const MAX_LIMIT = 100;

    /**
     * The page size `getUpdates` uses when none is given.
     */
    protected const DEFAULT_LIMIT = 100;

    /**
     * `sendMessage` rejects text longer than this many characters.
     */
    protected const MAX_MESSAGE_LENGTH = 4096;

    /**
     * The token argument, or the canonical refusal when it is absent.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    protected function token(array $arguments): mixed
    {
        $token = $this->credential($arguments, 'token');

        if ('' === $token) {
            return $this->errors->validationFailed(
                'A Telegram bot token is required.',
                array('token' => array('A valid Telegram bot token is required.'))
            );
        }

        return $token;
    }

    /**
     * GET one Bot API method.
     *
     * @param array<string, scalar> $query Query parameters.
     *
     * @return array<array-key, mixed> The decoded `ok: true` payload.
     *
     * @throws ChatChannelApiException On a transport failure or a refusal.
     */
    protected function apiGet(string $token, string $method, array $query = array()): array
    {
        return $this->call('GET', $this->endpoint($token, $method, $query), null);
    }

    /**
     * POST one Bot API method as JSON.
     *
     * An empty payload is sent as no body at all: the parameterless methods
     * (`deleteWebhook`, `getMyCommands` with the default scope) take none, and
     * `[]` is a JSON array where Telegram expects an object or nothing.
     *
     * @param array<string, mixed> $payload JSON body.
     *
     * @return array<array-key, mixed> The decoded `ok: true` payload.
     *
     * @throws ChatChannelApiException On a transport failure or a refusal.
     */
    protected function apiPost(string $token, string $method, array $payload): array
    {
        return $this->call(
            'POST',
            $this->endpoint($token, $method),
            array() === $payload ? null : Json::encode($payload)
        );
    }

    /**
     * The `result` member of a successful payload.
     *
     * @param array<array-key, mixed> $payload A decoded `ok: true` payload.
     */
    protected function result(array $payload): mixed
    {
        return $payload['result'] ?? null;
    }

    /**
     * The route, with the token as exactly one encoded segment.
     *
     * @param array<string, scalar> $query Query parameters.
     */
    private function endpoint(string $token, string $method, array $query = array()): string
    {
        $url = 'https://api.telegram.org/bot' . rawurlencode($token) . '/' . $method;

        if (array() !== $query) {
            $url .= '?' . \http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    /**
     * One request and its classification.
     *
     * @return array<array-key, mixed>
     *
     * @throws ChatChannelApiException
     */
    private function call(string $method, string $url, ?string $body): array
    {
        $headers = null === $body ? array() : array('Content-Type' => 'application/json');

        $response = $this->request($method, $url, $headers, $body);

        $decoded = $this->decode($response);

        if (200 !== $response->statusCode || empty($decoded['ok'])) {
            $provider = isset($decoded['error_code']) && is_numeric($decoded['error_code'])
                ? (string) (int) $decoded['error_code']
                : '';

            $description = $this->text($decoded['description'] ?? '');
            $parameters  = is_array($decoded['parameters'] ?? null) ? $decoded['parameters'] : array();

            $retry = isset($parameters['retry_after']) && is_numeric($parameters['retry_after'])
                ? (int) ceil((float) $parameters['retry_after'])
                : 0;

            throw new ChatChannelApiException(
                '' !== $description
                    ? 'Telegram API error: ' . $description
                    : 'Telegram API returned an error.',
                'telegram_api_error',
                $response->statusCode,
                $provider,
                $decoded,
                max(0, $retry)
            );
        }

        return $decoded;
    }

    /**
     * A string field from a Telegram row, or '' when absent.
     */
    protected function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * A chat id, which Telegram sends as a number and accepts as a string.
     */
    protected function chatId(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
