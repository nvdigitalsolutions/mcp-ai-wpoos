<?php

/**
 * Thrown when a request contradicts the current state of a resource.
 *
 * The resumable-upload protocol is the first caller: a chunk that arrives with an
 * `Upload-Offset` the server does not hold is not a validation failure — the
 * request was well formed, the world had moved — and the client's correct
 * response is to re-read the offset rather than to change the payload. That is
 * HTTP 409, and it is a different decision from "your file type is not allowed"
 * (422) or "that upload does not exist" (404).
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Error;

class ConflictException extends \RuntimeException
{
    /**
     * @param string               $message  Why the request conflicts.
     * @param array<string, mixed> $context  State a client can act on (offset, total).
     * @param \Throwable|null      $previous
     */
    public function __construct(
        string $message = 'The request conflicts with the current state.',
        public readonly array $context = array(),
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 409, $previous);
    }
}
