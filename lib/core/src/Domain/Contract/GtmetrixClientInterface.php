<?php

/**
 * GTmetrix REST API v2.0 transport contract.
 *
 * Deliberately thin: one verb over a path, with authentication, JSON:API
 * unwrapping and error classification behind it. The WordPress client has a
 * method per endpoint; keeping the endpoint knowledge in the tools is what keeps
 * the port mechanical, and it avoids a second, drifting copy of the API surface.
 *
 * The response shape is an array rather than a decoded `data` block because the
 * WordPress client dropped the JSON:API `meta` block, which is where GTmetrix
 * reports `credits_left` and `credits_used` after a test. A transport that
 * cannot answer "what did the server say about credits" makes that figure
 * unportable.
 *
 * A `303 See Other` is returned, not followed: `GET /tests/{id}` answers it with
 * `Location: /reports/{id}` once a test completes, and a client that follows the
 * redirect hands the caller a report where it expected a test.
 *
 * `document` is the decoded body as it arrived. Most endpoints wrap their
 * resource in `data`, but a report resource answers a bare object; returning
 * both shapes lets a tool read either without a second verb per resource.
 *
 * Implementations throw {@see \Nvoos\Core\Domain\Error\GtmetrixApiException}
 * rather than returning an error value, so a tool cannot forget to check.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface GtmetrixClientInterface
{
    /**
     * Whether an API key is configured on this host.
     *
     * A configured key is not a verified key: the first request is what verifies
     * it, and this method exists so a tool can refuse with "not configured"
     * before attempting one.
     */
    public function isConfigured(): bool;

    /**
     * Send one request.
     *
     * @param string                    $method GET, POST or DELETE.
     * @param string                    $path   Path relative to the v2 base, e.g. `tests` or `reports/{id}`.
     * @param array<string, mixed>|null $body   JSON:API body for a write, null for a read.
     *
     * @return array{
     *     status: int,
     *     data: mixed,
     *     meta: array<string, mixed>,
     *     links: array<string, mixed>,
     *     location: string,
     *     rate_limit: array{limit: int, remaining: int|null, reset: int|null},
     *     document: array<string, mixed>
     * }
     */
    public function request(string $method, string $path, ?array $body = null): array;
}
