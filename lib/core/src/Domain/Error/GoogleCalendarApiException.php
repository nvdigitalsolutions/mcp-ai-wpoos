<?php

/**
 * A Google Calendar API call that did not produce a usable event resource.
 *
 * Three fields are kept apart on purpose: the core `errorCode` a tool can use in
 * its envelope, the HTTP status, and Google's own `reason` string from the
 * error envelope (`notFound`, `forbiddenForNonOrganizer`, `invalid_grant`). The
 * WordPress client flattened all three into a `WP_Error` code, so a missing
 * event and a revoked token read the same to a caller deciding whether a retry
 * could help.
 *
 * The `notFound`/`deleted` pair is the one the sync tool branches on: an event
 * deleted at the provider is recreated rather than reported as a permanent
 * failure, which is what the WordPress tool did.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Error;

class GoogleCalendarApiException extends \RuntimeException
{
    /**
     * @param string               $message    Human-readable reason.
     * @param string               $errorCode  One of the `google_calendar_*` codes.
     * @param int                  $statusCode HTTP status, or 0 when no response arrived.
     * @param string               $apiCode    Google's own `error.errors[0].reason`, or ''.
     * @param int                  $retryAfter Seconds the server asked the caller to wait, or 0.
     * @param array<string, mixed> $payload    Decoded response body, when there was one.
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'google_calendar_api_error',
        public readonly int $statusCode = 0,
        public readonly string $apiCode = '',
        public readonly int $retryAfter = 0,
        public readonly array $payload = array(),
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * Whether the event the caller tried to update no longer exists.
     *
     * Google answers `404 notFound` for an event that was hard-deleted and
     * `410 deleted` for one a user removed from their calendar; both mean the
     * local link is stale and the event should be recreated.
     */
    public function isGone(): bool
    {
        return in_array($this->errorCode, array('google_calendar_event_not_found', 'google_calendar_event_deleted'), true);
    }
}
