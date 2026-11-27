<?php

/**
 * Shared ground for the four Slack Web API tools.
 *
 * Proposal 164 section 164.100. Slack has three habits that the whole family
 * shares and nothing else does, so they live here rather than in each tool:
 *
 * - **Errors arrive as HTTP 200.** A refusal is `{"ok": false, "error": ...}`
 *   with a 200 status, so the status alone says nothing. `apiGet()` and
 *   `apiPost()` classify on `ok` first and keep the HTTP status only as
 *   diagnostics.
 * - **Pagination is a cursor.** Slack returns `response_metadata.next_cursor`,
 *   empty on the last page; the tools project that as `next_cursor` and accept
 *   it back.
 * - **A page is at most 999 items.** The docs say "under 1000" for both list
 *   methods; the WordPress tools declared a 1-1000 range and silently dropped
 *   anything outside it, so a caller asking for 1000 had the limit discarded.
 *   The port clamps to 999 and always sends the effective value, so the
 *   request and the reply agree.
 *
 * The friendly error map is the one the WordPress tools carried: five codes
 * whose remedy a caller cannot guess from the code alone.
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

abstract class SlackTool extends ChatChannelTool
{
    /**
     * Slack's documented ceiling: a page size must be under 1000.
     */
    protected const MAX_LIMIT = 999;

    /**
     * The default page size for both list methods.
     */
    protected const DEFAULT_LIMIT = 100;

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
                'A Slack bot or user token is required.',
                array('token' => array('A valid Slack token is required.'))
            );
        }

        return $token;
    }

    /**
     * GET one Web API method.
     *
     * @param array<string, scalar> $query Query parameters.
     *
     * @return array<array-key, mixed> The decoded `ok: true` payload.
     *
     * @throws ChatChannelApiException On a transport failure or a refusal.
     */
    protected function apiGet(string $token, string $method, array $query = array()): array
    {
        $url = 'https://slack.com/api/' . $method;

        if (array() !== $query) {
            $url .= '?' . \http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $this->call('GET', $url, $token, null);
    }

    /**
     * POST one Web API method as JSON.
     *
     * Slack accepts JSON for its write methods and the WordPress tools sent it,
     * so the body keeps that shape; the encode happens before the request so an
     * encoding failure is not mistaken for a transport failure.
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
            'https://slack.com/api/' . $method,
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
    private function call(string $httpMethod, string $url, string $token, ?string $body): array
    {
        $response = $this->request(
            $httpMethod,
            $url,
            array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json; charset=utf-8',
            ),
            $body
        );

        $decoded = $this->decode($response);

        if (200 !== $response->statusCode || empty($decoded['ok'])) {
            $code = isset($decoded['error']) && is_scalar($decoded['error'])
                ? (string) $decoded['error']
                : '';

            throw new ChatChannelApiException(
                $this->friendly($code),
                'slack_api_error',
                $response->statusCode,
                $code,
                $decoded
            );
        }

        return $decoded;
    }

    /**
     * Slack's own code, in the words a person can act on.
     */
    private function friendly(string $code): string
    {
        $known = array(
            'account_inactive' => 'Slack API error: account_inactive — the bot account behind this token has been deactivated. Check that the app is still installed in the workspace and generate a new bot token at api.slack.com/apps.',
            'invalid_auth'     => 'Slack API error: invalid_auth — the token is invalid or has been revoked. Generate a new token from your Slack app.',
            'token_revoked'    => 'Slack API error: token_revoked — this token has been revoked. Reinstall the Slack app in the workspace.',
            'not_authed'       => 'Slack API error: not_authed — no token was provided. Pass a valid bot token (xoxb-).',
            'missing_scope'    => 'Slack API error: missing_scope — the token lacks the OAuth scopes this call needs. Update the app\'s permissions and reinstall it.',
        );

        if (isset($known[$code])) {
            return $known[$code];
        }

        return '' !== $code ? 'Slack API error: ' . $code : 'Slack API returned an error.';
    }

    /**
     * A channel row, projected to the documented fields.
     *
     * `num_members` is null when Slack omits it — a direct message has no
     * member count, and `conversations.create` does not return one — rather
     * than reporting zero members for a channel the caller just joined.
     *
     * @param mixed $channel One entry from `channels`.
     *
     * @return array<string, mixed>
     */
    protected function channelRow(mixed $channel): array
    {
        if (! is_array($channel)) {
            return array();
        }

        $name = $this->text($channel['name'] ?? '');

        if ('' === $name) {
            $name = $this->text($channel['name_normalized'] ?? '');
        }

        return array(
            'id'          => $this->text($channel['id'] ?? ''),
            'name'        => $name,
            'is_private'  => (bool) ($channel['is_private'] ?? false),
            'is_archived' => (bool) ($channel['is_archived'] ?? false),
            'num_members' => is_numeric($channel['num_members'] ?? null)
                ? (int) $channel['num_members']
                : null,
        );
    }

    /**
     * The next page's cursor, or null on the last page.
     *
     * @param array<array-key, mixed> $payload A decoded list payload.
     */
    protected function nextCursor(array $payload): ?string
    {
        $metadata = $payload['response_metadata'] ?? null;
        $cursor   = is_array($metadata) ? ($metadata['next_cursor'] ?? null) : null;

        return is_scalar($cursor) && '' !== trim((string) $cursor)
            ? (string) $cursor
            : null;
    }

    /**
     * A string field from a Slack row, or '' when absent.
     */
    protected function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
