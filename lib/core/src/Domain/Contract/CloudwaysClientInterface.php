<?php

/**
 * Cloudways API v2 transport contract.
 *
 * Deliberately thin: four verbs over a path, with authentication and error
 * mapping handled behind them. The WordPress toolkit's sixty tools each know
 * their own endpoint, and keeping that knowledge in the tool is what makes the
 * port mechanical — a client method per endpoint would be a second, drifting
 * copy of the API surface.
 *
 * Implementations throw {@see \Nvoos\Core\Domain\Error\CloudwaysApiException}
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

interface CloudwaysClientInterface
{
    /**
     * Whether an email and API key are configured on this host.
     *
     * A configured pair is not a verified pair: the first request is what
     * verifies it, and this method exists so a tool can refuse with
     * "not configured" before attempting one.
     */
    public function isConfigured(): bool;

    /**
     * @param string                $path  API path relative to the v2 base, e.g. '/server'.
     * @param array<string, scalar> $query Query parameters.
     * @return array<string, mixed> Decoded JSON body.
     */
    public function get(string $path, array $query = array()): array;

    /**
     * @param string               $path API path relative to the v2 base.
     * @param array<string, mixed> $body JSON body.
     * @return array<string, mixed> Decoded JSON body.
     */
    public function post(string $path, array $body = array()): array;

    /**
     * @param string               $path API path relative to the v2 base.
     * @param array<string, mixed> $body JSON body.
     * @return array<string, mixed> Decoded JSON body.
     */
    public function put(string $path, array $body = array()): array;

    /**
     * @param string               $path API path relative to the v2 base.
     * @param array<string, mixed> $body JSON body, because several v2 deletes take one.
     * @return array<string, mixed> Decoded JSON body.
     */
    public function delete(string $path, array $body = array()): array;
}
