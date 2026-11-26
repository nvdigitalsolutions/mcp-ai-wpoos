<?php

/**
 * Shared rules for the four calendar notification tools.
 *
 * Proposal 164 section 164.88. WordPress sent notification mail directly from
 * each tool with `wp_mail()` and recorded the fact in a different meta key per
 * tool (`_confirmation_sent_at`, `_reminders_sent`,
 * `_reschedule_invitation_sent_at`). Three consequences are handled here once:
 *
 * ## Idempotency
 *
 * Nothing stopped the same confirmation from being sent twice — an agent that
 * retried a tool call emailed the client again. A successful notification is
 * appended to `_notification_log` as `(kind, revision, sent_at, message_id)`,
 * and a tool refuses to send the same pair twice unless the caller asks for a
 * resend. Only successful sends are logged, so a transport failure can be
 * retried immediately.
 *
 * **Revision** is the booking's ICS sequence, not a timestamp: it is `0` until
 * the appointment is first rescheduled and rises by one per reschedule, which
 * is exactly the RFC 5545 `SEQUENCE` rule. The reschedule tool already appends
 * one `_activity_log` entry per move, so the count is the sequence — no new meta
 * key and no second source of truth. A confirmation sent before a reschedule
 * therefore does not suppress the confirmation that is owed afterwards.
 *
 * ## Invitations
 *
 * A confirmation can carry an RFC 5545 invite. When an organizer address is
 * supplied the document is a `METHOD:REQUEST` with `ORGANIZER` and an
 * `ATTENDEE;RSVP=TRUE`; without one it is a `METHOD:PUBLISH` the recipient can
 * add, because a REQUEST with no organizer is not conformant. The `UID` is
 * `appointment-{id}@{host}` — the host comes from `RuntimeIdentityInterface`
 * when bound and falls back to the RFC 2606 reserved `nvoos.invalid`, which can
 * never resolve to a real mailbox.
 *
 * ## Times
 *
 * The stored times are UTC wall-clock strings (section 164.82), so every
 * human-readable time in a notification is rendered as ISO 8601 with an
 * explicit offset. WordPress printed the site-local string; this runtime has no
 * site timezone and will not pretend the UTC value is local.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Calendar;

use Nvoos\Core\Domain\Contract\EmailServiceInterface;
use Nvoos\Core\Domain\Contract\RuntimeIdentityInterface;
use Nvoos\Core\Domain\Entity\ContentItem;

final class CalendarNotification
{
    /** The meta key holding the send log. */
    public const META_LOG = '_notification_log';

    /** How many log entries are kept; older sends fall off. */
    public const MAX_LOG_ENTRIES = 50;

    /** The kind recorded by a booking confirmation. */
    public const KIND_CONFIRMATION = 'confirmation';

    /** The kind prefix recorded by a reminder; the hours-before is appended. */
    public const KIND_REMINDER = 'reminder';

    /** The kind recorded by a reschedule invitation. */
    public const KIND_RESCHEDULE_INVITATION = 'reschedule_invitation';

    /** The ceiling on a caller-supplied message, in bytes. */
    public const MAX_CUSTOM_MESSAGE = 2000;

    /** The reserved domain used when the host reports no site URL. */
    public const FALLBACK_HOST = 'nvoos.invalid';

    /** The attachment name and type an invite carries. */
    public const ICS_NAME = 'appointment.ics';

    public const ICS_MIME = 'text/calendar; charset=utf-8';

    /**
     * The client address, trimmed, or '' when the record has none.
     */
    public static function email(ContentItem $item): string
    {
        $email = $item->meta[ '_client_email' ] ?? '';

        return is_scalar($email) ? trim((string) $email) : '';
    }

    /**
     * The appointment revision, which is also its ICS sequence.
     *
     * Zero until the first reschedule, then one per logged reschedule.
     */
    public static function sequence(ContentItem $item): int
    {
        $log = $item->meta[ '_activity_log' ] ?? array();

        if (! is_array($log)) {
            return 0;
        }

        $count = 0;

        foreach ($log as $entry) {
            if (is_array($entry) && 'rescheduled' === ( $entry['action'] ?? '' )) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * The logged send for this kind and revision, or null.
     *
     * @return array{sent_at: string, message_id: string}|null
     */
    public static function sentEntry(ContentItem $item, string $kind, int $revision): ?array
    {
        $log = $item->meta[ self::META_LOG ] ?? array();

        if (! is_array($log)) {
            return null;
        }

        foreach ($log as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if ((string) ( $entry['kind'] ?? '' ) !== $kind) {
                continue;
            }

            if ((int) ( $entry['revision'] ?? -1 ) !== $revision) {
                continue;
            }

            return array(
                'sent_at'    => is_scalar($entry['sent_at'] ?? null) ? (string) $entry['sent_at'] : '',
                'message_id' => is_scalar($entry['message_id'] ?? null) ? (string) $entry['message_id'] : '',
            );
        }

        return null;
    }

    /**
     * The log with one entry appended, capped to the newest entries.
     *
     * @return list<array<string, mixed>>
     */
    public static function withEntry(
        ContentItem $item,
        string $kind,
        int $revision,
        string $sentAt,
        string $messageId,
    ): array {
        $log = $item->meta[ self::META_LOG ] ?? array();

        if (! is_array($log)) {
            $log = array();
        }

        $log[] = array(
            'kind'       => $kind,
            'revision'   => $revision,
            'sent_at'    => $sentAt,
            'message_id' => $messageId,
        );

        if (count($log) > self::MAX_LOG_ENTRIES) {
            $log = array_slice($log, -self::MAX_LOG_ENTRIES);
        }

        return array_values($log);
    }

    /**
     * Send one message through the transport.
     *
     * A transport that throws is a failed send, not an uncaught fault: the
     * caller reports it and nothing is logged, so the send stays retryable.
     *
     * @param array<string, mixed> $message Message fields.
     *
     * @return array{0: bool, 1: ?string, 2: string} Sent, error, message id.
     */
    public static function send(EmailServiceInterface $transport, array $message): array
    {
        try {
            $result = $transport->send($message);
        } catch (\Throwable $error) {
            return array(false, $error->getMessage(), '');
        }

        if (empty($result['success'])) {
            $error = $result['error'] ?? 'The email could not be sent.';

            return array(false, is_scalar($error) ? (string) $error : 'The email could not be sent.', '');
        }

        return array(
            true,
            null,
            is_scalar($result['message_id'] ?? null) ? (string) $result['message_id'] : '',
        );
    }

    /**
     * The stable `UID` for this appointment's calendar entry.
     */
    public static function uid(ContentItem $item, ?RuntimeIdentityInterface $identity = null): string
    {
        $host = self::FALLBACK_HOST;

        if (null !== $identity) {
            $info = $identity->identity();
            $site = is_array($info) && isset($info['site_url']) && is_string($info['site_url'])
                ? trim($info['site_url'])
                : '';
            $parsed = '' !== $site ? parse_url($site, PHP_URL_HOST) : null;

            if (is_string($parsed) && '' !== $parsed && 1 === preg_match('/^[a-z0-9.-]+$/i', $parsed)) {
                $host = $parsed;
            }
        }

        return sprintf('appointment-%d@%s', $item->id, $host);
    }

    /**
     * Build the invite attachment, or '' when the appointment has no readable start.
     *
     * A `REQUEST` needs an organizer; without one the document is published.
     */
    public static function invite(
        ContentItem $item,
        ?RuntimeIdentityInterface $identity,
        string $organizer,
        string $description,
        int $sequence,
    ): string {
        $organizer = trim($organizer);
        $request   = '' !== $organizer;

        $options = array(
            'method'   => $request ? 'REQUEST' : 'PUBLISH',
            'sequence' => $sequence,
            'calName'  => 'Appointment',
        );

        if ($request) {
            $options['organizer'] = array('email' => $organizer);
        }

        $entry = array(
            'uid'      => self::uid($item, $identity),
            'title'    => self::summary($item),
            'start'    => AppointmentDefinition::string($item->meta[ '_start_time' ] ?? ''),
            'end'      => AppointmentDefinition::string($item->meta[ '_end_time' ] ?? ''),
            'location' => AppointmentDefinition::string($item->meta[ '_location' ] ?? ''),
        );

        if ('' !== $description) {
            $entry['description'] = $description;
        }

        if ($request) {
            $entry['attendee'] = array(
                'name'  => AppointmentDefinition::string($item->meta[ '_client_name' ] ?? ''),
                'email' => self::email($item),
            );
        }

        $ics = IcsWriter::calendar(array($entry), $options);

        return str_contains($ics, 'BEGIN:VEVENT') ? $ics : '';
    }

    /**
     * The attachment entry for a message, or an empty list.
     *
     * @return list<array{name: string, mime: string, content: string}>
     */
    public static function attachment(string $ics, int $appointmentId): array
    {
        if ('' === $ics) {
            return array();
        }

        return array(
            array(
                'name'    => sprintf('appointment-%d.ics', $appointmentId),
                'mime'    => self::ICS_MIME,
                'content' => $ics,
            ),
        );
    }

    /**
     * A stored time as ISO 8601, or the raw value when it cannot be read.
     */
    public static function time(ContentItem $item, string $key): string
    {
        $raw = AppointmentDefinition::string($item->meta[ $key ] ?? '');

        if ('' === $raw) {
            return '';
        }

        return AppointmentDefinition::iso($raw) ?? $raw;
    }

    /**
     * The calendar entry title.
     */
    private static function summary(ContentItem $item): string
    {
        $type = AppointmentDefinition::string($item->meta[ '_appointment_type' ] ?? '');

        return '' !== $type ? sprintf('Appointment: %s', $type) : 'Appointment';
    }
}
