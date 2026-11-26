<?php

/**
 * Shared availability rules for the calendar tools.
 *
 * Proposal 164 section 164.83. Three tools read the weekly business hours, two
 * of them to decide what is bookable, and two read/write the blocked ranges.
 * Keeping the shapes and the arithmetic here means `check_availability` and
 * `get_available_slots` cannot disagree about what a working day is.
 *
 * ## Storage
 *
 * - **Business hours** live in `SettingsStoreInterface` under `business_hours`:
 *   `{monday: {enabled, start_time: 'HH:MM', end_time: 'HH:MM',
 *   slot_duration: int, buffer_time: int}, …}`. WordPress kept the same shape in
 *   the `wp_mcp_ai_business_hours` option; the settings store is already
 *   namespaced, so the key does not repeat the prefix.
 * - **Blocked ranges** are `mcp_blocked_time` records with `_start_time`,
 *   `_end_time`, `_reason` and `_blocked_by`, read through
 *   `ContentStoreInterface` like appointments.
 *
 * ## Corrections
 *
 * 1. **Business hours were only checked by weekday.** WordPress's
 *    `check_business_hours()` returned true as soon as the day was enabled, so
 *    a 22:00 booking on an enabled Monday was "within business hours". The port
 *    requires the whole span to sit inside the day's `start_time`–`end_time`.
 * 2. **`get_available_slots` ignored blocked ranges and `buffer_time`.** The
 *    tool's own guidance promised blocked ranges would be respected, and the
 *    rules stored a buffer, but neither was read. Both now shape the grid: a
 *    slot overlapping a block is omitted, and the cursor advances by
 *    `slot_duration + buffer_time`. The buffer is not enforced by
 *    `create_appointment`, which WordPress never did either.
 * 3. **The weekday came from the server's timezone.** `gmdate('l',
 *    strtotime($date))` parses midnight in the PHP default zone and then
 *    formats in UTC, which is the previous day west of Greenwich. The port
 *    reads the date as UTC throughout.
 *
 * ## What is not supported
 *
 * An overnight window (`22:00`–`02:00`) has no representation: `end_time` is a
 * same-day clock value and must be after `start_time`. WordPress had the same
 * constraint, and `set_availability_rules` now refuses a reversed window rather
 * than storing one no reader can interpret.
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
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;

final class AvailabilityDefinition
{
    /** The content type for a blocked range. */
    public const BLOCKED_TYPE = 'mcp_blocked_time';

    /** The settings key holding the weekly rules. */
    public const SETTINGS_KEY = 'business_hours';

    /** The weekdays in calendar order. */
    public const DAYS = array(
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',
    );

    /** Defaults written when a field is not supplied, as WordPress did. */
    public const DEFAULT_START = '09:00';

    public const DEFAULT_END = '17:00';

    public const DEFAULT_SLOT_DURATION = 60;

    public const DEFAULT_BUFFER = 0;

    /** The ceiling on generated slots, so a pathological rule cannot spin. */
    public const MAX_SLOTS = 500;

    /** The ceiling on the blocked-range page. */
    public const MAX_BLOCKS = 200;

    /**
     * The normalised weekly rules.
     *
     * Only the days present in the stored value are returned; a day that was
     * never configured has no rule and is therefore not bookable.
     *
     * @return array<string, array{enabled: bool, start_time: string, end_time: string, slot_duration: int, buffer_time: int}>
     */
    public static function rules(SettingsStoreInterface $settings): array
    {
        $raw = $settings->get(self::SETTINGS_KEY, array());

        if (! is_array($raw)) {
            return array();
        }

        $rules = array();

        foreach (self::DAYS as $day) {
            if (! isset($raw[ $day ]) || ! is_array($raw[ $day ])) {
                continue;
            }

            $rules[ $day ] = self::normalise($raw[ $day ]);
        }

        return $rules;
    }

    /**
     * One day's rule, or null when the day is unconfigured or disabled.
     *
     * @param array<string, array<string, mixed>> $rules Normalised rules.
     *
     * @return array{enabled: bool, start_time: string, end_time: string, slot_duration: int, buffer_time: int}|null
     */
    public static function slotRule(array $rules, string $day): ?array
    {
        if (! isset($rules[ $day ]) || empty($rules[ $day ]['enabled'])) {
            return null;
        }

        return $rules[ $day ];
    }

    /**
     * A stored day rule, with every field defaulted and bounded.
     *
     * @param array<string, mixed> $raw Stored rule.
     *
     * @return array{enabled: bool, start_time: string, end_time: string, slot_duration: int, buffer_time: int}
     */
    public static function normalise(array $raw): array
    {
        $start = self::clock($raw['start_time'] ?? null);
        $end   = self::clock($raw['end_time'] ?? null);

        return array(
            'enabled'       => (bool) ( $raw['enabled'] ?? true ),
            'start_time'    => null === $start ? self::DEFAULT_START : $start,
            'end_time'      => null === $end ? self::DEFAULT_END : $end,
            'slot_duration' => isset($raw['slot_duration'])
                ? max(AppointmentDefinition::MIN_DURATION, min(AppointmentDefinition::MAX_DURATION, (int) $raw['slot_duration']))
                : self::DEFAULT_SLOT_DURATION,
            'buffer_time'   => isset($raw['buffer_time'])
                ? max(0, min(240, (int) $raw['buffer_time']))
                : self::DEFAULT_BUFFER,
        );
    }

    /**
     * A `HH:MM` clock value, or null when it is not one.
     */
    public static function clock(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (1 !== preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $value, $match)) {
            return null;
        }

        return sprintf('%02d:%02d', (int) $match[1], (int) $match[2]);
    }

    /**
     * The weekday slug of a `Y-m-d` date, read as UTC.
     */
    public static function weekday(string $date): ?string
    {
        $parsed = AppointmentDefinition::parseTime($date . ' 00:00:00');

        if (null === $parsed || $parsed->format('Y-m-d') !== $date) {
            return null;
        }

        return strtolower($parsed->format('l'));
    }

    /**
     * Whether a span sits inside the day's business hours.
     *
     * No rules at all means "always open", which is what WordPress returned for
     * an empty option; rules for other days do not open this one.
     *
     * @param array<string, array<string, mixed>> $rules Normalised rules.
     */
    public static function withinHours(array $rules, string $start, string $end): bool
    {
        if (array() === $rules) {
            return true;
        }

        $day = self::weekday(substr($start, 0, 10));

        if (null === $day) {
            return false;
        }

        $rule = self::slotRule($rules, $day);

        if (null === $rule) {
            return false;
        }

        $open  = substr($start, 0, 10) . ' ' . $rule['start_time'] . ':00';
        $close = substr($start, 0, 10) . ' ' . $rule['end_time'] . ':00';

        return $start >= $open && $end <= $close;
    }

    /**
     * Blocked ranges overlapping a span.
     *
     * @return array{ids: list<int>, truncated: bool}
     */
    public static function blocks(
        ContentStoreInterface $content,
        string $start,
        string $end,
    ): array {
        $page = $content->query(
            new ContentQuery(
                types: array(self::BLOCKED_TYPE),
                statuses: array('publish'),
                metaQuery: array(
                    'relation' => 'AND',
                    array('key' => '_start_time', 'value' => $end, 'compare' => '<'),
                    array('key' => '_end_time', 'value' => $start, 'compare' => '>'),
                ),
                orderBy: 'id',
                order: 'ASC',
                page: 1,
                perPage: self::MAX_BLOCKS,
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
     * The bookable grid for one date, with conflicts and blocks removed.
     *
     * The cursor advances by `slot_duration + buffer_time`, so the buffer keeps
     * consecutive offers apart. `duration_minutes` is the booking length and is
     * clamped to the appointment bounds.
     *
     * @param array<string, array<string, mixed>> $rules Normalised rules.
     *
     * @return list<array{start_time: string, end_time: string, available: bool, source: string}>
     */
    public static function slots(
        ContentStoreInterface $content,
        array $rules,
        string $date,
        int $duration,
    ): array {
        $day = self::weekday($date);

        if (null === $day) {
            return array();
        }

        $rule = self::slotRule($rules, $day);

        if (null === $rule) {
            return array();
        }

        $duration = max(AppointmentDefinition::MIN_DURATION, min(AppointmentDefinition::MAX_DURATION, $duration));
        $step     = max(AppointmentDefinition::MIN_DURATION, $rule['slot_duration'] + $rule['buffer_time']);

        $open  = AppointmentDefinition::parseTime($date . ' ' . $rule['start_time'] . ':00');
        $close = AppointmentDefinition::parseTime($date . ' ' . $rule['end_time'] . ':00');

        if (null === $open || null === $close || $close <= $open) {
            return array();
        }

        // One query for every block that overlaps the working day; each slot
        // then overlaps them locally, which is cheaper than a query per slot.
        $blocked = $content->query(
            new ContentQuery(
                types: array(self::BLOCKED_TYPE),
                statuses: array('publish'),
                metaQuery: array(
                    'relation' => 'AND',
                    array('key' => '_start_time', 'value' => AppointmentDefinition::store($close), 'compare' => '<'),
                    array('key' => '_end_time', 'value' => AppointmentDefinition::store($open), 'compare' => '>'),
                ),
                orderBy: 'id',
                order: 'ASC',
                page: 1,
                perPage: self::MAX_BLOCKS,
            )
        );

        $blockedSpans = array();

        foreach ($blocked->items as $item) {
            if (! $item instanceof ContentItem) {
                continue;
            }

            $blockedSpans[] = array(
                AppointmentDefinition::string($item->meta['_start_time'] ?? ''),
                AppointmentDefinition::string($item->meta['_end_time'] ?? ''),
            );
        }

        $slots   = array();
        $cursor  = $open;

        while (count($slots) < self::MAX_SLOTS) {
            $slotEnd = $cursor->modify('+' . $duration . ' minutes');

            if ($slotEnd > $close) {
                break;
            }

            $start = AppointmentDefinition::store($cursor);
            $end   = AppointmentDefinition::store($slotEnd);

            $conflicts = AppointmentDefinition::conflicts($content, $start, $end);

            if (array() === $conflicts['ids'] && ! self::overlapsAny($blockedSpans, $start, $end)) {
                $slots[] = array(
                    'start_time' => $start,
                    'end_time'   => $end,
                    'available'  => true,
                    'source'     => 'nvoos',
                );
            }

            $cursor = $cursor->modify('+' . $step . ' minutes');
        }

        return $slots;
    }

    /**
     * Whether a span overlaps any of the stored block spans.
     *
     * @param list<array{0: string, 1: string}> $spans Blocked spans.
     */
    private static function overlapsAny(array $spans, string $start, string $end): bool
    {
        foreach ($spans as $span) {
            if ($span[0] < $end && $span[1] > $start) {
                return true;
            }
        }

        return false;
    }
}
