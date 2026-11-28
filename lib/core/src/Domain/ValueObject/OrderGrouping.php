<?php

/**
 * How an analytics window is bucketed.
 *
 * Proposal 164 section 164.178. A closed enum rather than a free string because
 * the reference validated `group_by` with `sanitize_text_field()`, so an unknown
 * value fell through its `switch` default to `day` and silently answered a
 * different question than the caller asked.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\ValueObject;

enum OrderGrouping: string
{
    case Day   = 'day';
    case Week  = 'week';
    case Month = 'month';

    /**
     * The canonical set, for a schema enum.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases()
        );
    }
}
