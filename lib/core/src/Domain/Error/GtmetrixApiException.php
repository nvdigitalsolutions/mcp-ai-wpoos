<?php

/**
 * A GTmetrix API call that did not produce a usable payload.
 *
 * Three fields are kept apart on purpose: the core `errorCode` a tool can use in
 * its envelope, the HTTP status, and the API's own `E4xxxx`/`E5xxxx` code. The
 * WordPress client flattened all three into a message, which is why a
 * rate-limited call and a rejected parameter read the same to a caller.
 *
 * `retryAfter` carries the server's own advice — `Retry-After` where present,
 * `X-RateLimit-Reset` for a rate limit — instead of sleeping inside the request
 * the way the WordPress client did. A worker that blocks for seven seconds per
 * retry is a worker every other request is waiting on.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Error;

class GtmetrixApiException extends \RuntimeException
{
    /**
     * @param string               $message    Human-readable reason.
     * @param string               $errorCode  One of the `gtmetrix_*` codes.
     * @param int                  $statusCode HTTP status, or 0 when no response arrived.
     * @param string               $apiCode    The API's own code (`E42901`), or ''.
     * @param int                  $retryAfter Seconds the server asked the caller to wait, or 0.
     * @param array<string, mixed> $payload    Decoded response body, when there was one.
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'gtmetrix_api_error',
        public readonly int $statusCode = 0,
        public readonly string $apiCode = '',
        public readonly int $retryAfter = 0,
        public readonly array $payload = array(),
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
