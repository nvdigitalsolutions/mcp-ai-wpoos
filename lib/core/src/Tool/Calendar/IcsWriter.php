<?php

/**
 * A minimal RFC 5545 writer for event calendars.
 *
 * Proposal 164 section 164.84. WordPress delegated ICS generation to a media
 * worker route (`/api/data/generate-ics`) or a filter, because it had no local
 * generator. This runtime has neither, and the external dependency was also its
 * least testable part. The writer builds the file directly:
 *
 * - `VCALENDAR` with `VERSION:2.0`, `CALSCALE:GREGORIAN` and `METHOD:PUBLISH`;
 * - `VEVENT` with `UID`, `DTSTAMP`, `DTSTART`, `DTEND`, `SUMMARY`, optional
 *   `DESCRIPTION`, `LOCATION`, `ORGANIZER`, `ATTENDEE` and an optional `VALARM`.
 *
 * **Invitations.** A booking notification is not a published calendar: the
 * caller sets `method: 'REQUEST'` to ask the recipient to accept, and supplies
 * an `attendee` (with `RSVP=TRUE`) plus an organizer. `sequence` is emitted only
 * when given, because RFC 5545 treats an absent `SEQUENCE` as `0` and adding it
 * unconditionally would change every export the existing caller builds.
 *
 * **Times are UTC.** Timed events are emitted as `YYYYMMDDTHHMMSSZ` and all-day
 * events as `VALUE=DATE` with an exclusive `DTEND`, so no `VTIMEZONE` component
 * is needed and every calendar client reads the same instant. The requested
 * timezone is carried as `X-WR-TIMEZONE` for display of all-day dates; the
 * stored wall-clock values are already UTC and are not shifted a second time.
 *
 * **Text is escaped and folded.** Backslash, semicolon, comma and newline are
 * escaped per section 3.3.11, and content lines are folded at 75 octets on a
 * UTF-8 boundary with a leading space on each continuation.
 *
 * An entry whose start cannot be read is skipped rather than emitted as a
 * malformed event; the caller reports the count of entries it built.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Calendar;

use DateTimeImmutable;
use DateTimeZone;

final class IcsWriter
{
    public const PRODID = '-//NV Digital Solutions//Open Operator System//EN';

    /**
     * The methods this writer can emit.
     *
     * `CANCEL` is deliberately absent: cancelling also requires
     * `STATUS:CANCELLED`, and a half-implemented method is worse than a refusal.
     */
    public const METHODS = array('PUBLISH', 'REQUEST');

    /** RFC 5545 section 3.1: content lines SHOULD NOT be longer than 75 octets. */
    private const FOLD_OCTETS = 75;

    private const CRLF = "\r\n";

    /**
     * Build a calendar document.
     *
     * @param list<array{
     *     uid: string,
     *     title: string,
     *     description?: string,
     *     location?: string,
     *     start: string,
     *     end?: string,
     *     all_day?: bool,
     *     default_minutes?: int,
     *     attendee?: array{name?: string, email?: string}
     * }> $entries Events to write.
     * @param array{
     *     calName?: string,
     *     timezone?: string,
     *     reminders?: bool,
     *     organizer?: array{name?: string, email?: string},
     *     method?: string,
     *     sequence?: int
     * } $options Calendar options.
     * @param DateTimeImmutable|null $now Stamp for DTSTAMP; defaults to now.
     */
    public static function calendar(array $entries, array $options = array(), ?DateTimeImmutable $now = null): string
    {
        $stamp = ( $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')) )
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Ymd\THis\Z');

        $method = isset($options['method']) && is_string($options['method'])
            ? strtoupper(trim($options['method']))
            : 'PUBLISH';

        if (! in_array($method, self::METHODS, true)) {
            $method = 'PUBLISH';
        }

        $sequence = isset($options['sequence']) && is_numeric($options['sequence'])
            ? max(0, (int) $options['sequence'])
            : null;

        $lines = array(
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . self::PRODID,
            'CALSCALE:GREGORIAN',
            'METHOD:' . $method,
        );

        if (isset($options['calName']) && '' !== trim((string) $options['calName'])) {
            $lines[] = 'X-WR-CALNAME:' . self::text((string) $options['calName']);
        }

        if (isset($options['timezone']) && '' !== trim((string) $options['timezone'])) {
            $lines[] = 'X-WR-TIMEZONE:' . self::text((string) $options['timezone']);
        }

        $reminders  = ! empty($options['reminders']);
        $organizer  = is_array($options['organizer'] ?? null) ? $options['organizer'] : array();

        foreach ($entries as $entry) {
            $event = self::event($entry, $stamp, $reminders, $organizer, $sequence);

            if (array() === $event) {
                continue;
            }

            foreach ($event as $line) {
                $lines[] = $line;
            }
        }

        $lines[] = 'END:VCALENDAR';

        $folded = array();

        foreach ($lines as $line) {
            $folded[] = self::fold($line);
        }

        return implode(self::CRLF, $folded) . self::CRLF;
    }

    /**
     * One VEVENT, or an empty list when the entry cannot be represented.
     *
     * @param array<string, mixed>  $entry     Entry definition.
     * @param string                $stamp     DTSTAMP value.
     * @param bool                  $reminders Whether to add a 15-minute alarm.
     * @param array<string, mixed>  $organizer Organizer name and email.
     * @param int|null              $sequence  Event revision, or null to omit.
     *
     * @return list<string>
     */
    private static function event(array $entry, string $stamp, bool $reminders, array $organizer, ?int $sequence = null): array
    {
        $title       = isset($entry['title']) ? (string) $entry['title'] : '';
        $uid         = isset($entry['uid']) ? (string) $entry['uid'] : '';
        $description = isset($entry['description']) ? (string) $entry['description'] : '';
        $location    = isset($entry['location']) ? (string) $entry['location'] : '';
        $allDay      = ! empty($entry['all_day']);
        $startRaw    = isset($entry['start']) ? (string) $entry['start'] : '';
        $endRaw      = isset($entry['end']) ? (string) $entry['end'] : '';

        if ('' === $uid || '' === $startRaw) {
            return array();
        }

        $lines = array('BEGIN:VEVENT', 'UID:' . self::text($uid), 'DTSTAMP:' . $stamp);

        if (null !== $sequence) {
            $lines[] = 'SEQUENCE:' . $sequence;
        }

        if ($allDay) {
            $start = CalendarEventDefinition::date($startRaw);

            if (null === $start) {
                return array();
            }

            $end = '' !== $endRaw ? CalendarEventDefinition::date($endRaw) : null;
            $end = null !== $end && $end >= $start ? $end : $start;

            $lines[] = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $start);
            $lines[] = 'DTEND;VALUE=DATE:' . self::nextDay($end);
        } else {
            $start = AppointmentDefinition::parseTime($startRaw);

            if (null === $start) {
                return array();
            }

            $end = '' !== $endRaw ? AppointmentDefinition::parseTime($endRaw) : null;

            if (null === $end || $end <= $start) {
                $minutes = isset($entry['default_minutes']) ? max(1, (int) $entry['default_minutes']) : 60;
                $end     = $start->modify('+' . $minutes . ' minutes');
            }

            $lines[] = 'DTSTART:' . $start->format('Ymd\THis\Z');
            $lines[] = 'DTEND:' . $end->format('Ymd\THis\Z');
        }

        $lines[] = 'SUMMARY:' . self::text($title);

        if ('' !== $description) {
            $lines[] = 'DESCRIPTION:' . self::text($description);
        }

        if ('' !== $location) {
            $lines[] = 'LOCATION:' . self::text($location);
        }

        $lines[] = 'STATUS:CONFIRMED';

        $email = isset($organizer['email']) ? trim((string) $organizer['email']) : '';

        if ('' !== $email) {
            $name     = isset($organizer['name']) ? (string) $organizer['name'] : '';
            $lines[]  = 'ORGANIZER' . ( '' !== trim($name) ? ';CN=' . self::param($name) : '' ) . ':mailto:' . $email;
        }

        $attendee = is_array($entry['attendee'] ?? null) ? $entry['attendee'] : array();
        $attendeeEmail = isset($attendee['email']) ? trim((string) $attendee['email']) : '';

        if ('' !== $attendeeEmail) {
            $attendeeName = isset($attendee['name']) ? trim((string) $attendee['name']) : '';
            $lines[]      = 'ATTENDEE'
                . ( '' !== $attendeeName ? ';CN=' . self::param($attendeeName) : '' )
                . ';RSVP=TRUE:mailto:' . $attendeeEmail;
        }

        if ($reminders) {
            $lines[] = 'BEGIN:VALARM';
            $lines[] = 'TRIGGER:-PT15M';
            $lines[] = 'ACTION:DISPLAY';
            $lines[] = 'DESCRIPTION:' . self::text($title);
            $lines[] = 'END:VALARM';
        }

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /**
     * The calendar day after a `Y-m-d` date, as `Ymd`.
     */
    private static function nextDay(string $date): string
    {
        $parsed = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));

        return $parsed->modify('+1 day')->format('Ymd');
    }

    /**
     * Escape a TEXT value per RFC 5545 section 3.3.11.
     */
    private static function text(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(array("\r\n", "\r", "\n"), '\\n', $value);
        $value = str_replace(';', '\\;', $value);

        return str_replace(',', '\\,', $value);
    }

    /**
     * Quote a property parameter value.
     */
    private static function param(string $value): string
    {
        $value = str_replace(array('"', "\r", "\n"), '', $value);

        return '"' . $value . '"';
    }

    /**
     * Fold one content line at 75 octets, never inside a UTF-8 character.
     */
    private static function fold(string $line): string
    {
        if (strlen($line) <= self::FOLD_OCTETS) {
            return $line;
        }

        $out   = '';
        $limit = self::FOLD_OCTETS;

        while (strlen($line) > $limit) {
            $cut = $limit;

            while ($cut > 0 && ( ord($line[ $cut ]) & 0xC0 ) === 0x80) {
                --$cut;
            }

            if (0 === $cut) {
                $cut = $limit;
            }

            $out .= substr($line, 0, $cut) . self::CRLF . ' ';
            $line = substr($line, $cut);

            // A continuation line carries the leading space, leaving 74 octets.
            $limit = self::FOLD_OCTETS - 1;
        }

        return $out . $line;
    }
}
