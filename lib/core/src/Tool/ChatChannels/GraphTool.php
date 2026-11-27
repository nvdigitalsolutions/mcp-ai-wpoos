<?php

/**
 * Shared ground for the Microsoft Graph tools.
 *
 * Proposal 164 section 164.104. Eight tools across three workloads (Teams,
 * Outlook, OneDrive) that share one bearer family and four behaviours:
 *
 * - **One error shape.** A refusal is `{"error": {"code": "Forbidden",
 *   "message": "..."}}` with a real 4xx/5xx status. `error.code` is a word, not
 *   a number, and it is reported as the provider code.
 * - **Ids are path segments.** A team id is a GUID, a channel id carries `:` and
 *   `@`, an item id is base32-ish, and a drive path is several segments. Each
 *   is validated to a shape and encoded before it can become part of a URL.
 *   WordPress passed `sanitize_text_field()` output straight into the path for
 *   teams and channels, which leaves `/` and `..` intact.
 * - **Pagination is a URL.** Graph returns `@odata.nextLink`, an absolute URL,
 *   and none of the WordPress tools followed it. The port returns it and
 *   accepts it back, but only after checking that it is https and points at
 *   Graph itself — the link is fetched with the caller's bearer token, so an
 *   unvalidated link would hand the token to whatever host it named.
 * - **Rate limits use a header.** A 429 carries `Retry-After` in seconds.
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
use Nvoos\Core\Support\Json;

abstract class GraphTool extends ChatChannelTool
{
    /**
     * The Graph v1.0 root.
     */
    private const API_BASE = 'https://graph.microsoft.com/v1.0';

    /**
     * The hosts a `next_link` may name.
     *
     * `graph.microsoft.us` is the GCC High endpoint; every other host is
     * refused because the link is requested with the caller's bearer token.
     *
     * @var list<string>
     */
    private const ALLOWED_HOSTS = array('graph.microsoft.com', 'graph.microsoft.us');

    /**
     * The access token, or the canonical refusal when it is absent.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    protected function token(array $arguments): mixed
    {
        $token = $this->credential($arguments, 'token');

        if ('' === $token) {
            return $this->errors->validationFailed(
                'A Microsoft Graph access token is required.',
                array('token' => array('A valid Microsoft Graph access token is required.'))
            );
        }

        return $token;
    }

    /**
     * A team id, which Graph defines as a GUID.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    protected function teamId(array $arguments): mixed
    {
        $value = $this->textArg($arguments, 'team_id');

        if (1 === preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value)) {
            return $value;
        }

        return $this->errors->validationFailed(
            'A team id must be a GUID.',
            array('team_id' => array('A team id must be a GUID.'))
        );
    }

    /**
     * A channel id, encoded as one path segment.
     *
     * Channel ids look like `19:abc123@thread.tacv2`: letters, digits, `:`, `.`,
     * `@`, `_` and `-`. Everything else is refused rather than encoded, so a
     * value with a slash cannot change the route.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    protected function channelId(array $arguments): mixed
    {
        $value = $this->textArg($arguments, 'channel_id');

        if (1 === preg_match('/^[A-Za-z0-9:._@-]{1,300}$/', $value)) {
            return rawurlencode($value);
        }

        return $this->errors->validationFailed(
            'A channel id is required and may contain only letters, digits, colon, dot, at, underscore and hyphen.',
            array('channel_id' => array('Invalid channel id.'))
        );
    }

    /**
     * A drive item id, encoded as one path segment.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    protected function itemId(array $arguments, string $key = 'item_id'): mixed
    {
        $value = $this->textArg($arguments, $key);

        if (1 === preg_match('/^[A-Za-z0-9!]{1,200}$/', $value)) {
            return rawurlencode($value);
        }

        return $this->errors->validationFailed(
            'A drive item id is required and may contain only letters, digits and exclamation marks.',
            array($key => array('Invalid item id.'))
        );
    }

    /**
     * A drive path, encoded segment by segment.
     *
     * A `.` or `..` segment is refused: percent-encoding does not change those
     * two, and a path that climbs would address a different drive resource than
     * the one the caller named.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    protected function drivePath(array $arguments, string $key, string $label): mixed
    {
        $value = $this->textArg($arguments, $key);

        if ('' === $value) {
            return $this->errors->validationFailed(
                sprintf('A %s is required.', $label),
                array($key => array('A path is required.'))
            );
        }

        $segments = explode('/', $value);
        $encoded  = array();

        foreach ($segments as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                return $this->errors->validationFailed(
                    sprintf('A %s may not contain empty, dot or dot-dot segments.', $label),
                    array($key => array('Invalid path segment.'))
                );
            }

            $encoded[] = rawurlencode($segment);
        }

        return implode('/', $encoded);
    }

    /**
     * The optional `next_link` argument, validated.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return string|mixed The link, '' when absent, or the validation error.
     */
    protected function linkArg(array $arguments): mixed
    {
        $value = $this->textArg($arguments, 'next_link');

        if ('' === $value || $this->isGraphLink($value)) {
            return $value;
        }

        return $this->errors->validationFailed(
            'A next_link must be an https Graph URL on graph.microsoft.com.',
            array('next_link' => array('The link is not a Graph URL.'))
        );
    }

    /**
     * One page: the `next_link` argument when given, otherwise the path.
     *
     * @param array<string, scalar> $query Query parameters.
     * @param array<string, mixed>  $arguments Tool arguments.
     *
     * @return array<array-key, mixed>|mixed The body, or the error envelope.
     */
    protected function page(string $token, string $path, array $query, array $arguments): mixed
    {
        $next = $this->linkArg($arguments);

        if (! is_string($next)) {
            return $next;
        }

        try {
            return '' !== $next
                ? $this->apiGetUrl($token, $next)
                : $this->apiGet($token, $path, $query);
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }
    }

    /**
     * The `@odata.nextLink` member, when it is one this family will follow.
     *
     * @param array<array-key, mixed> $payload A decoded list payload.
     */
    protected function nextLink(array $payload): ?string
    {
        $link = $payload['@odata.nextLink'] ?? null;

        return is_string($link) && $this->isGraphLink($link) ? $link : null;
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
     * GET an absolute link that has already passed the host check.
     *
     * @return array<array-key, mixed>
     *
     * @throws ChatChannelApiException
     */
    protected function apiGetUrl(string $token, string $url): array
    {
        if (! $this->isGraphLink($url)) {
            throw new ChatChannelApiException(
                'The next_link is not an https Graph URL.',
                'graph_invalid_link'
            );
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
     * PUT raw bytes to one endpoint.
     *
     * The OneDrive simple upload takes the file itself, not a JSON document, so
     * the body is the decoded content and the content type is the file's.
     *
     * @return array<array-key, mixed>
     *
     * @throws ChatChannelApiException
     */
    protected function apiPut(string $token, string $path, string $body, string $contentType): array
    {
        $response = $this->request(
            'PUT',
            self::API_BASE . $path,
            array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => $contentType,
            ),
            $body
        );

        return $this->finish($response);
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

        return $this->finish($this->request($method, $url, $headers, $body));
    }

    /**
     * Turn a response into a body or a classified exception.
     *
     * @return array<array-key, mixed>
     *
     * @throws ChatChannelApiException
     */
    private function finish(HttpResponse $response): array
    {
        $decoded = $this->decode($response);

        if (200 > $response->statusCode || 300 <= $response->statusCode) {
            $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : array();

            $code = isset($error['code']) && is_scalar($error['code']) ? trim((string) $error['code']) : '';
            $text = $this->text($error['message'] ?? '');

            throw new ChatChannelApiException(
                '' !== $text ? 'Microsoft Graph error: ' . $text : 'Microsoft Graph returned an error.',
                'graph_api_error',
                $response->statusCode,
                $code,
                $decoded,
                $this->retryAfter($response)
            );
        }

        return $decoded;
    }

    /**
     * The `Retry-After` header, in whole seconds.
     */
    private function retryAfter(HttpResponse $response): int
    {
        foreach ($response->headers as $name => $value) {
            if (0 !== strcasecmp((string) $name, 'retry-after')) {
                continue;
            }

            return is_numeric($value) ? max(0, (int) ceil((float) $value)) : 0;
        }

        return 0;
    }

    /**
     * Whether a URL is an https Graph URL this family will request.
     */
    private function isGraphLink(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower((string) ($parts['host'] ?? ''));
        $path   = (string) ($parts['path'] ?? '');

        if ('https' !== $scheme || ! in_array($host, self::ALLOWED_HOSTS, true)) {
            return false;
        }

        return '' === $path || '/v1.0' === $path || 0 === strpos($path, '/v1.0/');
    }

    /**
     * A Teams channel, projected.
     *
     * @param mixed $channel One channel object.
     *
     * @return array<string, mixed>
     */
    protected function channelRow(mixed $channel): array
    {
        if (! is_array($channel)) {
            return array();
        }

        return array(
            'id'              => $this->text($channel['id'] ?? ''),
            'display_name'    => $this->text($channel['displayName'] ?? ''),
            'description'     => $this->text($channel['description'] ?? ''),
            'membership_type' => $this->text($channel['membershipType'] ?? ''),
            'web_url'         => $this->text($channel['webUrl'] ?? ''),
        );
    }

    /**
     * A Teams channel message, projected.
     *
     * @param mixed $message One chatMessage object.
     *
     * @return array<string, mixed>
     */
    protected function chatMessageRow(mixed $message): array
    {
        if (! is_array($message)) {
            return array();
        }

        $body    = is_array($message['body'] ?? null) ? $message['body'] : array();
        $from    = is_array($message['from'] ?? null) ? $message['from'] : array();
        $user    = is_array($from['user'] ?? null) ? $from['user'] : array();
        $app     = is_array($from['application'] ?? null) ? $from['application'] : array();

        $sender = '';

        if ('' !== $this->text($user['displayName'] ?? '')) {
            $sender = $this->text($user['displayName']);
        } elseif ('' !== $this->text($app['displayName'] ?? '')) {
            $sender = $this->text($app['displayName']);
        }

        return array(
            'id'                 => $this->text($message['id'] ?? ''),
            'reply_to_id'        => $this->text($message['replyToId'] ?? ''),
            'message_type'       => $this->text($message['messageType'] ?? ''),
            'subject'            => $this->text($message['subject'] ?? ''),
            'content'            => $this->text($body['content'] ?? ''),
            'content_type'       => $this->text($body['contentType'] ?? ''),
            'from'               => $sender,
            'created_date_time'  => $this->text($message['createdDateTime'] ?? ''),
            'web_url'            => $this->text($message['webUrl'] ?? ''),
            'deleted_date_time'  => $this->text($message['deletedDateTime'] ?? ''),
        );
    }

    /**
     * An Outlook message, projected.
     *
     * @param mixed $message One message object.
     *
     * @return array<string, mixed>
     */
    protected function mailRow(mixed $message): array
    {
        if (! is_array($message)) {
            return array();
        }

        $from    = is_array($message['from'] ?? null) ? $message['from'] : array();
        $address = is_array($from['emailAddress'] ?? null) ? $from['emailAddress'] : array();

        return array(
            'id'                     => $this->text($message['id'] ?? ''),
            'subject'                => $this->text($message['subject'] ?? ''),
            'from_address'           => $this->text($address['address'] ?? ''),
            'from_name'              => $this->text($address['name'] ?? ''),
            'received_date_time'     => $this->text($message['receivedDateTime'] ?? ''),
            'body_preview'           => $this->text($message['bodyPreview'] ?? ''),
            'is_read'                => ! empty($message['isRead']),
            'has_attachments'        => ! empty($message['hasAttachments']),
            'web_link'               => $this->text($message['webLink'] ?? ''),
        );
    }

    /**
     * A OneDrive item, projected.
     *
     * @param mixed $item One driveItem object.
     *
     * @return array<string, mixed>
     */
    protected function driveItemRow(mixed $item): array
    {
        if (! is_array($item)) {
            return array();
        }

        $folder = is_array($item['folder'] ?? null) ? $item['folder'] : null;
        $file   = is_array($item['file'] ?? null) ? $item['file'] : array();

        return array(
            'id'                      => $this->text($item['id'] ?? ''),
            'name'                    => $this->text($item['name'] ?? ''),
            'size'                    => is_numeric($item['size'] ?? null) ? (int) $item['size'] : 0,
            'is_folder'               => null !== $folder,
            'child_count'             => null !== $folder && is_numeric($folder['childCount'] ?? null)
                ? (int) $folder['childCount']
                : null,
            'mime_type'               => $this->text($file['mimeType'] ?? ''),
            'web_url'                 => $this->text($item['webUrl'] ?? ''),
            'last_modified_date_time' => $this->text($item['lastModifiedDateTime'] ?? ''),
        );
    }

    /**
     * A string field from a Graph row, or '' when absent.
     */
    protected function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
