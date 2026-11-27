<?php

/**
 * Shared ground for the Discord REST tools.
 *
 * Proposal 164 section 164.101. Discord differs from Slack in three ways that
 * the five portable tools share:
 *
 * - **The status is real.** A refusal carries a 4xx/5xx status and a
 *   `{code, message}` body, so the classifier keys on the status and keeps
 *   Discord's numeric code as the provider code.
 * - **Ids are path segments.** Channel, guild and message ids are interpolated
 *   into the URL, and WordPress passed them through `sanitize_text_field()`,
 *   which leaves `/` and `..` intact. A snowflake is validated here before it
 *   can become a path segment.
 * - **Rate limits are a documented response.** A 429 carries `retry_after` in
 *   seconds and in the `Retry-After` header; the value is surfaced so a caller
 *   can wait rather than retry blind.
 *
 * Success is any 2xx. WordPress required exactly 200, 201 or 204 per tool,
 * which is the same answer for every conforming response and turns a valid but
 * differently-numbered success into an error.
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

abstract class DiscordTool extends ChatChannelTool
{
    /**
     * The API root. Discord versions the REST surface in the path.
     */
    private const API_BASE = 'https://discord.com/api/v10';

    /**
     * `GET /channels/{id}/messages` accepts 1-100.
     */
    protected const MAX_LIMIT = 100;

    /**
     * The default page size the WordPress tool used.
     */
    protected const DEFAULT_LIMIT = 50;

    /**
     * The channel types `create_discord_channel` offers.
     *
     * @var list<int>
     */
    protected const CHANNEL_TYPES = array(0, 2, 4, 5);

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
                'A Discord bot token is required.',
                array('token' => array('A valid Discord bot token is required.'))
            );
        }

        return $token;
    }

    /**
     * A numeric id that is safe to interpolate into a path segment.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     * @param string               $label     Human-readable name, e.g. `channel id`.
     */
    protected function snowflake(array $arguments, string $key, string $label): mixed
    {
        $value = $this->textArg($arguments, $key);

        if (1 === preg_match('/^[0-9]{1,20}$/', $value)) {
            return $value;
        }

        return $this->errors->validationFailed(
            sprintf('A valid %s is required.', $label),
            array($key => array(sprintf('A %s must be a numeric Discord id.', $label)))
        );
    }

    /**
     * GET one endpoint.
     *
     * @param array<string, scalar> $query Query parameters.
     *
     * @return array<array-key, mixed> The decoded body, empty for a 204.
     *
     * @throws ChatChannelApiException On a transport failure or a refusal.
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
     * PUT one endpoint with no body, for the reaction route.
     *
     * @return array<array-key, mixed>
     *
     * @throws ChatChannelApiException
     */
    protected function apiPut(string $token, string $path): array
    {
        return $this->call('PUT', self::API_BASE . $path, $token, null);
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
        $response = $this->request(
            $method,
            $url,
            array(
                'Authorization' => 'Bot ' . $token,
                'Content-Type'  => 'application/json',
            ),
            $body
        );

        $decoded = $this->decode($response);

        if (200 > $response->statusCode || 300 <= $response->statusCode) {
            $provider = isset($decoded['code']) && is_numeric($decoded['code'])
                ? (string) (int) $decoded['code']
                : '';

            $message = $this->text($decoded['message'] ?? '');

            $retry = is_numeric($decoded['retry_after'] ?? null)
                ? (int) ceil((float) $decoded['retry_after'])
                : 0;

            throw new ChatChannelApiException(
                $this->friendly($response->statusCode, $provider, $message),
                'discord_api_error',
                $response->statusCode,
                $provider,
                $decoded,
                max(0, $retry)
            );
        }

        return $decoded;
    }

    /**
     * The refusal, in words a caller can act on.
     */
    private function friendly(int $status, string $providerCode, string $message): string
    {
        $known = array(
            '10003' => 'unknown channel — check the channel id.',
            '10004' => 'unknown guild — check the guild id.',
            '10008' => 'unknown message — check the message id.',
            '50001' => 'the bot cannot access that resource.',
            '50013' => 'the bot lacks the permission this call needs.',
            '50035' => 'Discord rejected the request body as invalid.',
        );

        if (isset($known[$providerCode])) {
            return 'Discord API error: ' . $known[$providerCode];
        }

        if (401 === $status) {
            return 'Discord API error: the bot token was rejected.';
        }

        if (429 === $status) {
            return 'Discord API error: the request was rate limited; retry after the retry_after interval.';
        }

        return '' !== $message
            ? 'Discord API error: ' . $message
            : 'Discord API returned an error.';
    }

    /**
     * A channel, projected to the documented fields.
     *
     * @param mixed $channel One guild channel object.
     *
     * @return array<string, mixed>
     */
    protected function channelRow(mixed $channel): array
    {
        if (! is_array($channel)) {
            return array();
        }

        $parent = $this->text($channel['parent_id'] ?? '');

        return array(
            'id'        => $this->text($channel['id'] ?? ''),
            'name'      => $this->text($channel['name'] ?? ''),
            'type'      => is_numeric($channel['type'] ?? null) ? (int) $channel['type'] : 0,
            'position'  => is_numeric($channel['position'] ?? null) ? (int) $channel['position'] : 0,
            'parent_id' => '' !== $parent ? $parent : null,
        );
    }

    /**
     * A message, projected to the documented fields.
     *
     * @param mixed $message One message object.
     *
     * @return array<string, mixed>
     */
    protected function messageRow(mixed $message): array
    {
        if (! is_array($message)) {
            return array();
        }

        $author  = is_array($message['author'] ?? null) ? $message['author'] : array();
        $edited  = $this->text($message['edited_timestamp'] ?? '');

        return array(
            'id'               => $this->text($message['id'] ?? ''),
            'channel_id'       => $this->text($message['channel_id'] ?? ''),
            'author_id'        => $this->text($author['id'] ?? ''),
            'author_username'  => $this->text($author['username'] ?? ''),
            'content'          => $this->text($message['content'] ?? ''),
            'timestamp'        => $this->text($message['timestamp'] ?? ''),
            'edited_timestamp' => '' !== $edited ? $edited : null,
        );
    }

    /**
     * A string field from a Discord row, or '' when absent.
     */
    protected function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
