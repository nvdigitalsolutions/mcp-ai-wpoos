<?php

/**
 * Shared rules for calendar appointments.
 *
 * Proposal 164 section 164.82. Five tools read and write one record type —
 * WordPress's `mcp_appointment` CPT — through `ContentStoreInterface`, keeping
 * the type and the `_`-prefixed meta family so an export round-trips.
 *
 * ## The stored times are UTC wall-clock strings
 *
 * WordPress stored site-local `Y-m-d H:i:s` because `current_time( 'mysql' )`
 * is local. The core has no site timezone, so every stored time is **UTC** and
 * the callers are told so. The parser accepts the documented format and ISO
 * 8601 with an explicit offset, and normalises both to `Y-m-d H:i:s` in UTC;
 * anything else — including `strtotime()`'s relative expressions
 * (`next friday`) — is refused. WordPress stored the relative string verbatim,
 * which is why a "next friday" booking never matched a slot.
 *
 * ## One correction: overlap detection
 *
 * WordPress built the conflict query from two range clauses that together miss
 * an existing appointment **entirely inside** the new slot: for new
 * 09:00–12:00 and existing 10:00–11:00, neither `existing.start <= 09:00` nor
 * `existing.end >= 12:00` holds, so the query returned nothing and the double
 * booking was accepted. The port uses the standard half-open test —
 * `existing.start < new.end AND existing.end > new.start` — which catches every
 * overlap and treats touching endpoints (10:00–11:00 then 11:00–12:00) as
 * adjacent, not conflicting.
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
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Support\Text;

final class AppointmentDefinition
{
    /** The content type WordPress stored appointments under. */
    public const TYPE = 'mcp_appointment';

    /** The stored time format, in UTC. */
    public const TIME_FORMAT = 'Y-m-d H:i:s';

    /** The five statuses the tools accept. */
    public const STATUSES = array('pending', 'confirmed', 'completed', 'cancelled', 'no-show');

    /** The statuses a booking blocks a slot with. */
    public const ACTIVE_STATUSES = array('confirmed', 'pending');

    /** The default length of a booking with no end and no duration. */
    public const DEFAULT_DURATION = 60;

    public const MIN_DURATION = 15;

    public const MAX_DURATION = 480;

    /** The ceiling on the conflict scan, matching the content store's page size. */
    public const MAX_CONFLICT_SCAN = 200;

    /** The actors that may cancel a booking. */
    public const CANCELLED_BY = array('client', 'staff', 'system');

    /**
     * A calendar date-time in the documented format, optionally with an offset.
     *
     * Deliberately strict: a relative expression is not a booking.
     */
    private const TIME_PATTERN = '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?(?:[Zz]|[+-]\d{2}:?\d{2})?$/';

    /**
     * Parse a caller-supplied time, or null when it is not a calendar time.
     *
     * An explicit offset (`Z`, `+02:00`) is honoured; a value without one is
     * read as UTC. The result is always in UTC.
     */
    public static function parseTime(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (1 !== preg_match(self::TIME_PATTERN, $value)) {
            return null;
        }

        $hasOffset = 1 === preg_match('/(?:[Zz]|[+-]\d{2}:?\d{2})$/', $value);

        try {
            $time = $hasOffset
                ? new DateTimeImmutable(str_replace(' ', 'T', $value))
                : new DateTimeImmutable(str_replace(' ', 'T', $value), new DateTimeZone('UTC'));
        } catch (\Exception $error) {
            return null;
        }

        return $time->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * The canonical stored form of a time.
     */
    public static function store(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format(self::TIME_FORMAT);
    }

    /**
     * A stored time as ISO 8601, or null when it is unset or unreadable.
     */
    public static function iso(mixed $stored): ?string
    {
        $time = self::parseTime($stored);

        return null === $time ? null : $time->format('c');
    }

    /**
     * The start and end to store, or null when the pair is unusable.
     *
     * An explicit end must be after the start; a duration is clamped to
     * 15–480 minutes; neither means 60 minutes.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function span(mixed $start, mixed $end, mixed $duration): ?array
    {
        $from = self::parseTime($start);

        if (null === $from) {
            return null;
        }

        if (null !== $end && '' !== $end) {
            $to = self::parseTime($end);

            if (null === $to || $to <= $from) {
                return null;
            }

            return array(self::store($from), self::store($to));
        }

        $minutes = is_numeric($duration)
            ? max(self::MIN_DURATION, min(self::MAX_DURATION, (int) $duration))
            : self::DEFAULT_DURATION;

        return array(self::store($from), self::store($from->modify('+' . $minutes . ' minutes')));
    }

    /**
     * The minutes between two stored times, or the default when unreadable.
     */
    public static function minutesBetween(mixed $start, mixed $end): int
    {
        $from = self::parseTime($start);
        $to   = self::parseTime($end);

        if (null === $from || null === $to || $to <= $from) {
            return self::DEFAULT_DURATION;
        }

        return max(1, (int) round(( $to->getTimestamp() - $from->getTimestamp() ) / 60));
    }

    /**
     * The appointment title, which embeds the start time.
     */
    public static function title(string $clientName, string $start): string
    {
        return sprintf('Appointment: %1$s - %2$s', $clientName, $start);
    }

    /**
     * A valid status, or the fallback.
     */
    public static function status(mixed $value, string $fallback = 'confirmed'): string
    {
        $value = is_scalar($value) ? Text::sanitizeKey((string) $value) : '';

        return in_array($value, self::STATUSES, true) ? $value : $fallback;
    }

    /**
     * Appointments overlapping a stored span.
     *
     * @param int $excludeId An appointment to ignore (the one being moved).
     *
     * @return array{ids: list<int>, truncated: bool}
     */
    public static function conflicts(
        ContentStoreInterface $content,
        string $start,
        string $end,
        int $excludeId = 0,
    ): array {
        $page = $content->query(
            new ContentQuery(
                types: array(self::TYPE),
                statuses: array('publish'),
                exclude: $excludeId > 0 ? array($excludeId) : array(),
                metaQuery: array(
                    'relation' => 'AND',
                    array(
                        'key'     => '_status',
                        'value'   => self::ACTIVE_STATUSES,
                        'compare' => 'IN',
                    ),
                    array(
                        'key'     => '_start_time',
                        'value'   => $end,
                        'compare' => '<',
                    ),
                    array(
                        'key'     => '_end_time',
                        'value'   => $start,
                        'compare' => '>',
                    ),
                ),
                orderBy: 'id',
                order: 'ASC',
                page: 1,
                perPage: self::MAX_CONFLICT_SCAN,
            )
        );

        $ids = array();

        foreach ($page->items as $item) {
            if ($item instanceof ContentItem) {
                $ids[] = $item->id;
            }
        }

        return array('ids' => $ids, 'truncated' => $page->total > count($ids));
    }

    /**
     * Append an entry to one of the two audit arrays in meta.
     *
     * @param array<string, mixed> $meta  The record's current meta.
     * @param array<string, mixed> $entry The entry to append.
     *
     * @return array<string, mixed>
     */
    public static function appendLog(array $meta, string $key, array $entry): array
    {
        $log = $meta[ $key ] ?? array();

        if (! is_array($log)) {
            $log = array();
        }

        $log[] = $entry;

        $meta[ $key ] = array_values($log);

        return $meta;
    }

    /**
     * The API projection of an appointment.
     *
     * @return array<string, mixed>
     */
    public static function project(ContentItem $item, bool $history = false, bool $metadata = false): array
    {
        $meta = $item->meta;

        $details = array(
            'id'             => $item->id,
            'title'          => $item->title,
            'client'         => array(
                'name'  => self::string($meta['_client_name'] ?? ''),
                'email' => self::string($meta['_client_email'] ?? ''),
                'phone' => self::string($meta['_client_phone'] ?? ''),
            ),
            'appointment'    => array(
                'type'       => self::string($meta['_appointment_type'] ?? ''),
                'start_time' => self::string($meta['_start_time'] ?? ''),
                'end_time'   => self::string($meta['_end_time'] ?? ''),
                'location'   => self::string($meta['_location'] ?? ''),
                'status'     => self::string($meta['_status'] ?? ''),
            ),
            'notes'          => $item->content,
            'created_at'     => self::string($meta['_created_at'] ?? ''),
            'created_by'     => (int) ( $meta['_created_by'] ?? 0 ),
            'start_time_iso' => self::iso($meta['_start_time'] ?? null),
            'end_time_iso'   => self::iso($meta['_end_time'] ?? null),
        );

        if ($history) {
            $details['history'] = array(
                'activity_log'   => self::listOf($meta['_activity_log'] ?? array()),
                'change_history' => self::listOf($meta['_change_history'] ?? array()),
            );

            if (isset($meta['_rescheduled_at']) && '' !== (string) $meta['_rescheduled_at']) {
                $details['history']['rescheduled'] = array(
                    'at'     => self::iso($meta['_rescheduled_at']),
                    'by'     => (int) ( $meta['_rescheduled_by'] ?? 0 ),
                    'reason' => self::string($meta['_reschedule_reason'] ?? ''),
                );
            }

            if (isset($meta['_cancelled_at']) && '' !== (string) $meta['_cancelled_at']) {
                $details['history']['cancelled'] = array(
                    'at'               => self::iso($meta['_cancelled_at']),
                    'by'               => self::string($meta['_cancelled_by'] ?? ''),
                    'by_user_id'       => (int) ( $meta['_cancelled_by_user_id'] ?? 0 ),
                    'reason'           => self::string($meta['_cancellation_reason'] ?? ''),
                    'refund_processed' => self::string($meta['_refund_processed'] ?? ''),
                );
            }
        }

        if ($metadata) {
            $custom = array();

            foreach ($meta as $key => $value) {
                if (is_string($key) && 0 === strpos($key, '_custom_')) {
                    $custom[ substr($key, 8) ] = $value;
                }
            }

            if (array() !== $custom) {
                $details['custom_metadata'] = $custom;
            }
        }

        return $details;
    }

    /**
     * A scalar meta value as a string. Arrays and objects become ''; a caller
     * that stored a structure under a scalar key gets a readable record rather
     * than "Array".
     */
    public static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * The `_custom_` half of the meta map, ready to merge back.
     *
     * Non-scalar values are dropped rather than cast: WordPress passed them to
     * `sanitize_text_field()`, which cannot represent a structure either.
     *
     * @param mixed $raw Caller-supplied metadata object.
     *
     * @return array<string, string>
     */
    public static function customMeta(mixed $raw): array
    {
        if (! is_array($raw)) {
            return array();
        }

        $meta = array();

        foreach ($raw as $key => $value) {
            if (! is_scalar($key) || ! is_scalar($value)) {
                continue;
            }

            $clean = Text::sanitizeKey((string) $key);

            if ('' === $clean) {
                continue;
            }

            $meta[ '_custom_' . $clean ] = Text::singleLine((string) $value);
        }

        return $meta;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function listOf(mixed $value): array
    {
        if (! is_array($value)) {
            return array();
        }

        return array_values(array_filter($value, 'is_array'));
    }
}
