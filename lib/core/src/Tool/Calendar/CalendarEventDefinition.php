<?php

/**
 * Shared rules for calendar events.
 *
 * Proposal 164 section 164.84. Six tools read and write one record type —
 * WordPress's `mcp_ai_event` CPT — through `ContentStoreInterface`, keeping the
 * type and the `_event_*` meta family so an export round-trips. The calendar
 * view also reads the two project-management types (`mcp_ai_project`,
 * `mcp_ai_task`) that the project toolkit already writes.
 *
 * ## Dates and times
 *
 * An event is either all-day (both clock times empty) or timed (a start time,
 * and usually an end time). The date is a `Y-m-d` string and the time is `HH:MM`
 * in UTC, the same wall-clock convention appointments use. This runtime has no
 * site timezone, so the tools say UTC rather than inheriting one.
 *
 * ## Corrections carried by the writers
 *
 * 1. **A replace clears what it omits.** WordPress's create-with-`event_id`
 *    path wrote only the fields it was given, so clearing a time left the old
 *    value in meta while `_event_all_day` flipped to 1. The port writes every
 *    key on both create and replace, using `''` or `[]` to clear.
 * 2. **An update is validated.** WordPress's `update_event` wrote any string
 *    into a date or time and never recomputed `_event_all_day`. The port
 *    validates the effective record and refreshes the flag whenever a time
 *    changes.
 * 3. **Attendees are stored as strings.** `_event_attendees` keeps user IDs as
 *    strings, the encoding `_project_assigned_to` already uses (see
 *    {@see \Nvoos\Core\Tool\ProjectManagement\ProjectRow} and `list_projects`), so the membership filter is a
 *    portable JSON/PHP-serialised LIKE instead of an array comparison no store
 *    offers. Readers cast back to integers.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Calendar;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Support\Text;

final class CalendarEventDefinition
{
    /** The content type WordPress stored events under. */
    public const TYPE = 'mcp_ai_event';

    /** The project-management types the calendar view reads. */
    public const PROJECT_TYPE = 'mcp_ai_project';

    public const TASK_TYPE = 'mcp_ai_task';

    /** The five event types the tools accept. */
    public const TYPES = array('meeting', 'deadline', 'milestone', 'reminder', 'other');

    public const DEFAULT_TYPE = 'meeting';

    /** The stored date format. */
    public const DATE_FORMAT = 'Y-m-d';

    /** The event meta family. */
    public const META_START_DATE = '_event_start_date';

    public const META_END_DATE = '_event_end_date';

    public const META_START_TIME = '_event_start_time';

    public const META_END_TIME = '_event_end_time';

    public const META_ALL_DAY = '_event_all_day';

    public const META_TYPE = '_event_type';

    public const META_LOCATION = '_event_location';

    public const META_ATTENDEES = '_event_attendees';

    public const META_PROJECT_ID = '_event_project_id';

    /** The store's page ceiling, from `ContentQuery`. */
    public const PAGE_SIZE = CalendarScan::PAGE_SIZE;

    /** The read ceiling: how many matching rows one call will scan. */
    public const MAX_SCAN = CalendarScan::MAX_SCAN;

    /** `list_events` bounds, as WordPress declared them. */
    public const LIST_LIMIT_DEFAULT = 100;

    public const LIST_LIMIT_MAX = 500;

    /**
     * A valid `Y-m-d` date, or null.
     *
     * Delegates to the appointment family so both calendar toolkits share one
     * date parser.
     */
    public static function date(mixed $value): ?string
    {
        return AppointmentDefinition::date($value);
    }

    /**
     * A `HH:MM` clock time, normalised, or null. Shares one parser with the
     * availability rules so the two families cannot disagree about a time.
     */
    public static function clock(mixed $value): ?string
    {
        return AvailabilityDefinition::clock($value);
    }

    /**
     * A stored event type, the default when empty, or null when unknown.
     */
    public static function type(mixed $value, ?string $default = null): ?string
    {
        $raw = is_scalar($value) ? Text::sanitizeKey((string) $value) : '';

        if ('' === $raw) {
            return $default;
        }

        return in_array($raw, self::TYPES, true) ? $raw : null;
    }

    /**
     * Attendee IDs as the stored string list.
     *
     * Non-scalars are dropped, non-positive IDs are dropped (a user id of 0 is
     * not a member), and first-seen order is kept.
     *
     * @return list<string>
     */
    public static function attendees(mixed $value): array
    {
        if (! is_array($value)) {
            return array();
        }

        $ids = array();

        foreach ($value as $id) {
            if (! is_scalar($id)) {
                continue;
            }

            $id = (int) $id;

            if ($id > 0 && ! in_array((string) $id, $ids, true)) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * Attendee IDs as the API list.
     *
     * @param mixed $value Stored string list or any legacy shape.
     *
     * @return list<int>
     */
    public static function attendeeIds(mixed $value): array
    {
        return array_map('intval', self::attendees($value));
    }

    /**
     * A positive project id, or 0.
     */
    public static function projectId(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /**
     * Whether the record is an all-day event.
     *
     * The stored flag wins; a record without one is read as all-day when both
     * clock times are empty, which is what the writers store.
     *
     * @param array<string, mixed> $meta The record meta.
     */
    public static function allDay(array $meta): bool
    {
        if (isset($meta[ self::META_ALL_DAY ]) && '' !== (string) $meta[ self::META_ALL_DAY ]) {
            return (bool) (int) $meta[ self::META_ALL_DAY ];
        }

        return '' === self::string($meta[ self::META_START_TIME ] ?? '')
            && '' === self::string($meta[ self::META_END_TIME ] ?? '');
    }

    /**
     * Whether a start/end pair describes an all-day event.
     */
    public static function isAllDay(string $startTime, string $endTime): bool
    {
        return '' === $startTime && '' === $endTime;
    }

    /**
     * The first problem with a start/end combination, or null.
     *
     * @return array{field: string, message: string}|null
     */
    public static function spanProblem(
        string $startDate,
        string $startTime,
        string $endDate,
        string $endTime,
    ): ?array {
        if ($endDate < $startDate) {
            return array(
                'field'   => 'end_date',
                'message' => 'The end date cannot be before the start date.',
            );
        }

        if ('' !== $endTime && '' === $startTime) {
            return array(
                'field'   => 'end_time',
                'message' => 'An end time needs a start time.',
            );
        }

        if ($endDate === $startDate && '' !== $startTime && '' !== $endTime && $endTime <= $startTime) {
            return array(
                'field'   => 'end_time',
                'message' => 'The end time must be after the start time on the same day.',
            );
        }

        return null;
    }

    /**
     * The array key that sorts events by date and time.
     *
     * @param array<string, mixed> $meta The record meta.
     *
     * @return array{0: string, 1: string, 2: int}
     */
    public static function sortKey(int $id, array $meta): array
    {
        return array(
            self::string($meta[ self::META_START_DATE ] ?? ''),
            self::string($meta[ self::META_START_TIME ] ?? ''),
            $id,
        );
    }

    /**
     * Sort two events by start date, then start time, then id.
     */
    public static function compare(ContentItem $a, ContentItem $b): int
    {
        return self::sortKey($a->id, $a->meta) <=> self::sortKey($b->id, $b->meta);
    }

    /**
     * The portable membership clause for a list-of-user-IDs meta field.
     *
     * The quoted pattern matches a JSON element (`["7"]`) and a
     * PHP-serialised string element (`s:1:"7";`), which is the same trick
     * `list_projects` uses for `_project_assigned_to`.
     *
     * @return array<string, string>
     */
    public static function attendeeClause(string $key, int $userId): array
    {
        return array(
            'key'     => $key,
            'value'   => '%"' . $userId . '"%',
            'compare' => 'LIKE',
        );
    }

    /**
     * The overlap clauses for a range stored as a start date and an end date.
     *
     * `start <= window_end AND (end >= window_start OR end is absent)` — the
     * standard half-open overlap. WordPress asked whether either bound fell
     * inside the window, which hid a record spanning the whole range: for a
     * project running 01-01 to 12-31 and a January view, neither bound was
     * inside January, so the project disappeared.
     *
     * @return array<int|string, mixed>
     */
    public static function overlap(string $startKey, string $endKey, string $from, string $to): array
    {
        return array(
            'relation' => 'AND',
            array(
                'key'     => $startKey,
                'value'   => $to,
                'compare' => '<=',
            ),
            array(
                'relation' => 'OR',
                array(
                    'key'     => $endKey,
                    'value'   => $from,
                    'compare' => '>=',
                ),
                array(
                    'key'     => $endKey,
                    'compare' => 'NOT EXISTS',
                ),
            ),
        );
    }

    /**
     * The API projection of an event.
     *
     * @return array<string, mixed>
     */
    public static function project(ContentItem $item): array
    {
        $meta      = $item->meta;
        $startDate = self::string($meta[ self::META_START_DATE ] ?? '');
        $endDate   = self::string($meta[ self::META_END_DATE ] ?? '');

        return array(
            'id'          => $item->id,
            'title'       => $item->title,
            'description' => $item->content,
            'project_id'  => self::projectId($meta[ self::META_PROJECT_ID ] ?? 0) ?: null,
            'start_date'  => $startDate,
            'start_time'  => self::string($meta[ self::META_START_TIME ] ?? ''),
            'end_date'    => '' !== $endDate ? $endDate : $startDate,
            'end_time'    => self::string($meta[ self::META_END_TIME ] ?? ''),
            'all_day'     => self::allDay($meta),
            'location'    => self::string($meta[ self::META_LOCATION ] ?? ''),
            'type'        => self::type($meta[ self::META_TYPE ] ?? '', self::DEFAULT_TYPE) ?? self::DEFAULT_TYPE,
            'attendees'   => self::attendeeIds($meta[ self::META_ATTENDEES ] ?? array()),
            'created_at'  => $item->createdAt->format('c'),
            'updated_at'  => $item->updatedAt->format('c'),
        );
    }

    /**
     * A scalar meta value as a string; arrays become ''.
     */
    public static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Read up to `$max` matching items, page by page.
     *
     * The store cannot order by meta, so a reader that must return the earliest
     * events has to see a window of rows and sort them itself. The window is
     * bounded by {@see CalendarScan}, and the caller reports `truncated` when
     * the collection holds more than the window.
     *
     * @param ContentQuery $query Base query; page and perPage are replaced.
     * @param int          $max   Items to collect, at most `MAX_SCAN`.
     *
     * @return array{items: list<ContentItem>, total: int, scanned: int}
     */
    public static function scan(ContentStoreInterface $content, ContentQuery $query, int $max = self::MAX_SCAN): array
    {
        return CalendarScan::scan($content, $query, $max);
    }
}
