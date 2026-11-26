<?php

/**
 * A Cloudways API call that did not produce a usable payload.
 *
 * The exception carries the API's own classification rather than a generic
 * message, because the caller's next move differs by code: an expired token is
 * "reconnect the account", a rate limit is "wait", a 404 is "wrong id", and a
 * 500 is "nothing you can fix from here". Flattening those into one
 * `RuntimeException` forces every tool to re-parse the message.
 *
 * The status code travels separately from the message so a tool can pass it
 * through without embedding an HTTP number in prose.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Error;

class CloudwaysApiException extends \RuntimeException
{
    /**
     * @param string               $message    Human-readable reason.
     * @param string               $errorCode  One of the `cloudways_*` codes.
     * @param int                  $statusCode HTTP status, or 0 when no response arrived.
     * @param array<string, mixed> $payload    Decoded error body, when there was one.
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'cloudways_api_error',
        public readonly int $statusCode = 0,
        public readonly array $payload = array(),
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
