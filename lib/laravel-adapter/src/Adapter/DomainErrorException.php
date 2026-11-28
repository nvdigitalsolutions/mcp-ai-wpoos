<?php

/**
 * A domain error that keeps its code and payload.
 *
 * `ErrorFactory::create()` used to return a bare `RuntimeException`, so the
 * domain code (`wp_mcp_ai_db_scope_table_not_allowed` and friends) and the
 * `status` / `refusal_code` context the caller supplied were both discarded on
 * the floor. Every tool that documents "the same code and status as the
 * WordPress host" was therefore only half true on this host: the message
 * matched, the code did not.
 *
 * This subclass is still a `RuntimeException` with the HTTP status in
 * `getCode()`, so existing callers are unaffected. The extras exist for the
 * callers that *can* tell the difference — `normalize()` reports the domain code
 * instead of a numeric one, and the database tools' `refusal_code` survives.
 *
 * @package Nvoos\Laravel
 * @since   1.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

final class DomainErrorException extends \RuntimeException
{
    /**
     * @param string               $domainCode Framework-agnostic code, e.g. `wp_mcp_ai_db_timeout`.
     * @param string               $message    Human-readable message.
     * @param int                  $status     HTTP status for `getCode()`.
     * @param array<string, mixed> $data       Contextual data supplied by the caller.
     */
    public function __construct(
        private readonly string $domainCode,
        string $message,
        int $status,
        private readonly array $data = array(),
    ) {
        parent::__construct($message, $status);
    }

    /**
     * The framework-agnostic error code.
     */
    public function domainCode(): string
    {
        return $this->domainCode;
    }

    /**
     * The contextual payload the caller passed to `create()`.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * One value from the payload, or a fallback.
     *
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }
}
