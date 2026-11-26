<?php

/**
 * The recurrence vocabulary for Pro Schedules.
 *
 * Proposal 164 section 164.80. WordPress accepts whatever `wp_get_schedules()`
 * returns: four core intervals plus the eight the Pro addon registers. That set
 * is a site fact, not a portable contract, so the port fixes the union here and
 * validates against it.
 *
 * ## Intervals are fixed seconds, not wall-clock times
 *
 * Every slug is a number of seconds, so `daily` is 86,400 s rather than "the
 * same local time tomorrow". Across a daylight-saving change a `daily` schedule
 * therefore shifts by an hour in local time. That is WordPress's behaviour
 * (Trac #17957) and the reason a scheduler that must keep a wall-clock time
 * reschedules a single event after each run instead of using an interval. The
 * port keeps the interval model and names the drift in the readiness row rather
 * than silently switching to cron expressions.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\ValueObject;

final class ScheduleInterval
{
    /** One-shot; no recurrence. */
    public const SINGLE = 'single';

    /**
     * Interval slug → seconds.
     *
     * The first four are WordPress core; the rest are the Pro addon's
     * registrations.
     *
     * @var array<string, int>
     */
    public const SECONDS = array(
        'hourly'                      => 3600,
        'twicedaily'                  => 43200,
        'daily'                       => 86400,
        'weekly'                      => 604800,
        'wp_mcp_ai_every_5_minutes'   => 300,
        'wp_mcp_ai_every_15_minutes'  => 900,
        'wp_mcp_ai_every_30_minutes'  => 1800,
        'wp_mcp_ai_every_2_hours'     => 7200,
        'wp_mcp_ai_every_6_hours'     => 21600,
        'wp_mcp_ai_every_12_hours'    => 43200,
        'wp_mcp_ai_weekly'            => 604800,
        'wp_mcp_ai_monthly'           => 2592000,
    );

    /**
     * The schema vocabulary: `single` first, then the intervals sorted, as the
     * WordPress tool builds it.
     *
     * @return list<string>
     */
    public static function slugs(): array
    {
        $intervals = array_keys(self::SECONDS);
        sort($intervals);

        return array_merge(array(self::SINGLE), $intervals);
    }

    public static function isValid(string $slug): bool
    {
        return self::SINGLE === $slug || isset(self::SECONDS[ $slug ]);
    }

    /**
     * Seconds in an interval; 0 for `single` or an unknown slug.
     */
    public static function seconds(string $slug): int
    {
        return self::SECONDS[ $slug ] ?? 0;
    }
}
