<?php

/**
 * The result of an authenticated provider liveness check.
 *
 * A probe answers one question — "would a request with the configured key be
 * accepted right now?" — without generating output, so it costs no completion
 * tokens. It is deliberately not derived from `listModels()`: that method is a
 * catalogue in some clients (no network at all) and swallows the HTTP status in
 * others, so a 401 would read as "reachable, zero models".
 *
 * `attempted` separates the two null-status outcomes: no safe endpoint exists,
 * versus an endpoint that was called and could not be reached.
 *
 * @package Nvoos\Core
 * @since   1.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\ValueObject;

final class ProviderConnectivity
{
    /**
     * @param bool   $attempted  Whether an HTTP request was made.
     * @param bool   $ok         True only when the provider answered 2xx.
     * @param int|null $status   HTTP status, when a response was received.
     * @param int    $modelCount Models observed in a successful response.
     * @param string $detail     One line, safe to render: never a URL with a
     *                           query string and never a credential.
     */
    private function __construct(
        public readonly bool $attempted,
        public readonly bool $ok,
        public readonly ?int $status,
        public readonly int $modelCount,
        public readonly string $detail,
    ) {
    }

    /**
     * This client exposes no endpoint that can prove a key.
     */
    public static function notSupported(string $detail): self
    {
        return new self(false, false, null, 0, $detail);
    }

    /**
     * The provider accepted the request.
     */
    public static function ok(int $status, int $modelCount, string $detail): self
    {
        return new self(true, true, $status, $modelCount, $detail);
    }

    /**
     * A request was attempted and failed. `$status` is null when no response
     * was received at all (DNS failure, timeout, connection refused).
     */
    public static function failed(?int $status, string $detail): self
    {
        return new self(true, false, $status, 0, $detail);
    }
}
