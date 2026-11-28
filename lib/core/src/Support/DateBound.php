<?php

/**
 * A strict calendar-day bound for a query.
 *
 * Several tool ports accept `date_from` / `date_to` and hand them to a query.
 * The WordPress originals pass them through `sanitize_text_field()` and
 * concatenate them into a range string, so an unparseable date is silently
 * ignored and the filter quietly disappears.
 *
 * The parse here round-trips instead. `DateTimeImmutable::createFromFormat()`
 * does not fail on an out-of-range value — `2024-13-01` rolls over to
 * `2025-01-01` — so the result is formatted back and compared with the input.
 * Anything that does not survive the round trip is not a date this tool will
 * accept.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Support;

final class DateBound
{
    /**
     * Parse `YYYY-MM-DD`, optionally extended to the last second of that day.
     *
     * The end-of-day form is what a `date_to` means: "up to the 31st" that
     * excluded everything placed on the 31st would be a boundary the caller did
     * not ask for.
     *
     * @param mixed $raw      The raw argument.
     * @param bool  $endOfDay Whether to return 23:59:59 on that day.
     */
    public static function parse(mixed $raw, bool $endOfDay = false): ?\DateTimeImmutable
    {
        $text = is_string($raw) ? trim($raw) : '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        if (false === $date || $date->format('Y-m-d') !== $text) {
            return null;
        }

        return $endOfDay ? $date->setTime(23, 59, 59) : $date;
    }

    /**
     * The refusal message for a bound that did not parse.
     */
    public static function reason(): string
    {
        return 'A date in YYYY-MM-DD form.';
    }
}
