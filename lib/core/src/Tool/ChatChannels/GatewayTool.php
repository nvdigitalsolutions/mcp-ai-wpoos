<?php

/**
 * Shared ground for the two caller-supplied gateway families.
 *
 * Proposal 164 section 164.108. Seven of the fifty chat-channel tools do not
 * talk to a first-party API at all:
 *
 * - **Apple Messages for Business** is reached only through an approved
 *   Messaging Service Provider (MSP). Apple publishes the MSP REST API that a
 *   provider *implements*; the business calls its provider's endpoint, and the
 *   provider owns the path, the auth and the exact envelope.
 * - **iCloud Drive** has no public third-party REST API. Apple exposes CloudKit
 *   to app developers, not a drive API to a server; reaching a user's iCloud
 *   Drive needs a bridge the host runs.
 *
 * So both families take an **endpoint and a bearer token from the caller** and
 * post JSON to it. That makes them the most SSRF-sensitive tools in the family
 * after the Google Chat webhook: the credential travels to whatever host the
 * caller names. `publicUrl()` is the shared guard, and the endpoint is never
 * echoed, because a gateway URL may carry a key in its query string.
 *
 * ## Why the reply is not projected the way the provider tools are
 *
 * Everywhere else in this family a success returns a named projection, because
 * the provider's schema is published and the set of fields worth keeping is
 * knowable. Here it is not: the response schema belongs to the caller's
 * gateway, and this tool cannot know which field a caller needs. Projecting to a
 * guessed field set would silently drop fields the gateway defines. The reply
 * therefore carries the decoded document under `response`, plus `items` when one
 * of the conventional collection keys is present, plus the inputs this tool
 * normalised.
 *
 * ## One classifier for both families
 *
 * The two gateways are different products with the same error convention:
 * a non-2xx body carrying one of `message`, `error`, `errorMessage` or
 * `detail`. That is what the WordPress tools read, in that order, and it is
 * what is read here — plus a nested `error.message`, which the flat loop
 * missed.
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

abstract class GatewayTool extends ChatChannelTool
{
    /**
     * The collection keys a gateway uses for a list result.
     *
     * @var list<string>
     */
    private const COLLECTION_KEYS = array('data', 'items', 'results', 'messages', 'conversations', 'files');

    /**
     * The caller-supplied endpoint, restricted to public https.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     * @param string               $key       Argument name.
     * @param string               $label     Human-readable name.
     *
     * @return string|mixed
     */
    protected function gatewayUrl(array $arguments, string $key, string $label): mixed
    {
        return $this->publicUrl($arguments, $key, $label);
    }

    /**
     * The gateway bearer token, or the canonical refusal.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return string|mixed
     */
    protected function gatewayKey(array $arguments): mixed
    {
        $key = $this->credential($arguments, 'api_key');

        if ('' === $key) {
            return $this->errors->validationFailed(
                'A gateway API key or bearer token is required.',
                array('api_key' => array('Required.'))
            );
        }

        return $key;
    }

    /**
     * One GET, classified.
     *
     * @return array<array-key, mixed>
     *
     * @throws ChatChannelApiException
     */
    protected function gatewayGet(string $url, string $token): array
    {
        return $this->finish(
            $this->request('GET', $url, $this->headers($token, false), null)
        );
    }

    /**
     * One POST, classified.
     *
     * @return array<array-key, mixed>
     *
     * @throws ChatChannelApiException
     */
    protected function gatewayPost(string $url, string $token, string $body): array
    {
        return $this->finish(
            $this->request('POST', $url, $this->headers($token, true), $body)
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $token, bool $json): array
    {
        $headers = array(
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        );

        if ($json) {
            $headers['Content-Type'] = 'application/json';
        }

        return $headers;
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
            throw new ChatChannelApiException(
                $this->message($decoded, $response->statusCode),
                'gateway_api_error',
                $response->statusCode,
                $this->providerCode($decoded),
                $decoded,
                $this->retryAfter($response)
            );
        }

        return $decoded;
    }

    /**
     * The message a gateway body carries, in the order the WordPress tools read
     * it, with the nested `error.message` shape added.
     *
     * @param array<array-key, mixed> $decoded Decoded body.
     */
    private function message(array $decoded, int $status): string
    {
        foreach (array('message', 'errorMessage', 'detail') as $key) {
            $value = $this->stringValue($decoded[$key] ?? null);

            if ('' !== $value) {
                return 'The gateway returned HTTP ' . $status . ': ' . $value;
            }
        }

        $error = $decoded['error'] ?? null;

        if (is_array($error)) {
            $value = $this->stringValue($error['message'] ?? null);

            if ('' !== $value) {
                return 'The gateway returned HTTP ' . $status . ': ' . $value;
            }
        }

        $value = $this->stringValue($error);

        if ('' !== $value) {
            return 'The gateway returned HTTP ' . $status . ': ' . $value;
        }

        return 'The gateway returned HTTP ' . $status . '.';
    }

    /**
     * The provider's own error code, when it gives one.
     *
     * @param array<array-key, mixed> $decoded Decoded body.
     */
    private function providerCode(array $decoded): string
    {
        foreach (array('code', 'errorCode', 'status') as $key) {
            if (isset($decoded[$key]) && is_scalar($decoded[$key]) && '' !== trim((string) $decoded[$key])) {
                return trim((string) $decoded[$key]);
            }
        }

        $error = $decoded['error'] ?? null;

        if (is_array($error) && isset($error['code']) && is_scalar($error['code'])) {
            return trim((string) $error['code']);
        }

        return '';
    }

    /**
     * A `Retry-After` delay, in whole seconds.
     */
    private function retryAfter(HttpResponse $response): int
    {
        foreach ($response->headers as $name => $value) {
            if (0 === strcasecmp((string) $name, 'retry-after')) {
                return is_numeric($value) ? max(0, (int) ceil((float) $value)) : 0;
            }
        }

        return 0;
    }

    /**
     * The first conventional collection in a gateway document.
     *
     * @param array<array-key, mixed> $payload Decoded body.
     *
     * @return list<array<array-key, mixed>>
     */
    protected function items(array $payload): array
    {
        foreach (self::COLLECTION_KEYS as $key) {
            if (! is_array($payload[$key] ?? null)) {
                continue;
            }

            $rows = array();

            foreach ($payload[$key] as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            return $rows;
        }

        return array();
    }

    /**
     * A string field, trimmed, or ''.
     */
    protected function stringValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
