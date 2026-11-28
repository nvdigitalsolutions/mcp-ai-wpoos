<?php

/**
 * Outbound Google Calendar event writer for the oOS AI orchestration core.
 *
 * The calendar-booking toolkit pushes one appointment at a time and keys the
 * link on the event id, so the contract is deliberately narrow: insert an event,
 * replace an event, and report whether credentials exist at all. Reads, listing
 * and incremental pull sync are not part of the WordPress tool this contract
 * serves.
 *
 * ## Why the credential surface is methods, not a store
 *
 * The WordPress tool resolved credentials through a per-connection store, with
 * a documented `settings` fallback that reads one option. The runtime has one
 * Google account per site, so the client resolves the settings itself and the
 * tool never holds a refresh token. `connectionId()` is reported back so a
 * response shape stays compatible with the WordPress envelope; passing anything
 * other than the settings connection is refused rather than silently ignored.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

use Nvoos\Core\Domain\Error\GoogleCalendarApiException;

interface GoogleCalendarClientInterface
{
    /**
     * Whether a refresh token (or access token) and calendar are configured.
     */
    public function isConfigured(): bool;

    /**
     * The connection this client writes through.
     *
     * `google_calendar` for the settings-based credentials. A response carries
     * this so a caller can tell which account received the event.
     */
    public function connectionId(): string;

    /**
     * A human-readable label for the connection, or '' when none is configured.
     */
    public function connectionName(): string;

    /**
     * The calendar id to write to: the override when non-empty, otherwise the
     * configured calendar, otherwise `primary`.
     */
    public function calendarId(string $override = ''): string;

    /**
     * The IANA time zone the event times should be rendered in.
     *
     * Returns `UTC` when the configured zone is missing or is a bare offset,
     * which the Calendar API rejects.
     */
    public function defaultTimeZone(): string;

    /**
     * Create an event.
     *
     * @param string               $calendarId Calendar identifier.
     * @param array<string, mixed> $body       Full event resource.
     * @param array<string, mixed> $params     Query parameters (`sendUpdates`).
     *
     * @return array<string, mixed> The created event resource.
     *
     * @throws GoogleCalendarApiException When the call is refused or cannot be made.
     */
    public function insertEvent(string $calendarId, array $body, array $params = array()): array;

    /**
     * Replace an event.
     *
     * A full replace rather than a patch: Google's `events.update` does not
     * support patch semantics, and a partial body resets omitted fields.
     *
     * @param string               $calendarId Calendar identifier.
     * @param string               $eventId    Event identifier.
     * @param array<string, mixed> $body       Full event resource.
     * @param array<string, mixed> $params     Query parameters (`sendUpdates`).
     *
     * @return array<string, mixed> The updated event resource.
     *
     * @throws GoogleCalendarApiException When the call is refused or cannot be made.
     */
    public function updateEvent(string $calendarId, string $eventId, array $body, array $params = array()): array;
}
