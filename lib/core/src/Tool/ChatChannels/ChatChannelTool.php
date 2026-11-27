<?php

/**
 * Shared ground for the chat-channel tools.
 *
 * Proposal 164 section 164.100. The family has fifty tools across ten provider
 * APIs, and four things are the same in every one of them:
 *
 * - **The capability.** Every WordPress tool declared `edit_posts` and then
 *   enforced `manage_options` behind a per-slug filter. The port declares what
 *   it enforces, once, here.
 * - **The credential is an argument.** No provider credential lives in a store
 *   in this family; the caller passes it. The schemas mark those properties
 *   `writeOnly` so a UI or a logger can redact them.
 * - **One request, one envelope.** A provider refusal and a transport failure
 *   both become the canonical error through `failure()`; a success is always
 *   `success($message, $data)` with a projected payload, never the provider
 *   document verbatim.
 * - **The flags.** A credentialed external call; reads say so, writes add
 *   `state-changing`.
 *
 * A transport failure is normalised here too: `HttpClientInterface::send()`
 * throws when no response arrives, and a caller should not have to tell a DNS
 * failure from a provider refusal to build the same envelope.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Tool\AbstractTool;

abstract class ChatChannelTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly HttpClientInterface $http,
    ) {
        parent::__construct($e);
    }

    /**
     * What the WordPress tools enforced, not what they declared.
     */
    public function getRequiredCapability(): string
    {
        return 'manage_options';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array(
            'external-api',
            'network-dependent',
            'requires-credentials',
            'read-only',
        );
    }

    /**
     * A provider refusal, as the canonical error envelope.
     */
    protected function failure(ChatChannelApiException $error): mixed
    {
        $data = array();

        if ($error->statusCode > 0) {
            $data['status'] = $error->statusCode;
        }

        if ('' !== $error->providerCode) {
            $data['code'] = $error->providerCode;
        }

        if ($error->retryAfter > 0) {
            $data['retry_after'] = $error->retryAfter;
        }

        return $this->errors->create($error->errorCode, $error->getMessage(), $data);
    }

    /**
     * One outbound request, with a transport failure normalised.
     *
     * @param array<string, string> $headers Request headers.
     *
     * @throws ChatChannelApiException When the request could not be sent.
     */
    protected function request(string $method, string $url, array $headers, ?string $body = null): HttpResponse
    {
        try {
            return $this->http->send($method, $url, $headers, $body);
        } catch (\RuntimeException $error) {
            throw new ChatChannelApiException(
                'The chat-channel API request failed to send. ' . $error->getMessage(),
                'chat_channel_request_failed'
            );
        }
    }

    /**
     * A credential argument, trimmed, or ''.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    protected function credential(array $arguments, string $key = 'token'): string
    {
        return array_key_exists($key, $arguments) && is_scalar($arguments[$key])
            ? trim((string) $arguments[$key])
            : '';
    }

    /**
     * A scalar argument, trimmed, or ''.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    protected function textArg(array $arguments, string $key): string
    {
        return array_key_exists($key, $arguments) && is_scalar($arguments[$key])
            ? trim((string) $arguments[$key])
            : '';
    }

    /**
     * A decoded JSON body, or an empty array when the body is empty or not an
     * array. A provider that answers 204 No Content has said everything it has
     * to say.
     *
     * The key type is `array-key`, not `string`: some endpoints answer with a
     * list (Discord's channel and message collections) and some with an object.
     *
     * @return array<array-key, mixed>
     */
    protected function decode(HttpResponse $response): array
    {
        $body = trim($response->body);

        if ('' === $body) {
            return array();
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * The one sensible read limit: a positive integer, clamped to a ceiling.
     *
     * @param mixed $value Raw argument.
     */
    protected function limit(mixed $value, int $default, int $max): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return max(1, min($max, (int) $value));
    }
}
